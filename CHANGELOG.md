# 变更记录

## v0.6.0 — 2026-11-16（首次封版）

首个可交付版本。对应里程碑 M0–M6 全部交付，M7（wasm 热更）未做。
细节与验收依据见 `FRAMEWORK-TASK.md`；框架原理与实测过程见 `tsf/README.md`。

### 交付内容

**M0 骨架**
- 分层配置（`tsf.json` → `tsf.{env}.json` → `tsf.local.json`，点号键；加载时扁平化）
- 结构化日志（JSON Lines，error 额外写 stderr）
- `tsf` CLI：`new` / `build` / `serve` / `watch` / `migrate` / `migrate-status` / `lint` / `list` / `add` / `remove`
- 可选功能包机制：`tsf/pkg/<name>/` + `pkg.json`（ns/desc/requires），构建时展开依赖闭包。已拆出
  **fsm / entity / frameloop / room / aoi / match / db / snapshot / eventlog** 九个包

**M1 网络**
- TCP + 帧编解码双实现（`header` 纯 PHP / `native` C++ 扩展），运行期切换，配对实测 **+13.9%**
- 会话与鉴权：uid ↔ fd 双向索引、顶号、token 表 + HMAC
- 心跳空闲扫描（阈值 3 s 实测 4.6 s 断开）、每连接令牌桶限流（**74 ns/帧**）、背压断慢客户端
- WebSocket（复用同一套二进制帧）
- 连接规模 **8,500 连接零丢失**（68,005/68,005）；2 万连接 119,999/119,999、RSS 144 MB
- 容量守卫：按 `max_conn` 自动抬高 `memory_limit`、启动时校验 `RLIMIT_NOFILE` 并给出可行的修复写法

**M2 并发**
- `Service` / `Scheduler` / `Mailbox`：稳态 **1,161,289 msg/s**（每批 64 条），p99 20 µs
- Actor 复用 Service（不引入第二套概念）：1 万服务 spawn 1.5 µs/个
- 定时器：4096 槽时间轮 + 远端堆，10 万量级**误差 p50/p99/max 全为 0 ms**；cron 分钟粒度
- 跨进程消息：UDS + 攒批，2000 次往返 **p99 285 µs**
- 慢消息：**改判** —— `Coroutine::cancel()` 打不断运行中的 CPU 循环（实测返回 false），
  所以做成"检测 + 告警 + 可选销毁"（`setWatchdog`），开启代价 −13.8%

**M3 玩法**
- `pkg/fsm` 状态机（`fire()` 207 ns）、`pkg/room`（成员表/席位，零坐标）、
  `pkg/entity`（EntitySet，**258 ns/实体/帧**）、`pkg/frameloop`（真 delta，上限 200 ms）
- 房间分派两种模式：一房一 Service / 多房共用 RoomHost（1000 房定时器降 200 倍）
- `pkg/aoi` 九宫格：**不存视野表**（存储为 0），格内移动 322 ns / 跨格 21.5 µs
- `pkg/match`：1000 人单轮 2.4 ms；**配对率由评分密度决定**（验收线已改绑密度）
- `demo/duel`：登录 → 匹配 → 对战 → 结算，全走真实 TCP

**M4 数据**
- `pkg/db`：Attribute 映射（`#[Table]/#[Id]/#[Col]`）+ 懒连接 + 分片（`Source` 抽象让分片与单库
  调用代码完全一致）；主键读 **~4 µs**
- 迁移：SQL 文件 + 方言变体 + 记录表存库 + checksum 检测。**已命令化**：`tsf migrate` 部署跑一次，
  服务启动只 `verify()`
- `pkg/snapshot`：带版本 JSON + 原子写；200 实体恢复 **0.40 ms**，`kill -9` 后房间继续跑
- `pkg/eventlog`：追加写、两档持久性（FFLUSH 1.2 µs/条 / SYNC 4.3 ms/条）、幂等重放、截断修复；
  **1,000 笔 kill -9 零丢失、零坏行**

**M5 分布式**
- RPC：跨进程串行 **p99 377 µs**；协议分层（框架定消息头，载荷交给 `RpcCodec`）；
  **故意不做自动重试**（幂等性只有业务知道）；**不做** = 协议号收敛到 2 个
- 服务发现：`Contract\Registry` + `FileRegistry`（心跳用内容指纹，**不用 mtime**），
  `kill -9` 后 **3,016 ms 收敛**
- 分片环：一致性哈希 + 虚拟节点，**扩容 3→4 迁移 24.79%**（理论 25%），零老节点间搬迁
- 跨服路由：按 uid 透明调用，本地 **58 µs** / 远端 **217 µs**，四个提前拦截（含 codec-mismatch）
- 熔断：三态 + 单探针 + 冷却翻倍；半死节点 **250 ms 摘除**（验收 5 s），摘除后 30.6 µs 拒绝
- 集群压测 3 节点：**16.9k req/s、p99 1.75 ms**；拐点约 96 在飞；**上限 ~20k req/s**

**M6 工具链**
- 配置表导出：Excel 表头即 schema，校验失败一个字节都不写，**末尾追加列免重编**；1 万行加载 63 ms
- 配置热更：盯 **crc32 清单**（不用 mtime），小表 **34 ms 生效**，坏数据被拒仍服务旧值
- 协议生成：proto3 子集 → 类 + 编解码，**字节与标准 protobuf 一致**（前端可直接用 protobuf.js）
- 指标：指数分桶（误差恒定 ≤19%），`inc` **81 ns** / `observe` **390 ns**，慢调用日志限流
- GM 通道：独立端口迷你 HTTP，空 token 拒启动、默认只绑本机、头传 token、爆破限流、审计统一记
- `tsf watch`：改一行 **1.45 s**（9 包的 demo 1.85 s）

### 封版前修的

**迁移命令化**（2026-11-15）
- `tsf migrate` / `tsf migrate-status`；服务启动只校验不建表。原实现每个 worker 各调一次 `up()`，
  记录表挡得住重复执行但**挡不住并发建表**。锁用 `mkdir`（`flock` 不在 AOT std 表面里）

**金币结算接进真实对局**（2026-11-15）
- `demo/duel` 每局给赢家发 100；幂等键用业务主键 `duel:<房间号>`，顺序是**日志先行**

**架构审计**（2026-11-15，报告在 `tsf/AUDIT-2026-11-15.md`）
- **修 P0**：事件日志多写入进程下 `seq` 必然冲突（实测 120 条记录只有 61 个不同序号）——
  应用层启动断言 `worker_num > 1` 直接拒绝
- **修 P0**：房号跨重启复用 → 新一局被判"已结过账"，**钱静默不发、日志也不多一行**——
  `Settle::maxSettledRoom()` + 房号只增不回退
- **删** `Kernel\Container`（82 行零引用，能力表却打 ✅）、**删** `Config::reloadIfChanged()`
  （零调用，且用的是秒级 mtime——项目里同一个坑踩过四次）
- 补 `demo/README.md`、`tpc-patches` 的升级回归清单（db-test / watch-test / migrate-test）

**仓库整理**（2026-11-16）
- 3.8 G → 839 个文件 / 3.3 MB：白名单 `.gitignore`，只收源码、文档、配置
- 去掉 29 个脚本里写死的 `cd /data/TypeServer/<dir>`（克隆后会操作原目录）
- 配置支持 `${base}` 占位符（`Config::expandBase()`），不再写死绝对路径
- `tools/repo-guard.py`：抓「被 gitignore 误挡的源码」（`ext/codec/cpp-src/*.cc` 就这么漏过）

### 已知边界

| 场景 | 状态 |
|:--|:--|
| 单 worker 房间类玩法、内部框架、demo | 已验证（26 个 bench、1,111 条断言） |
| 多 worker + 事件日志 | **不支持**（启动会拒绝）；多 worker 整体未验证 |
| 扣钱 / 余额对账 | **未做**：只有发奖；余额靠启动全量重放；db 战绩与事件日志之间无对账 |
| 大日志量 | 未做 checkpoint/truncate 自动化，启动时间随日志线性增长 |
| 非 Linux | 只验过 WSL/Linux，Windows/macOS 未测 |
| NTS 构建 | 工具链断（`typephp-095` 编不动当前源码），`demo-echo-nts` 是无法重建的历史产物 |
| wasm 热更（M7） | 未做 |

### 版本兼容

- 无历史包袱：v0.6.0 是首个封版版本。
- AOT 编译器锁 `typephp 0.9.6` + 两个本地补丁（见 `tpc-patches/README.md`），**升级 tpc 后需重新应用**。
- 生成的 protobuf 字节与标准 wire format 一致，前端可用 protobuf.js 直读同一份 `.proto`。
