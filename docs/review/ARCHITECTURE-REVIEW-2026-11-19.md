# TSF 架构评审：与成熟游戏服务端架构的对比

> 评审对象：`D:\hhr\TypeServer`（tsf 子模块 `a84f10f`，`tsf 0.10.0`）  
> 评审日期：2026-11-19  
> 评审方式：源码通读 + 在 `tsf:latest` 容器与 WSL 副本上实跑测试与基准  
> 原则：本文所有"实测"数字均可复现（命令见 §11）；所有"评估"与"外部对标"明确标注为判断

---

## 一、一句话结论

TSF 是**把 Skynet 的 Actor 模型用 PHP + AOT 重新实现了一遍**，并且在"钱"这条路径上的工程纪律明显高于同体量的自研框架（幂等、重放、对账都有测试兜底）。  
它的定位不是"通用游戏服务端平台"，而是**单机、单玩法、强正确性优先的房间/活动服框架**。  
当前最大的三个约束是：**集群只支持单机（Unix socket）**、**没有代码级热更**、**工具链单人维护且预发布**。

---

## 二、实测记录

### 2.1 环境

| 项      | 值                                                              |
| :----- | :------------------------------------------------------------- |
| 容器镜像   | `tsf:latest`（313MB，PHP 8.5.12 ZTS + swoole 6.2.5 + tpc + phpx） |
| 宿主机    | Windows + WSL2 (Ubuntu)，4 核                                    |
| WSL 副本 | `/data/TypeServer`，`tsf doctor` 全部通过                           |

### 2.2 测试套件结果

| 套件           | 内容        |          结果          | 备注                                                   |
| :----------- | :-------- | :------------------: | :--------------------------------------------------- |
| settle       | 金币账本      |    **91 / 91 通过**    | 幂等、重放、对账、跨节点归并                                       |
| settle-crash | 账本崩溃重放    |    **14 / 14 通过**    | 20 万事件、3 轮 kill -9，`dup=0 torn=0`                    |
| script       | 脚本热更      |    **16 / 16 通过**    | 含越权写只读字段、脚本异常隔离                                      |
| http         | HTTP 接口   |     **8 / 8 通过**     | 路由/中间件/CORS/鉴权/限流                                    |
| swoole       | Swoole 契约 |     **6 / 6 通过**     | 1MB 帧、慢客户端、shutdown 末帧                               |
| fd           | fd/内存守卫   |    **10 / 10 通过**    | `/proc/self/limits` 解析与真实值一致                         |
| semantics    | AOT 语义差分  | **实质通过（41/45 逐字一致）** | 4 项差异全部是 `known-diff.txt` 已登记项；容器缺 `python3` 时判 FAIL |
| cluster      | 集群/房间归属   |    **11 / 11 通过**    | 修复 D3 后复测                                            |
| drain        | 进程退役      |     **8 / 8 通过**     | 修复 D3 后复测                                            |
| rejoin       | 断线重连      |     **6 / 6 通过**     | 修复 D3 后复测                                            |
| room         | 房间/成员/共享宿主 |   **100 / 100 通过**   | 用例按新契约重写后纳入 verify（§12 追加）                            |

> 结论：**核心正确性套件（钱、热更、HTTP、Swoole 契约、fd 守卫）真实可跑且全绿**。  
> 修复 D1–D3 之后，`tsf verify` 在 WSL 副本上 **11 套件全通过、0 失败 0 跳过**（§12）。  
> 修复前 README 声称的"9 套件全绿"不成立，根因是示例与框架的 API 漂移（D3）。

### 2.3 性能实测（WSL，4 核，本机回环）

| 场景                  | 结果                                         | 说明                              |
| :------------------ | :----------------------------------------- | :------------------------------ |
| 进程内调度（深队列）          | **1,149,160 msg/s**（870 ns/条）              | `bench/service`，单服务投递+派发        |
| 进程内调度（浅队列 256）      | **1,340,985 msg/s**（746 ns/条）              | 批处理摊薄调度开销                       |
| 派发延迟 p99（批 8）       | **9,002 ns**                               | 达标（阈值 100 µs）                   |
| 1 万服务的 spawn        | **7.9 ms**（0.8 µs/个）                       | 无 O(n²) 退化                      |
| 网络端 1 worker @1 连接  | 3,526 msg/s，p50 0.24 ms                    | `demo-echo` + C 压测端，closed-loop |
| 网络端 1 worker @32 连接 | **34,371 msg/s**，p50 0.78 ms / p99 2.46 ms | ≈ 29 µs/消息                      |
| 网络端 4 worker @64 连接 | **58,484 msg/s**，p99 4.45 ms               | 4 核，压测端与服务端抢 CPU                |
| 增量编译                | 101 文件 **17.6 s**（4 并发）                    | 缓存命中；冷构建另计                      |

**关键比值**：进程内调度 115 万/s，网络端 3.4 万/s，**相差 34 倍**。  
这直接说明——**瓶颈不在 Actor 调度，而在每帧的网络路径**（syscall + Swoole 事件分发 + PHP 闭包调用 + 帧编解码）。这决定了优化方向，也说明 AOT 的收益上限受 I/O 侧限制。

---

## 三、架构分层与代码规模

### 3.1 分层（与 `docs/ARCHITECTURE-DESIGN.md` 一致，已核对代码）

```
L3 热更脚本层   运行时 include 返回 Closure（pkg/script）        ← 解释执行，秒级生效
L2 业务逻辑层   AOT 编译的普通 PHP 类（Zend Object）
L1 原生核心层   #[Native] + std 容器（ext/codec，可关）
L0 接入层       Swoole（TCP/WS/协程），AOT 只编胶水
```

### 3.2 代码规模（`tsf/` 本体，实测）

| 模块                                  |      文件 |         行数 | 职责                                                  |
| :---------------------------------- | ------: | ---------: | :-------------------------------------------------- |
| `src/Service`                       |       4 |      1,020 | Scheduler / Mailbox / Service / Message —— **框架心脏** |
| `src/Gm`                            |       5 |        873 | GM 指令服务                                             |
| `src/Rpc`                           |       8 |        786 | 异步 RPC 客户端 + 路由 + 编解码                               |
| `src/Gateway`                       |       5 |        748 | 会话、鉴权、限流、心跳、背压                                      |
| `src/Shard`                         |       2 |        728 | 一致性哈希环 + 跨服路由（含熔断/在飞限流）                             |
| `src/Timer`                         |       4 |        701 | 时间堆 + cron                                          |
| `src/Transport`                     |       4 |        687 | 抽象 Server + TCP/WS                                  |
| `src/Protocol`                      |      13 |        663 | 帧/载荷编解码（raw/json/msgpack/protobuf）                  |
| `src/Kernel`                        |       5 |        619 | 配置、日志、Bootstrap                                     |
| `src/Discovery`                     |       4 |        618 | 文件注册表 + 心跳                                          |
| `src/Metrics`                       |       4 |        612 | 直方图 / 快照 / 日志上报                                     |
| `src/Cluster`                       |       4 |        520 | 跨进程通道（**Unix socket**）                              |
| `src/Config`                        |       2 |        505 | 配置表热更                                               |
| `src/Proto`                         |       4 |        457 | 消息定义 / wire format                                  |
| `src/Breaker`                       |       1 |        292 | 熔断器                                                 |
| **`src` 小计**                        |      68 |  **9,829** |                                                     |
| `pkg/db`                            |       9 |      1,717 | DB / Repo / Migrator / 分片                           |
| `pkg/room`                          |       8 |      1,429 | 房间 / 成员表 / 三阶段 / 共享宿主                               |
| `pkg/aoi`                           |       2 |        613 | 九宫格 AOI                                             |
| `pkg/snapshot`                      |       6 |        641 | 快照与恢复                                               |
| `pkg/http`                          |       8 |        636 | HTTP 路由 + 中间件                                       |
| `pkg/redis`                         |       1 |        531 | Redis 客户端                                           |
| `pkg/schedule`                      |       2 |        527 | 定时调度                                                |
| `pkg/eventlog`                      |       2 |        493 | 事件日志（seq + 归并）                                      |
| `pkg/script`                        |       3 |        430 | 热更脚本沙箱                                              |
| 其余（fsm/match/mail/entity/frameloop） |       8 |      1,143 |                                                     |
| **`pkg` 小计**                        |      49 |  **8,150** |                                                     |
| `tools`（proto/config 生成）            |       6 |      2,536 | 不进 AOT                                              |
| **合计**                              | **155** | **20,540** |                                                     |

### 3.3 依赖方向（硬约束，已核对）

`tsf/src` 不 `use` 任何 `tsf/pkg` —— 由 `tsf lint` 规则 4 强制。  
这条约束比文档承诺可靠得多：**它保证"房间/AOI/存档"真的是可选能力**，而不是被核心偷偷依赖。在同类自研框架里很少见。

---

## 四、运行模型（实际代码）

### 4.1 调度：单进程 Actor + 就绪队列

```
业务代码 ──deliver()──> 目标 Mailbox ──tick()──> Service::onMessage()
```

- 地址是 `(nodeId, localId)` 打包的整数；`deliver()` 用一次位运算决定走本地信箱还是 Cluster。
- 就绪队列 FIFO，每服务每轮预算 200 条（防饿死），撞预算则排到队尾。
- 定时器到期**不直接回调**，而是投一条消息进信箱——保证服务执行路径唯一，不存在重入。
- 服务抛异常 = **销毁该服务**（`onCrashed`），受监督服务按 `maxRestarts / withinMs` 重启，超限放弃。这是刻意的"钱优先"选择。

### 4.2 与 OTP 的对应关系（作者自己列过，我核对后确认成立）

| OTP                    | TSF                              | 成立度              |
| :--------------------- | :------------------------------- | :--------------- |
| `gen_server` + mailbox | Service + Mailbox                | ✅                |
| pid 位置透明               | `Addr::pack(node, id)`           | ✅（但 node 目前只能同机） |
| supervisor + 重启强度      | `Scheduler::supervise()`         | ✅ 语义一致，**状态不保留** |
| `code_change/3` 状态迁移   | 文档给出 `PlayerState::migrate()` 方案 | ⚠️ 方案有了，未见实现     |
| per-process heap       | 无（worker 内共享堆）                   | ❌                |
| 抢占式调度                  | 无（协作式，只能事后记账）                    | ❌                |
| 两版本模块共存                | 无（PHP 类表只增不减）                    | ❌                |

**判断**：这不是"PHP 版 Erlang"，而是"PHP 版 Skynet"。差异在失效模型——Skynet 服务崩了也不重启，靠上层；TSF 多了 supervisor，比 Skynet 更接近 OTP。

### 4.3 集群：最强的能力，也是最大的天花板

`tsf/src/Cluster/Cluster.php` 只实现了 **Unix domain socket**：

```php
stream_socket_server("unix://" . $this->path, ...);   // :70
stream_socket_client("unix://" . $path, ...);         // :82
```

配套的服务发现是**本地文件注册表**（`{node}.json` + `{node}.beat`，靠 `scandir` 轮询）。

**这意味着**：`node_id` 1..255 的地址空间是为多机设计的，但**当前实现只能跑在同一台机器上**。  
`Shard\Router`、`CircuitBreaker`、`Discovery` 这一整套跨服基础设施，在单机上跑得通，跨机跑不通——换传输层（TCP + 握手鉴权 + 序列化协商）是必须补的一课。

---

## 五、与成熟架构的横向对比

### 5.1 定位对照

| 架构               | 语言/形态           | 并发模型                   | 分布式范围               | 热更              | 定位             |
| :--------------- | :-------------- | :--------------------- | :------------------ | :-------------- | :------------- |
| **TSF**          | PHP → AOT 原生    | Actor（单进程协作式）          | **单机多进程**           | 闭包脚本 + 滚动重启     | 自研房间/活动服框架     |
| **Skynet**       | C + Lua         | Actor（多线程 worker + 服务） | 多机（harbor）          | Lua 模块热替换       | 通用游戏服务端底座      |
| **Erlang/OTP**   | Erlang/BEAM     | 抢占式 Actor + 每进程堆       | 多机（分布式节点）           | 真·两版本代码共存       | 电信级高可用系统       |
| **Pomelo**       | Node.js         | 单线程事件循环 + 多进程          | 多机（前后端分离 + RPC）     | 进程重启            | 中重度 MMO/社交     |
| **Nakama**       | Go              | goroutine              | 无状态水平扩展 + PG        | 滚动发布            | 通用后端（社交/匹配/存储） |
| **KBEngine**     | C++ + Python    | Entity 分层（base/cell）   | 多机（cellapp/baseapp） | Python 热更       | 传统 MMO         |
| **传统 C++ + Lua** | C++ 内核 + Lua 脚本 | 单线程逻辑 + 多线程 IO         | 分区分服                | **Lua 热更（最成熟）** | 商业 MMO/页游      |

### 5.2 九维度打分（10 分制，判断值）

| 维度       |   TSF   | Skynet | OTP | Pomelo | Nakama | C++/Lua | 说明                               |
| :------- | :-----: | :----: | :-: | :----: | :----: | :-----: | :------------------------------- |
| 单机调度性能   |    7    |   10   |  7  |    4   |    7   |    10   | TSF 115 万 msg/s 进程内，但网络端仅 3.4 万  |
| 网络端吞吐    |    5    |    9   |  8  |    4   |    7   |    9    | PHP 每帧固定开销 ~29 µs                |
| 分布式能力    |  **3**  |    8   |  10 |    8   |    8   |    8    | **Unix socket + 文件注册表**          |
| 热更能力     |  **4**  |    7   |  10 |    3   |    3   |    8    | 只有闭包 + 滚动重启                      |
| 失效隔离     |    6    |    5   |  10 |    4   |    6   |    6    | 有 supervisor，但无 per-process heap |
| 数据正确性工具  |  **8**  |    3   |  6  |    3   |    6   |    7    | 幂等 + 重放 + 对账有测试                  |
| 可观测性     |    4    |    6   |  10 |    6   |    7   |    6    | 指标只打日志，无 Prometheus              |
| 生态与人才    |  **2**  |    6   |  6  |    5   |    7   |    9    | PHP 服务端 + TypePHP 编译器            |
| 上手成本     |    5    |    4   |  3  |    6   |    8   |    3    | 需要 AOT 子集纪律                      |
| **加权体感** | **4.7** |   6.4  | 7.9 |   5.3  |   6.4  |   7.6   |                                  |

> 打分依据：单机性能来自本次实测；分布式/热更/生态来自源码事实与公开资料；  
> 外部框架的吞吐量级为**公开基准与经验值，非本次实测**，仅用于量级参考。

### 5.3 三个最能说明差距的地方

**① 位置透明有了，跨机没有。**  
TSF 的 `Addr::pack(nodeId, id)` + `deliver()` 位运算，是 OTP/Skynet 那套"业务不关心目标在哪"的正确抽象。  
Skynet 用 `harbor` + TCP 把这条路走完；TSF 停在 Unix socket。  
**抽象对了，实现没走完**——这是"好的架构设计 + 未完成的工程"的典型状态。

**② 热更：文档诚实，代价真实。**  
`ARCHITECTURE-DESIGN.md` 明确写"进程内类替换不成立"，给出 T0（配置）/T1（闭包）/T2（滚动重启）三级方案，这一步比很多自研框架诚实。  
但对**运营期改玩法**来说，Lua 系（Skynet / C++ + Lua）能在**不丢状态**的前提下换逻辑，TSF 的 T1 只能放"几十行、无热点"的薄逻辑，T2 会丢进程内状态（靠快照 + 重连兜）。  
**对活动频繁、需要紧急修 bug 的线上游戏，这是最大的运营短板。**

**③ 钱这条路径，反而比很多成熟框架做得好。**  
`pkg/eventlog` 的 seq + 幂等 ref + 启动全量重放 + 崩溃对账，配 91 + 14 项测试（含 3 轮 `kill -9` 后 `dup=0 torn=0`）。  
Skynet 官方不带这种设施；传统 C++ 服通常有，但很少有可跑的回归测试。  
**这是 TSF 真正可以被借鉴的部分。**

---

## 六、实测发现的缺陷（按严重度）

| ID     | 缺陷                                                                                                     | 证据                                                                                                                                                                 | 影响                                                         | 修复成本                                                    |
| :----- | :----------------------------------------------------------------------------------------------------- | :----------------------------------------------------------------------------------------------------------------------------------------------------------------- | :--------------------------------------------------------- | :------------------------------------------------------ |
| **D1** | `tsf doctor` 把 phpx 路径硬编码成 `~/typephp-src/...`，忽略 `TPC` 实际位置                                           | 官方镜像里 phpx 在 `~/.tsf/toolchain/typephp/vendor/...`，doctor 判红："缺 …/typephp-src/…/libphpx.so"                                                                        | **官方 Docker 镜像首跑就报"环境不合格"**，与 README"全绿即可"矛盾               | 10 行：从 `$TPC` 推导前缀                                      |
| **D2** | 仓库无 `.gitattributes`，`core.autocrlf=true` 时所有 `.sh` 变 CRLF；`tsf` 的 shebang 也变 `#!/usr/bin/env php85\r` | Windows 克隆下 `bash run.sh` 报 `set: -: invalid option`；直接调 `tsf` 报 ENOENT                                                                                            | **Windows 克隆上 `tsf verify` 一条都跑不了**，且报错完全误导                | 加 `.gitattributes`：`*.sh text eol=lf`、`tsf text eol=lf` |
| **D3** | `demo/duel` 调用 `Room::start()` / `Room::uids()`，但 `Room` 在 `b0f6b5f` 重构时已移除这两个方法                       | 运行时 `Invalid callback TSF\Room\Room::start, class ... does not have a method "start"`；`DuelService:98,303`、`DuelManager:237`、`RoomService:128`、`RoomHost:181` 均未同步 | **cluster / drain / rejoin 三套件在 HEAD 必然失败**，旗舰示例跑不通一局      | 改调用点为 `fsm()->fire("start")` / `members()->uids()`      |
| **D4** | `tpc` 不校验"具体类上是否存在被调用的方法"                                                                              | `private Room $room;` 是完整类型标注，`$this->room->start()` 编译零告警，运行时才炸                                                                                                   | 编译通过 ≠ 能跑；AOT 的静态检查红利只兑现了一半                                | 需编译器支持类方法表校验，成本高                                        |
| **D5** | `bench/run_server.sh` 在当前 HEAD 上测出 0 msg/s                                                             | 客户端 `load_tsf` 不发 LOGIN，而 `demo-echo` 已 `auth.require=true`；且脚本不编译 `load_tsf.c`                                                                                    | **仓库自带性能脚本失效**，README 的 37k 数字无法开箱复现（我另配覆盖后测到 34.4k，与文档吻合） | 客户端加登录帧 + 脚本加 `gcc` 步骤                                  |
| **D6** | `semantics` 套件依赖 `python3`，但 `verifySuites()` 未声明 `needs`                                              | 容器缺 python3 → 判 FAIL 而非 SKIP                                                                                                                                       | 违反 README"缺工具链标 SKIP"的承诺，CI 会假红                            | 加 `"needs" => ["python3"]`                              |

**共性问题**：D1/D5/D6 都是"文档承诺 vs 脚本现实"的偏差；D2/D3 是"重构后未同步调用点"。  
`DEMO` 与 `bench` 没有进入与 `src` 同等级的回归门禁——**`tsf verify` 依赖 demo，但 demo 本身不在 CI 的编译检查里**。

> **状态（2026-11-19）**：**D1 / D2 / D3 / D5 / D6 已修复并复验**，见 §12。
> **D4 仍未处理**——它不在本仓库范围内：需要 `tpc` 增加"类方法表存在性校验"，
> 属 typephp 上游。本仓库能做的只有用运行期冒烟去兜（见 §12 末尾的取舍说明）。

---

## 七、性能定位

| 层              | TSF 实测                       | 同层参考（量级，非本次实测）                                 | 差距判断                        |
| :------------- | :--------------------------- | :--------------------------------------------- | :-------------------------- |
| Actor 调度（进程内）  | 115 万 msg/s / 870 ns         | Skynet ≈ 数百万/s；Go channel ≈ 100–300 万/s        | **同量级**，PHP AOT 在这里不落后      |
| 网络回环（每消息）      | 29 µs @ 3.4 万/s              | Skynet ≈ 5–10 µs；Go ≈ 10–15 µs；Node ≈ 30–50 µs | **落后 Skynet 3–5 倍，优于 Node** |
| 单机在线（10 Hz 心跳） | ≈ 3,400 在线/worker（按 1KB 帧折算） | C++ 服 1–3 万/进程                                 | 差 3–10 倍                    |
| 增量编译           | 101 文件 17.6 s                | —                                              | 迭代速度可用，但冷构建仍受 C++ 约束        |

**结论**：AOT 把 PHP 从"PHP-FPM 级"拉到了"接近原生逻辑层"，但**网络路径的固定开销（Swoole 回调 + PHP 闭包 + 帧编解码）无法靠 AOT 消掉**。  
对回合制、房间制、活动服（单局消息量中等）够用；对 30 Hz 状态同步的 MOBA/FPS 不够。

---

## 八、场景适配判断

### 适合

1. **单机房间类玩法**：棋牌、1v1、回合制、小局制对战（这正是 `demo/duel` 的形态）。
2. **活动服 / 跨服排行**：状态可外置、生命周期短、需要快速迭代。
3. **已有 PHP 团队**的游戏公司：把现有 PHP 业务能力直接搬到服务端，人效优势明显。
4. **强正确性场景**：金币、订单、结算——`eventlog` + 幂等 + 对账是现成资产。

### 不适合

1. **需要跨机扩展的重度 MMO**：集群只支持 Unix socket，做不了多机。
2. **要求不停服改逻辑的线上项目**：没有代码级热更，T2 丢状态。
3. **高频状态同步（30 Hz+）**：3.4 万 msg/s/worker 撑不住百人同屏。
4. **平台化中台**：缺中心化注册/配置中心/链路追踪，运维能力停留在"打日志"。
5. **团队没有 AOT 子集纪律**：`mixed`、动态调用、`switch` fallthrough 都会静默降级或报错。


## 十、总体评价

**架构层面：7/10。** 抽象是对的——地址、信箱、单写者、监督树、依赖单向、可选包边界清晰，这些是十年经验才总结得出的约束，不是拼凑出来的。  
**工程完成度：5/10。** 核心正确性做得扎实（钱路径 105 项测试全绿），但外围（Docker 自检、跨平台脚本、示例同步、压测脚本）明显落后于核心，形成"内核信得过、外壳信不过"的落差。  
**生产就绪度：4/10。** 单机能用，分布式没打通，热更不到位，工具链单人维护且预发布。

**一句话**：这是一个**设计水准高于实现完成度**的框架。它证明了"PHP + AOT 做游戏服务端"在技术上成立（115 万 msg/s 的进程内调度不是玩笑），但要成为可交付的架构，还差"跨机 + 热更 + 工程化"这三步。

---

## 十一、复现命令

### Docker（Linux 语义）

```bash
# 环境自检（会因 D1 误报）
docker run --rm -v "D:/hhr/TypeServer:/data/TypeServer" -w /data/TypeServer \
  tsf:latest php85 tsf/bin/tsf doctor

# 账本测试（D2 修复后脚本是 LF，Windows 工作区可直接挂进容器跑）
docker run --rm -v "D:/hhr/TypeServer:/data/TypeServer" -w /data/TypeServer/tests/settle-test \
  tsf:latest bash run.sh
```

### WSL（推荐，环境完整）

```bash
cd /data/TypeServer/tests/script-test     && bash run.sh   # 16 项
cd /data/TypeServer/tests/http-test       && bash run.sh   # 8 项
cd /data/TypeServer/tests/swoole-contract && bash run.sh   # 6 项
cd /data/TypeServer/tests/fd-test         && bash run.sh   # 10 项
cd /data/TypeServer/tests/settle-crash    && bash run.sh   # 14 项

# 调度基准
cd /data/TypeServer/bench/service && php85 ../../tsf/bin/tsf build . && ./service

# 网络基准（需绕开 D5：临时关鉴权 + 编译压测端）
cd /data/TypeServer/bench && gcc -O2 -o load_tsf load_tsf.c -lpthread
cd /data/TypeServer/demo/demo-echo && printf '%s' \
  '{"server":{"auth":{"require":false},"rate_per_sec":1000000,"rate_burst":1000000}}' \
  > config/tsf.bench.json
TSF_ENV=bench ./demo_echo & 
cd /data/TypeServer/bench && ./load_tsf 127.0.0.1 9001 32 4
```

> 注意：`run-cluster.sh` 固定使用 9101/9102，本机上被 `mir_login`/`mir_game` 占用时会连到错误的进程  
> （表现为 `frame: too large`）。测试前需释放端口，或用独立网络命名空间隔离。

---

## 十二、D1–D3 修复记录（2026-11-19）

### D1 · `tsf doctor` 的 phpx 路径改为从 TPC 推导

改 `tsf/bin/tsf`：不再写死 `$HOME/typephp-src/...`，改为按以下顺序解析，取第一个存在的：

1. `PHPX_LIB`（显式指定，装了非标准路径时用）
2. `dirname(dirname($tpc)) . "/vendor/swoole/phpx/lib/libphpx.so"` —— 工具链版与源码树版都成立
3. 报告时给出实际尝试的路径，而不是一个不存在的默认值

**复验**：官方镜像 `tsf:latest` 内由红转绿：

```
✓ phpx 运行时   /root/.tsf/toolchain/typephp/vendor/swoole/phpx/lib/libphpx.so
必要项全部通过（PHP 8.5.12 ZTS）
```

### D2 · 行尾规则入库

新增 `.gitattributes`（父仓库 + `tsf` 子模块各一份），只钉「必须在 Linux 上被解释执行」的两类：

```gitattributes
*.sh   text eol=lf
*.bash text eol=lf
/tsf/bin/tsf text eol=lf      # 子模块内写作 /bin/tsf
```

**为什么不用 `* text=auto eol=lf`**：本仓库 blob 已是 LF，但 `core.autocrlf=true` 会把它检出成 CRLF；  
加全局规则等于让 892 个文件同时产生行尾 diff，噪音会盖住真实改动。只钉脚本与入口，收益全覆盖、代价为零。

同时 `.gitignore` 补了 `!.gitattributes`——这个仓库用「白名单」忽略（首行是 `*`），  
不加这行新文件根本进不了库，正是它自己注释里警告的「本机有、clone 没有」。

**复验**：`git check-attr eol` 对 `.sh` 与 `bin/tsf` 返回 `lf`；重新检出的脚本首行是 `\n` 结尾；  
`bin/tsf` 的 shebang 恢复为 `#!/usr/bin/env php85` 且可执行。

### D3 · `Room` 与调用点的 API 对齐

`b0f6b5f`（状态机从 Room 外移）删掉了 `start()` / `finish()`，但**消费方全都没有同步**。  
本次按「状态机由业务注入」的既定设计对齐，而不是把旧方法加回去（加回去等于把 start/finish  
语义重新焊进 Room，正是那次重构要拆掉的东西）。

| 文件                                 | 改动                                                                                               |
| :--------------------------------- | :----------------------------------------------------------------------------------------------- |
| `tsf/pkg/room/src/Room.php`        | 补 `uids()`（纯成员表委托）；`toArray()` 带 `maxPlayers`；新增静态工厂 `fromArray(array $d, ?Machine $fsm = null)` |
| `tsf/pkg/room/src/RoomService.php` | `MSG_START` / `MSG_FINISH` 改走 `fireRoom()` → `fsm()->fire()`                                     |
| `tsf/pkg/room/src/RoomHost.php`    | `OP_START` / `OP_FINISH` 同上                                                                      |
| `demo/duel/src/DuelRoom.php`       | 新建房间改为 `ThreeStage::room()`（原来是不带状态机的裸 `Room`）；`ready()` 与 3 处结算改走 `fire()`；`fromArray()` 传入状态机  |

两个顺带修掉的*真问题：*

- ***快照丢容量****：`toArray()` 原先不带 `maxPlayers`，恢复时只能按默认 8 人重建，  
  一个 2 人房会变成"还能再进 6 个人"。现在带上并在 `fromArray()` 里读回。*
- ***静默失效****：`DuelRoom` 原先 `new Room($id, 2)` 不带状态机，`ready()` 走的是已*删除的方法；  
  就算只把调用改成 `fire()`，没有状态机也永远返回 false——所以必须同时改构造。

### 复验结果

```
PASS  semantics     15.0s      PASS  cluster     14.8s
PASS  swoole         9.3s      PASS  drain       15.5s
PASS  settle        20.6s      PASS  rejoin       8.8s
PASS  settle-crash  50.5s      PASS  script      15.2s
PASS  fd             0.0s      PASS  http        16.6s
------------------------------------------------------------
通过 10 / 失败 0 / 跳过 0
```

补充验证：`demo/duel` 的崩溃恢复（`run-crash.sh`）通过，快照日志显示恢复后 `state=2`、  
`容量=2`——说明 `maxPlayers` 的往返正确，而不是被默认值吞掉。  
`tsf lint` 通过（118 个源文件，无 `mixed`、命名空间与目录一致、核心不依赖可选包）。

### D3 续 · `room-test` 重写时暴露的三处语义缺口

`tests/room-test` 是 D3 的同源受害者：它整套写在"状态机还在 `Room` 里"的年代，
而且 `project.yml` 的源码路径指向 `bench/room-test/main.php`（该目录不存在），
既编不过也没进 `tsf verify`——所以 `Room` 重构后它烂了半年没人发现。

重写它（并补 `run.sh`）时，三处不是改名字就能过的语义缺口被迫显形：

| # | 缺口 | 现象 | 处理 |
|:--|:--|:--|:--|
| 1 | `Room::close()` 不触发状态机的 `close` 边 | `ThreeStage` 从 WAITING/PLAYING/SETTLED 都声明了 `close` 边，却没人 fire——三条边是死代码；而且"关闭"之后 `fire("start")` 仍然成功（状态机不知道房间关了） | `close()` 置标志后 `fsm->fire("close")`；重复调用早退 |
| 2 | `loadFrom()` 不读回 `closed` | `toArray()` 写了 `closed`，恢复时忽略 → 已关闭的房间恢复后又能收人（快照往返丢字段） | 补读回 |
| 3 | `RoomService::onFrame` / `RoomHost::tick` 无条件推进 | 旧版这层判断在 `Room::tick()` 里（它认状态）。状态机外移后 `Room::tick` 只管"已关闭/负 delta"，于是 WAITING 和 SETTLED 阶段也在涨帧、快照一直变脏 | 两处都改为**仅 PLAYING 推进**；但 `beginFrame()` 仍每帧调用，否则等待期间的时间会堆到开战第一帧 |

第 3 条的细节值得记：不能简单"跳过着不调 beginFrame"——`beginFrame()` 负责推进
`lastMs`，跳过会让房间从 WAITING 进入 PLAYING 的第一帧拿到整段等待的 delta
（虽被 `maxDeltaMs` 截断，但那一帧的位置推进是假的）。

同时修正了两处注释与实现不符：`DuelService::onStop` 里"close 会清空成员表"（现在不清），
以及测试用例里"SETTLED 后拒收玩家"（`Room` 不再解释状态，业务需自己看 `state()`）。

**结果**：`room-test` 100 / 100 通过，并接入 `tsf verify`（第 11 个套件）：

```
通过 11 / 失败 0 / 跳过 0
PASS  semantics  PASS  swoole    PASS  settle    PASS  settle-crash  PASS  fd
PASS  room       PASS  cluster   PASS  drain     PASS  rejoin        PASS  script  PASS  http
```

README 的验证章节同步更新为 11 套件 / 315 项断言（原文写"9 个套件"、表里只列 8 行）。

### D5 / D6 · 压测脚本与套件门禁

**D5** 的两个原因都修了，但方向选了"加配置"而不是"改压测端"：

- `run_server.sh` 增加 `gcc -O2 -o load_tsf load_tsf.c`（源文件比二进制新就重编）；
- 服务端改用 `TSF_ENV=bench` 启动，叠加新增的 `demo/demo-echo/config/tsf.bench.json`
  （`auth.require=false` + 放开限流）。

为什么不给 C 压测端补 LOGIN 帧：它的解析器是**定长假设**（每帧 `8+16` 字节、按
`FRAME` 步进），而 `LOGIN_OK` 是短包，接上去要先把定长解析改成按长度字段解析——
等于重写测量内核，风险大于收益。而且关鉴权后量到的路径与历史上 37k 那组可比
（两者都是"无鉴权、无限流"的纯 echo）。

另外把 `MSG=0` 时的 `bc` 除零改成了明确提示：原来那行
`Runtime error (func=(main), adr=24): Divide by zero` 完全看不出"一条回包都没收到"。

**复验**（1 worker，WSL 4 核）：

```
conns=1   消息=14138    3534 msg/s   p50=0.26ms p99=0.87ms  CPU=41.02 us/msg
conns=8   消息=113666  28416 msg/s   p50=0.27ms p99=0.49ms  CPU=26.74 us/msg
conns=32  消息=158635  39659 msg/s   p50=0.68ms p99=1.61ms  CPU=27.61 us/msg
```

与 `bench/REPORT.md` 的 37k msg/s / 22.96 µs 同一量级（那组是 1KB 包，这里 16B）。

**D6** 给 `semantics` 加了 `"needs" => ["python3"]`。复验：正常环境 PASS；
用 `PATH=/usr/local/bin` 模拟缺 python3 时输出 `SKIP（缺 python3）`，
不再把"环境没装"判成"代码有问题"。

### D4 · 为什么没在本仓库修

D4 的根因在 `tpc`：它对 `private Room $room;` 这种**已声明类型**的属性调用不做方法表校验，
所以 `$this->room->start()` 编译零告警。修它要改 typephp 编译器（属性类型 → 类方法表 → 报错），
属上游工程，本仓库改不动，硬改工具链只会让"本地能编、别人编不过"。

本仓库能做的替代是**运行期冒烟**：构建每个 demo 并启动 2 秒、扫日志里的
`error|Fatal`。这能兜住 D3 那类漂移（编译过、跑起来炸），代价是多一个套件和
一批 demo 的启动前置（端口、迁移、配置）。这次没有直接加，原因是它牵涉每个 demo 的
启动差异，草率加进去大概率变成"常年红、被忽略"的套件——那比没有更糟。
建议单独排期，先只覆盖 `demo/duel` + `demo/doudizhu` 两个已知可启动的。


