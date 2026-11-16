# Spike S1 —— WASM 能否作为「可热替换模块」被宿主加载并调用

> 执行时间：2026-11-11（本轮为端到端实测，取代同日早间的源码推断版）
> 执行环境：WSL2 Ubuntu 22.04 / PHP 8.5.12 ZTS / tpc v0.9.6（`$HOME/typephp-src`）/ 宿主 Rust + wasmtime 47.0.4
> **结论一句话：全链路跑通。单次跨边界调用 0.74–0.79 µs，边界成本约 0.75 µs，远低于 5 µs 判定线——E1 可进 tick 热路径。**

---

## 一、结论

| 待验证项 | 结论 | 实测值 |
|:--|:--:|:--|
| `--wasm` 产出可被宿主实例化并调用导出函数的 component | ✅ 通过 | 23.8 MB component，`export typephp:s1-probe/api@1.0.0` |
| 多版本可共存（热更前提） | ✅ 通过 | 实例级隔离，每个实例独立线性内存 + 独立 PHP 运行时 |
| 资源可回收 | ✅ 通过 | `resource_drop` → `runtime_shutdown`，0.7–0.9 ms |
| 单次调用开销 < 5 µs | ✅ 通过 | **0.74–0.79 µs**（标量）/ **1.01 µs**（字符串往返） |
| 计算开销（AOT 编译后） | ✅ 优秀 | **≈0.8 ns / 实体迭代**（1 万实体 8.9 µs，边界仅占 0.75 µs） |
| 实例启动成本 | ✅ 低 | instantiate 3.9–4.5 ms + PHP 运行时 2.3 ms |
| 模块加载成本 | ⚠️ **高，但可消除** | 首次 2.9–3.1 s → 预编译后 **16 ms** |
| 单实例内存 | ✅ 低 | 边际 **+4 MB**（进程固定 400 MB 为 JIT 代码，见 §4.4） |
| Windows 本地构建 | ❌ 不可行 | `.sh` 无法通过 `is_executable`，只能在 Linux 构建 |
| JS（jco）宿主 | ❌ 不可行 | 组件含 exception-handling，jco 报 `exception refs not supported` |

---

## 二、实测数据（Rust + wasmtime 47 动态 API，release 模式）

三次运行取范围，2 万～20 万次迭代：

```
precompile: 2996~3038 ms, artifact 40.6 MB | deserialize: 15.8~16.2 ms
component load (from_file): 2895~3148 ms | instantiate: 3.9~4.5 ms | create-runtime: 2.3~2.4 ms
RSS: start 4 MB -> after instantiate 397~430 MB -> after boot 400~434 MB

add(int,int)->int        : 0.742~0.789 us/call   (200000 iters)
greet(string)->string    : 1.013~1.025 us/call   (50000 iters)
simulateFrame(100 ent)   : 0.789~0.878 us/call
simulateFrame(10000 ent) : 8.819~8.904 us/call
instance #2: 2.3~2.5 ms, RSS +4 MB
instance #3: 2.2~2.3 ms, RSS +4 MB
resource_drop (shutdown) : 0.7~0.9 ms
```

### 3.1 边界成本约 0.75 µs，与负载无关

用 100 实体与 10000 实体两档拟合：

```
8.85 µs - 0.83 µs = 8.02 µs / (10000-100) 实体 ≈ 0.81 ns/实体迭代
截距 ≈ 0.75 µs  ← 这就是 canonical ABI + resource 借用 + PHP 函数分发 + zend_try 的总和
```

对照判定线：**0.75 µs 属于「可进 tick 热路径」**（<5 µs）。60 fps 下，单帧 1000 次调用的边界开销 = 0.75 ms，占 16.7 ms 预算的 4.5%。

### 3.2 字符串往返是标量的 1.35 倍

`greet` 每次调用除了边界，还要在 guest 侧 `string_dup_n` 分配、host 侧构造 `String`。**1 µs 仍是可接受量级，但热路径上应优先传数值 ID、避免大字符串往返**。

### 3.3 create-runtime 只有 2.3 ms

PHP 运行时（Zend VM + 类表 + 全部内嵌 opcode）冷启动仅 2.3 ms。这比预想快一个数量级——意味着**「一实例一 PHP 运行时」在启动成本上完全可接受**，不必为了复用运行时去做池化。

---

## 四、三个必须写进架构决策的发现

### 4.1 NTS 单运行时是硬约束（读适配层源码确认）

`build/wasm-interface/typephp_wasm_adapter.cc` 里三条守卫：

| 守卫 | 代码位置 | 含义 |
|:--|:--|:--|
| `runtime_started` | `create_runtime` | **一个组件实例只允许一个 PHP 运行时**，重复创建直接报错 |
| `self->call_active` | 每个方法入口 | **同实例禁止并发/重入调用** |
| `runtime_failed = true` | `zend_catch` 分支 | **一旦 Zend bailout，实例永久报废**，必须丢弃重建 |

推论：

1. 并发粒度 = 实例粒度。`N` 并发 = `N` 个实例，每个 +4 MB；
2. 崩溃隔离天然成立：实例被 poison 后直接 `drop`，不影响其他实例——**这正好是 Erlang 式「崩溃半径」想要的效果，而且比进程池轻**；
3. 每次调用都过 `zend_try`（setjmp）+ C++ `try/catch` + 重入检查，这 0.75 µs 里相当一部分花在这里，是「可靠性买来的开销」，不是浪费。

### 4.2 加载 3 秒是唯一真正的性能坑，且可完全消除

原生 component 每次加载要 JIT 编译 23.8 MB 的 wasm（等于整个 PHP 解释器）。实测：

| 方式 | 耗时 | 产物 |
|:--|:--|:--|
| `Component::from_file`（现场编译） | 2895–3148 ms | — |
| `Engine::precompile_component` | 2996–3038 ms | `.cwasm` 40.6 MB |
| `Component::deserialize`（读预编译产物） | **15.8–16.2 ms** | — |

**结论：发版期预编译一次，运行期只 deserialize，启动从 3.1 s 降到 16 ms（快 195 倍）。** 这条必须写进部署规范：产物包里放 `.cwasm` 而不是 `.wasm`，且 `.cwasm` 与 wasmtime 版本绑定（升级 wasmtime 必须重编）。

如果不做这一步，"热更"会变成"每次换版卡 3 秒"，反而比 T2 滚动重启更差。

### 4.3 进程固定 400 MB：JIT 代码，不是数据

`RSS after instantiate ≈ 397–430 MB`，但第 2、3 个实例只各加 4 MB。拆解：

| 项 | 量级 | 说明 |
|:--|:--|:--|
| JIT 生成的机器码 | ~350–390 MB | 23.8 MB wasm 编译后的本地代码，**每进程一份** |
| 预编译产物映射 | ~40 MB | `.cwasm` |
| 每实例线性内存 + PHP 堆 | **~4 MB** | 边际成本，这才是容量规划用的数字 |

含义：
- **一个宿主进程可承载几十个实例**（4 MB/实例），而不是"一个实例占 400 MB"；
- 但每台机器都得多付 400 MB 底噪。对游戏服这种单机跑多进程的场景，**应该少进程多实例**，而不是多进程；
- 若要把 400 MB 压下去，只能裁剪 PHP 编译进来的扩展（当前是全量 PHP）。这是后续可选优化，不影响 E1 成立。

### 4.4 JS 生态这条路是断的

`jco transpile` 直接失败：

```
ComponentError: failed to translate component
Caused by: exception refs not supported without the exception handling feature (at offset 0x1d10)
```

原因是 PHPX 的 wasm 产物用 C++ 异常实现 PHP 错误处理，带上了 wasm exception-handling 提案，而 jco 尚不支持。**所以宿主必须是原生（Rust / C / C++ / Go+FFI），不能指望 Node/浏览器直接跑这套组件。** 这条同时否掉了"用 jco 在 Web 侧做预览"的想法。

---

## 五、构建链：从零到产物的完整路径（含 5 个坑）

### 5.1 生效的配置

```yaml
# project.yml
name: s1-probe
wasm: component
wasm-mode: library
wasm-package: typephp:s1-probe@1.0.0
wasm-world: s1-probe
sources:
  - lib.php
```

```php
// lib.php —— 只能导出命名函数，不能导出类
#[\WasmExport]
function add(int $a, int $b): int { return $a + $b; }
```

实测报错印证了约束：`WasmExport can only be applied to named functions [target: class Calc]`。**编译器会把所有导出函数统一收进 `runtime` 资源的方法表**，宿主侧必须 `create-runtime()` 拿句柄后逐个调用，没有"无状态顶层函数"这种形态。

### 5.2 五个必须处理的坑

| # | 坑 | 现象 | 处理 |
|:--|:--|:--|:--|
| 1 | 源码从 Windows 拷来带 CRLF | `/usr/bin/env: 'bash\r'`、`'php\r'` | `sed -i "s/\r//"` 处理 `wasm/*.sh`、`bin/tpc.php`、`cli.php` |
| 2 | 代理污染 | `build-sdk.sh` 内部 curl 报 `SSL unexpected eof` | 所有网络命令 `env -u http_proxy -u https_proxy ...` 包裹 |
| 3 | 依赖源单点不可达 | `download.gnome.org` 连不上 → libxml2 失败 | 用南大镜像预置 tar 包到 `wasm/dependencies/.../archives/`，脚本内置 sha256 校验 |
| 4 | **phpx 2.9.5 的 WASI 构建缺源文件** | 链接报 `undefined symbol: typephp_runtime_start` 等 3 类符号 | 在 `phpx/wasm/CMakeLists.txt` 补 5 个源文件（见下） |
| 5 | SDK 头文件不全 | `phpx_exception_policy.h` not found | `cp phpx/include/*.h .../wasm32-wasip2/include/phpx/` |

第 4 条是本轮最关键的发现，也是**上游未发布状态**的证据：

```
符号：typephp_runtime_start / typephp_runtime_stop
      FunctionCallCacheSlot::~FunctionCallCacheSlot
      typephp_install_reflection_attribute_handlers
定义位置：src/misc/typephp_runtime.cc
         src/typephp/typephp_{attribute,call,property,trait}.cc
现状：phpx v2.9.5 的 wasm/CMakeLists.txt 只收集 src/core/*.cc 与 src/std/*.cc
```

补丁（5 行加入 CMakeLists 源文件列表）后重编 `libphpx.a`，链接通过。**这意味着 TypePHP 的 WASM library 模式目前处于「代码已实现、依赖未发布」状态：任何人按官方文档搭环境都会卡在这一步。** 这不是配置问题，是公开版本的缺口。

### 5.3 一键复现

```bash
# 1) 构建 SDK（7 分 45 秒，4 核）
export PATH=$HOME/.tools/wasi-sdk-33.0-x86_64-linux/bin:$PATH
cd /data/TypeServer/spike/s1
env -u http_proxy -u https_proxy \
  $HOME/typephp-src/wasm/build-sdk.sh \
  --prefix $HOME/wasip2-sdk \
  --php-source /tmp/php-wasm \
  --phpx-source $HOME/typephp-src/vendor/swoole/phpx --jobs 4

# 2) 编译 component（产出 23.8 MB）
php85 $HOME/typephp-src/bin/tpc.php project.yml --wasm

# 3) 宿主测量
./host-rs/target/release/s1-host s1-probe.wasm 200000 2
```

---

## 六、对 E1 决策的最终修正

原估（`ARCHITECTURE-EVOLUTION.md`）：3–5 周集成，单次调用 <200 µs 为通过线。

| 项 | 原估 | 实测修正 |
|:--|:--|:--|
| 单次调用开销 | 200 µs 以内即可 | **0.75 µs**，比判定线好 4 个数量级 |
| 定位 | 只做「低频事件钩子」 | **可进 tick 热路径**，可承载逐帧实体逻辑 |
| 实例并发 | 未考虑 | 实例级并发，+4 MB/实例，天然崩溃隔离 |
| 启动成本 | 未考虑 | deserialize 16 ms（必须用 `.cwasm`）；PHP 冷启动 2.3 ms |
| 环境成本 | +3–5 人日 | 维持，且必须打 phpx 补丁（应向上游提 PR） |
| 开发回路 | 只能走 CI | 维持：改一行仍需 Linux 构建（约 30–60 s 增量） |

**最终判定：E1 成立，且应上调优先级。** 理由不再只是"能隔离"，而是：

1. 0.75 µs 的边界成本下，「wasm 模块 + 原生宿主」的性能与「进程内调用」差距不到 1 µs，**足以承载帧内逐实体/逐单位的玩法逻辑**；
2. 实例被 bailout 污染后可整体丢弃重建（2.3 ms），**这是 PHP 侧拿不到的崩溃隔离**；
3. 多版本实例共存是天然的 A/B 与灰度能力——同一宿主可同时跑 v1、v2 两个 wasm 实例，按玩家分流。

代价也明确，需要在设计里写死三条：

| 纪律 | 原因 |
|:--|:--|
| 产物发 `.cwasm` 不发 `.wasm` | 否则每次加载 3 秒 |
| 每实例单线程串行调用 | NTS，重入直接报错 |
| 模块必须瘦身或接受 400 MB/进程 | JIT 代码与实例数无关，与模块大小相关 |

---

## 七、套件清单（可复现）

| 文件 | 作用 |
|:--|:--|
| `lib.php` | 三个探针导出：`add` / `greet` / `simulateFrame` |
| `project.yml` | library 模式配置 |
| `s1-probe.wasm` | 实测产物（23.8 MB） |
| `host-rs/` | Rust 宿主（wasmtime 47 动态 API，不用 bindgen，测净开销） |
| `host/host.mjs` | 早期 jco 方案，**已废弃**（jco 不支持 exception-handling） |
| `Dockerfile` / `run-in-docker.sh` | 早期 Docker 方案，保留作为 CI 参考 |
| `wit-full.txt` | 组件真实 WIT（含全部 wasi 导入） |
| `sdk-build.log` / `phpx-rebuild.log` | 构建日志 |

宿主代码要点：`instance.get_export_index(...)` → `get_func(...)` → `Func::call(&store, &[Val], &mut [Val])`，全程走动态 API，不经 bindgen，所以测到的就是 canonical ABI + PHP 调用的净成本。
