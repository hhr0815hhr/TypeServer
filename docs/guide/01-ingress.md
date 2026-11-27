# 01 · 接入层：传输、网关、协议

## 它解决什么问题

客户端连进来，是一串 TCP 字节。要变成"某个玩家点了技能"，中间隔着三件事：

1. **粘包**：TCP 是字节流，一次 `read` 可能拿到半个包或三个包——必须靠分帧切
2. **未鉴权连接**：谁都能连 9001，不能让他不登录就发业务消息
3. **慢客户端**：一个不发数据的连接能占住整个 worker

这三件事分别对应本层的三个模块：

| 模块 | 职责 | 没有它会怎样 |
|:--|:--|:--|
| `Transport` | 监听端口、收发字节、worker 管理 | 连不上 |
| `Protocol` | 分帧 + 消息体编解码 | 粘包错乱、无法识别消息 |
| `Gateway` | 会话、鉴权、限流、心跳、背压、路由 | 裸奔，任何一个连接都能打垮进程 |

## 核心概念

### 协议是两层，可独立替换

```
字节流 →[frame 分帧]→ 一帧 →[payload 解码]→ 消息体 → 业务 handler
```

| 层 | 可选值 | 说明 |
|:--|:--|:--|
| `frame` | `native`（默认）/ `header` | `native` 是 C++ 实现；`header` 是纯 PHP，慢但排障时能打断点看字节 |
| `payload` | `raw`（默认）/ `json` / `msgpack` / `protobuf` / `registry` | `raw` 表示消息体原样透传，由业务自己解析 |

**换协议只改配置，不改代码**：

```json
// config/tsf.json
"protocol": { "frame": "native", "payload": "raw" }
```

### Gateway 是必经之路

`Transport` 能直接收发帧（`$server->sendFrame()`），但**业务处理器一律走 Gateway**：

```php
// 对：经过 Gateway，发送失败会计数、会触发背压
$gw->send($fd, Msg::ECHO_RESP, $body);

// 错：绕过了失败统计，慢客户端不会被断开
$server->sendFrame($fd, Msg::ECHO_RESP, $body);
```

差别在于：`Gateway::send` 返回 `bool` 并累加该连接的失败次数，连续失败到
`setSendFailLimit` 就**主动断开**这个 fd。绕过它，一个不收数据的客户端会一直
占着缓冲区，直到 worker 内存被吃满。

### 工厂方法是为了 AOT 类型稳定

```php
$server = ServerFactory::make($transport, $host, $port, $frame, $opts);
```

为什么不直接 `new TcpServer(...)` / `new WsServer(...)`？因为 AOT 下局部变量的类型由
**首次赋值**推断，这样写编译不过：

```php
if ($t === "ws") {
    $server = new WsServer(...);   // $server 被定成 WsServer
} else {
    $server = new TcpServer(...);  // ✗ Cannot re-assign typed object
}
```

`ServerFactory::make()` 的返回类型是基类 `Server`，两种情况都是它，类型稳定。
**这类"为了编译而改写"的模式在本框架里反复出现，认出来能少走很多弯路。**

## 公开 API

### Transport

| 方法 | 用途 | 注意 |
|:--|:--|:--|
| `ServerFactory::make($transport, $host, $port, FrameCodec $frame, array $opts): Server` | 建服务 | `$transport` 为 `"tcp"` 或 `"ws"` |
| `$server->start()` | 阻塞运行 | 之后不再返回 |
| `$server->onFrame(callable)` | 直接收帧（绕过 Gateway） | **只用于压测** |
| `$server->sendFrame($fd, $msgId, $body)` | 直接发帧 | 生产请用 `$gw->send()` |

`$opts` 常用键：`worker_num`、`max_conn`、`nodelay`。

> **`worker_num` 只能配 1（有状态服务）。** 多 worker 不是"分片"而是"复制"：
> 每个 worker 各有一份 Service 实例、各一套 `interval` 定时器、各一份内存状态。
> 实测（2026-11-27）：`worker_num=2` 时 100 ms 的 tick 打了两次，两个 pid 各自
> 从 `n=1` 数起。后果是"定时任务执行 N 次"和"同房间玩家落在不同 worker 时互相看不见"。
>
> 扩容走**多节点**（不同 `node_id` + 不同端口 + Cluster 互连），不要调这个值。
> 纯无状态服务（只做 echo 转发）可以配多 worker；配了之后启动会打一条 WARN。

### Gateway

| 方法 | 用途 |
|:--|:--|
| `new Gateway($server, $sessions, $auth, $limiter, $heartbeat, $maxQueue, $requireAuth, $payloadCodec)` | 构造 |
| `on(int $msgId, \Closure $fn)` | 注册业务处理器 |
| `onDisconnect(\Closure $cb)` | 断线回调，**签名必须是 `(int $fd, int $uid)`**，两个参数都不能少 |
| `send(int $fd, int $msgId, $data, int $flags = 0): bool` | 回包 |
| `setSendFailLimit(int $n)` | 连续发送失败多少次断开 |
| `boot()` | 启动前初始化（必须在 `$server->start()` 之前） |
| `stats(): array` | 连接数、收发量、失败次数 |

配件：`SessionManager($heartbeatSec)` · `TokenAuth($tokenMap, $secret)` ·
`RateLimiter($perSec, $burst)`。

### 内置消息号（`TSF\Protocol\Msg`）

| 常量 | 值 | 用途 |
|:--|--:|:--|
| `LOGIN` / `LOGIN_OK` / `LOGIN_FAIL` | 1 / 2 / 3 | 握手 |
| `KICKED` | 4 | 被踢 |
| `PING` / `PONG` | 100 / 101 | 心跳 |
| `ERR` / `TOO_FAST` | 400 / 429 | 错误 / 限流 |

业务消息号从 1000 起（`tsf new` 的示例用 `ECHO_REQ`/`ECHO_RESP`）。

## 最小用法

`tsf new hello` 生成的 `main.php` 就是完整例子。抽掉配置读取，核心就这些：

```php
// 1) 协议栈：选两层实现
$proto = ProtocolStack::fromConfig("native", "raw");

// 2) 传输：工厂返回基类，类型稳定
$server = ServerFactory::make("tcp", "0.0.0.0", 9001, $proto->frame(), [
    // worker_num 只该配 1：多 worker 是复制不是分片（见上面的警告）
    "worker_num" => 1, "max_conn" => 10000, "nodelay" => true,
]);

// 3) 会话 / 鉴权 / 限流
$sessions = new SessionManager(30);          // 30s 空闲踢
$auth     = new TokenAuth(["dev" => "token-dev"], "secret");
$limiter  = new RateLimiter(50, 100);        // 50/s，突发 100

$gw = new Gateway($server, $sessions, $auth, $limiter, 30, 1000, true, null);
$gw->setSendFailLimit(50);

// 4) 业务：payload=raw 时载荷是 string
$gw->on(Msg::ECHO_REQ, function (int $fd, int $uid, string $body) use ($gw): void {
    $gw->send($fd, Msg::ECHO_RESP, $body);
});

$gw->boot();
$server->start();
```

跑起来：

```bash
tsf build . && ./hello
php85 client.php 9001
```

```
login ok uid=1001
resp msgId=1002 body=hello-tsf-1794822609
OK: echo matched
```

## 边界与坑

1. **handler 的载荷参数类型随 payload 变**。`payload=raw` 时第三个参数是 `string`；
   其他 payload 是 `string|array|object`。写错类型编译不过（AOT 没有隐式转换），
   而报错指向的是你的 handler 签名，不是配置——先确认 `protocol.payload` 再改签名。

2. **`payload=raw` 时不要给 Gateway 传 codec**。传了会每帧空转一次编解码。
   示例里的写法是刻意的：

   ```php
   $payloadCodec = $proto->payloadName() !== "raw" ? $proto->payload() : null;
   ```

3. **`gateway=off` 只用于压测对照**。它跳过会话、鉴权、心跳、限流——意味着任何连接
   都能发业务消息、且永不超时。生产环境不要关。

4. **`$gw->boot()` 必须在 `$server->start()` 之前**。顺序反了不会报错，但心跳定时器
   和会话清理不会启动，表现为"空闲连接永远不断"。

5. **心跳是"空闲多久踢"，不是"多久发一次"**。`SessionManager(30)` 表示 30 秒没收到
   任何帧就断开；客户端要在这之前发 `PING`。

6. **`onDisconnect` 的回调必须声明两个参数，且类型写 `\Closure`**。两个坑叠在一起：

   ```php
   // Gateway 内部是：$cb($fd, $uid)
   $gw->onDisconnect(function (int $fd, int $uid): void {   // ✓
       // ...
   });
   $gw->onDisconnect(function (int $fd): void { });          // ✗ 少一个参数
   ```

   少声明一个参数在 PHP 里语法合法、编译期也不报，但 AOT 下运行时直接崩，
   报 `stdClass->{closure}()` —— 这个报错和"参数个数不对"毫无关系，很难联想。

   同理，**参数类型要写 `\Closure` 而不是 `callable`**：`callable` 在 AOT 里是联合类型
   （`string|array|Closure|...`），赋给 `?\Closure` 属性时会被转成 `stdClass`，
   之后调用就报 `Invalid callback`。框架里 `on(int $msgId, \Closure $fn)` 一直正常，
   正是因为它用了精确类型。

## 什么时候不该用它

- **服务间内部调用**不需要 Gateway（没有不可信客户端）。走 `Rpc`，见 05 篇。
- **只需要一个 HTTP 接口**（如健康检查、回调）不必开 `ws`，用 TCP + 简单消息号即可，
  或者直接在 GM 后台里做（06 篇）。
- **单机小工具**不需要限流和鉴权，但**开着也没坏处**——它们只在有连接时才分配。
