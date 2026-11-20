# TSF — TypePHP Server Framework

用 TypePHP（AOT 编译）+ PHP 写游戏服务器，替代「C++ + Lua」。

**当前版本 v0.7.0**：M0–M7 全部交付。

一句话说清它换来什么：**性能接近原生（单 worker 稳态 116 万 msg/s），但保留 PHP 的开发效率**。
代价是 AOT 之后改不了运行中的代码，所以热更靠「配置热更 + 内嵌 PHP 脚本（pkg/script）+ 滚动重启」三条轨道。

---

## 安装

> **需要 PHP 8.5 ZTS（线程安全版）。** 8.4 及以下 `composer require` 直接拒绝；
> NTS 版能装上，但会在**链接期**报一堆和版本无关的错误（phpx 依赖 ZTS 的线程模型）。
>
> 装完先跑 `tsf doctor` —— 它逐项检查 PHP/ZTS、swoole、phpx、tpc、php-config 版本一致性、clang，
> 缺什么直接给修复命令。

**第 1 步：框架 + `tsf` 命令（约 3.2MB）**

```bash
composer require hhr0815hhr/tsf
```

装完得到 `vendor/bin/tsf`。

> **为什么是独立仓库**：框架源码在 `hhr0815hhr/tsf`（根目录就是包，142 个文件 / 3.2MB），
> 本仓库通过 git submodule 引用它。这样 `composer require` 只装框架本身，
> 不会把 bench、tests、docs 一起拖下来。

**第 2 步：工具链（约 42MB，解压 168MB）**

```bash
curl -fsSL https://raw.githubusercontent.com/hhr0815hhr/TypeServer/main/scripts/install-toolchain.sh | bash
export TPC=~/.tsf/toolchain/typephp/bin/tpc.php
export TSF_PHP=php85
```

**第 3 步：自检**

```bash
./vendor/bin/tsf doctor
```

**为什么是两步**：tsf 不是纯 PHP 框架 —— 它调用 typephp 编译器（`tpc`）把 PHP 编译成 C++。
Composer 只能装 PHP 代码，装不了编译器本体和 C++ 后端（clang），所以工具链单独发。

> 工具链还没正式发布。在那之前，可以自己打一个：
> `scripts/package-toolchain.sh`（产物 42MB），然后用
> `TSF_TOOLCHAIN_URL=file:///path/to/pkg.tar.gz bash scripts/install-toolchain.sh` 装。

---

## 30 秒上手

环境确认：`tsf doctor` 全绿即可。它验的是 PHP 8.5 ZTS、swoole、phpx、tpc、
php-config 版本一致性、C++ 编译器。

这套工具链目前只在 WSL/Linux 上验证过。

```bash
tsf new my-server              # 1) 生成工程
cd my-server && tsf serve .    # 2) 编译并启动
php85 client.php 9001          # 3) 另开终端验证
```

开发时 `tsf watch .`（改一行 1.4~1.9 s 看到效果）；提交前 `tsf lint`
（禁 `mixed`、命名空间与目录一致，违反返回非 0，已挂 CI）。

---

## 验证：一条命令跑完 644 项断言

```bash
bash scripts/ci-preflight.sh   # 先看环境（缺什么、缺在哪、怎么补）
tsf verify                     # 18 个套件
```

```
结果    套件             内容                                耗时
PASS  semantics      AOT 语言语义差分（45 用例）                  17.5s
PASS  swoole         Swoole 契约（6 项）                       9.3s
PASS  settle         金币账本（115 项）                        23.1s
PASS  settle-crash   账本崩溃重放（14 项）                       45.9s
PASS  fd             fd/内存守卫（10 项）                       0.0s
PASS  room           房间/成员/共享宿主（100 项）                  20.6s
PASS  cluster        集群/房间归属（11 项）                      14.9s
PASS  cluster-chan   集群通道/握手（TCP 11 项 + UDS 2000 往返）   16.7s
PASS  registry       Redis 注册表+租约（38 项，无 Redis 则 SKIP） 20.9s
PASS  handoff        状态交接（120 项：含版本落后调度）                  20.9s
PASS  checkpoint     事件日志检查点（45 项：截断/等价/水位/增量/坏点被拒） 15.1s
PASS  schema         状态版本迁移链（19 项：缺 _v/拒绝降级/恢复集成）      14.6s
PASS  prom           Prometheus 导出（22 项：格式/名字/TYPE 唯一/路由）   20.8s
PASS  rpc            RPC（50 项：调用/超时/并发/codec/载荷版本协商）    17.3s
PASS  drain          进程 drain（8 项）                        13.2s
PASS  rejoin         断线重连（6 项）                            8.5s
PASS  script         脚本热更（16 项）                          15.8s
PASS  http           HTTP 接口（8 项）                         15.7s
```

`tsf verify --list` 看清单，`tsf verify <id>` 只跑一个。缺工具链的套件会标 `SKIP`
而不是 FAIL——环境问题不该把整套判成失败。

> `room` 是 2026-11-19 补进门的：它的 `project.yml` 指向一个不存在的源码路径
> （`bench/room-test/main.php`），既跑不起来也没进 verify，于是 `Room` 重构后
> 它烂了半年没人发现。现在有 `run.sh`、也进了 verify。

---

## 目录结构

```
tsf/                 框架本体
  src/               15 个核心模块：Transport/Gateway/Protocol/Service/Rpc/Cluster/
                     Discovery/Shard/Breaker/Metrics/Gm/Config/Kernel/Timer/Proto
  pkg/               9 个可选包：fsm entity frameloop room aoi match db snapshot eventlog script
  ext/codec/         原生帧编解码（C++，可关）
  tools/             config 导出、proto 生成（不进 AOT）
bench/               功能断言与基准（tsf verify 依赖它，属于框架本体）
docs/guide/          使用教程（00 快速开始 …）
docs/                设计文档与 spike 结论
scripts/             ci-preflight.sh 等
tpc-patches/         对 typephp 编译器的本地补丁（AOT 下必须）
.github/workflows/   CI：lint 走托管 runner，verify 走自托管
tools/repo-guard.py  提交前检查：抓「被 .gitignore 误挡的源码」
spike/               一次性技术验证，只保留结论文档入仓库
```

**`demo/` 是子模块，不在框架本体里**：示例（duel 完整对局、demo-echo 最小 echo）放在
[tsf-demo](https://github.com/hhr0815hhr/tsf-demo) 独立仓库，克隆时用
`git clone --recurse-submodules`，已克隆的仓库补 `git submodule update --init`。

`bench/` 不能同样处理——`tsf verify` 依赖它，拆出去验证就断了。两者的区别是：
**`bench/` 是回归的一部分，`demo/` 是可以独立演进的产品示例**。

---

## 文档

文档分两层：**`docs/guide/`** 是使用教程（讲怎么做），其余是参考与依据（讲为什么）。
根目录只留 README 与 CHANGELOG：

| 想知道 | 看 | 状态 |
|:--|:--|:--|
| **想上手写东西** | **`docs/guide/`** | **使用教程，按顺序读** |
| 框架怎么用、编码约束 | `tsf/README.md` | 权威参考，按主题分节（4000 行） |
| 每个任务做了什么、验收数字 | `docs/FRAMEWORK-TASK.md` | 权威任务表（M0–M7） |
| 这一版有什么、边界在哪 | `CHANGELOG.md` | 按时间倒序 |
| 为什么这么分层（含 libhv 取舍） | `docs/ARCHITECTURE-DESIGN.md` | 设计依据 |
| 房间归属怎么做 | `docs/DESIGN-ROOM-OWNERSHIP.md` | 已实现 |
| 脚本热更怎么做（含踩的坑） | `docs/research/hot-reload.md` | ✅ 已验证 |
| CI 与自托管 runner | `docs/CI-RUNNER.md` | — |
| 架构审计遗留问题 | `tsf/AUDIT-2026-11-15.md` | — |
| 编译器补丁与升级须知 | `tpc-patches/README.md` | — |

已移除的三份（`typeGame.md`、`ARCHITECTURE-EVOLUTION.md`、`IMPLEMENTATION-PLAN.md`）：
它们的结论已并入 `docs/FRAMEWORK-TASK.md`（§六 早期构想修正、§九 架构决策记录），
原文在 git 历史里可查。

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
