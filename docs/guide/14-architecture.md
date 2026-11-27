# 14 · 工程组织：一个游戏服该怎么长

**一句话**：`tsf new` 给你的是 164 行的单文件。它能跑，但它不是"该怎么组织"的答案。
这篇讲的是从 500 行长到 1 万行时，目录怎么分、分层怎么划、哪一层能依赖哪一层。

---

## 它解决什么问题

框架把"零件"讲清楚了（13 篇指南），但没有讲"整机怎么摆"。

结果是每个人的 `src/` 都长得不一样：

| 常见形态 | 什么时候开始疼 |
|:--|:--|
| 全部塞进 main.php | 600 行以后：改一个技能要滚过登录、路由、仓库初始化 |
| 按功能平铺（`login.php` / `battle.php` / `bag.php`） | 2000 行以后：`battle.php` 里出现了 DB 调用，想单测就得起 MySQL |
| 抄了一个 demo 的 `src/Battle/` | 第二个领域进来时：不知道 `src/Player/` 该不该 import `Battle` |

这篇要给的是一套**能长期用的约定**，不是"唯一正确解"。核心只有两条：

1. **依赖方向单向**：外层可以依赖内层，内层不知道外层存在。
2. **纯逻辑单独一层**：不碰网络、不碰 DB 的那部分要能单独跑起来测。

原文见 `demo/battle`（2038 行的完整示例，本文所有规则它都遵守）。

---

## 目录长什么样

一个到 1 万行规模的服务，目录大概是：

```
mygame/
├── main.php                入口：只做装配，不写业务（见下）
├── project.yml             AOT 编译配置（tsf 生成）
├── config/
│   ├── tsf.json            框架配置：端口、集群、日志、鉴权
│   └── game/               业务配置（表驱动，热更友好）
│       ├── skills.php
│       └── items.php
├── src/
│   ├── Protocol/           协议：消息 ID、编解码。只依赖 TSF
│   │   ├── Cmd.php
│   │   └── Codec.php
│   ├── Domain/             纯逻辑：规则、状态机、数值。不依赖任何外部
│   │   ├── Battle/
│   │   │   ├── Battle.php
│   │   │   └── Combatant.php
│   │   └── Player/
│   │       └── Profile.php
│   ├── App/                用例编排：调 Domain + Infra。这是 Service 所在层
│   │   ├── RoomService.php
│   │   └── PlayerService.php
│   ├── Infra/              外部世界：DB / Redis / 文件 / 时钟
│   │   ├── Repo/
│   │   │   └── PlayerRepo.php
│   │   └── Cache/
│   │       └── SessionStore.php
│   └── Support/            工具：Addr、随机、序列化
│       └── Ids.php
├── tests/                  单测（**不需要网络**，`php85 tests/run.php` 就能跑）
└── var/                    运行时产物：日志、快照、crash dump
```

小项目不必一次到位。第三节讲什么时候拆。

---

## 四层：谁可以依赖谁

```
        ┌─────────────┐
        │  main.php   │  装配：读配置、建 Server、注册 handler
        └──────┬──────┘
               │ 依赖所有
   ┌───────────┼───────────┐
   ▼           ▼           ▼
┌──────┐   ┌──────┐   ┌──────┐
│ App  │──▶│Domain│   │Infra │
└──┬───┘   └──────┘   └──┬───┘
   │                      │
   └──────────┬───────────┘
              ▼
          ┌──────┐
          │ TSF  │
          └──────┘
```

**规则**（违反其中任何一条，都是后面要还的债）：

| 层 | 可以依赖 | 绝对不能 |
|:--|:--|:--|
| `Domain` | **什么都不能**（连 TSF 都不该 import） | DB、Redis、Scheduler、网络 |
| `App` | Domain、Infra、TSF 的 `Service` | 直接写 SQL、直接 `fwrite` socket |
| `Infra` | TSF 的 `pkg/db`、`pkg/eventlog` 等 | Domain（仓库不该知道业务规则） |
| `Protocol` | TSF 的 `Protocol`、`Proto` | Domain、App |
| `main.php` | 所有 | ——（它只做装配） |

**为什么 Domain 必须"什么都不依赖"**：它是唯一能**毫秒级单测**的部分。
一个 `Battle::resolve()` 如果里面 `new Redis()`，那这个测试就要起 redis、要造数据、
要清库——最后没人写测试，规则全靠手测。

`demo/battle/src/Battle/SkillTable.php` 就是这一层：读配置、算伤害，不知道网络存在。

---

## 四类服务分别怎么写

### 1. 登录服（HTTP + token）

```
main.php            建 Http Server + 登录 handler
src/App/Login.php   校验账号 → 签发 token → 返回
src/Infra/Repo/AccountRepo.php
src/Protocol/Cmd.php
```

- HTTP 只做"拿 token"，之后的游戏流量走 TCP/WS 带 token
- 签发和校验**共用一份代码**（见 [10-login-service.md](10-login-service.md)）
- 登录服不持有玩家状态，它只发凭证

### 2. 房间服（一房一 Service）

```
src/App/RoomService.php   一个 Service 管一个房间（或 N 个房间的宿主）
src/Domain/Battle/        规则
src/Protocol/Cmd.php      房间内的消息
```

- **房间状态放 Service 的成员变量**，不放全局、不放静态
- 建/销毁通过消息驱动（`spawnOf` / `kill`），别在 tick 里随手 new
- 帧循环用 `interval(16)` 或 `pkg/frameloop`，别用 `while(true)`
- 房间结束时务必 `kill` 自己：否则 id 不复用、定时器泄漏

### 3. 世界服（单点 + 分片）

```
src/App/WorldService.php  排行榜、全服邮件这类"必须唯一"的东西
src/App/ShardService.php  按 uid 分片的玩家数据
```

- 世界服是**单点**：它能成为瓶颈，但因为只有一个，逻辑最简单
- 排行榜用 `SplPriorityQueue` 或有序结构，别每帧全排序
- 需要横向扩展时用 `Shard`，按 uid 哈希到固定分片（分片数定了就不要改）

### 4. 后台 / GM

```
src/App/GmHandlers.php   只做参数校验和调用，不写业务逻辑
```

- GM 命令**必须审计**（`Gm\Server` 的 `op` 字段），不要裸写日志
- GM 走的路径和玩家路径要能复用同一份 Domain 逻辑，否则两边规则会分叉

---

## 从单文件长起来：三个阶段

不要一开始就摆好四层——**过早分层和过晚分层一样痛**。按规模走：

### 阶段一：< 600 行，单文件

就是 `tsf new` 给的形态。够用。**只做一个动作**：把数值挪进 `config/game/`，
不要写在代码里（`$damage = 100 * 1.5` 这种，改一次要重编译）。

### 阶段二：600 – 3000 行，先拆 Domain

第一个该拆出去的是**纯逻辑**，因为它最好测：

```
src/Domain/Battle.php     从 main.php 搬出来，去掉一切 IO
main.php                  只剩装配 + handler 壳
```

这一步做完的标志：**`php85 tests/run.php` 能在没有网络的机器上跑通规则测试**。

### 阶段三：3000 行以上，按域拆 Service

一个 Service 一个文件，`src/App/` 下按领域分组：

```
src/App/Battle/RoomService.php
src/App/Player/PlayerService.php
src/App/World/RankService.php
```

这时候才会出现"跨域调用"的需求。规则是：**域之间不直接调方法，发消息**。
`RoomService` 需要玩家数据时，发一条消息给 `PlayerService` 并 await 回包，
不要在 `RoomService` 里 `new PlayerRepo()`——那会让房间和数据库绑死。

---

## 测试怎么放

三层，成本递增：

| 层 | 放哪 | 依赖 | 跑一次 |
|:--|:--|:--|:--|
| 规则测试 | `tests/unit/` | 无 | 毫秒 |
| 服务测试 | `tests/service/` | 只起 Scheduler（进程内） | 百毫秒 |
| 端到端 | `tests/e2e/` | 真起 Server + 客户端 | 秒级 |

**关键约定**：`tests/unit/` 里**不允许**出现 `new Server`、`new Redis`、
`file_get_contents("http://...")`。一旦出现，这一层就失去意义了。

`demo/battle/bench.php` 是端到端那层的样子：起服务、连上去、发消息、断言。

---

## 七个常见错误

这些在真实项目里都见过，按出现频率排：

1. **Domain 里 `new Redis()`**
   后果：规则没法单测，最后全靠手测。
   改法：Domain 只接收数据（数组/对象），读写由 App 层负责。

2. **Service 之间直接 `new` 另一个 Service**
   后果：两个 Service 的 id 对不上，消息发不到；生命周期也纠缠。
   改法：用 `spawn("name")` 拿地址，用 `deliverAs` 发消息。

3. **在 `onMessage` 里做长循环**
   后果：整个进程卡住（实测一段 81 ms 的循环会把所有定时器拖到 87 ms，见 ROADMAP 第六节）。
   改法：拆成多条消息，一条处理一小批。

4. **配置写死在代码里**
   后果：改一个数值要重编译、重启。
   改法：`config/game/*.php`，`Config` 热更。

5. **房间结束时忘了 `kill`**
   后果：定时器泄漏 + id 不复用 + 内存慢慢涨（v0.14.1 修过一个同类问题）。
   改法：房间结束统一走一个 `close()`，里面 `kill($this->addr)`。

6. **handler 里直接 `$server->send()`**
   后果：绕过 Gateway 的会话/鉴权，也绕过了限流。
   改法：业务只产出"要发的消息"，由出口层统一发（`demo/battle/src/Battle/Output.php`）。

7. **把 `var/log`、`var/` 提交进仓库**
   后果：仓库里混进运行时产物，`git status` 永远是脏的。
   改法：`.gitignore` 加 `var/`。

---

## 上手清单

新建一个工程时，按这个顺序做：

1. `tsf new mygame --layout=layered` → 生成分层骨架，跑通 echo（确认工具链没问题）
2. `php85 tests/run.php` → 跑 Domain 的单测（**不需要编译**，这是分层的第一个回报）
3. 把数值挪进 `config/game/`，`config/tsf.json` 里关掉 `auth.allow_legacy`
4. 写第一个 `src/Domain/` 类，配一条 `tests/unit/` 断言
5. 加 `src/App/` 的 Service，`main.php` 只加装配代码
6. 上线前对照 [13-production.md](13-production.md) 过一遍：`setWatchdog`、
   `setMemoryPressure`、`setSlice`、日志轮转、TLS

### `--layout=layered` 生成什么

```
mygame/
├── main.php                    装配（约 130 行，只调函数）
├── config/{tsf.json, game/}
├── src/Protocol/{Cmd, EchoTask}.php
├── src/Domain/Counter.php      纯逻辑 + 5 条规则断言
├── src/App/EchoService.php     Service 的写法（状态在成员变量）
├── src/Infra/README.md         这一层该放什么（目录还没建，先讲清楚）
├── src/Support/README.md
├── tests/run.php
├── tests/unit/CounterTest.php
└── README.md                   工程自己的规矩清单
```

它**可编译、可跑通、单测能过**：`tsf build .` 之后起服务、跑 `php85 client.php`
能看到 `OK: echo matched`；`php85 tests/run.php` 不编译就能跑 Domain 的断言。

不带 `--layout`（默认 `flat`）时行为一字不变，仍是那个单文件 `main.php`。
**小服务就该用 flat**：164 行能说清的事分四层是负担，分层是给 2000 行以上准备的。

---

## 参考实现

| 想看什么 | 看哪 |
|:--|:--|
| **四层拆分 + 纯逻辑单测**（3490 行） | `demo/ddz-hall/`：`src/{Domain,App,Protocol,Infra}`，Domain 72 条断言、不需要编译 |
| 填满之后长什么样（2038 行） | `demo/battle/`（`src/Battle/` 五个文件 + `main.php` 装配） |
| 一局斗地主的全流程 | [07-walkthrough.md](07-walkthrough.md) |
| 最小能跑的 echo | `demo/demo-echo/` |
| 从零件到整机的五个"缝" | [09-assembly.md](09-assembly.md) |

`battle` 和 `ddz-hall` 是两种参照：前者是"业务做大之后自然形成的分层"，
后者是"从第一行就按四层写"。看 `main.php` 的差别最明显——battle 的装配里
还留着不少业务判断，ddz-hall 的只有函数调用。
