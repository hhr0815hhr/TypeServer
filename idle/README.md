# idle —— 挂机收菜

一个**极简但完整**的业务 demo。目的不是做游戏，是**把各个 pkg 串起来跑一遍**。

## 跑起来

```bash
php85 init_db.php                    # 建 idle_player / mail 两张表（幂等）
tsf build .                          # 编译
./idle                               # 启动，默认 9501
php85 client.php                     # 另开终端
```

客户端会依次走：登录 → 收取 → 排行榜 → 邮件列表 → 领附件 → 重复领（验证幂等）。

## 验证了哪些链路

| 链路 | 怎么验的 |
|:--|:--|
| **protobuf** | proto → `tsf proto build` → 生成类 → Gateway 编解码 → Service 收到对象。之前只有单测，这是第一次端到端 |
| **db 持久化** | 登录建角色、收取写回、退出前刷盘 |
| **离线收益** | 重连时 `pending` 按 `now - lastTick` 算（第二次登录能看到上次挂机的收益） |
| **Redis 排行榜** | `zAdd` 写榜、`zRevRange` 读榜 |
| **邮件** | 新角色自动发欢迎邮件（带附件）、领取、**重复领取幂等** |
| **断线重连** | 断线只解绑 fd，内存状态保留；即使进程重启，从 db 读 `lastTick` 也能算出同样结果 |

## 三个设计点

**① 不存"待领取"，用 `lastTick` 现算**

存 `pending` 就要维护"什么时候加、什么时候清"，漏一次永久错位。现算则只有一个时间字段，离线在线同一套公式。

**② 收取时 `last_tick += collected * secPerCoin`，不是 `= now`**

直接设 `now` 会把"不足一个结算周期的零头"吃掉。玩家挂一天会少拿几百金币。

**③ 断线不清内存**

重连复用内存里的对象；内存丢了也能从 db 重建 —— 这就是"db 是真相、内存只是缓存"。

## 踩过的坑（都是这次才发现的）

**1. proto 的两条生成规则**

- **每个 message 都要 `// @id`**，包括只被 `repeated` 引用的嵌套类型（`RankItem` / `MailInfo` / `Item`）
- **不允许空 message**，无参数的请求（`CollectReq`）也要放一个占位字段

**2. 生成类的属性名保留 proto 的下划线**

```proto
int32 rate_per_minute = 5;
```

生成的是 `$resp->rate_per_minute`，**不是** `ratePerMinute`。写成驼峰会得到 `Creation of dynamic property` 警告，然后值一直是默认的 0 —— 症状是"字段明明赋了值，客户端收到 0"。

**3. Service 回包不能用 `$this->send($fd, ...)`**

`Service::send(int $to, ...)` 的第一个参数是**服务地址**（Scheduler 寻址空间），不是 fd。传 fd 进去会投到错误的服务，**而且是静默的**（没有报错，只是客户端收不到）。

正确做法（照 `demo/mmo-mini`）：构造注入一个 `\Closure $publish`，闭包内部是 `$gw->send($fd, ...)`。

**4. `config/tsf.json` 缺失时不会报错，会用默认值**

我第一次跑的时候配置没写成功（目录不存在），服务用默认端口 9001 起来了，看起来"正常启动"，但连不上 9501。**启动日志里 `protocol` 那行会显示实际用的配置**（`payload: raw` vs `protobuf`），排查时先看它。

**5. `tsf build` 只编译"被引用到"的类**

`src/IdlePlayer.php` 一开始没被编进去，运行时报 `Class "Idle\IdlePlayer" does not exist`。原因是文件确实缺了（生成脚本的目录没建），但**如果真是"没被引用"也会这样**——排查时先看编译日志里 `prepare: src/xxx.php` 有几行。

## 已知取舍（故意不修）

**排行榜和 db 会短暂不一致**：先写 db 再写 Redis，中间进程挂掉，榜上就是旧值。

demo 不修，因为：

- 真修需要"事务性双写"或"定时对账"，两种方案都有代价
- **demo 的价值是暴露问题，不是假装没有问题**

真实项目怎么选：

| 方案 | 适合 |
|:--|:--|
| 定时对账（每分钟全量刷榜） | 榜的实时性要求低（如小时榜） |
| 写 db 成功后再写 Redis + 失败重试队列 | 实时榜 |
| 直接以 Redis 为准，db 存快照 | 榜是核心玩法时 |

## 下一步可以加什么

- `last_tick` 的时区/夏令时问题（现在是 unix 时间戳，不受影响，但如果策划要"每天 0 点重置"就要考虑）
- 多 worker 时的状态迁移（现在 `worker_num: 1`）
- 跨天/跨周榜（`idle:rank:2026-11-17`）
