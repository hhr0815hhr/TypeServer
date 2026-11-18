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

- [ ] **`pkg/http` / `pkg/script` 进 `verify-all.sh`**
      现在的 197 项断言没覆盖这两个新包。
      估算 1 天。

- [ ] **`ext/interp` 进 CI**
      扩展编译没进流水线，clone 下来要手动 `phpize85`。
      估算 半天。

## P2 运维能力（有真实流量前做）

- [ ] **`pkg/snapshot`（状态快照）**
      已实测：10 万玩家 json 存 40ms / 读 122ms（11.2 MB）。
      做热更前落盘、重启恢复。这是「PHP 替 Lua」的最后一块。
      估算 2-3 天。

- [ ] **`tsf mem` / `tsf top`（内存可视化）**
      Erlang `observer` 的等价物，目前完全空白。
      分三层：单条命令（表列表+内存）→ TUI（实时刷新）→ Web（`/admin/mem`）。
      估算 2 天（到 TUI 层级）。

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
