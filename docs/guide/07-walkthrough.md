# 07 · 深度示例：一局斗地主

> 前六篇讲"每一层能做什么"，这一篇讲"**把它们拼起来是什么样**"。
> 代码在 `demo/doudizhu/`（独立仓库 `tsf-demo`），可以直接跑：
> `./run-web.sh` → 浏览器开三个标签页 → 三人真打一局。

## 这个示例是什么

| 项 | 数字 |
|:--|:--|
| 服务端 | **1412 行**（`main.php` 166 + `src/` 1246） |
| 网页客户端 | **456 行**（单 HTML，原生 JS + WS） |
| 覆盖的层 | 01 接入 / 02 服务 / 03 玩法 / 04 数据 / 06 运维 |
| 没覆盖 | 05 分布式（单桌不需要）、AOI / 帧循环（斗地主是事件驱动） |

**它证明了什么**：这套框架能用不到 1500 行写出一个规则完整、能落库、能断线清理的
真实玩法——不需要自己造轮子，但**每一层都要选对用法**。

## 代码地图

| 文件 | 行数 | 属于哪层 | 职责 |
|:--|--:|:--|:--|
| `main.php` | 166 | 装配 | 协议栈、传输、Gateway、消息转发、断线清理、`migrate` 子命令 |
| `src/DdzService.php` | 696 | 02 服务 / 03 玩法 | 一桌的全部业务：坐桌、发牌、叫地主、出牌、轮转、结算 |
| `src/Pattern.php` | 312 | 03 玩法 | 牌型判定与比大小（纯函数，无状态） |
| `src/DdzRoom.php` | 93 | 03 玩法 | 牌局状态：三家手牌、底牌、地主、当前出牌人、连过数 |
| `src/Card.php` | 73 | 03 玩法 | 54 张牌的编号约定 |
| `src/Msg.php` | 29 | 协议 | 消息号 |
| `src/PlayerRow.php` | 26 | 04 数据 | 余额表映射（`#[Table]/#[Id]/#[Col]`） |
| `src/DdzState.php` | 17 | 03 玩法 | 四个状态常量（业务定义，框架不认识） |
| `migrations/001_create_player.sql` | 9 | 04 数据 | 建表 |
| `web/index.html` | 456 | 客户端 | 牌桌渲染、选牌、帧编解码 |

## 七个关键决策

每个都是"另一种写法也行，但这里选了它"。

### ① 不用 `RoomManager`，只开一张桌

`RoomManager` 管的是"很多房间"：建房、回收、承载方式（独占/共享）、按 roomId 寻址。
单桌场景用不上——直接 `Scheduler::spawnOf()` 建一个 `DdzService` 就够。

**代价**：只能开一桌。要多桌就得引入 `RoomManager`，并给 `create()` 传自定义 Service 工厂
（框架已经支持，见 03 篇）。

### ② 用 `Room`，而不是裸 `Machine` + `MemberTable`

一开始我打算绕开 `Room`（理由：它把状态焊死在三段式）。后来改成**修框架让 `Room` 可扩展**，
然后正常用它——因为这个场景确实需要 Room 的东西：3 个席位、观战、容量约束、生命周期。

**教训**：如果为了绕开框架的一个限制而自己拼装，那个限制往往是应该被修掉的。
拼装出来的东西将来每个玩法都要重写一遍。

### ③ 状态机四段，由业务定义

```php
// src/DdzState.php —— 框架完全不认识这四个值
final class DdzState
{
    public const WAITING = 1;   // 等人/等准备
    public const CALLING = 2;   // 叫地主
    public const PLAYING = 3;   // 出牌
    public const SETTLED = 4;   // 结算
}
```

比 `ThreeStage` 多一段"叫地主"——**这正是"状态不能写死在 Room 里"的实证**。

构造时注入：

```php
$fsm = new Machine(DdzState::WAITING);
$fsm->allow(DdzState::WAITING, "deal", DdzState::CALLING)
    ->allow(DdzState::CALLING, "start", DdzState::PLAYING)
    ->allow(DdzState::PLAYING, "settle", DdzState::SETTLED);
$this->room = new DdzRoom(1, 3, $fsm);
```

### ④ 协议号分段

| 段 | 用途 |
|:--|:--|
| `1 / 2 / 3 / 100 / 101 / 400` | 框架标准（登录、心跳、错误） |
| `1101 ~ 1107` | 客户端 → 服务端（坐桌、准备、叫地主、出牌、过、离桌） |
| `1201 ~ 1207` | 服务端 → 客户端（桌面、手牌、轮到你、出牌广播、过牌、结算、错误） |
| `1199` | **内部消息**（出牌超时），不走网络 |

分段的理由不是好看：一眼能从日志里看出方向，也不会和框架消息撞号。

### ⑤ 消息载荷统一用 JSON 字符串

```php
Scheduler::instance()->deliver($ddzAddr, Msg::SEAT, json_encode([
    "uid" => $uid, "fd" => $fd, "p" => $data,
]));
```

**不用数组**，因为 `Message::data` 的类型是 `string|array|object`，数组里再嵌联合类型
（`"p" => $data` 本身就是 `string|array|object`）会踩全框架"不出现 mixed"的红线。
JSON 字符串把联合类型压成一个具体类型。

代价是每次多一次 `json_decode`。但这条路径不在热循环里（每局几十条消息），可接受。

### ⑥ 结算先写日志，再改余额

```php
// src/DdzService.php::persist()
$this->log->append($key, "settle", [...]);
$this->log->commit();
foreach ($rows as $row) { /* 改 coins */ }
```

**顺序有讲究**：崩在两者之间时，日志里有一条结算记录、余额没动——重启后可以按日志补账。
反过来（先改余额、后写日志）就查不出差额从哪来。

**日志是事实，余额是事实的推导结果。**

### ⑦ 所有"该谁动了"都走 `beginTurn()`

```php
private function beginTurn(int $seat, bool $mustLead): void
{
    // 取消旧定时器 → 推 TURN → 起新定时器
}
```

如果有的地方推 `TURN` 时忘了起定时器，那个人一旦挂机，整桌就卡死。
**统一入口是唯一可靠的办法**——漏一处就是线上事故。

## 六层怎么串起来

```
客户端 (WS)
   │  ① 登录（裸 token，不是 JSON）
   ▼
Gateway ── 鉴权 / 限流 / 心跳 / 背压
   │  ② 业务消息 → json_encode({uid, fd, p}) → deliver
   ▼
DdzService（Scheduler 调度，单线程）
   │  ③ 状态机与手牌都在 DdzRoom 里；牌型判定用 Pattern（纯函数）
   │  ④ 结算：写 EventLog → 改 player 表
   ▼
publish 闭包 → Gateway::send(fd, msgId, data)
   │  ⑤ 广播 PLAYED / SETTLED
   ▼
客户端渲染
```

几个容易忽略的点：

- **登录走裸 token**（系统消息不解码 payload），业务消息才解码 JSON——这个差异让客户端
  的第一条消息和后续消息格式不同（见 01 篇坑 6）
- **Service 不持有 Gateway**，只持有一个 `$publish(fd, msgId, data)` 闭包。换传输层时
  这一层不用改
- **断线时 Gateway 通知 Service**（`onDisconnect`），Service 清座位并复位空桌

## 踩过的 8 个坑

全部是"跑起来才发现"的，没有一个是编译期错误。

| # | 现象 | 根因 | 修法 |
|:--|:--|:--|:--|
| 1 | `333444` 被判非法 | `distinct==2` 的三带一分支先 `return null` | 分支不命中就往下走，只有全不匹配才返回 null |
| 2 | 同样的坑又犯两次 | 顺子、连对分支也有提前 `return null` | 同上（**按张数特征分派的分支会重叠**） |
| 3 | 准备按钮点不动 | 服务端先推 `HAND` 后推 `TABLE`，客户端在 `HAND` 时反查座位失败 | 抽出 `resolveMySeat()`，凡 tableInfo 更新都调 |
| 4 | 断线后座位永久占用 | 没处理 `onDisconnect` | 注册回调，清座位 |
| 5 | 注册回调就崩进程 | 回调只声明了 `(int $fd)`，Gateway 传两个参数 | 补 `$uid` 参数（AOT 下参数个数不匹配直接崩） |
| 6 | 人走光了新玩家进不来 | 空桌状态还停在 `calling`/`playing` | `onLeave` 发现空桌就 `force` 回 `WAITING` |
| 7 | 超时功能不触发 | 定时器载荷传数组（被 `(string)` 转成 "Array"）；补了 JSON 又缺 `p` 这一层 | 载荷结构必须和业务消息同构 |
| 8 | `tsf migrate` 报 `Swoole\Server->__construct` | 产物没有 `migrate` 子命令分支 | 加分支（见 04 篇） |
| 9 | 机器人"轮到它"但永不出牌 | 只登记 `uidOf` 没登记 `seatOf`，`onPlay` 里取座位得 -1 后静默 return | 两个表都要登记 |
| 10 | 掉线的角色一直往前跑（mmo） | 断线只清了 `fd`、没清 `dir` | 清理状态时把方向也归零 |

**第 1、2 条是同一个 bug 犯了三次**。根因不在某一处代码，而在"用张数特征分派牌型"
这个结构本身：顺子（5 张以上全单）、连对（3 对以上）、飞机带单（k×4 张）的张数会重叠，
任何一处的 `return null` 都可能挡住后面的判定。

## 如果要做成产品，还缺什么

### 已经补上的

| 能力 | 做法 |
|:--|:--|
| **断线重连** | 局中掉线**保留座位**（只标记 `offline`），60 秒内同 token 重连直接恢复手牌；掉线期间由超时机制自动代打 |
| **机器人** | uid 用负数（`-1-seat`），轮到它时用 1 秒短超时自动出牌 —— 一个人也能开局 |
| **观战** | 局中进来的人 `addAs(uid, Member::SPECTATOR)`，收桌面和出牌广播，收不到手牌（`HAND` 是单发的，天然不漏） |
| **余额显示** | 结算广播里带 `coins`（从 db 读的真实余额，不是客户端自己累加） |

**机器人那个坑值得记**：只登记了 `uidOf` 没登记 `seatOf`，代打时 `onPlay()` 第一句
`$seat = $this->seatOf[$uid]` 拿到 `-1` 就静默 return —— 能"轮到它"但永远不出牌，没有任何报错。

**重连那个坑更隐蔽**：断线时只清了 `fd`、没清 `dir`（mmo-mini 那边），角色会一直按最后的方向
往前走，变成在世界里游荡的幽灵。发现方式是"断开 2 秒后重连，位置往前跑了 283 单位"，
283 ÷ 2 秒 = 141 单位/秒，正好对上 `speed × 20 帧`。

### 还缺的

| 缺 | 影响 | 对应哪层 |
|:--|:--|:--|
| 多桌 + 匹配 | 只能一桌 | 03 + `pkg/match` |
| 抢地主 | 简化为"第一个叫的就是地主" | 03（状态机多一条边） |
| 观战者看不到已出的牌 | 只收桌面和广播，历史出牌没回放 | 03 |

## 从这里开始改

| 想做什么 | 看哪个文件 |
|:--|:--|
| 改规则（倍数、春天定义） | `src/DdzService.php::settle()` |
| 加牌型 | `src/Pattern.php`（先补用例再写实现） |
| 改协议 | `src/Msg.php` + `main.php` 的转发 + `web/index.html` 的常量表（**三处都要改**） |
| 换存储 | `main.php` 里把 `Db` 换成 `Shards`，`Repo` 调用代码不用动 |
| 加 GM 功能 | 参考 06 篇，`Gm\Server` 和 `Scheduler` 同事件循环，天然单写者 |

**改协议要动三处**是这个 demo 最需要注意的地方：`Msg.php` 的常量、`main.php` 的
`$gw->on(...)` 注册、客户端的常量表。少一处就是"消息发出去了没反应"。
