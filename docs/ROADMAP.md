# 路线图

> 更新：2026-11-27（v1.0.0-rc.3）
> 来源：会话中讨论的未完成事项。做完一项就勾掉，别让它散落在对话里。
>
> 分工：**这份管"能不能发布"**（运行闭环、安全、发布工程）。
> **能力面"瘸不瘸腿"在 [`ROADMAP-NO-GAP.md`](ROADMAP-NO-GAP.md)**——调度时间片、脚本表达力、
> 三层固化那几项。两份有交叉，但判断标准不同，别混着看。

## 通往 1.0.0 正式版（候选版之后）

2026-11-27 的评估结论：功能面到了门槛（下面 P0 六项、`ROADMAP-NO-GAP` 的 P0 三项全部完成），
所以打了候选版 `v1.0.0-rc.1` — `rc.3`。转正之前还差下面三项——**都不是"再写多少功能"，是时间和承诺**。

- [x] **封版的硬门**（2026-11-27 做完）：`tsf lint` 在 HEAD 判红（解释执行的套件被"必须编译"
      规则误伤）→ 改成显式声明 `"noBuild"`；`frame-test` 跑旧产物 → 先删再编；
      `ext/interp` 不在 composer 包里 → 移进 `tsf/ext/interp`；tsf 包没有 CHANGELOG → 补上。
- [ ] **CI 在 GitHub 上跑绿**。两份 workflow 的修复 2026-11-27 推上去之后第一次真实执行：
      - tsf 仓库的包完整性 CI：第一次红（`tsf lint` 在单独克隆的包里必然判红，修于 v1.0.0-rc.2），**现在绿**。
      - 根仓库：`lint` job 绿；`verify` job 第一次 **58 / 67**——环境在托管 runner 上一次装成
        （约 25 分钟），        红的全是本地复现不了的问题：写死的仓库路径、环境缺 phpredis / zip / openpyxl、
        两条卡在计时边界上的断言（`clock` 的落点差 1 毫秒落进前一秒、`shard-router` 的过期判断撞上墙钟校正）。顺着它们还挖出两个"红着也是绿的"套件
        （`schedule` 藏了一条 v0.12.0 引入的 cron 回归，`eventlog` 本机一直是红的）。
        修复与 `tsf verify` 的判定加固见 `CHANGELOG.md` 的 v1.0.0-rc.3。
      - 第二次：环境自检失败（zip 的 configure 选项写错，`--enable-zip` 应为 `--with-zip`）。
      - 第三次：**64 / 67**。剩下三个：两个套件的夹具文件从没进过仓库（白名单 `.gitignore`
        没放行 `*.xlsx` / `*.proto`），一个用例在 PHP 默认的 128M 内存下跑不完（本机是 512M）。
        都已修，第四次的结果以 CI 页面为准。
- [ ] **浸泡**。v0.14 → v0.17 四个版本在 11-26 当天打出，rc 再叠十几个提交，全部在 48 小时内；
      现有最长的长跑测试是 30 秒（`bench/latency-drift`），文档自己写了"只能说没有快速退化"。
      当天修的两个 bug（定时器泄漏、cron 落点）都属于"跑几小时才显形"。要做的：
      一次 ≥24 小时长跑（采样 RSS、定时器数、p99）+ 至少一个真实小服务带流量跑 1–2 周。
- [ ] **API 稳定性分级**。136 个类 + 8 个接口，`@internal` / `@experimental` / `@deprecated` 各 0 个。
      1.0 等于全部公开 API 进兼容承诺，所以得先划清"承诺什么"。**建议的划分**（待作者确认）：

      | 层 | 承诺 | 说明 |
      |:--|:--|:--|
      | `src/` 的公开类与方法、`bin/tsf` 的子命令与参数 | 稳定 | 1.0 起只做兼容变更；要删先标 `@deprecated`，**保留至少两个 minor** 再删 |
      | `pkg/*` 各包的公开类 | 稳定 | 同上。`pkg/script` 例外：它还在快速演进，1.0 时标 `@experimental` |
      | 配置键（`config/*.json`）与线协议（帧格式、握手、`Broadcast` 的消息号） | 稳定 | 加键可以，不改语义；线协议一冻结，客户端 SDK 才有依据 |
      | 模板生成的工程（`tpl/`） | 不承诺 | 生成出来的代码归用户，模板本身随版本变 |
      | CLI 的**输出文本**、`tsf_scheduler_*` 以外的指标名 | 不承诺 | 脚本不要解析它们；要机器读的走 `stats()` / Prometheus 导出 |
      | `Scheduler::debugSizes()` 这类自省方法 | 不承诺 | 给排障用的，随时会变 |

      定下来之后：给不承诺的那些补 `@internal`，在 `tsf/README.md` 加"稳定性"一节，
      `CHANGELOG` 里出现第一条 `@deprecated` 时写明移除版本。

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
- [x] **CI** —— 已完成（2026-11-27）。**全部跑在 GitHub 托管 runner 上，不再需要自托管机器**。
      `scripts/ci-setup.sh` 幂等装环境（PHP 8.5 ZTS + swoole + 工具链 + interp），
      装好的约 600MB 打成 tar 走 `actions/cache`；数据库用 runner 自带的 docker 起，
      六个需真库的套件不再 SKIP。见 `docs/CI-RUNNER.md`。
      **tsf 仓库**另有一份包完整性 CI（lint / php -l / composer validate / 模板生成，3 分钟）。
- [ ] **token 作废跨进程生效** —— 把作废水位放到共享存储（`pkg/redis`），或通过 GM 广播到每个进程。
- [ ] **节点号重复检测** —— 两个进程配成同一个节点号，注册表不拦；地址会指向错的进程。
- [ ] **心跳时间戳依赖各机时钟** —— `lastSeen` 用墙钟；跨机房时钟偏差大会误判节点死亡。
- [x] **作废 `tests/db-pg-test` 里泄露过的数据库凭据** —— 已完成（2026-11-27，数据库密码已轮换；历史里的旧密码随之失效，不改写历史）。

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

- [x] **`ext/interp` 进 CI** —— 已完成（2026-11-27）。`ci-setup.sh` 里 `phpize + make`，
      产物在 `tsf/ext/interp/modules/interp.so`，套件用 `PHP_INI_SCAN_DIR` 直接指它。
      **同日把 interp 的源码从根仓库移进 tsf 包**（`tsf/ext/interp/`，附 `build.sh`）：
      `composer require` 的用户此前拿不到运行时，`pkg/script` 用不了。

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
