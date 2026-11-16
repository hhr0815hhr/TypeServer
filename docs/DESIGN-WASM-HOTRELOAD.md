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

### 已做：把方法表从硬编码改成运行时配置（2026-11-16）

改了 `spike/s2/host-capi/src/lib.rs`：`tp_open` 里那张 5 个名字的数组改为
`methods_from_env()`——默认值与原来完全一致，可用 `TP_METHODS`（逗号分隔的完整名）覆盖。

验证（同一次运行、同一个二进制）：

| 场景 | `add` | `simulate-frame` |
|:--|:--:|:--:|
| 不设 `TP_METHODS` | 3 / 42 | **297** |
| `TP_METHODS='[method]runtime.add'` | 3 / 42 | **-2001** |

第二行是证据：把 `simulate-frame` 从表里去掉，它立刻查不到，而 `add` 不受影响。
说明表确实来自运行时配置。

**收益**：接口变更从「改 Rust → 重编 `libtp_host.so`（实测 2m29s）→ 重启」
降到「设环境变量 → 重启」。省掉了改代码和重编，但**没有省掉重启**。

**代价**：`TP_METHODS` 要人来维护，漏写一个方法就会出现"模块有、宿主查不到"的
`-2001`——比硬编码更容易出运维事故。所以这只是过渡方案。

编译命令（`cargo` 不在 PATH 里，必须用绝对路径）：

```bash
cd spike/s2/host-capi && /home/hhr/.cargo/bin/cargo build --release
```

### 下一步：让 host 动态枚举接口方法

`build(s, &p, &methods)` 那一步不需要硬编码——component model 支持在 interface 下
遍历导出（`get_export_index` 取到 interface 后枚举其成员）。改成运行时枚举后：

- 新增/删除导出函数 → host 零改动 → **接口也能热更**；
- `methods` 表消失，`wasm_call2` 的名字解析成为唯一路径。

**这是 M7 成立与否的真正前提，比封装门面重要得多。** 它是个 Rust 侧改动
（`host-capi`），不在 PHP 侧，成本也比"重编整个宿主"低（只重编一个 so，且只需一次）。

实现线索：`build()` 里已经有 `instance.get_export_index(&mut store, None, IFACE)` 拿到
interface，再 `get_export_index(Some(&iface), "create-runtime")` 拿到函数——说明
"在 interface 下按名取成员"这条路是通的。要做的是**反向枚举**：拿到 interface 的成员
列表（wasmtime 的 component type 提供了遍历导出/成员的接口），把 `for name in methods`
换成 `for name in 枚举结果`。那样 `TP_METHODS` 和默认表都可以删掉。

**没做的原因**：wasmtime 的 component type 枚举 API 需要精确的类型/生命周期写法，
而实测每次 `cargo build` 要 1.5–2.5 分钟，猜错一次的代价是又一轮编译。

**✅ 已实现（2026-11-16 晚）。** `TP_METHODS` 那份人工表可以不再维护了——
host 从 component 的类型信息里枚举成员，模块新增导出函数自己就能发现：

```
[enum] members=9 names=["typephp-error", "runtime", "[method]runtime.add",
  "[method]runtime.greet", "[method]runtime.simulate-frame", "[method]runtime.spin",
  "[method]runtime.forever", "[method]runtime.mul", "create-runtime"]
load(mul-probe.cwasm) handle=1
call2(mul,'mul',6,7) = 42        ← 没设 TP_METHODS
```

**正确的 API（wasmtime 50，查源码确认，不是凭记忆）**：

```rust
use wasmtime::component::types::ComponentItem;

for (ename, eitem) in component.component_type().exports(engine) {
    if ename != IFACE { continue; }
    if let ComponentItem::ComponentInstance(inst) = eitem.ty {   // 字段，不是方法
        for (mname, _item) in inst.exports(engine) {             // 产出 ComponentExtern
            // mname 已经是 "[method]runtime.add" 这种完整名，别再加前缀
        }
    }
}
```

四个容易写错的点（我逐个踩了一遍）：

1. `component_type()` 返回 **`types::Component`**，不是 `ComponentType`；
2. `ComponentItem` 在 **`component::types`** 子模块里，不在 `component` 根；
3. 顶层 `exports()` 产出的是 **`ComponentExtern`**，要取 `.ty`（**字段**）才是 `ComponentItem`；
4. `ComponentInstance::exports()` 给出的成员名**已经带 `[method]runtime.` 前缀**，
   再拼一次就变成 `[method]runtime.[method]runtime.mul`，查不到（表现是 -2001，
   很容易误判成"枚举没生效"）。

现在实现里保留了兜底：枚举失败（解析不了/为空）就退回 `TP_METHODS`/内置表，
不影响已验证的路径。`typephp-error` / `runtime` / `create-runtime` 这类非函数成员
也会被加进候选表，但它们过不了 `get_func`，自然被跳过。

**上一轮失败的原因**：我按记忆写了 `wasmtime::component::ComponentItem`（旧版路径），
编译直接 `unresolved import`。教训是——**这类跨大版本的 API 只能查源码/文档，
不能凭记忆写**，否则每次代价是一轮 1.5–2.5 分钟的编译加回滚。

**上一轮（已作废）的记录：** 2026-11-16：按记忆写了
`use wasmtime::component::ComponentItem`，编译直接报 `unresolved import`——
说明 wasmtime 50 里这个类型**不存在**（component 的类型 API 在这个大版本里重构过），
`ComponentItem` 这个名字在我的知识里是旧版的。`grep` 源码也没直接定位到替代名。

所以现在的状态是：**枚举这条路要查着文档写，不能靠记忆写**。
探测改动已全部回滚（`lib.rs` 从备份恢复 + 重编），闭环成果未受影响——
回滚后重新验证：

```
load(mul-probe.cwasm) handle=1
call2(mul,'mul',6,7) = 42
```

**下一步要做的具体事**（不是"再试一次"）：读 wasmtime 50.x 的
`component::types` 模块文档，确认遍历 interface 成员的 API（类型名 + 方法签名），
再写一次。那是一次纯查证工作，不需要试错。

### 补工具链的结论：装齐了，但 SDK 在本机跑不起来（2026-11-16）

为了能编出带新导出的 wasm 模块（验证"接口热更"），把缺的四样都装了：

| 组件 | 状态 |
|:--|:--|
| `wasmtime` 47.0.4 | ✅ `~/.local/bin`（必须与 `libtp_host.so` 链接版本一致） |
| rustup target `wasm32-wasip2` | ✅ |
| `wasi-sdk` 28.0 | ⚠️ 下载解压正常（121,193,888 字节，10 路分段并行 3m29s），但 **`clang --version` 挂死** |
| `wit-bindgen` 0.60.0 | ✅ `cargo install` 2m46s |

**卡点**：wasi-sdk 28 的 `clang --version` 在本机 **180 秒无任何输出**（不是报错，是挂住），
连带 `wasm32-wasip2-clang` wrapper 也无法给出可解析的版本号，tpc 的工具链检查因此失败：

```
WASI toolchain check failed: Unable to determine the version of WASI tool
`wasm32-wasip2-clang` from PATH
```

判断：SDK 28 与 Ubuntu 22.04 的 glibc/依赖不匹配（SDK 越新要求的 glibc 越新）。
**没有任何证据支持"再试一次就能过"**，所以停在这里，不猜。

三条可选路径（按成本排序）：

1. **降级 wasi-sdk**（试 24/25，对 glibc 要求更低）——最便宜，但版本不保证能配 tpc；
2. **用项目已有的 Docker 构建**（`spike/s1/Dockerfile`）——spike 本来就是这么来的，
   环境一致，代价是要跑容器；
3. **换更新的发行版**（Ubuntu 24.04+）——最彻底，但会动开发环境。

在工具链打通之前，M7 的"新增导出函数"这条验证**做不了**，因此也**无法**证明或否证
"接口可热更"。这是当前唯一的事实。

### 工具链打通了，但撞上 WASI 版本死结（2026-11-16 下午）

继续在 WSL2 里（不引入 Docker）把工具链补齐，过程记录：

**先发现是我自己把编译器写坏了。** wasi-sdk 28 的 `clang --version` 挂死 180 秒，
原因不是 glibc：我先 `ln -sf clang .../wasm32-wasip2-clang`，随后往这个路径写 wrapper，
`open(O_TRUNC)` **跟随符号链接**，把 121 KB 的编译器本体覆盖成了那段 123 字节的脚本，
于是 `clang` → `clang-21` → `clang` 无限递归。重新解压 tarball 即恢复。

**然后是版本要求。** wasi-sdk 28 带的是 clang 21，而 tpc 要求 **≥22**。
换成 **wasi-sdk 34**（clang 23.1.0）后工具链检查通过。

**还修了 phpx 的一个上游适配缺口**：`phpx.h` 用了 `std::abort()` / `std::fill`，
但没 include `<cstdlib>` / `<algorithm>`。旧版 libc++ 恰好间接包含，clang 22+ 不再包含，
报 `no member named 'abort' in namespace 'std'`。补两行 include + 把 `std::abort()` 换成
`abort()` 之后编译通过（编译器自己提示了 "did you mean simply 'abort'?"）。

**结果：模块编出来了。** `mul-probe.wasm` 23.8 MB（含 PHP 运行时），
`wasmtime compile` 出 `.cwasm` 42.6 MB。

**但是 `tp_open` 返回 -1。根因是 WASI 接口版本不匹配：**

```
mul-probe（wasi-sdk 34 产出）: wasi:cli/*@0.2.12
s1-probe-v2（能加载）        : wasi:cli/*@0.2.0 / @0.2.6
```

component model 的版本化 import 是**精确匹配**的，host 的
`wasmtime_wasi::p2::add_to_linker_sync` 只认它编译时对应的那版（0.2.0–0.2.6），
遇到 0.2.12 直接 instantiate 失败 → `tp_open` 返回 null → C++ 侧看到 -1。

**这是一个死结，两条路都被卡住：**

| 需求 | 后果 |
|:--|:--|
| tpc 要求 clang ≥ 22 | 只能用新 SDK（≥30），它产出 wasi 0.2.12 imports |
| host 用 wasmtime 47 的 p2 linker | 只认 0.2.0–0.2.6 |

所以"新增导出函数不重编宿主"这条**到这一轮结束仍未验证**。阻塞点从
"没有工具链"精确到了"WASI 接口版本契约不匹配"——这是比方法表硬编码更根本的约束。

两条出路（都要动 Rust 侧）：

1. **升级 host 的 `wasmtime-wasi`**，让它匹配 0.2.12（重编 so，约 2.5 分钟，
   但可能牵动 wasmtime 大版本，需要连带升级）；
2. **给 tpc 加"目标 WASI 版本"选项**，让它按 host 认的版本产出（上游改动，最干净）。

顺带确认：interface 名是一致的（两边都是 `typephp:s1-probe/api@1.0.0`），
所以**不是**命名空间/包名的问题——这一点排除了。

### ✅ 闭环了：新增导出函数不重编桥接层（2026-11-16）

上面那个 WASI 版本死结，用**升级 host 的 wasmtime** 解开了。实测输出：

```
load main(v1): handle=0 耗时 46 ms
  call2(main,'add',1,2)      = 3
  call2(main,'add',40,2)     = 42
  call2(main,'no_such_fn',0,0) = -2001
load(mul-probe.cwasm) handle=1
  call2(mul,'mul',6,7)       = 42        ← 新增导出，桥接层零改动
```

`mul` 这个函数：用 PHP 写成（`#[\WasmExport] function mul(int $a, int $b)`），
经 tpc 编成 wasm component，`cpp-src/wasm_bridge.cc` 里**搜不到 "mul" 一个字**，
靠 `wasm_call2($h, "mul", 6, 7)` 直接调通，返回 42。

**这就是 T7-01 的验收条件，现在满足了。**

### 打通它需要动的四处（按依赖顺序）

**1. host 的 wasmtime 从 47 升到 50**（`Cargo.toml` 三行 → 50）。

原因：wasi-sdk 34 产出 `wasi:cli/*@0.2.12`，而 wasmtime 47 的 `wasmtime_wasi::p2`
只 link 到 0.2.6，精确匹配失败 → `instantiate` 失败 → `tp_open` 返回 -1。
升级后 50.0.1 支持 0.2.12。

连带一处 API 适配：`WasiHttpView` / `WasiHttpCtxView` 从 `wasmtime_wasi_http::p2`
移到了 crate root，import 改一行即可。编译 2m42s（首次拉依赖）+ 1m30s（改完重编）。

**2. phpx 的 wasm 头要打 clang 22+ 的补丁。**

`phpx.h` 用了 `std::abort()` / `std::fill` 但没 include `<cstdlib>` / `<algorithm>`；
旧版 libc++ 恰好间接包含，clang 22+ 不再包含，报 `no member named 'abort' in namespace 'std'`。
补两行 include + `std::abort()` → `abort()` 后编译通过（编译器自己提示了
"did you mean simply 'abort'?"）。这是上游适配缺口，不是本项目的代码问题。

**3. cwasm 必须用 `tp-precompile`，不能用 `wasmtime` CLI。**

这一条最容易踩：`fuel` / `epoch` 属于**编译期插桩**，预编译时的配置必须与运行期一致，
产物里带 `fingerprint` 与 `artifact_size`，不一致直接拒绝启动。
用 CLI 编出来的 cwasm 会让**所有模块**加载失败（表现是句柄全变 `-1000`，
看起来像"升级把一切都搞坏了"）。

```bash
./spike/e1/precompile.sh build <in.wasm> <out.cwasm>
```

**4. 升级后所有既有模块的 cwasm 都要重编**（v1/v2 也一样），否则一起打不开。

### 完整复现步骤

```bash
# 1) host：升级 wasmtime 并重编 so
cd spike/s2/host-capi
sed -i 's/version = "47"/version = "50"/' Cargo.toml
~/.cargo/bin/cargo build --release          # 约 2.5 分钟

# 2) 模块：PHP 源码 → wasm component（要 WASI SDK 34 / clang 23）
cd /tmp/mulmod
env PATH=~/.local/bin:/tmp/wasi-sdk-34.0-x86_64-linux/bin:~/.cargo/bin:/usr/local/bin:/usr/local/php-8.5-zts/bin:/usr/bin:/bin   php85 ~/typephp-src/bin/tpc.php project.yml   # 产出 mul-probe.wasm

# 3) 预编译（必须用 tp-precompile，不是 wasmtime CLI）
cd /data/TypeServer/spike
./e1/precompile.sh build /tmp/mulmod/mul-probe.wasm /tmp/mulmod/mul50.cwasm

# 4) 跑：TP_METHODS 里登记新方法名，验证调用
cd spike/e1 && php85 ~/typephp-src/bin/tpc.php project.yml
TP_METHODS='[method]runtime.add,[method]runtime.mul' ./e1_stub
```

### 能力边界（更新后）

| 变更类型 | 现在需要做什么 | 热更？ |
|:--|:--|:--|
| 同名函数换实现 | 换 cwasm + `wasm_swap`（4.4–5.5 ms） | ✅ 是 |
| 新增导出函数 | 编 cwasm + 在 `TP_METHODS` 加名字 + 重启 | 🟡 不用改代码/重编 so |
| 改签名 | 同上 + 改调用点 | 🟡 同上 |
| 自动发现接口 | 未做（`TP_METHODS` 仍要人维护） | ❌ |

所以"M7 = 三级热更全部可用"里，**实现热更是真的、接口变更还差"自动枚举"这一步**。
但至少现在可以确定：这条路的技术前提是成立的，不是死路。

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
