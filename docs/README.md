# 文档索引

这个目录里混着**四类**东西：现行的使用教程、设计决策、路线图、历史材料。
它们的可靠程度不同，先看这张表再决定读什么。

## 1. 现行教程 —— `guide/`

从 `00` 顺着读。每篇都标注了验证状态，✅ 表示跑过。

| | 篇 | 讲什么 |
|:--|:--|:--|
| 00 | [30 分钟跑起来](guide/00-quickstart.md) | 装工具链 → 起第一个服务 |
| 01 | [接入层](guide/01-ingress.md) | Transport / Gateway / 协议栈 |
| 02 | [服务层](guide/02-service.md) | Service / Timer / 调度器 |
| 03 | [玩法层](guide/03-gameplay.md) | room / frameloop / aoi / match / fsm / entity |
| 04 | [数据层](guide/04-data.md) | db（仓储/迁移/分片）/ eventlog / snapshot |
| 05 | [分布式](guide/05-distributed.md) | Rpc / Discovery / Shard / Cluster / Breaker |
| 06 | [运维](guide/06-ops.md) | Metrics / Gm / script 热更 / 工具链 |
| 07 | [深度示例：一局斗地主](guide/07-walkthrough.md) | 六层拼起来的样子（对照 `demo/doudizhu`） |
| 08 | [AOT 下的类型契约](guide/08-aot-types.md) | 五类坑 + 排查方法 |
| 09 | [总装](guide/09-assembly.md) | 从零件到整机的五个"缝" |
| 10 | [登录服](guide/10-login-service.md) | HTTP 与 TCP 怎么分工 |
| 11 | [HTTP 接口](guide/11-http-api.md) | 路由 / 统一响应 / 中间件 |
| 12 | [热更](guide/12-hot-reload.md) | 三条路线实测对比 |
| 13 | [上线](guide/13-production.md) | 停机 / 日志 / TLS / 告警接线清单 |
| 14 | [工程组织](guide/14-architecture.md) | 四层规则、生长路径、两种参考实现 |

`guide/README.md` 是这张表的详细版（带行数、验证状态、常见报错对照）。

## 2. 设计决策

讲"为什么这么设计"，不是"怎么用"。

| 文档 | 内容 | 现况 |
|:--|:--|:--|
| [ARCHITECTURE-DESIGN.md](ARCHITECTURE-DESIGN.md) | 整体分层与各模块职责 | 与 `guide/14` 有重叠，14 是给使用者的精简版 |
| [DESIGN-ROOM-OWNERSHIP.md](DESIGN-ROOM-OWNERSHIP.md) | 房间归属与生命周期 | 现行 |
| [FRAMEWORK-TASK.md](FRAMEWORK-TASK.md) | 任务表（835 行），每条带实测数据 | 现行，但按任务编号组织，只适合查证 |
| [CI-RUNNER.md](CI-RUNNER.md) | 自托管 runner 的配置 | 现行 |

## 3. 路线图

| 文档 | 管什么 |
|:--|:--|
| [ROADMAP.md](ROADMAP.md) | **能不能发布**：运行闭环、安全、发布工程 |
| [ROADMAP-NO-GAP.md](ROADMAP-NO-GAP.md) | **能力面瘸不瘸腿**：哪些短板值得补 |

两份有交叉但不互相替代——各自文件开头都写了分工。

## 4. 历史材料 —— 别当现行结论读

这些是**当时**的验证记录和评审，有引用价值，但结论可能已经被后续改动推翻。

| 目录 | 是什么 | 怎么用 |
|:--|:--|:--|
| [spike/](spike/) | 8 篇一次性技术验证（wasm、快照粒度、AOT 边界…） | 只在"为什么当初否掉了某方案"时有价值 |
| [review/](review/) | `ARCHITECTURE-REVIEW-2026-11-19.md`（4698 行） | 全面自审，问题清单大部分已修，修没修要看 ROADMAP |
| [archive/](archive/) | `tsf-readme-full.md`（3873 行） | 框架 README 的历史长版本，现行内容已拆进 `guide/` |
| [research/](research/hot-reload.md) | 热更的完整实验记录 | `guide/12` 是结论，这份是过程 |

**判断方法**：历史材料里的命令、行号、文件路径大概率已经变了。
拿不准就看 `guide/` 或直接跑一遍——这个项目的坑集中在"AOT 和 PHP 语义不同"，
看代码看不出来，只有跑过才知道。

## 维护约定

- `guide/` 的每篇开头标验证状态，改了框架要回来重跑并更新那一行
- 新增文档先想清楚属于上面哪一类，不要往 `guide/` 里塞设计讨论
- `scripts/check-doc-links.sh` 检查相对链接，改完跑一次
