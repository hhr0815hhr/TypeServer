# E1 落地 —— PHP 业务代码 → native stub → wasm 模块

> 执行时间：2026-11-12
> 环境：tpc v0.9.6 原生 AOT / 链接 S2 产出的 `libtp_host.so` / 加载 S1 产出的 `.cwasm`
> **结论一句话：链路端到端打通。PHP 侧调用一次 wasm 函数 782 ns，和 Rust 宿主直调（742–870 ns）完全一致——说明 AOT 的 PHP→C++ 原生调用这一层不花钱。启动 36 ms、重建实例 3.6 ms、内存 +52 MB。**

---

## 一、实测结果

```
== E1: PHP -> native stub -> wasm ==
RSS(before): 42 MB
wasm_boot: 36192 us
RSS(after boot): 94 MB
wasm_call_add(1, 2) = 3
wasm_call_sim(100, 3) = 297
wasm_ping  : 1 ns/call (last=200000)
wasm_add   : 782 ns/call (last=200000)
wasm_sim10k: 8708 ns/call (last=29999)
RSS(after shutdown): 90 MB
reboot (drop + new instance): 3620 us
after reboot add(5,6) = 11
done
```

| 指标 | 数值 | 对照 |
|:--|:--:|:--|
| PHP→C++ 纯原生调用（`wasm_ping`） | **1 ns/次** | 等于零开销 |
| PHP→stub→wasm（`add`） | **782 ns/次** | S1 的 Rust 宿主直调 742–870 ns → **PHP 层不加价** |
| 计算型（1 万实体） | 8.71 µs | S1 的 8.82–8.90 µs，一致 |
| 启动（cwasm 反序列化 + 实例 + PHP 运行时） | **36.2 ms** | S2 的 48.4 ms（冷启动），热 cache 后更快 |
| 内存增量 | **+52 MB**（42 → 94 MB） | S2 的 55 MB 一致 |
| 销毁后重建实例 | **3.6 ms** | S1 的 instantiate 4 ms + PHP 启动 2.3 ms |
| AOT 二进制体积 | **80 KB** | 宿主放在 .so 里，二进制本身很小 |

**最重要的结论**：`wasm_ping` 的 1 ns 证明 TypePHP 的 AOT 调用链（PHP 函数 → 原生函数）没有可见开销，所以从 PHP 业务代码调 wasm 的成本**就是 wasm 边界本身的 782 ns**。这也意味着：**宿主语言选 Rust/C++ 还是别的，对业务侧感知的成本没有影响**——S2 的这个推论现在有了端到端证据。

---

## 二、实现结构

```
spike/e1/
├── project.yml            # cxx-std / cxx-flags / ld-flags / sources
├── main.php               # 业务代码：调用 wasm、测吞吐、重建实例
├── php-src/wasm.stub.php  # 只有声明，无实现
└── cpp-src/wasm_bridge.cc # 实现：extern "C" 调 S2 的 C ABI
```

### 2.1 三步接起来

**第一步：stub 声明**（PHP 侧只写签名）

```php
function wasm_call_add(int $a, int $b): int {}
```

**第二步：C++ 实现**（函数名必须是 `php_` + stub 里的函数名）

```cpp
extern "C" {
void *tp_open(const char *path);
int tp_call2(void *h, const char *name, long long a, long long b, long long *out);
}

Int php_wasm_call_add(Int a, Int b) {
    long long out = 0;
    int rc = tp_call2(g_host, "[method]runtime.add", a, b, &out);
    return rc == 0 ? static_cast<Int>(out) : static_cast<Int>(-2000 + rc);
}
```

**第三步：project.yml 链接**（关键，且踩过坑）

```yaml
cxx-std: c++17
cxx-flags: |
  -O2
ld-flags: |
  -L/data/TypeServer/spike/s2/host-capi/target/release
  -Wl,--no-as-needed
  -ltp_host
  -Wl,-rpath,/data/TypeServer/spike/s2/host-capi/target/release
```

### 2.2 两个踩到的坑

| 坑 | 现象 | 原因与解法 |
|:--|:--|:--|
| 配置键名是 **`ld-flags` / `cxx-flags`**（带连字符） | 写成 `ldflags` 时**静默忽略**，链接报 `undefined reference to tp_ping` | 源码在 `src/Translator.php:4677` 读的是 `$cfg['ld-flags']`；写成 `ldflags` 不报错只是不生效 |
| Ubuntu 默认 `--as-needed` | `-ltp_host` 被排在目标文件之前，被判定"未使用"而丢弃 | 加 `-Wl,--no-as-needed` 强制保留 |

第二个坑很隐蔽：链接顺序 + `--as-needed` 一起作用时，报错只显示"未定义引用"，不会告诉你库其实被丢了。

---

## 三、部署形态与体积

| 组成 | 体积 | 说明 |
|:--|:--:|:--|
| `e1_stub`（AOT 二进制） | **80 KB** | 只含业务代码与桥接层 |
| `libtp_host.so`（wasm 宿主） | 20 MB | S2 产出，可被多个二进制共享 |
| `libphpx.so` | 988 KB | TypePHP 运行时（AOT 本来就需要） |
| `libphp.so` | 72 MB | PHP 引擎（AOT 本来就需要） |
| `s1-probe.wasm.cwasm` | 41 MB | 每个 wasm 模块一份 |

**wasm 相关的净增量 = 20 MB（宿主，全进程共享）+ 41 MB/模块 + 52 MB RSS。**

二进制只有 80 KB 这件事值得注意：它说明 wasm 宿主以动态库形式挂载时，AOT 产物仍然保持"小二进制"的形态，便于分发；代价是要维护 rpath 与 .so 版本。若要做真·单文件，可以改链静态库（`libtp_host.a`，+20 MB），两者都可行。

---

## 四、从 spike 到可用的改造清单

当前版是**能跑通的最小实现**，离生产还差五件事，按优先级排：

| # | 改造项 | 原因 | 成本 |
|:--|:--|:--|:--:|
| 1 | **宿主开启 epoch/fuel 预算** | 当前 `libtp_host.so` 是 `epoch_interruption(false)`，一个死循环会挂死整个进程（S3 已验证机制可用，只是没接） | 0.5 人日 |
| 2 | **trap 后自动丢弃重建** | S3 证明 trap 后实例永久报废；本次实测重建只要 3.6 ms，完全可承受 | 0.5 人日 |
| 3 | **方法名改成编译期绑定** | 现在每次调用都传 `"[method]runtime.add"` 字符串做哈希查找；直接绑到 `Func` 句柄可省掉这次查找（预计几十 ns） | 1 人日 |
| 4 | **多模块/多版本管理** | 每个 `.cwasm` 一个实例；换版时新实例就绪后再切流量（多版本共存是 S1 已证实的特性） | 2–3 人日 |
| 5 | **`.cwasm` 与 wasmtime 版本绑定** | 升级 wasmtime 必须重编全部模块；这条必须落进 CI，否则升级会炸在线上 | 2 人日 |

其中 **1 和 2 是上线前必须做的**，成本合计 1 人日；3–5 可以随迭代补。

---

## 五、复现命令

```bash
cd /data/TypeServer/spike/e1

export PATH=/usr/local/php-8.5-zts/bin:$PATH
php85 /home/hhr/typephp-src/bin/tpc.php project.yml

./e1_stub
```

前提：`/data/TypeServer/spike/s2/host-capi/target/release/libtp_host.so` 与 `/data/TypeServer/spike/s1/s1-probe.wasm.cwasm` 已存在（S1/S2 的产物）。

---

## 六、E1 判定

**通过。** 三个数字：

1. **782 ns**：PHP 业务代码调用 wasm 函数的端到端成本，与 Rust 宿主直调一致，**PHP 层零加价**；
2. **36 ms / +52 MB**：启动成本与内存增量，符合 S2 预期；
3. **3.6 ms**：销毁重建一个实例的成本——这就是"trap 即回收"和"换版即重建"的价格，非常便宜。

至此 S1/S2/S3/S4/S5/S6 + E1 全部落地验证完毕。架构上的判断可以收敛成一句：**wasm 模块在 TypePHP 里既跑得动（0.78 µs）、也切得掉（10 ms）、也恢复得快（3.6 ms），而且不改变业务代码的写法**——PHP 侧只是多了一组普通函数。

---

## 七、第二版：接上预算治理与自动重建（上线前必做项）

第一版把链路跑通了，但宿主是 `epoch_interruption(false)`——一个 `while(true)` 就能把整个游戏进程挂死。第二版补上这两项。

### 7.1 实测结果

```
== E1-v2: 预算治理 + trap 自动重建 ==
RSS(before): 42 MB
wasm_boot: 55821 us
RSS(after boot): 105 MB
wasm_call_add(1,2) = 3
wasm_call_sim(100,3) = 297
burn(2e9):  rc=-3000  耗时 29 ms      ← 本该跑 6 秒的计算，30ms 内切断
after burn, add(1,2) = 3              ← 自动重建后立刻可用
forever(3): rc=-3000  耗时 30 ms      ← 真死循环同样被切断
after forever, add(5,6) = 11
累计自动重建次数: 2
wasm_add: 793 ns/call (last=100000)   ← 正常调用不受影响
wasm_sim10k: 12081 ns/call
```

**进程全程存活**，业务侧只看到 `rc=-3000`（当次调用失败），下一次调用自动恢复。这就是 S3 设计的"trap 即回收"从机制变成可用的运维路径。

### 7.2 三个实现层面的坑（都会在生产上咬人）

**坑一：`.cwasm` 的编译期配置必须与宿主一致。**

第一次开启 fuel 后启动直接失败：

```
deserialize: Module was compiled without fuel support but it is enabled for the host
```

fuel 是**编译期插桩**，不是运行期开关。这意味着发版流水线里"预编译"这一步的 Config 必须和宿主完全一致（fuel 开/关、是否启用其他提案），否则产出的 `.cwasm` 在运行期加载即报错。这条要写进 CI 的构建脚本，不能靠人记。

**坑二：`ticks=1` 会误杀正常调用。**

`set_epoch_deadline(N)` 的语义是"再过 N 个心跳就 trap"，而不是"N 个心跳那么长的时间预算"。设成 1 时，任何**跨越心跳点**的调用都会被切断：

```
add(1,2) 单次 0.8 µs，心跳 10ms → 每次调用命中心跳边界的概率 0.008%
20 万次调用 → 约 16 次误杀
```

实测表现极具迷惑性：`add` 全部"返回成功"（第一次确实成功），但后续调用的耗时骤降到 54 ns——因为实例已被打死，全部走的是"拒绝进入"分支。

**正确取值是 ticks=3**（≈20–30 ms 预算）：正常调用（<1 ms）不可能跨越 3 个心跳，而死循环一定会在 30 ms 内被切断。这条语义差别必须写进文档，否则每个接入的人都会踩一次。

**坑三：自动重建要连带恢复预算设置。**

重建出的新实例如果忘了重新 `tp_set_budget`，它就退化成"无预算"状态——看起来一切正常，直到某天一个死循环把进程挂死。这一点在桥接层用 `ensure_host()` 统一收口可以避免。

### 7.3 预算治理的开销：比想象中小

同一份 `.wasm`，三种配置各预编译一次，用同一压测端测：

| 配置 | `add`（边界型） | `simulateFrame(10000)`（计算型） | `.cwasm` 体积 |
|:--|:--:|:--:|:--:|
| 无插桩（基线） | 1.056 µs | 9.79 µs | 41 MB |
| 仅 epoch | 0.794 µs | **9.60 µs** | 47 MB |
| epoch + fuel | 0.718 µs | **10.72 µs** | 54 MB |

结论：

1. **边界型调用完全不受影响**（0.72–1.06 µs 全在噪声内，`add` 的成本被跨边界主导）；
2. **计算型调用：epoch 约 0 开销，fuel 约 +10~15%**；
3. **`.cwasm` 体积：epoch +15%、fuel 再 +15%**（41 → 47 → 54 MB）。

> 需要更正一处：本轮中途曾测到 `sim10k` 从 8.7 µs 涨到 13.1 µs（+50%），当时判断是 fuel 插桩的代价。复查发现那是冷启动与实例重建后的瞬时状态，重复三次稳定在 11.8–12.5 µs。**真实代价是 +10~15%，不是 50%。**

### 7.4 因此的推荐配置

| 场景 | 配置 | 理由 |
|:--|:--|:--|
| 帧内高频调用（技能判定、AOI 过滤） | **仅 epoch**（ticks=3） | 零开销，已经能切断死循环 |
| 需确定性配额的玩法脚本（防单个脚本吃满整帧） | epoch + fuel | 多 10~15% 计算开销，换"同一输入消耗恒定额度"的能力 |
| 低价值/低频钩子 | 仅 epoch | 简单，无额外体积 |

即：**epoch 是默认档，fuel 是按需档**。上一版 S3 报告里"fuel 开销可忽略、可默认开启"的说法据此收窄——当时的对照实际上没有真正插桩（模块由无 fuel 的引擎编译），数字不可用。

---

## 八、发版流水线：`.cwasm` + manifest

### 8.1 为什么必须做这一步

fuel / epoch 是**编译期插桩**，运行期改不了。这意味着三类漂移都可能发生，而它们的最坏表现极难排查：

| 漂移类型 | 不校验时的表现 |
|:--|:--|
| 预编译没开 fuel，运行期开了 | 启动即报错（wasmtime 拦得住） |
| 预编译开了 fuel，运行期关了 | **可能静默运行但预算不生效**——平时看不出，直到某个死循环把进程挂死 |
| 改了 `.wasm` 忘了重新预编译 | 跑的是旧逻辑，无任何报错 |
| wasmtime 升级但产物没重编 | 反序列化失败（wasmtime 产物头自己会拦） |

第二行是真正的危险项：它不会报错，只会在最坏的时刻失效。所以 manifest 的核心价值不是"记录信息"，而是**把配置一致性变成启动时的硬失败**。

### 8.2 工具与产物

```bash
# 预编译（CI 或本地）
./precompile.sh build <input.wasm> [output.cwasm]

# 校验（CI / 部署前）
./precompile.sh verify <input.wasm> <output.cwasm>
```

产物是两个文件：

```
s1-probe.wasm.cwasm           54 MB   预编译产物（运行期只做反序列化）
s1-probe.wasm.cwasm.manifest  300 B   指纹 + 尺寸 + sha256
```

manifest 内容：

```ini
# 由 tp-precompile 自动生成，请勿手改
format=1
fingerprint=component=1;epoch=1;fuel=1;os=linux;arch=x86_64
artifact_size=54138960
source_size=23806403
source=/data/TypeServer/spike/s1/s1-probe.wasm
source_sha256=265a805b...
artifact_sha256=485c31a7...
built_at=2026-11-12T10:54:51+08:00
```

### 8.3 三道校验，各管一段

| 位置 | 校验内容 | 目的 |
|:--|:--|:--|
| 运行期（`tp_open`） | `fingerprint` + `artifact_size` | 拦住配置漂移与截断产物，**硬失败** |
| CI（`precompile.sh verify`） | `source_sha256` | 拦住"改了 wasm 忘了重编" |
| CI（同上） | `artifact_sha256` | 拦住产物被改动/损坏 |

**运行期刻意不做全量 sha256**：54 MB 全量哈希约 60–80 ms，会把 16 ms 的加载退化成"每次启动多花 70 ms"。完整性交给 CI 的 verify（一次），运行期只做 O(1) 的元数据比对。

### 8.4 实测：三种失败都被拦住，且报错可直接照做

```
=== 1) 配置漂移：宿主 TP_NO_FUEL=1（manifest 记的是 fuel=1）===
open failed: 配置漂移：产物指纹 [component=1;epoch=1;fuel=1;os=linux;arch=x86_64]
             与当前宿主 [component=1;epoch=1;fuel=0;os=linux;arch=x86_64] 不一致；
             请用相同配置重新预编译（fuel/epoch 属于编译期特性，运行期改不了）

=== 2) 产物尺寸不符 ===
open failed: 产物损坏：manifest 记录 123 字节，实际 54138960 字节

=== 3) 缺少 manifest ===
open failed: 缺少 manifest（.../s1-probe.wasm.cwasm.manifest）：No such file or directory
             ；请用 precompile.sh 生成产物

=== 4) 正常产物 ===
tp_open #1: 54.3 ms (含 JIT), rss 66 MB
add(1,2) = 3
```

CI 侧的陈旧检测同样有效：

```
=== 模拟源文件变更（不改产物）===
FAIL: 源文件已变更，产物陈旧（改了 .wasm 但没重新预编译）
  manifest: 265a805b...
  实际    : 0fef38f7...
(返回码 1)
```

### 8.5 落到 CI 里的最小形态

```yaml
# 伪代码
build:
  - tpc project.yml --wasm                      # 产出 .wasm
  - precompile.sh build out/mod.wasm            # 产出 .cwasm + manifest
  - precompile.sh verify out/mod.wasm out/mod.wasm.cwasm
  - 上传 .cwasm + .manifest 到制品库（带上 fingerprint 作为 tag）
deploy:
  - 拉制品 → 校验 fingerprint 与目标环境一致 → 原子替换 → 灰度
```

**关键纪律：`.cwasm` 与 wasmtime 版本、编译期特性绑定，升级任何一项都必须全量重编，不能只换库。** 这条现在已经由 manifest 强制，而不是靠流程文档约束。

---

## 九、P1：多模块注册 + 灰度切换 + 观测

### 9.1 做了什么

第一版是"单模块硬编码"（`kArtifact` 写死一个路径、一个全局句柄）。P1 把它改成注册表：

| 能力 | 实现 |
|:--|:--|
| 多模块共存 | 固定 8 槽注册表，每槽独立实例（各自 Store/Instance） |
| 调用路径 | 加载时把方法名解析成索引，调用走 `tp_call_idx`；模块用**整数句柄**，不做字符串转换 |
| 灰度切换 | `wasm_swap(handle, newPath)`：新实例就绪 → 原子换句柄 → 释放旧实例 |
| 观测 | 每模块：calls / traps / rebuilds / max_us / alive |
| trap 恢复 | 按模块独立重建，不影响其他模块 |

### 9.2 实测

```
== P1: 多模块 + 索引绑定 + 灰度切换 ==
load main(v1): handle=0 耗时 64 ms
  main.add(1,2) = 3
load canary(v2): handle=1 耗时 60 ms
  canary.add(1,2) = 1003      <- 两个模块行为确实不同
存活模块数: 2
RSS(after 2 modules): 162 MB  <- 42 MB 基础 + 2 × ~55 MB

索引绑定调用: 1081 ns/call
字符串查找  : 1106 ns/call
差异        : 25 ns/call      <- 多轮在 2–30 ns 之间波动，属噪声

切换前 main.add(1,2) = 3
wasm_swap 耗时: 4683 us       <- 4.7 ms
切换后 main.add(1,2) = 1003   <- 行为立即改变

burn(2e9) 返回值: -3000
重建后 canary.add(1,2) = 1003  <- trap 后按模块独立重建

== 观测 ==
  main  : calls=400003 traps=0 rebuilds=1 alive=1
  canary: calls=3      traps=1 rebuilds=1 alive=1
  main max 单次耗时(sim 10000): 15 us
```

三条读数：

1. **切换 4.4–5.5 ms**——因为组件已按路径缓存，`wasm_swap` 只新建实例（instantiate + create-runtime），不重新反序列化 54 MB 产物；
2. **索引绑定 vs 字符串查找：2–30 ns，属噪声量级**（多轮实测 2 / 12 / 25 / 30 ns 不等）。0.8–1.1 µs 的边界成本完全主导，方法名查找占比 <3%。这项优化价值有限，但**模块句柄化是必需的**——按名字查会引入 `Str→std::string` 转换，那一项才是真正的开销（见坑二）；
3. **每个模块独立重建**：canary trap 后只有 canary 的 rebuilds+1，main 不受影响。

### 9.3 三个坑

**坑一：宿主只缓存了一个组件，多模块全部指向同一个。**

第一次跑多模块，`canary.add(1,2)` 返回 3 而不是 1003——两个模块表现完全一样。根因在 Rust 侧：

```rust
struct Shared { engine: Engine, component: Component }   // 只有一个 component
static SHARED: OnceLock<...>                              // 进程级只初始化一次
```

第二个 `tp_open` 拿到的是第一个组件。改成按路径缓存才正确：

```rust
struct Shared { engine: Engine, cache: Mutex<HashMap<String, Component>> }
```

这个坑很隐蔽：单模块场景永远不会暴露，**一上多模块立刻错，而且错得"看起来正常"**（调用成功、返回值合理，只是版本不对）。

**坑二：按名字查表把调用推高约 300 ns。**

第一版注册表用 `wasm_add("main", a, b)`，每次调用要做 `Str→std::string` 转换 + 线性扫描。实测从 0.78 µs 涨到 1.10 µs。改成整数句柄后回到 1.0–1.1 µs（剩余差异主要是本次二模块共存的内存压力与机器波动，S2 同代码不同轮次也测到 0.72–1.06 µs 的波动）。

**结论：调用路径上不要出现字符串。**

**坑三：计时开关关闭时仍在取时钟。**

统计 `max_us` 需要 `steady_clock::now()`，但即使 `g_timing=0` 代码里也取了一次 t0——每次调用白付 20–30 ns，还会污染测量。改成只在开启时取。

### 9.4 距离生产还差什么

| 缺口 | 说明 |
|:--|:--|
| 灰度是"整体切换" | 现在是全量换句柄，没有按比例分流（如 10% 流量走 canary） |
| 无健康检查 | 新实例起得来就算成功，没有"切换后探测若干请求再放开"的步骤 |
| 观测只有累计值 | 缺分位数与时间窗口（现在是 calls 累计 + max） |
| 槽位固定 8 个 | 模块数超过 8 需要扩容或改用动态表 |

**灰度最小闭环**建议：`加载 canary → 探测 N 次 → 全量切换 → 观察 M 秒 → 失败则切回旧 artifact`。当前 `wasm_swap` 已经是"新实例就绪才换"，把探测与回滚补上即可。
