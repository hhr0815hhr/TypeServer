# 路线图

> 更新：2026-11-26（v0.13.0 发布后）
> 来源：会话中讨论的未完成事项。做完一项就勾掉，别让它散落在对话里。

## P0 通往 1.0（审计后新增，见 ARCHITECTURE-REVIEW §58）

这六项不做完不叫 1.0。**2026-11-26 全部完成**，每项都钉了回归并做过反向验证。
用法见 `docs/guide/13-production.md`。

- [x] **服务 id 回收** —— 已完成（2026-11-25）。**回绕**而不是空闲表：id 仍单调递增，到顶绕回 1，
      跳过活着的和 5 秒内刚死的。空闲表会让低号 id 被反复复用，别处存的旧地址很快指向新服务（ABA）。
      回绕下一个 id 要隔一整轮地址空间才会被复用。`tests/id-recycle` 含真的绕一轮（53 项）。
- [x] **统一的优雅停机** —— 已完成（2026-11-25）。挂在 Swoole 的 `WorkerStop` 上：实测它在正常退出和
      窗口到期强退两条路径下都会触发，而 worker 里接管不了 SIGTERM。顺序：停机钩子 → 停定时器 →
      排空（有预算）→ 逆创建顺序逐个 `onStop`（单个抛异常不影响别的）→ 收尾钩子 → 冲缓冲。
      `max_wait_time` 可配，设 0 时空闲进程 20 多毫秒退出且流程完整。`tests/shutdown-guard`（16 项）。
- [x] **集群断链自动重连** —— 已完成（2026-11-25）。`Cluster::watchPeer()` 登记，指数退避（0.5s 起翻倍封顶
      15s，带抖动，稳定过的链路断了从头算）；两端同时互拨按节点号裁决只留一条；TCP 拨号改非阻塞
      （对不可达地址单次 poll 从 2,001ms 降到 0.8ms）。`tests/cluster-reconnect`（30 项）。
      **没做的**：注册表成员变更 → `watchPeer()` 的联动（谁该 watch 仍由业务决定，核心不依赖可选包）。
- [x] **LICENSE / SECURITY.md / CONTRIBUTING** —— 已完成（2026-11-26，作者定 MIT，与 `composer.json` 一致）。
      根目录与包根各一份 `LICENSE`；`SECURITY.md` 列了已知设计边界；`CONTRIBUTING.md` 把"先让它红一次再修 + 变异检查"写成了规矩。
- [x] **日志轮转 + 保留份数** —— 已完成（2026-11-25）。单文件 64 MB × 5 份，多进程安全（`rename` + 锁文件）；
      具名通道 `Logger::to()`，GM 审计默认落到独立的 `gm.log` 并带操作人字段（请求头 `x-gm-operator`，
      自报、非鉴权）。`tests/log-rotate`（42 项，含两进程并发轮转）。
- [x] **TLS / WSS** —— 已完成（2026-11-25）。`ssl_cert_file` + `ssl_key_file`，明文连接被握手拒绝；
      只配一半按明文启动并记 error。集群内部仍是明文，不能上不可信网络。`tests/tls-guard`（16 项）。

## P1 上线后很快会被要（审计后新增）

2026-11-26 做完了下面带 [x] 的；每项的实测与反向验证见 ARCHITECTURE-REVIEW §60。

- [x] **内置指标** —— 已完成。`tsf_scheduler_*` / `tsf_gateway_*` / `tsf_cluster_*` 自动导出（登记统计提供者，导出时现取），
      `rpc_*` 在事件点计数。`prom` 套件 34 → 51 项。
- [x] **连接准入** —— 已完成。登录时限（未登录连接发心跳也留不住）、单连接失败上限、同一来源失败封禁、
      未登录流量按来源限流。`admission` 套件 38 项。
- [x] **Gateway 装配入口** —— 已完成。`Gateway::assemble()` 按 `server.*` 一次装好，`tsf new` 的模板和默认配置同步。
- [x] **token 过期 / 作废** —— 已完成。签发带 `iat`；`revokeUid()` / `revokeAll()` / 密钥轮换宽限；2 段永不过期格式可关。
      **作废是进程内状态**，多进程要全服生效得广播（没做）。
- [x] **注册表成员 → `watchPeer` 联动** —— 已完成。`Discovery\Membership` + `LocalNode::afterBeat()`。`membership` 套件 38 项。
- [x] **GM 一人一个 token** —— 已完成。`setOperators()`；操作人来自 token。`gm` 套件 39 → 55 项。
- [x] **零散项** —— HTTP 405 + Allow、访问日志 info 级、`bearerToken()` 不再接受 query、`Gm\Task::gc()` 有人调了。
- [x] **三个套件跑的是旧产物** —— 已完成。`gm-test` / `fd-test` / `settle-crash` 的 `run.sh` 此前不编译，
      产物停在 11-16；现在都编译，并且 `tsf lint` 对"注册了但不编译"判红。
- [x] **接真服务的套件进回归** —— 已完成（2026-11-25）。`registry / mail / db-mysql / db-shards / db-pg / redis`
      六个此前只有 `skip-auto`、从未执行过的套件（共 153 项）现在跑在 docker 起的服务上；
      `scripts/dev-redis.sh` / `dev-mysql.sh` / `dev-pg.sh`，缺服务时 exit 77。
- [x] **文档漂移** —— README 曾写"19 个套件 / 671 项断言"，已对齐。
- [ ] **CI** —— `verify` 只能手动触发，PR 上没有门禁。需要一台能跑 docker 的自托管 runner。
- [ ] **token 作废跨进程生效** —— 把作废水位放到共享存储（`pkg/redis`），或通过 GM 广播到每个进程。
- [ ] **节点号重复检测** —— 两个进程配成同一个节点号，注册表不拦；地址会指向错的进程。
- [ ] **心跳时间戳依赖各机时钟** —— `lastSeen` 用墙钟；跨机房时钟偏差大会误判节点死亡。
- [ ] **作废 `tests/db-pg-test` 里泄露过的数据库凭据**（已从源码移除，但还在 git 历史里）。

## P0 安全

- [x] **改掉泄露过的数据库密码**（`demo/idle` 和 `tests/*` 里那两个）—— 已完成
- [x] **检查其他明文凭据** —— 已完成

## P1 可用性（上线前必须）

- [x] **脚本异常不带崩服务** —— 已完成（ext/interp 0.5.0）
      `interp_invoke` 用 `zend_try` 包住调用：throw 返回 `{ok:false,class,message}`，
      Fatal 返回 `{ok:false,fatal}`，进程退出码 0。两种都实测过。
      **但 bailout 之后状态不可信** —— 所以监督树（下一项）仍然是必须的。

- [x] **监督树** —— 已完成（Scheduler::supervise，2026-11-18）
      已有的：tick 里 try/catch + kill，异常不拖垮进程。
      这次补的：`supervise(name, factory, maxRestarts, withinMs)` —— 崩了按阈值重启，
      超限放弃（记 error 日志）。单实例语义（世界 / 邮件 / 排行榜）。
      实测：maxRestarts=3 时前 3 次重启、第 4 次放弃，addr 归 0。
      **没做**：`one_for_all` / `rest_for_one`（多实例联动重启），当前场景不需要。

- [x] **`pkg/http` / `pkg/script` 进 `tsf verify`** —— 已完成（2026-11-18）
      `tests/script-test`（16 项）+ `tests/http-test`（8 项），都接进 `verifySuites`。
      跑出两个真问题：`Script::reload` 没清 opcache（同进程热更读到旧字节码）、
      测试自己的字符串替换写错（`+ 100` vs `"gold", 100`）。
      顺带确认了一个设计：CORS 要注册两次（`use` 管预检、`after` 管正常响应）。

- [ ] **`ext/interp` 进 CI**
      扩展编译没进流水线，clone 下来要手动 `phpize85`。
      估算 半天。

## P2 运维能力（有真实流量前做）

- [x] **`pkg/snapshot`** —— 0.x 就已有，不是待办
      原实现比原计划的更完整：抽象 `Store` + `FileStore`/`MemoryStore` 两个实现
      + `Snapshot`（分块 + 版本号）+ `Restorer`（批量恢复，带耗时告警）+ `Codec`。
      我最初以为是待办，动手写了个简化版，覆盖掉 `Store.php` 后把 `FileStore`
      的继承打断了（`final` 不能继承）。已 `git checkout` 恢复，我的实现在这次提交里删掉。
      **教训：做之前先 `ls` 一下目标目录，别凭 ROADMAP 的描述直接开写。**

- [x] **`tsf mem`（进程级内存）** —— 已完成（2026-11-18）
      `tsf mem [关键字]`：从 `/proc` 读 RSS / 线程数 / 运行时长 / 命令行。
      坑：`pgrep -f` 会匹配到 `bash -c "...hi..."`，改用 `/proc/{pid}/exe` 判断真实可执行文件。

- [ ] **`tsf top`（TUI 实时刷新）+ `/admin/mem`（应用级）**
      进程级已经够看"谁吃了内存"，应用级还缺：服务数 / 信箱长度 / 定时器数 / 错误数。
      这些在 `Scheduler::stats()` 里，但它是进程内数据，必须由服务自己通过 HTTP 吐出来
      （登录服的 `/admin/mem`，走 `Gm` 或直连）。
      估算 1 天。

- [ ] **热更编排（版本目录 + 灰度 + 回滚）**
      `pkg/script` 只有 `reload()`，没有灰度分流和回滚。
      估算 3 天。

- [ ] **脚本沙箱（禁用危险函数）**
      现在脚本和宿主同权限，能 `exec` / `file_put_contents` / 发网络请求。
      估算 2 天。

## P3 完善（有余力再做）

- [ ] **脚本性能测量**
      每次 `interp_invoke` 的开销，和 native 调用差多少。
      现在没性能目标，测了也不知道算好算坏 —— 等有真实场景。
      估算 半天。

- [ ] **`GenServer` 模板（OTP 风格）**
      把「同步请求 / 异步通知 / 系统消息」三类分开，
      避免用户把所有逻辑塞进一个 `onMessage`。估算 1-2 天。

- [ ] **补一篇「热更方案对比」教程**
      wasm 那篇删了，现在缺正面叙述"为什么不用 wasm"。
      研究记录（`docs/research/hot-reload.md`）里有，但不在教程序列里。
      估算 半天。

- [ ] **`pkg/http` 支持 405**
      现在「路径存在但方法不对」返回 404。HTTP 规范要求 405 + Allow 头。
      改 `Router::dispatch` 时能一起做：记住"这个路径注册过哪些方法"。
      估算 2 小时。

- [ ] **`pkg/http` 的 `after()` 写进教程**
      现在只在代码注释里。教程 11 讲了 CORS 两次注册，但没展开 `after()`。
      估算 2 小时。

## 已完成（本版）

- [x] `pkg/http`（路由 + 3 个中间件）
- [x] `pkg/script`（脚本热更 + `Context` 安全边界）
- [x] `ext/interp` 扩展（4 个函数）
- [x] `tsf new --login`（登录服脚手架）
- [x] wasm 全部移除
- [x] `tsf new .` / `--login` 参数 bug
- [x] 教程 11 篇 / `tsf-dev` skill
- [x] Packagist v0.8.0

## 一个判断

**P1 里的前两项（脚本异常、监督树）是「能跑」和「能上线」的分界线。**

现在的状态是：功能齐了，但一个脚本写错就全挂。
生产环境不接受这个风险，所以这两项在有任何真实流量之前必须做完。
