# 房间 / 副本 / 世界：用什么载体跑

> 结论先给：**房间是 Service（actor），不是线程、不是协程、不是进程。**
> 并行靠多 worker（进程），协程只用来挡住阻塞 I/O。
>
> 全部数据来自 `bench/service-cost`、`bench/scene-scale`、`tests/room-test`（2026-11，v0.12.0）。

---

## 一、先纠正一个概念

TSF 的调度模型是 Skynet 式 actor：

```
业务代码 ──deliver──> 目标信箱 ──tick──> Service::onMessage()
```

**Service 不占线程，也不是常驻循环。** 它平时不存在，只在信箱里有消息时被
`onMessage()` 调一下、处理完就返回。所以"一房一 Service"**不等于**"一房一线程"，
也不等于"一房一协程"。

三层并行度是分开的三件事：

| 层 | 是什么 | 解决什么 | 成本 |
|:--|:--|:--|:--|
| **Service** | 逻辑隔离单元（信箱 + 状态） | 状态隔离、单独销毁、可迁移 | **322–333 B/个**，创建 0.79 µs |
| **协程** | `setCoroutine(true)` + `Runtime::enableCoroutine` | `onMessage` 里的阻塞 I/O 不卡住别人 | 只在有 DB/Redis 调用时需要 |
| **worker** | `server.worker_num`（Swoole 进程） | 横向扩展（真正的多核） | 每个 worker 一个独立 Scheduler |

**载体成本实测**：

| 对象 | 内存 | 创建 |
|:--|--:|--:|
| 空 Service | 307–333 B/个 | 0.8–2.3 µs |
| 带 60ms 帧定时器 | 788–812 B/个 | — |
| 投递一条消息 | — | 0.73–0.89 µs |

1 万个房间 Service 只占 **2–3 MB**、创建 **8–23 ms**。所以"一房一 Service 会不会太重"这个
担心是错的——**贵的是房间内的状态和执行时间，不是载体**。

> **时间类数字别跨天比。** 同一段代码 2026-11-23 测 0.79 µs/个，11-26 在同一台机器上测
> 1.8–2.3 µs；同期消息吞吐也从 115 万降到 85 万 msg/s——是整机变慢（此前连跑了十几轮
> 全量回归），不是代码退化。内存类数字稳定（307–333 B 两轮一致），可以跨天引用。
> 判据：**报"快了多少"必须带同批对照**，见 `bench/REPORT.md` §二。
> 复现：`bash bench/service-cost/run.sh`。

---

## 二、三种形态怎么选

### 房卡房（斗地主 / 麻将 / 德州）

**用 `RoomManager::MODE_SHARED`**：几百个房间共享一个宿主 Service。

```php
$mgr = new RoomManager(RoomManager::MODE_SHARED, 50, 200);   // 50ms 帧、每宿主 200 房
$roomId = $mgr->create(4);        // 4 人房，返回房间号
$mgr->send($roomId, RoomManager::OP_JOIN, $uid);
```

理由不是 Service 贵，而是**调度是单线程的**——1 万个房间的帧循环在同一 worker 里
就是串行。共享宿主还有个附带好处：定时器数量从"每房一个"降到"每宿主一个"
（1000 房 → 5 个定时器）。

容量参考（20 人房、每帧推进、20Hz、50ms 预算）：

| 房间数 | 单 worker 帧耗时 | 判断 |
|--:|--:|:--|
| 1,000 | 10.8 ms | 轻松 |
| 4,000 | 43 ms | 接近上限 |
| 10,000 | 108 ms | 超了 |

**单 worker ≈ 4000 个 20 人房**，8 worker ≈ 3.2 万间。

房卡房大多是**玩家操作驱动**（出牌、结算），不是每帧跑满——实际能撑更多。
只有需要主动推进的玩法（倒计时、AI、位移）才吃帧循环。

### 副本 / MOBA / 战斗局

**用 `MODE_DEDICATED`**：一房一 Service（`create()` 里 `spawnOf` 一个）。

```php
$mgr = new RoomManager(RoomManager::MODE_DEDICATED, 50, 1);
$roomId = $mgr->create(10, true, function (int $id, int $max): BattleService {
    return new BattleService($id, $max);   // 自有协议、自有状态机
});
```

需要独立帧循环 + 独立生命周期 + 出错只销毁自己。工厂注入就是为了这种玩法
（默认的 `RoomService` 只跑"帧循环 + 标准操作"，承载不了自有协议）。

### 世界服（单个大场景）

一个独立 Service，不套 `Room`（世界的"成员表"就是 `EntitySet` + 玩家索引表）。

**单场景容量上限约 2 万人**（20Hz）：

| 人数 | 纯逻辑 | 加广播估算 |
|--:|--:|--:|
| 1 万 | 10 ms | ≈ 21 ms |
| 2 万 | 20 ms | ≈ 42 ms |
| 3 万 | 45 ms | ≈ 67 ms ✗ |

超过 2 万必须**按位置分区**。注意别用"按 uid 分片 + 位置副本"那条路——
AOI 开销取决于密度，密度不会因为"谁是本地/谁是副本"而降低，反而多出每帧全员
位置同步。理由和实测见 `bench/SCENE-CAPACITY.md` 第四节。

---

## 三、协程什么时候是必须的

**`onMessage` 里只要有一次阻塞调用，就必须开协程。**

不开的话，一个房间的 `SELECT` 会让**同一个 worker 里其他几千个房间一起卡住**——
这不是"慢"，是"全体停顿 50ms"。表现是"每隔一会儿所有房间都掉帧"，很难联想到是
某个房间在查库。

```php
// 启动时（worker 进程内）
Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
Scheduler::instance()->setCoroutine(true);
```

开了之后，`onMessage` 里的阻塞 I/O 只挂起自己那个协程，其他服务照常被 tick。

**纯内存房间不需要协程**，开了反而多一层调度开销。

---

## 四、几个容易踩的点

### `Grid::move()` 的返回值不是"新 key"

```php
// 返回：>= 0 = **旧**格子 key（换格了）；-1 = 没换格；-2 = 实体不存在
$oldKey = $grid->move($uid, $x, $y);
if ($oldKey < 0) {
    continue;                       // 格内移动，视野没变，这是绝大多数帧
}
$newKey = $grid->cellKeyOf($uid);   // 新 key 要自己再取
```

把它当新 key 去比较，判断会**完全反**：没换格判成换格、真换格反被跳过。
后果是每帧给所有实体算视野差集，耗时放大十几倍（实测 9.6 ms → 121 ms）。

### 房间容量和 `maxPlayers` 是两回事

`Room::add()` 返回 `false` 有**四种**原因：房间已关闭、`uid <= 0`、该 uid 已在房内、
**席位已满**。别把它当成"只表示关闭"。

### 高并发下别用 `count($room->uids())` 判满

`uids()` 每次分配一个数组。判满用 `playerCount()`，它内部是计数器。

### 定时器不是回调

```php
$this->interval(50, Msg::TICK);     // 按毫秒投消息
$this->every("0 5 * * *", Msg::DAILY);  // cron 字符串（每天 5 点）
```

要处理就得在 `onMessage` 里认 `Msg::TICK`。两者别混：`interval` 不是毫秒版 `every`。

---

## 五、自检清单

改完房间/场景逻辑，按顺序确认：

1. `tsf build` 过了吗（AOT 不重编不生效）
2. 帧循环里有没有阻塞调用 → 有就开协程
3. `Room::add()` 的 `false` 你处理的是哪一种
4. 房间数 × 每房间帧耗时 < 50ms（单 worker）
5. 房间销毁后 `close()` 有没有清成员（`close()` 只置标记，**不清成员**）
