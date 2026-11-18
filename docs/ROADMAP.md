# 路线图

> 更新：2026-11-18（v0.8.0 发布后）
> 来源：会话中讨论的未完成事项。做完一项就勾掉，别让它散落在对话里。

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
