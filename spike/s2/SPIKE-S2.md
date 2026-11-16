# Spike S2 —— 把 wasm 宿主嵌进常驻进程的成本

> 执行时间：2026-11-12
> 环境：WSL2 Ubuntu 22.04 / Rust 1.99 / wasmtime 47.0.4 / gcc 11.4
> **结论一句话：可以嵌，代价明确——库 20.4 MB、加载 0.5 ms、调用零额外开销、符号零冲突，但每进程要付 ≈50 MB 常驻内存（用 `.cwasm` 时）。sidecar 方案每次调用贵 48 倍（41 µs vs 0.87 µs），tick 路径上不可用。**

---

## 一、结论

| 待验证项 | 结论 | 实测 |
|:--|:--:|:--|
| 能否封装成 C ABI 供外部调用 | ✅ | `libtp_host.so`，6 个业务符号 + 2 个 wasmtime 内部符号 |
| 动态库体积 | ⚠️ 偏大但可接受 | **20.46 MB**（LTO + strip + panic=abort） |
| 静态链接增量 | ⚠️ 同上 | 二进制 16 KB → **20.4 MB**（`--gc-sections` 后） |
| 加载耗时 | ✅ | `dlopen` **0.51–0.63 ms** |
| 符号冲突（对 libphp.so 5408 个导出） | ✅ 零冲突 | 交集为空 |
| 运行时依赖 | ✅ 干净 | 仅 `libc / libm / libgcc_s`，无 libstdc++ |
| C ABI 调用开销 | ✅ 可忽略 | `tp_ping` **1 ns**；`add` 0.876 µs（Rust 直调 0.867，差在噪声内） |
| 实例创建（共享 Engine） | ✅ | **2.6–3.0 ms** |
| 内存（JIT 路径） | ❌ 严重 | **344–359 MB / 进程** |
| 内存（`.cwasm` 路径） | ✅ 可接受 | **55 MB / 进程**（PSS 50 MB） |
| `.cwasm` 加载 | ✅ | 42.65 MB 产物，**48.4 ms**（比现场 JIT 快 66 倍） |
| 跨进程共享机器码 | ❌ 基本不共享 | 两进程 PSS 47.2 / 47.3 MB，仅约 5 MB 共享 |
| sidecar（UDS 往返） | ❌ tick 路径不可用 | **41.35 µs / 次** = 进程内的 **48 倍** |

---

## 二、实测明细

### 2.1 产物与符号面

```
libtp_host.so   20.46 MB        # cdylib，LTO=thin, strip=symbols, panic=abort, codegen-units=1
libtp_host.a    62.80 MB        # staticlib
bench-static    20.42 MB        # 静态链接后的可执行文件（unstripped 29.9 MB）
bench-dl        16.5 KB         # 纯 dlopen 版本，库体积不计入
```

导出符号只有 8 个：

```
tp_ping  tp_open  tp_call1  tp_call2  tp_close  tp_last_error
resolve_vmctx_memory_ptr_47_0_4   set_vmctx_memory_47_0_4
```

与 `libphp.so` 的 5408 个导出**交集为空**，因此加载进 PHP/Swoole 进程不会发生符号抢占。后两个是 wasm 栈切换所需的回调，无法隐藏（可加 version script 收窄可见性，但不影响功能）。

运行时依赖只要 `libc / libm / libgcc_s`。**没有 libstdc++**，这点很关键——Swoole、PHP 扩展各自链的 C++ 运行库版本不会互相干扰。

### 2.2 调用开销：C ABI 是免费的

| 调用路径 | 耗时 | 说明 |
|:--|:--:|:--|
| `tp_ping(i64)` | **0.001 µs**（1 ns） | 纯 C→Rust，函数体只有一次加法 |
| `tp_call2("add", 1, 2)` | **0.876 µs** | 与 Rust 直调（0.867 µs）在噪声内 |
| `tp_call2("simulate-frame", 10000)` | 9.18 µs | 计算为主 |
| 第二实例上的 `add` | 0.836 µs | 多实例不互相拖慢 |

**结论：C ABI 这一层不花钱，0.87 µs 全部来自 wasm 边界 + PHP 调用本身。** 也就是说，宿主用什么语言写（Rust/C/C++）对每次调用的成本没有影响，选型可以完全看工程效率。

### 2.3 加载与内存：`.wasm` 与 `.cwasm` 是两个世界

| 路径 | 首次 `tp_open` | RSS | 第二个实例 |
|:--|:--:|:--:|:--:|
| 现场 JIT（`.wasm`） | **3189–6888 ms** | **344–359 MB** | 2.6–3.0 ms |
| 反序列化（`.cwasm`） | **48.4 ms** | **55 MB** | 2.7 ms |

- JIT 路径那 340 MB 是**编译产物（机器码）占的匿名内存**，每进程一份，跟实例数无关；
- `.cwasm` 路径的机器码是文件映射，RSS 掉到 55 MB，加载快 66 倍；
- 两个进程并发加载同一个 `.cwasm`：PSS 47.2 / 47.3 MB——**说明 wasmtime 把机器码重定位成了进程私有页，跨进程共享非常有限（仅约 5 MB）**。

所以内存模型必须按"**每进程 50 MB 底噪 + 每实例 4 MB**"来算，不能指望共享省内存：

| 部署形态 | 底噪 | 说明 |
|:--|:--:|:--|
| 8 个 worker 各内嵌 | **≈400 MB** | 换来每次调用 0.87 µs |
| 2 个专用 host 进程 | ≈100 MB | 但其他 worker 走 IPC，41 µs/次 |

### 2.4 sidecar：贵 48 倍，tick 路径直接出局

```
UDS socketpair 往返 (24 字节): 41.352 us/call
```

对照 60 fps、单帧 16.7 ms 的预算：**每帧只能做 400 次跨进程调用**，而游戏逻辑一帧动辄数千次实体级调用。所以：

- **tick 热路径必须进程内**；
- sidecar 只适合"低频 + 必须强隔离"的场景（玩家自定义脚本、审核期灰度、可疑逻辑沙箱）。

---

## 三、两个必须写进设计的前提

### 3.1 产物必须是 `.cwasm`，且这件事要在发版期完成

`.cwasm` 42.65 MB，比 `.wasm`（23.8 MB）大 79%，这是正常的——它内含机器码。换来的是 48 ms 加载、55 MB 内存。

**代价是版本绑定**：`.cwasm` 与 wasmtime 版本强绑定，升级 wasmtime 必须重新编译全部模块。这条要写进发版流水线，否则升级时会炸在线上。

### 3.2 PHP 侧接入方式还没实测，但风险已收敛

本机 PHP 8.5.12（ZTS + Swoole）**没有编译 FFI 扩展**（`phpversion('ffi')` 返回 none，`php -m` 无 ffi）。所以"PHP 直接 `FFI::cdef` 调用我们的库"这条路在本机不可行，需要：

| 接入方式 | 成本 | 每次调用额外开销 | 备注 |
|:--|:--|:--|:--|
| PHP 扩展（C，薄封装 6 个函数） | 2–3 人日 | ≈0.1–0.2 µs | 最稳，Swoole 本身就是扩展 |
| PHP FFI（需重编 `--with-ffi`） | 半天（重编 PHP）+ 0 代码 | ≈0.3–1 µs | 零扩展代码，但引入运行时 FFI 面 |
| TypePHP 原生 stub（AOT 场景） | 1 人日 | ≈0 | 业务编译成 C++ 后直接 `extern "C"` 调，**这是 T1.5 的目标形态** |

前两条的额外交互开销相对 0.87 µs 的 wasm 边界都是小项，**不构成架构决策的分水岭**；真正的分水岭是内存（§2.3）和 IPC（§2.4）。

> 本机原生 AOT 构建已验证可用（`Initialized platform/backend: Linux + GCC`），但 AOT 模式不支持 `echo` 这类语句，需要一个符合 TypePHP 入口约定的工程才能跑通完整 stub 流程。这一步留到 E1 落地时做，风险已很低——C ABI 已证明可从 C 直接调用。

---

## 四、对 E1 部署形态的最终建议

```
每台游戏服节点：
  1 个 worker 进程组（Swoole，N 个 worker）
     └─ 每个 worker 内嵌 libtp_host.so（+50 MB 底噪）
          └─ wasm 实例按需创建（+4 MB/实例），Engine 进程内共享
  静态产物：s1-probe.cwasm 之类的预编译模块（42 MB/个）
```

三条硬约束：

1. **每进程 50 MB 底噪是必须接受的**，靠减少 worker 数或减少模块数来控总量。若单机 8 worker，仅 wasm 就吃掉 400 MB，这在容量规划里必须提前算。
2. **实例创建 2.6 ms**，所以实例应当按"房间/场景"粒度复用，而不是每次调用新建；S3 已证明 trap 后重建就是这个成本，仍在可接受范围。
3. **`.cwasm` 与 wasmtime 版本绑定**，升级必须走"重编译全部模块 → 灰度"的流程，不能只换库。

---

## 五、复现命令

```bash
cd /data/TypeServer/spike/s2

# 1) 编译 C ABI 库（约 5 分钟）
export PATH=/home/hhr/.cargo/bin:$PATH
cd host-capi && cargo build --release && cd ..

# 2) 动态加载 + 全量基准
./bench-dl ./host-capi/target/release/libtp_host.so /data/TypeServer/spike/s1/s1-probe.wasm

# 3) 静态链接体积
gcc -O2 -ffunction-sections -fdata-sections bench_static.c \
    host-capi/target/release/libtp_host.a -lpthread -ldl -lm \
    -Wl,--gc-sections -o bench-static && strip bench-static

# 4) 符号冲突检查
./symcheck.sh

# 5) .cwasm 路径（首次用 .wasm 跑一遍会自动生成 s1-probe.wasm.cwasm）
./bench-dl ./host-capi/target/release/libtp_host.so /data/TypeServer/spike/s1/s1-probe.wasm.cwasm

# 6) 跨进程内存共享
./share.sh

# 7) sidecar 对照
gcc -O2 ipc.c -o ipc && ./ipc 200000
```

---

## 六、S2 判定

**通过。** 三个数字决定了架构形状：

1. **20.4 MB / 0.5 ms / 0 符号冲突** —— 嵌入本身不构成障碍，可以放心做成进程内常驻库；
2. **50 MB / 进程（`.cwasm`）vs 344 MB（JIT）** —— 产物形态不是优化项而是必需项，这一步做错，单机内存直接失控；
3. **41 µs（sidecar）vs 0.87 µs（进程内）** —— tick 热路径没有第二种选择。

至此 S1（可行性）、S3（资源治理）、S2（嵌入成本）三关全部通过，E1 的技术不确定性已经清空，剩下的都是工程活：原生 stub 接入、`.cwasm` 发版流水线、实例生命周期管理。
