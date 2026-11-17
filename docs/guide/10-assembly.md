# 10 · 总装：把零件拼成一个能跑的业务

> 前面九篇讲的是零件。这一篇是**总装**——用一个 600 行的挂机游戏
> （`demo/idle`），把 protobuf、db、redis、mail、Service 串起来，
> 记下零件之间那些**缝**。
>
> 缝不在零件里，所以零件级的测试永远抓不到。

## 为什么"零件全绿"不够

做这个 demo 之前的状态：五个 pkg、168 个断言全过、每个都有真环境验证。

第一次总装，撞了五个问题。其中三个**单元测试永远抓不到**：

| 问题 | 为什么单测抓不到 |
|:--|:--|
| proto 生成类的属性名是下划线 | 单测直接用属性名，不会写错 |
| `Service::send($fd)` 其实是服务地址 | 单测里没有 Gateway，不会走这条路径 |
| 配置缺失时静默用默认值 | 单测直接 new，不读配置 |

**它们的共同点：只在"真实服务 + 真实客户端"的边界上出现。**

## 一、数据建模：先确定"真相在哪"

一个业务实体，三个决定要先做：

**① 主键由业务分配，不用自增**

```php
#[Id]                       // ✓
public int $uid = 0;

#[Id(true)]                 // ✗ 分片场景会算出重复 id
```

`Repo` 用主键算分片。自增主键在各分片都从 1 开始，结果是"同一个玩家在两个库里各有一份"——**要等数据对账才发现**。

**② 时间字段用 BIGINT 存 unix 时间戳**

不用 `DATETIME`，两个理由：

- 跨库行为一致（sqlite / mysql / pg 的 DATETIME 格式和时区处理都不同）
- `0` 天然表示"未读 / 未领 / 永不过期"，不需要额外的 null 语义

**③ 不存派生状态**

挂机游戏的"待领取金币"有两种存法：

```php
// ✗ 存 pending：每次产出要 +，每次领取要清零
public int $pending = 0;

// ✓ 只存 lastTick：要用的时候现算
public int $lastTick = 0;
```

存 `pending` 就要维护"什么时候加、什么时候清"，**漏一次永久错位**。现算则状态只有一个字段，离线在线同一套公式，不可能不一致。

> 通用原则：**能从已有字段算出来的，就不要存第二个字段。**
> 两个字段就有两套更新时机，就有不一致的可能。

## 二、消息层：protobuf 的五个坑

这一节全部是实测踩出来的。用 JSON 的话前三个不适用，但后两个一样。

### 坑 1：每个 message 都要 `@id`，包括嵌套类型

```proto
// @id 3006
message RankResp {
  repeated RankItem items = 1;
}

// @id 3101        ← 没有这行，生成器直接报错
message RankItem {
  int32 uid = 1;
}
```

`RankItem` 只被 `repeated` 引用、不会单独收发，但生成器要求它也有 id。

### 坑 2：不允许空 message

```proto
// @id 3003
message CollectReq {
  int32 seq = 1;    // ← 一个字段都没有会报"消息一个字段都没有"
}
```

无参数的请求也要放个占位字段。**代价是客户端要多填一个没用的 0**，但比"生成器不支持"好。

### 坑 3：生成类的属性名保留下划线

```proto
int32 rate_per_minute = 5;
```

```php
$resp->rate_per_minute = 10;   // ✓
$resp->ratePerMinute = 10;     // ✗ 变成 dynamic property，值被丢掉
```

**症状极难查**：不报错（只有一条 deprecation 警告），字段一直是默认值 0。看到"字段明明赋了值，客户端收到 0"，先怀疑这里。

> 想让字段名是驼峰，就在 proto 里写驼峰（`ratePerMinute`）——
> 生成器**原样保留**，不会转换。

### 坑 4：`Service::send()` 的第一个参数不是 fd

```php
// ✗ 静默失败：客户端收不到任何东西，服务端不报错
$this->send($fd, $msgId, $resp);

// ✓ 构造注入回包闭包
public function __construct(\Closure $publish) { $this->publish = $publish; }
($this->publish)($fd, $msgId, $resp);
```

`Service::send(int $to, ...)` 的 `$to` 是**服务地址**（Scheduler 的寻址空间）。fd 和 addr 都是小整数，**数值上可能撞**，语义完全不同。

`demo/mmo-mini` 就是这么做的（`__construct(\Closure $publish)`），我当时没注意，绕了一圈。

### 坑 5：`payload: protobuf` 不是"自动找类"

它需要显式注册每个协议的编解码：

```php
$payload = $proto->payload();   // RegistryPayload
$payload->register(
    Msg::LOGIN_REQ,
    fn($m) => $m->encode(),
    fn($b) => Codec::decode(Msg::LOGIN_REQ, $b)
);
```

漏注册的协议号会在 `pack`/`unpack` 时抛异常（`未注册编码器 msgId=xxx`）——这个报错是清楚的，算是唯一不坑的地方。

## 三、Service 与 Gateway 的边界

全景是这样：

```
客户端 ──fd──> Gateway（连接、鉴权、限流、心跳）
                  │
                  │ deliver(addr, msgId, data, fd)
                  ↓
              IdleService（业务状态、db/redis）
                  │
                  │ publish 闭包 → gw->send(fd, ...)
                  ↓
客户端 <──fd─── Gateway
```

**两个寻址空间的转换是这层的核心**：

| | 用的是什么 | 谁关心 |
|:--|:--|:--|
| 连接 | `fd`（int，连接号） | Gateway |
| 服务 | `addr`（int，Scheduler 编址） | Service |

**跨边界时用 `Message::session` 带 fd**（`deliver($addr, $msgId, $data, $fd)`）—— 不要像某些 demo 那样 `json_encode(["uid".., "fd".., "p"..])` 包一层：

- protobuf 的载荷是 `Message` 对象，`json_encode` 得到 `{}`，信息全丢
- `session` 本来就是给"关联标识"用的，类型稳定（int）

## 四、时间与结算：零头最容易被吃掉

挂机游戏的收益结算，有一种**写对了才不亏玩家**的写法：

```php
$got = intdiv(min(now - lastTick, capSeconds), secPerCoin);
$coins += $got;
$lastTick += $got * secPerCoin;    // ← 关键：不是 = now
```

如果写成 `$lastTick = now`：

| 时刻 | 操作 | 结算 | 实际收益 |
|:--|:--|:--|:--|
| 0s | lastTick=0 | — | — |
| 5s | 收取 | intdiv(5,6)=0 | **0** |
| 6s | 收取 | intdiv(6,6)=1 | 1 |

**第 5 秒那笔白等了 5 秒。** 玩家每收一次就丢一次零头，一天下来少拿几百金币。

推进 `got * secPerCoin` 则把零头留到下次：

| 时刻 | lastTick | 结算 | 剩余零头 |
|:--|:--|:--|:--|
| 5s | 0 → 0 | 0 | 5s |
| 6s | 0 → 6 | 1 | 0s |

**上限**也要有（`capSeconds = 8 小时`）：否则玩家一年不上线，一次领一年的产出。

## 五、跨组件一致性：有些不一致是故意的

`IdleService` 里，收取金币的顺序是：

```php
$repo->update($p);        // 先写 db
$this->rankUpdate($p);    // 再写 Redis
```

中间挂掉，**db 是新值、榜是旧值**。

**demo 故意不修**，因为修法都有代价：

| 方案 | 代价 | 适合 |
|:--|:--|:--|
| 定时对账（每分钟全量刷榜） | 榜最多滞后 1 分钟 | 实时性要求低（小时榜、周榜） |
| 写 db 成功 → 写 Redis，失败进重试队列 | 要维护队列和重放 | 实时榜 |
| Redis 为准，db 存快照 | db 不再是真相，其他功能要改 | 榜是核心玩法 |

**关键是先意识到"这里有取舍"，而不是假装它不存在。**

> 凡是有两个存储的地方，就有一致性问题。
> 不需要每次都用事务解决，但必须知道自己选了哪种不一致。

## 六、一个业务上线前该验什么

按这个 demo 的经验，清单是这样：

| 项 | 怎么验 | 这次的结果 |
|:--|:--|:--|
| 协议往返 | 客户端完整走一遍每个消息 | ✅ protobuf 全通 |
| 数据落库 | 重启进程后数据还在 | ✅ |
| 离线/断线 | 断开重连，状态对不对 | ✅ 收益按 lastTick 补上 |
| 幂等 | 同一请求发两次 | ✅ 邮件领取第二次拿不到 |
| 上限 | 边界值（8 小时 cap） | ⚠️ 代码有，没构造极端用例 |
| 跨组件一致 | 挂掉重来，两个存储对不对得上 | ⚠️ 已知会不一致，未修 |

**最后两行是"知道但没做"，这比"不知道"好，但比"做了"差。**

## 最后一句话

> **零件级的"通过"是必要条件，不是充分条件。**
>
> 五个 pkg、168 个断言全绿，第一次总装还是撞了五个问题——
> 因为那些问题不在零件里，在**零件之间**。

所以：**每加一个 pkg，都应该有一个 demo 把它用起来。** 不然它只是"能编译、能单测"，不是"能用"。
