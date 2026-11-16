# TypeServer 实施包（P0 / P1 / P2）

> 版本：2026-11-12
> 适用：把"wasm 热更通道 + 房间分级与监督"接进实际服务
> 前置：本文档包只包含**已完成并有实测依据**的部分；P3（快照+事件日志）与 P4（激活回收）尚未完成，不在包内。

---

## 一、这个包解决什么问题

| 问题 | 不做的后果 | 本包提供 |
|:--|:--|:--|
| wasm 里的死循环会挂死整个游戏进程 | 一个脚本 bug 全服掉线 | 预算治理（epoch/fuel）：**30 ms 内切断**，进程存活 |
| 配置漂移导致预算静默失效 | 平时正常，关键时刻失效 | `.cwasm.manifest` 启动校验：**不匹配直接拒绝启动** |
| 房间崩溃连带整个 worker | 几千玩家一起掉线 | 房间独立进程：**崩溃半径 = 1 房**，4–12 ms 重生 |
| 启动即崩的房间无限重启 | 250 次/秒刷 CPU 与日志 | 重启强度监督：**退避阶梯 + 第 6 次隔离** |
| 退避期间其他房间崩了没人管 | 雪崩 | 非阻塞退避：旁观房间重生 **358 ms → 5.6 ms** |
| 监督器重启后隔离失效 | 隔离形同虚设 | 状态持久化：`supervisor.state` |

---

## 二、目录结构

```
implementation-kit/
├── README.md            本文件：总览与快速验证
├── INTEGRATION.md       分步集成指南（照着做）
├── API.md               接口速查（C ABI / PHP stub / manifest / 监督参数）
├── CHECKLIST.md         上线检查清单（逐项打勾）
├── build.sh             构建示例（PHP AOT + 宿主桥接）
├── run.sh               运行示例
├── sync.sh              从 spike 目录同步源码（改完 spike 后跑）
├── src/
│   ├── wasm-host/       Rust 宿主库 + 预编译工具 + C++ 桥接 + PHP stub
│   ├── wasm-module/     wasm 模块示例（v1 / v2 两个版本）
│   └── supervisor/      房间分级 + 重启监督
├── lib/                 libtp_host.so（构建时复制进来）
└── build/               kit_demo 可执行文件与生成的 project.yml
```

**约定**：`src/` 是 spike 目录的镜像，**spike 是源**。改了实现后跑 `./sync.sh` 重新镜像。

---

## 三、5 分钟验证

```bash
cd implementation-kit
./build.sh          # 编译示例（复用已有宿主库，约 40 秒）
./run.sh            # 运行，应看到下面的输出
```

期望输出（关键行）：

```
load main(v1): handle=0 耗时 64 ms
  main.add(1,2) = 3
load canary(v2): handle=1 耗时 60 ms
  canary.add(1,2) = 1003          <- 两个模块行为不同，说明多模块生效
存活模块数: 2
RSS(after 2 modules): 162 MB
wasm_swap 耗时: 4390 us           <- 灰度切换
切换后 main.add(1,2) = 1003       <- 切换后行为改变
burn(2e9) 返回值: -3000           <- 死循环被切断
重建后 canary.add(1,2) = 1003     <- trap 后自动重建
main  : calls=400003 traps=0 rebuilds=1
canary: calls=3      traps=1 rebuilds=1
```

这五条全部对上，说明宿主、预算、切换、恢复、观测整条链路在本机可用。

---

## 四、关键数字（全部实测，供排期与容量估算）

| 指标 | 值 | 说明 |
|:--|:--:|:--|
| wasm 单次调用 | **0.78–1.1 µs** | 边界成本主导；索引绑定只省 2–30 ns |
| 模块加载（`.cwasm`） | **58–86 ms** | 首次含 Engine 创建；同路径第二次不重复反序列化 |
| 单模块内存 | **≈55 MB** | 2 模块共 162 MB（含 42 MB 基础） |
| 死循环切断 | **≈30 ms** | ticks=3 × 10 ms 心跳 |
| trap 后重建 | **3.6–5 ms** | 丢弃实例 + 同路径重建 |
| 灰度切换 | **3.7–5.5 ms** | 新实例就绪后原子换句柄 |
| 房间进程重生 | **5.6–11.9 ms** | 非阻塞监督 |
| 退避阶梯 | **0 / 0 / 100 / 200 / 400 ms** | 第 6 次隔离 |
| 旁观房间受拖累 | **5.6 ms**（旧实现 358 ms） | 非阻塞退避 |
| 监督器自身 CPU | **0.66%** 单核 | 自适应轮询 |

**排期参考**：P0（本包已含）2 人日；P1 8–10 人日；P2 8–10 人日。

---

## 五、三条不能省的纪律

1. **`.cwasm` 必须按环境重新预编译**，不能跨版本分发。fuel/epoch 是编译期插桩，产物与 wasmtime 版本、配置强绑定——manifest 会在启动时校验，不匹配直接拒绝启动。
2. **`ticks` 不能设 1**。`set_epoch_deadline(1)` 的语义是"跨过下一个心跳就 trap"，任何正常调用都可能被误杀（实测 20 万次约误杀 16 次）。用 3（≈20–30 ms）。
3. **调用路径上不要出现字符串**。按模块名查表要做 `Str→std::string` 转换，实测把 0.78 µs 推到 1.10 µs。用整数句柄。

---

## 六、不在本包范围内

| 项 | 原因 |
|:--|:--|
| 快照与事件日志（P3） | 需要业务状态结构才能落地，详见 `IMPLEMENTATION-PLAN.md` |
| 激活/回收（P4） | 依赖 P3 的快照能力 |
| 按比例灰度分流 | 当前是"全量换句柄"，分流策略需业务定 |
| 告警接入 | 现在写 `alarms.log` 与 stdout，需接采集器（filebeat/vector → 企微/邮件） |
| Agones 接入 | 房间监督已可用，编排层对接尚未做 |

---

## 六点五、当前状态（2026-11-16 补记）

本包是 **2026-11-12 的交付快照**。之后框架本体独立演进，对照如下：

| 本包内容 | 现状 |
|:--|:--|
| `src/wasm-module/` | ✅ 已转化为 `tsf/pkg/wasm`（但走的是 spike 的简化实现，不是这里的引擎层） |
| `src/wasm-host/` | ⏳ **待转化**：`FRAMEWORK-TASK` §九 计划让它成为 `typephp/wasm` 的引擎层 |
| `src/supervisor/` | ⏳ **待转化**：计划成为独立的 supervisor 包 |
| `lib/libtp_host.so` | ❌ **已删除**：11-12 编译、对应 wasmtime 47；现在的宿主是 **wasmtime 50**（WASI 0.2.12 必需）。拿这个库去集成，`instantiate` 必然失败 |

删 `lib/` 的理由很具体：**留着比删掉更危险**。它是唯一一个"看起来能用、实际肯定不能用"的
文件——19MB 的 .so 摆在那儿，下一个人会先试它，然后花半天排查一个版本不匹配的错误。
真正可用的产物在 `spike/s2/host-capi/target/release/`（`tp-precompile` + `libtp_host.so`）。

---

## 七、相关文档

| 文档 | 内容 |
|:--|:--|
| `../FRAMEWORK-TASK.md` | 权威任务表与架构决策记录（**取代**了原先的 `IMPLEMENTATION-PLAN.md` 与 `ARCHITECTURE-EVOLUTION.md`，那两份已移除，原文在 git 历史里） |
| `../spike/s1/SPIKE-S1.md`、`SPIKE-S3.md` | wasm 可行性与资源治理的原始实验 |
| `../spike/s2/SPIKE-S2.md` | 嵌进进程的成本（体积/加载/内存/符号） |
| `../spike/s4/SPIKE-S4.md` | 房间独立进程与崩溃半径 |
| `../spike/e1/SPIKE-E1.md` | PHP 接入、预算治理、发版流水线、多模块与灰度 |
| `../spike/e4/SPIKE-E4.md` | 房间分级、重启监督、非阻塞退避、状态持久化 |
