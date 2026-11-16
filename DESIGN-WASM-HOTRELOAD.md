# wasm 热更（M7 / T7-01）设计

> 状态：**设计稿，未实现**。编制 2026-11-16。
> 依据：`spike/e1/SPIKE-E1.md` 的实测结论 + `spike/e1/{main.php,cpp-src/wasm_bridge.cc}` 的实际接口形态。

---

## 零、一句话

M7 的前提**成立**（E1 已端到端打通，PHP→wasm 782 ns/次，与 Rust 宿主直调同量级），
但 E1 的接口形态**不适合热更**：它给每个 wasm 导出函数都手写了一个 PHP 全局函数。
热更要的是"按名字调用"，否则改一个函数就得重编宿主。

---

## 一、E1 已经证明了什么

| 指标 | 实测 | 结论 |
|:--|:--:|:--|
| PHP→C++ 纯原生调用（`wasm_ping`） | **1 ns/次** | AOT 的 PHP→C++ 这一层不花钱 |
| PHP→stub→wasm（`add`） | **782 ns/次** | 与 Rust 宿主直调 742–870 ns 同量级，PHP 层不加价 |
| 启动（加载 + 实例化 + 预热） | **36.2 ms** | 可接受，但**不能放进帧循环** |
| 重建实例（drop + new） | **3.6 ms** | L2 热更的代价上限 |
| 内存 | **+52 MB** | 一个 wasmtime 实例 + 模块的开销，不是每模块 52 MB，需再测 |
| 灰度切换（swap） | **4.4–5.5 ms** | 换句柄，旧实例延后释放 |

这些数字说明：**性能不是障碍，接口形态才是。**

---

## 二、决定性问题：逐函数绑定 vs 按名调用

E1 的 PHP 侧长这样（`spike/e1/main.php`）：

```php
$h = wasm_load("main", art_v1());
wasm_add($h, 1, 2);          // 每个导出函数一个 PHP 函数
wasm_burn($h, 2000000000);
wasm_count();                 // 存活模块数
wasm_swap($h, art_v2());      // 换实例
```

对应 `wasm_bridge.cc` 里每个函数一个绑定。后果：

> **wasm 模块里新增一个导出函数，就要改 C++ stub → 重编整个 AOT 二进制 → 重启进程。**
> 那优化的是"模块实现热更"，代价却是"接口变更不能热更"，而且每次都要动 C++。
> 对于"玩法逻辑频繁调整"这个真实场景，函数的增删是常态，这条路走不通。

所以 T7-01 的第一件事不是封装 `load/swap`，而是**补一个通用调用入口**：

```php
// 目标形态：一个 C 入口承载所有调用
c_wasm_call(int $handle, string $fn, array $args): mixed
```

C 侧只做"按签名解包 + 调 wasmtime"，不感知业务函数名。这样：
- wasm 模块换实现、增函数、改签名（只要 PHP 侧调用点跟着改）**都不用重编宿主**；
- C++ 那份 `wasm_bridge.cc` 从此**稳定**，只随 wasmtime 升级而变。

**这是整个 M7 的胜负手**：先有它，"热更"才成立；没有它，后面三级都是给逐函数绑定打补丁。

### 为什么不干脆全走 JSON 字符串传参

`c_wasm_call($h, "add", [1, 2])` 需要在 C 侧把 PHP 数组解成 wasm 的参数元组。
若偷懒改成 `c_wasm_call($h, "add", "1,2")` 再在 wasm 里解析，就把序列化成本推进了
热路径（E1 的 782 ns 会变成几 µs）。**参数按类型数组传、在 C 侧展开**，不要过字符串。

---

## 三、三级热更的边界

| 级别 | 换什么 | 代价 | 现状 |
|:--|:--|:--|:--|
| **L1 函数级** | 单个 C++ 函数（`tsf` 本体逻辑） | 需要重编 + 替换共享库，**工程上不可行** | 不做 |
| **L2 模块级** | 一个 wasm 模块实例（玩法逻辑） | **3.6 ms**（重建）／ 4.4–5.5 ms（灰度 swap） | **本次目标** |
| **L3 进程级** | 整个进程（二进制） | 需要 drain + 重启 + 重连 | 已有（`run-drain.sh` + `run-rejoin.sh`） |

所以 M7 实际要做的是 **L2**，而 L3 已经在阶段 2 落地了——这一点文档里要写清，
否则会重复投入。

---

## 四、`TSF\Wasm` 门面（T7-01 的产出）

```php
namespace TSF\Wasm;

final class Module            // 一个已加载模块的句柄
{
    public static function load(string $name, string $artifact): self;   // 启动 36 ms，别放帧循环
    public function call(string $fn, array $args = []): mixed;           // 782 ns 量级
    public function swap(string $artifact): int;   // 换实例，返回耗时 us（L2 热更）
    public function id(): int;
}

final class Registry          // 名称 → 当前实例；灰度期可能同时存在两代
{
    public static function attach(string $name, Module $m): void;
    public static function current(string $name): ?Module;
    public static function count(): int;
}
```

约束（从 E1 的坑里来的）：

1. **`load` 不能出现在帧循环里**。36 ms 会直接打爆 60 fps 预算；热更要"预先加载新实例 →
   就绪后一次性 swap"，不是"发现变了再加载"。
2. **`swap` 之后旧实例不能立刻 drop**。正在执行的调用持有它的栈；E1 的做法是延后释放。
3. **调用必须可预算化**。`wasm_burn` 那种死循环要有指令数上限（E1 实测 30 ms 切断 +
   trap 自动重建 3.6 ms），否则一个坏模块能挂住整个 worker。

---

## 五、落地顺序（每步都可单独验收）

| 步 | 内容 | 验收 |
|:--|:--|:--|
| **1** | 通用入口 `c_wasm_call(handle, fn, args)`，替换 E1 的逐函数绑定 | 同一个 `.cwasm`，用 `call("add",[1,2])` 得到 3；**不重编宿主**就能调用新增的导出函数 |
| **2** | `TSF\Wasm\Module`（load/call/swap/id） | 与 E1 行为一致：swap 后返回值变化，耗时 ≤6 ms |
| **3** | `Registry` + 灰度（按比例返回两代句柄） | 10% 流量走新实例，指标可分代统计 |
| **4** | 发布流水线（编译 → 预编译 → manifest → 分发） | 一条命令产出可发布产物，manifest 含版本/校验和 |
| **5** | 演练 SOP（含回滚） | 演练报告：耗时、回滚验证、玩家无感（重连 ≤1 s） |

**第 1 步是唯一真正的阻塞点**：它要改 C++，且决定了后面所有 API 的形状。
建议先只做第 1 步，用一个最小模块（`add` + 一个 E1 里没有的 `mul`）验证
"新增导出函数不重编宿主"——这一条过了，M7 才成立。

### 第 1 步进度（2026-11-16）

已改 `spike/e1/cpp-src/wasm_bridge.cc`，新增两个通用入口（纯增量，不动既有函数）：

```cpp
Int php_wasm_call2(Int h, Str fn, Int a, Int b);   // PHP: wasm_call2($h, "add", 1, 2)
Int php_wasm_call1(Int h, Str fn, Int a);          // PHP: wasm_call1($h, "burn", n)
```

实现要点：

- **底层本来就是按名调用**（`tp_call1/tp_call2` 接 `const char *name`），E1 只是把它
  包成了逐函数绑定。所以这一步不需要改 wasmtime 交互，只把名字透出去。
- 错误码与 `call_idx2` 完全一致（`-1000` 句柄无效 / `-3000` 已 trap 并重建 /
  `-2000+rc` 其他），trap 后走同一个 `recover()`，调用计数与计时口径也一致——
  否则两个入口的观测数据没法放在一起看。
- 计时默认关闭的行为保留了：`clock_gettime` 每次 20–30 ns，开着会把 782 ns 的量级污染。

**验证状态：已跑通（部分）。** 2026-11-16 实测输出：

```
main.add(1,2)                     = 3
call2(main,'add',1,2)             = 3        <- 通用入口
call2(main,'add',40,2)            = 42
call2(main,'no_such_fn',0,0)      = -2001    <- 名字运行时解析，宿主不感知函数名
```

`-2001` 是"名字查不到"（`-2000 + rc(1)`），说明名字确实在运行时解析，
**桥接层没有任何业务函数名的白名单**。这是第 1 步的核心结论。

### 过程中踩到的三个坑（都会让结论反过来）

**坑 1：`tsf build` 用不了。** `genProjectYml()` 是硬编码生成的，会重写 `project.yml`，
把 spike 手写的 `ld-flags`（`-ltp_host` + rpath）冲掉，产物名也改成目录名。
必须手工执行 tpc：

```bash
cd spike/e1 && php85 /home/hhr/typephp-src/bin/tpc.php project.yml
```

**坑 2：PHP 侧要声明存根。** 只写 C++ 的 `php_wasm_call2` 不够，PHP 调用会报
`function 'wasm_call2' is undefined`。必须同时在 `spike/e1/php-src/wasm.stub.php` 里声明：

```php
function wasm_call2(int $h, string $fn, int $a, int $b): int { }
function wasm_call1(int $h, string $fn, int $a): int { }
```

**坑 3（最隐蔽）：`tp_call2` 的名字要带 ABI 前缀。**
直接传 `"add"` 会返回 `-2001`（查不到），必须传 `"[method]runtime.add"`。
参考 `php_wasm_add_byname` 的写法才看出来。前缀是 host 的 ABI 细节，
所以由桥接层拼，**业务侧仍传裸名**：

```cpp
std::string name = "[method]runtime." + fn.toStdString();
```

### 验收结果：通用入口成立，但"接口可热更"被推翻

补装 wasmtime 47.0.4（必须与 `libtp_host.so` 链接的版本一致，否则 `.cwasm` 不兼容）后，
又验出一条关键事实。实测：

```
call2(main,'simulate-frame',100,3) = 297   ✅ 与 wasm_call_sim(100,3) 一致
call2(main,'sim',100,3)            = -2001
call2(main,'no_such_fn',0,0)       = -2001
```

**通用入口不仅能调，还能按任意名字调**（`simulate-frame` 这个名字在 PHP 侧的绑定里叫
`wasm_sim`，两者不同名却指向同一函数）。第 1 步本身是成功的。

**但 `tp_open` 的实现否掉了"新增导出函数免重编"这个前提：**

```rust
// spike/s2/host-capi/src/lib.rs:454
let methods = [
    "[method]runtime.add",
    "[method]runtime.greet",
    "[method]runtime.simulate-frame",
    "[method]runtime.spin",
    "[method]runtime.forever",
];
match build(s, &p, &methods) { ... }
```

host 在打开模块时按**硬编码的 5 个名字**去解析方法，**不枚举模块导出**。后果：

> 模块新增一个导出函数（比如 `mul`），host 必须在 `methods` 数组里加一行 → 重编
> `libtp_host.so` → 重启进程加载新 so。**接口不可热更。**

**我原来的判断是错的。** 本文档上一版写着"有了通用入口，新增导出函数就不用重编宿主"——
实测推翻了它。`c_wasm_call` 的真实价值是"少写 N 个绑定函数"（从"每个函数一个
`php_wasm_xxx`"变成"改一行名字表"），**不是**"接口可热更"。

### 修正后的能力边界

| 变更类型 | 代价 | 热更？ |
|:--|:--|:--|
| 同名函数换实现（v1→v2，行为不同） | **0**（E1 实测 swap 4.4–5.5 ms） | ✅ 是 |
| 新增/删除导出函数 | 改 host 的 `methods` 表 + 重编 `libtp_host.so` + 重启 | ❌ 否 |
| 改函数签名 | 同上，且要改调用点 | ❌ 否 |

所以 M7 现在的"M7 = 三级热更全部可用"这个验收标准**达不到**：L2 只覆盖"实现热更"，
接口变更仍然要重编+重启，等价于 L3。

### 真正的胜负手：让 host 动态枚举接口方法

`build(s, &p, &methods)` 那一步不需要硬编码——component model 支持在 interface 下
遍历导出（`get_export_index` 取到 interface 后枚举其成员）。改成运行时枚举后：

- 新增/删除导出函数 → host 零改动 → **接口也能热更**；
- `methods` 表消失，`wasm_call2` 的名字解析成为唯一路径。

**这是 M7 成立与否的真正前提，比封装门面重要得多。** 它是个 Rust 侧改动
（`host-capi`），不在 PHP 侧，成本也比"重编整个宿主"低（只重编一个 so，且只需一次）。

### 验证环境现状

| 项 | 状态 |
|:--|:--|
| `wasmtime` CLI | ✅ 已装 **47.0.4**（`~/.local/bin`），必须与 `libtp_host.so` 一致 |
| 手写 core module | ❌ host 只认 **Component Model**（`instantiate(&component)` + interface），core module 一律 `tp_open` 返回 -1 |
| 编 wasm component | ❌ 缺 `wasm32-wasip2-clang`（WASI SDK）与 `wit-bindgen-cli 0.60.0`，需要 Rust 工具链 |

**顺带记一条**：wasm 模块的源码形态是 **PHP + `#[\WasmExport]`**，由 tpc 编成
component（`module_v2/project.yml` 里 `wasm: component` / `wasm-mode: library` /
`wasm-package: typephp:s1-probe@1.0.0` / `wasm-world: s1-probe`）。
这说明"玩法逻辑用 PHP 写、编成 wasm 热更"这条路在工具链设计上是通的，只是本机缺编译依赖。

### 注意：spike 不入库

`.gitignore` 第 49 行是 `/spike/**`，所以上面这些 C++ / stub / main.php 的改动
**只在本地工作区**，git 里只有这份设计稿。复现步骤已写在上面，改动是纯增量的
（新增两个函数 + 两个存根声明 + 三行验证输出）。

---

## 六、风险与未验证项

| 风险 | 说明 |
|:--|:--|
| 参数类型映射 | `int/float/string/数组` 与 wasm 签名（i32/f64/线性内存指针）的映射规则没定，字符串要拷贝进线性内存，谁负责分配/释放需要明确 |
| 内存泄漏 | wasm 实例里的线性内存不会自动回收，长跑要能观测（E1 只测了单次运行） |
| ABI 稳定性 | 模块与宿主之间的调用约定（函数名、参数序、错误码）一旦发布就不能随意改，否则新旧模块不能共存——灰度期必须共存 |
| 52 MB 的归属 | E1 报的是"加载前后 RSS 差"，不清楚是 wasmtime 运行时固定开销还是每模块开销，这决定能同时挂几个模块 |
| 与事件日志/快照的关系 | wasm 模块产生的事件要不要走 `EventLog`？如果要，模块必须能回调宿主（host function），E1 没做 |

最后一条尤其重要：**玩法逻辑里一旦有"发钱"，就必须走 `Settle` 那条账本**，
否则多进程/崩溃重放的保证在这里就断了。这条要在接口设计时就想清楚，
不能等模块跑起来再补。
