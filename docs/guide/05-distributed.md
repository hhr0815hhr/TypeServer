# 05 · 分布式：RPC、发现、分片、集群、熔断

> 本页代码块标 `片段` 的只讲结构；完整可运行的示例在 [07 篇](07-walkthrough.md)。

## 先读这一节：什么时候**不该**拆

单进程性能：**稳态 116 万 msg/s**（单 worker）。绝大多数游戏直到关服都不需要多节点。

拆成多节点的代价不是"配置变复杂"，是**多出一整类问题**：

| 多出来的问题 | 表现 |
|:--|:--|
| 跨节点一致性 | 两个节点各算一次，结果不同，谁对？ |
| 服务发现 | 节点 A 不知道 B 的地址，或知道的是过期的 |
| 网络分区 | A 认为 B 挂了，B 其实活着且还在处理消息 |
| 故障传播 | 一个慢节点拖垮整条链路（所以要熔断） |
| 排查成本 | 一个请求跨三个进程，日志要对时 |

**拆分的正当理由只有四个**：

1. CPU 打满（单核跑不动帧逻辑）
2. 内存不够（所有玩家的状态放不下一台机器）
3. 需要独立扩缩容（战斗服和登录服负载差异大）
4. 需要故障隔离（一个玩法崩了不能带走全部）

任一条不满足就**不要拆**。这不是保守——`05` 这一层用不上，一点都不影响单机跑。

## 核心概念

### 地址：8 位节点 + 24 位服务

```php
// 片段
Addr::pack(int $node, int $id): int   // node 占高 8 位，id 占低 24 位
Addr::node(int $addr): int
Addr::id(int $addr): int
Addr::isLocal(int $addr): bool
```

| 位宽 | 上限 |
|:--|:--|
| 节点 8 位 | **256 个节点** |
| 服务 24 位 | 每节点 **1600 万个服务** |

**256 个节点是硬上限**。超过要改 `NODE_BITS`，而它影响所有持久化的地址——是一个不可逆的
决策点，上线前想清楚。

同一个 `send()` 对本地和远程写法一致：`Addr::isLocal()` 判断，本地直投、远程走 `Cluster`。

### Cluster：节点间链路

```php
// 片段
$cluster = new Cluster($nodeId, "/tmp/node1.sock");
$cluster->listen();
$cluster->connect($peerNodeId, "/tmp/node2.sock");
```

节点间用 UDS（Unix domain socket），一条链路。`send()` 把消息发到对端节点；
`poll()` 取出收到的消息并驱动投递。

这是**点对点全连**，没有中心。所以节点数上限是 256——再多全连的链路数会爆（256 个节点
理论上需要 3.2 万条链路）。

### RPC：异步回调，不是同步阻塞

```php
// 片段
$id = $client->call($addr, "getGold", "1001", function (?string $resp, string $err): void {
    if ($err !== "") {
        // 失败：超时、对端错误、bad-addr、too-many-pending
        return;
    }
    // 成功，$resp 有值（字符串，由 codec 决定内容格式）
}, 200);   // 200ms 超时

if ($id === 0) {
    // 根本没发出去：地址为 0，或待发表满
}
```

三个必须知道的语义：

1. **`$cb` 一定会被调用一次**（除非进程中途挂了）。调用方不需要自己兜底"回调永远不来"——
   超时到期时框架会以 `$err` 非空的形式回调。
2. **返回值 0 表示没发出去**，此时回调**已经同步执行过**了（`bad-addr` / `too-many-pending`）。
3. **没有同步等待**。`call()` 立即返回，结果通过回调给出——单线程调度模型里同步等会
   阻塞整个 worker。

`pendingCount()` 看在飞请求数，`stats()` 看累计失败/超时。

### 发现：文件注册表

`FileRegistry` 用文件系统做注册表：每个节点把自己的地址写一个文件，其他节点读目录。

为什么不用 etcd/consul：**节点数 ≤ 256、变更频率极低（只有扩缩容）、且要求零外部依赖**。
文件注册表在这个规模下够用，而且宕机时不会因为没有依赖而无法启动。

### 分片：一致性哈希 + 负载感知 + 熔断

`Shard\Router` 负责"把 key 映射到节点"：

| 组件 | 作用 |
|:--|:--|
| `Ring` | 一致性哈希环，节点增减时只迁移相邻段 |
| `inFlight(int $nodeId)` | 该节点在途请求数，用于避开拥塞节点 |
| `breaker()` | 熔断器，节点连续失败时直接拒绝 |
| `refresh()` | 重新加载节点表 |

**三者组合的效果**：ring 决定"该去哪"，inFlight 决定"现在去不去"，breaker 决定"还能不能去"。

### 熔断：指数退避

```php
// 片段
new CircuitBreaker(int $threshold = 3, int $baseCooldownMs = 2000, int $maxCooldownMs = 30000)
```

连续 `threshold` 次失败后打开；冷却时间从 `baseCooldownMs` 开始**翻倍**，上限
`maxCooldownMs`。`allow($key, $nowMs)` 返回现在能不能发，`ok()` / `fail()` 上报结果。

冷却翻倍的意义：一个真的挂了的节点，不应该每 2 秒被打一次——那样恢复瞬间会被压垮。

## 公开 API

### Addr

| 方法 | 用途 |
|:--|:--|
| `pack(int $node, int $id): int` | 拼地址 |
| `node($addr) / id($addr)` | 拆地址 |
| `isLocal($addr): bool` | 是否本节点 |
| `label($addr): string` | 可读形式（日志用） |

### Cluster

| 方法 | 用途 |
|:--|:--|
| `new Cluster(int $nodeId, string $path, ?PayloadCodec $codec = null)` | |
| `listen(): bool` / `connect(int $node, string $path): bool` | 建链 |
| `send(int $dst, int $srcLocalId, int $msgId, $data, int $session = 0): bool` | 发到别的节点 |
| `poll(): array` | 取收到的消息 |
| `hasPeer($node) / peerNodes()` | 查询 |

### Rpc\Client

| 方法 | 用途 |
|:--|:--|
| `new Client(int $defaultTimeoutMs = 200, ?RpcCodec $codec = null)` | |
| `call(int $addr, string $method, string $body, \Closure $cb, int $timeoutMs = 0): int` | 异步调用；回调签名 `(?string $resp, string $err)`；返回 0 = 没发出去 |
| `pendingCount(): int` / `stats(): array` | 观察 |
| `onStart() / onMessage()` | 它是 `Service`，这两个是生命周期钩子 |

### Shard\Router / Breaker

| 方法 | 用途 |
|:--|:--|
| `$router->ring() / breaker() / refresh()` | 组件与刷新 |
| `$router->inFlight($nodeId) / decInFlight(...)` | 负载观察 |
| `$cb->allow($key, $nowMs): bool` | 能否发 |
| `$cb->ok($key) / fail($key, $nowMs)` | 上报结果 |
| `$cb->state($key, $nowMs): int` | 当前状态 |

## 边界与坑

1. **256 节点是硬上限**。`NODE_BITS = 8`，改它意味着所有地址编码变化——持久化过地址的数据
   全部失效。

2. **RPC 是异步的**。写完 `call()` 就往下走了，回调可能在几毫秒后、也可能在超时后。
   需要"等结果再继续"的逻辑必须拆成状态机，不能在原地等。

3. **`$err` 非空不代表对端一定没执行**。超时的语义是"我不知道结果"——对端可能已经
   扣了钱只是回包慢了。所以跨节点写操作必须幂等（用请求 id 去重），不能靠超时判断成败。

3. **熔断的 key 是节点，不是接口**。一个节点上某个接口出问题会熔断整个节点——如果
   你有"慢接口"和"快接口"混部，考虑拆成不同节点。

4. **文件注册表依赖共享文件系统**。跨机器部署时 `/tmp` 不共享，需要挂 NFS 或改用其他
   `Registry` 实现。

5. **`inFlight` 只是观察值，不是限流**。它由调用方自己增减免（`decInFlight`），
   漏减会让节点永远显示拥塞。

6. **一致性哈希只保证"节点增减时少迁移"**，不保证均匀。热点 key 仍会集中到一个节点，
   需要业务侧做 key 打散。

## 什么时候不该用它

| 情况 | 结论 |
|:--|:--|
| 单机 CPU/内存没打满 | **不要拆**。116 万 msg/s 足够大多数游戏 |
| 只做登录/支付/配置（无状态） | 用 `Rpc` 即可，不需要分片和房间归属 |
| 玩家之间不交互（单机玩法） | 每个玩家一个进程，不需要 Cluster |
| 玩家数 < 1 万 | 单进程 + 多 worker 足够，多节点只会增加故障面 |
| 只是为了"看起来专业" | 最贵的错误。每多一层，排查成本翻倍 |

**一个判断方法**：如果你说不出"拆了之后哪个具体指标会改善"，就不要拆。
