# 03 · 玩法层：Room、帧循环、AOI、状态机

> 本页代码块标 `片段` 的只讲结构；完整可运行的示例在 [07 篇](07-walkthrough.md)。

## 它解决什么问题

写玩法会遇到四个问题，它们看起来都该"自己写一个"，但每一个都有坑：

| 问题 | 自己写的典型错误 |
|:--|:--|
| 一局游戏有阶段（等待→进行→结算） | 用 `if ($state === 3)` 散在各处，加一个阶段要改十个地方 |
| 桌上有几个玩家、谁坐哪、谁是观战 | 用一个 `array` 装玩家，离开时 `unset` 造成席位空洞，客户端座位错乱 |
| 每 50ms 推进一次 | 每个房间各开一个定时器，5000 个房间就是每秒 10 万次出槽 |
| 谁在我附近 | 每帧遍历所有实体算距离，O(n²) |

本层给的是这四件事的**通用解**，且每一个都独立成包，可以只拿你要的那一个。

## 核心概念

### 房间 = 状态机 + 成员表

```
pkg/fsm   →  Machine       状态与转移（也能用在登录流程、订单、战斗单位）
pkg/room  →  MemberTable   谁在里面、坐哪、什么身份
             Room          上面两个的组合，仅此而已
```

`Room` 本身几乎不含逻辑——它把 `Machine` 和 `MemberTable` 放在一起，暴露 `state()`、
`members()`、`playerCount()`、`frame()`。

**这意味着：如果你只需要状态机，别装 room。**

### 席位是稳定的，观战不占位

`MemberTable` 只管"关系"，不管状态机、不管坐标（坐标在 `pkg/entity`，视野在 `pkg/aoi`）。

两个设计细节值得知道：

- **容量只约束占席位的人**。棋牌房"一桌 4 人 + 200 观战"是常态，若观战也占容量，要么开不了观战、要么容量虚高。
- **席位号在一局内不变**。玩家坐下分配最小空位，离开后空出来给下一个；但已在座的人不会被挪。客户端按席位号画座位，中途换号会让人物跳位。

### 两种承载方式

| 方式 | 结构 | 适合 | 代价 |
|:--|:--|:--|:--|
| `dedicated` | 一房一 `Service` + 一个定时器 | MMO 副本、MOBA——实体多、帧重 | 房间数 = 服务数 = 定时器数 |
| `shared` | 多个小房间共用一个 `RoomHost` | 棋牌、回合制——人少、无实时位置 | **无隔离**：一个房间慢帧会拖累同宿主的其他房间 |

`shared` 的收益是量化的：5,000 个房间时定时器从 5,000 个降到 25 个（每宿主 200 间）。

**这不是二选一的教条**，`RoomManager` 的第一个构造参数就是它。

### 房间号：分段编址，且不回收

```
roomId = nodeId * 10^9 + seq
```

不做全局自增（要么依赖中心服务、要么多节点各自从 1 数导致重号），也不做号段回收——
因为房间号会进结算幂等键 `duel:<房间号>`。**号一旦复用，新一局会被判"已结过账"，
钱静默不发。**

### 帧循环是可选的

`FrameLoop` 是"房间 + 定帧"的胶水：

```php
// 片段
$loop = new FrameLoop($hostService, $tickMsgId, 50, 200);   // 50ms 一帧，最长补 200ms
$loop->start();
```

`RoomManager::create($maxPlayers, $autoFrame)` 的第二个参数可以关掉它。**棋牌和回合制
根本不需要帧**——它们用事件驱动或超时推进。硬套帧循环只会让逻辑变复杂。

## 公开 API

### RoomManager

| 方法 | 用途 |
|:--|:--|
| `new RoomManager(int $mode = MODE_DEDICATED, int $frameMs = 50, int $maxRoomsPerHost = 200)` | 选择承载方式 |
| `create(int $maxPlayers = 8, bool $autoFrame = true): int` | 建房，返回 roomId |
| `send(int $roomId, int $op, $payload = ""): bool` | 向房间投递操作 |
| `addrOf(int $roomId): int` | 房间的 Service 地址 |
| `has(int $roomId) / count() / roomIds()` | 查询 |

自带标准操作号：`OP_JOIN = 1` / `OP_LEAVE = 2` / `OP_START = 3`。**自定义操作从 100 起**，
别占用低号段——它们会被框架当标准语义处理。

**对外只用 roomId 寻址**，不暴露 Service 地址——这是刻意的，换承载方式时调用方不用改。

### Room 与 MemberTable

| 方法 | 用途 |
|:--|:--|
| `state() / stateName() / fsm()` | 状态与状态机 |
| `members(): MemberTable` | 成员表 |
| `playerCount() / memberCount() / maxPlayers() / frame()` | 计数 |
| `members()->add($uid, $role = Member::PLAYER, $name = ""): ?Member` | 入座，满员返回 `null` |
| `members()->remove($uid): bool` | 离开 |
| `members()->get($uid) / has($uid) / occupants() / capacity()` | 查询 |

### Machine（状态机）

| 方法 | 用途 |
|:--|:--|
| `new Machine(int $initial)` | 初始状态 |
| `allow(int $from, string $event, int $to): Machine` | 声明转移（**链式**） |
| `fire(string $event): bool` | 触发，返回是否成功 |
| `can(string $event): bool` | 能否触发 |
| `onEnter(int $state, \Closure $cb)` / `onExit(...)` | 进入/离开钩子 |
| `force(int $state)` | 强制跳转（**绕过校验，慎用**） |

### AOI（`pkg/aoi`）

| 方法 | 用途 |
|:--|:--|
| `new Grid(int $cellSize, int $cols, int $rows)` | 网格 |
| `insert(int $uid, int $x, int $y): bool` | 加入 |
| `move(int $uid, int $x, int $y): int` | 移动，**返回旧格 key** |
| `neighbors(int $uid): array` | 3×3 邻居（含自己） |
| `neighborsShared(array $uids): array` | 批量，**同格共用一次扫描** |
| `isAdjacent(int $a, int $b): bool` | 相邻判定 |

## 最小用法：一个回合制房间

```php
// 片段：状态机 + 成员表，不要帧循环
$machine = new Machine(Room::WAITING);
$machine
    ->allow(Room::WAITING, "start", Room::PLAYING)
    ->allow(Room::PLAYING, "settle", Room::SETTLED);

$table = new MemberTable(4);          // 4 个席位，观战不限
$table->add($uid, Member::PLAYER);

$room = new Room($roomId, 4);         // Room 内部自己建 machine 与 table
```

**状态常量用 `Room::` 上现成的**（`WAITING=1` / `PLAYING=2` / `SETTLED=3` / `CLOSED=4`），
不要自己另造一套——`MemberTable`、`RoomManager` 都按这套约定工作。

`Room` 已经把两者组合好了，实际使用中更常见的是：

```php
// 片段
$manager = new RoomManager(RoomManager::MODE_SHARED, 50, 200);
$roomId = $manager->create(4, false);        // 4 人，不自动开帧
$manager->send($roomId, RoomManager::OP_JOIN, ["uid" => $uid]);
```

**注意 `create()` 的第二个参数**：回合制传 `false`，实时玩法传 `true`。这是全篇最容易
忽略、但影响最大的一个选择。

## 边界与坑

1. **`shared` 模式没有隔离**。同宿主里一个房间的慢帧会拖累其他 199 个。判断标准：
   房间里有物理、AOI、大量实体时，用 `dedicated`。

2. **席位号稳定，但成员表不保证顺序**。`occupants()` 是计数，遍历座位要按 seat 取，
   不要假设数组下标就是席位。

3. **`Machine::force()` 绕过所有校验**。调试用可以，业务代码里用它会掩盖状态机设计错误。

4. **房间号不能回收**。如果你自己实现房间号分配，务必保留这个性质——它会进幂等键。

5. **别自己造状态号/操作号**。`Room` 有 `WAITING/PLAYING/SETTLED/CLOSED`，
   `Member` 有 `PLAYER/NPC/SPECTATOR`，`RoomManager` 有 `OP_JOIN/OP_LEAVE/OP_START`。
   自己另编一套不会报错，但和框架的内部判断对不上，表现为"操作发出去没反应"。

6. **`neighbors()` 包含自己**。调用方自己排除，因为在这里判断"是不是我"要多一次比较，
   并在某些实现里触发数组复制。

7. **AOI 的 `neighborsShared()` 稀疏场景反而更慢**（实测 0.8×）。全散开的地图用逐个调用。

## 什么时候不该用 room

这一节比上面所有 API 都重要。**装错包的代价是后面每个玩法都要绕开它。**

| 你的情况 | 该用什么 |
|:--|:--|
| 只有状态机（订单、登录流程、战斗单位） | `pkg/fsm` 的 `Machine`，不要装 room |
| 只要"谁在里面"（公会成员、队伍） | `MemberTable`，不要 `Room` |
| 纯排队（匹配池） | 自己写队列 + `Machine`；room 的席位概念在这里是多余的 |
| 无房间概念（开放世界、大世界分区） | 一个 `Service` 就是一片区域，不需要 Room |
| 单机 / 无状态服务（GM 后台、配置服务） | 完全不需要玩法层 |
| 房间里有物理/AOI/大量实体 | 用 room 但要 `dedicated` 承载 |

**框架核心不依赖任何 pkg**（`tsf lint` 规则 4 强制保证）。所以装不装 room，核心行为
完全一样——这个决定权在你，而且随时可以改。

## 两个小包

`pkg/match`（298 行）和 `pkg/entity`（272 行）较小，本篇不展开：

- **`match`**：`MatchQueue` + `MatchService`，做"凑人开一局"。如果你的匹配规则复杂
  （分段位、分模式、跨服），建议自己写——这个包只覆盖最基础的同分匹配。
- **`entity`**：`Entity` + `EntitySet`，带属性的实体集合。**它不含 ECS**——
  `tsf/README.md` §十一 有实测结论：ECS 在这个运行时里得不偿失。
