# TSF — TypePHP Server Framework

用 TypePHP（AOT 编译）+ PHP 写游戏服务器，替代「C++ + Lua」。
**当前版本 v0.6.0**：M0–M6 已交付，M7（wasm 热更）未做。

一句话说清它换来什么：**性能接近原生（单 worker 稳态 116 万 msg/s），但保留 PHP 的开发效率**；
代价是 AOT 之后改不了运行中的代码，所以热更必须靠"配置热更 + wasm 模块 + 滚动重启"三条轨道替代 Lua。

---

## 30 秒上手

前置：PHP 8.5 **ZTS**、`libphpx`、`typephp 0.9.6`（+ 两个本地补丁，见 `tpc-patches/`）。
这套工具链目前只在 WSL/Linux 上验证过。

```bash
# 1) 生成工程
tsf new my-server

# 2) 编译并启动
cd my-server && tsf serve .

# 3) 另开终端验证
php85 client.php 9001        # resp msgId=1002 body=hello-tsf-...
```

开发时用 `tsf watch .`（改一行 1.4~1.9 s 看到效果）；
提交前跑 `tsf lint`（禁 `mixed`、命名空间与目录一致，违反返回非 0，可挂 CI）。

跑一个有内容的例子（登录 → 匹配 → 对战 → 结算 → 落库 + 发金币）：

```bash
cd demo/duel
./duel migrate     # 建表（部署步骤，跑一次）
./run.sh           # 两个客户端真打一局
```

---

## 目录结构

```
tsf/                 框架本体（src 核心 + pkg 可选包 + bin/tsf CLI + tools）
  src/               15 个核心模块：Transport/Gateway/Protocol/Service/Rpc/Cluster/
                     Discovery/Shard/Breaker/Metrics/Gm/Config/Kernel/Timer/Proto
  pkg/               9 个可选包：fsm entity frameloop room aoi match db snapshot eventlog
  ext/codec/         原生帧编解码（C++，可关）
  tools/             config 导出、proto 生成（不进 AOT）
demo/                duel（完整对局示例，含多进程 run-cluster.sh）、demo-echo（最小 echo）
bench/               58 个目录：功能断言、性能基准、一次性技术探针（probe-*）
tpc-patches/         对 typephp 编译器的本地补丁（AOT 下必须）
tools/repo-guard.py  提交前检查：抓「被 .gitignore 误挡的源码」
spike/               一次性技术验证（S1~S6、E1/E2/E4），只保留结论文档入仓库
```

---

## 文档在哪

| 想知道 | 看 |
|:--|:--|
| **框架怎么用、为什么这么设计** | `tsf/README.md`（最全，约 4,000 行，按主题分节） |
| **每个任务做了什么、验收数字** | `FRAMEWORK-TASK.md` |
| **这一版有什么、边界在哪** | `CHANGELOG.md` |
| **架构审计结论与遗留问题** | `tsf/AUDIT-2026-11-15.md` |
| **AOT 为什么这么写** | `tsf/README.md` §5（编码约束）+ `ARCHITECTURE-DESIGN.md` |
| **编译器的补丁与升级须知** | `tpc-patches/README.md` |
| **想跑多进程/多节点（房间归属）** | `DESIGN-ROOM-OWNERSHIP.md`（设计稿，未实现） |
| **想做 wasm 热更（M7）** | `DESIGN-WASM-HOTRELOAD.md`（设计稿，未实现） |

---

## 开始用之前请先读这几条

这些不是"待优化"，是**刻意的边界**，踩上去不会报错只会静默出错：

1. **事件日志每个文件只支持一个写入进程。** 两个进程写同一份日志，`seq` 必然冲突
   （实测 120 条记录只有 61 个不同序号）。所以多进程时**按节点分文件**：
   `var/settle.n<节点号>.log`，读侧归并（`MergedLog`）。
   `demo/duel` 里同一个进程内开多 `Swoole worker` 会直接拒绝——多开请起多个**进程**
   （各配不同的 `server.node_id` 与端口，见 `demo/duel/run-cluster.sh`）。
2. **幂等键一旦取自内存计数器，就必须从持久化真相里恢复。** 房号只在内存里从 1 数起，
   复用就会让新一局被判"已结过账"——钱静默不发，日志也不多一行。
3. **服务抛异常 = 该服务被销毁**（`Scheduler` 记 error 后 `kill`）。所以"钱"这条路径上
   抛异常意味着玩家掉线 + 本局不结算，这是刻意选的"钱优先"。
4. **迁移是部署步骤**，不是启动步骤：`tsf migrate` 跑一次，服务启动只校验。
5. **改配置要重启进程**，没有文件热重载（配置表有独立的热更机制，那是另一套）。

完整的"能做什么/不能做什么"见 `CHANGELOG.md` 的「已知边界」。
