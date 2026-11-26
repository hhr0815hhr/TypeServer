# 13 · 上线闭环：停机、日志、TLS、集群自愈

> 前面 12 篇讲的是"怎么把服务写出来"。这一篇讲的是**写出来之后，它能不能像一个正常的线上服务那样停下来、写日志、加密、断线自己恢复**。  
> 这四件事在 2026-11-25 之前都没有：进程收到 SIGTERM 什么也不做、日志没有上限、网关只有裸 TCP、集群断链之后永久失联。

## 一句话对照

| 场景           | 以前                              | 现在                               | 怎么用                                               |
| :----------- | :------------------------------ | :------------------------------- | :------------------------------------------------ |
| 进程收到 SIGTERM | 等满 3 秒被强杀，`Service::onStop` 不执行 | 排空 → 逆序 `onStop` → 收尾钩子，全部执行完才退出 | 什么都不用做；要调时长用 `max_wait_time` / `setDrainMs()`     |
| 日志           | 单文件无限追加                         | 单文件 64 MB × 5 份；GM 审计单独一个文件      | `log.max_bytes` / `log.max_files` / `log.gm_file` |
| 客户端连接        | 明文 TCP / WS                     | 配证书就是 TLS / WSS，明文连接直接被拒         | `ssl_cert_file` + `ssl_key_file`                  |
| 集群对端重启       | 永久失联                            | 指数退避自动重连                         | `Cluster::watchPeer()` 代替 `connect()`             |
| 网关装配         | 每个 `main.php` 手写五件套，准入策略没地方配    | `Gateway::assemble()` 一次装好       | 第六、七节                                             |
| GM 操作人       | 共享 token，操作人靠请求头自报              | 一人一个 token，操作人来自 token           | 第九节                                               |

四件事都有回归盯着：`tests/shutdown-guard`、`tests/log-rotate`、`tests/tls-guard`、`tests/cluster-reconnect`。

---

## 一、停机：SIGTERM 之后会发生什么

```
SIGTERM
  │
  ├─ Swoole 让 reactor 再跑 max_wait_time 秒（在途请求照常处理）
  │
  └─ WorkerStop 回调（框架在这里接管）
       1. Server::onShutdown() 里登记的钩子        ← 服务还活着，可以给玩家发"服务器即将重启"
       2. 停掉所有定时器，排空就绪队列（有预算）   ← 已经收到的请求尽量处理完
       3. 逆着创建顺序逐个调 Service::onStop()     ← 房间依赖世界，所以先停房间
       4. Scheduler::onShutdown() 里登记的钩子     ← 所有服务都停了，做进程级落盘
       5. 冲掉还没写出的回包和跨节点消息
```

**能保证的**：`onStop` 和收尾钩子一定被调用，单个抛异常不影响别的服务；排空有上限不会卡死。  
**不能保证的**：打断一个卡住的 `onStop`——它是同步代码，卡住了整个 worker 就跟着卡住，只能靠外部（k8s 的 `terminationGracePeriodSeconds`）兜底。

```php
// 片段
$server->onShutdown(function (int $workerId): void {
    // 服务还活着：广播、冲队列都在这里
});

Scheduler::instance()->onShutdown(function (): void {
    // 所有服务都停了：关连接池、落最后一次快照
});

final class Room extends Service
{
    public function onStop(): void
    {
        // 这个房间自己的收尾：落盘、通知世界服"我走了"
    }
}
```

| 配置 / 方法                                    | 默认   | 说明                                                                                                   |
| :----------------------------------------- | :--- | :--------------------------------------------------------------------------------------------------- |
| `max_wait_time`（`TcpServer` 选项，秒）          | 3    | 收到信号后让在途请求跑多久。**它同时就是"发信号到退出"的最短时间**（只要有持久定时器在，这个窗口一定会等满）。想空闲进程秒退就设 0，实测退出从约 2.5 秒降到 20 多毫秒，停机流程照样完整 |
| `Server::setDrainMs(int $ms)`              | 1000 | 排空预算。0 = 不排空直接停，被丢掉的消息数会记在 `scheduler shutdown` 日志的 `dropped` 里                                      |
| `Scheduler::shutdown(int $drainMs): array` | —    | 幂等。返回 `drained / dropped / stopped / failed / slow / ms`                                             |

停机时日志里一定有一行 `ERRNO 9101 worker exit timeout, forced termination`。**它只说明"窗口到期时定时器还在"，不代表有东西没处理完**——以紧随其后的 `scheduler shutdown` 和 `worker stopped` 为准。

## 二、日志：轮转与通道

```json
{
    "log": {
        "level": "info",
        "file": "${base}/var/log/app.log",
        "max_bytes": 67108864,
        "max_files": 5,
        "gm_file": "${base}/var/log/gm.log"
    }
}
```

| 键           | 默认              | 说明                                                  |
| :---------- | :-------------- | :-------------------------------------------------- |
| `max_bytes` | 64 MB           | 单文件上限，超过就转成 `app.log.1`，最老的删掉。设 0 = 不转（磁盘上限没了，别这么做） |
| `max_files` | 5               | 保留几份。磁盘上限 ≈ (max_files + 1) × max_bytes             |
| `gm_file`   | 日志目录下的 `gm.log` | GM 审计单独落一个文件。设成空串 = 关闭（审计回到主日志）                     |

- 文件最多会超出上限约 1/16：大小检查是按"已经写了多少字节"计数的，攒够 `max_bytes / 16` 才去查真实大小（每行都 `filesize()` 比写日志本身还贵）。
- 多进程（master + 多个 worker）往同一个文件追加：追加靠 `O_APPEND` 原子，轮转靠 `app.log.lock` 上的排它锁，转出来的每一层都是满的。
- 只按大小、不按日期。

**具名通道**：`Logger::to("gm", ...)` 写到通道自己的文件，有自己的级别阈值——主日志调到 warn，审计记录不会跟着消失。通道没建过时落回主日志，不会丢。

```php
// 片段
Logger::initChannel("pay", "/var/log/pay_audit.log", Logger::INFO);
Logger::to("pay", Logger::INFO, "order paid", ["order" => $id, "uid" => $uid]);
```

GM 审计现在带操作人字段，取自请求头 `x-gm-operator`（自报，**不是鉴权**——GM 通道仍然只有一个共享 token）：

```bash
curl -X POST http://127.0.0.1:9701/gm -H 'x-gm-token: ***' -H 'x-gm-operator: zhangsan' -d '{"cmd":"player.add_gold","args":{...}}'
```

## 三、TLS / WSS

```php
// 片段
$server = new TcpServer("0.0.0.0", 9001, new HeaderFrame(), [
    "ssl_cert_file" => "/etc/tsf/server.crt",   // 证书链（PEM）
    "ssl_key_file"  => "/etc/tsf/server.key",    // 私钥（PEM）
]);
```

`WsServer` 同理，配了就是 `wss://`。

- **两个选项必须同时给**。只给一个、或者文件不存在：按明文启动并记一条 error。不会静默——运维以为加密了、端口其实是裸的，是最糟的结果。
- **明文连接会被握手直接拒掉**，不存在"协商降级到明文"。
- **证书必须由客户端校验**。客户端把 `verify_peer` 关掉的话，加密还在但中间人保护没了——游戏客户端至少要做证书固定。
- **集群内部（`Cluster`）不走这里**：它仍然是明文 + 握手 mac，不能暴露在不可信网络上。
- 性能：TLS 握手有成本，长连接摊下来可以忽略；短连接密集的服务要先量。

## 四、集群：断了会自己好

```php
// 片段
$cluster = new Cluster(1, "tcp://0.0.0.0:9301", new JsonPayload(), $secret);
$cluster->listen();

// 以前：connect() 只连一次，断了就永久失联
// 现在：登记要连通的对端，断了按指数退避重连
$cluster->watchPeer(2, "tcp://10.0.0.2:9301");
$cluster->watchPeer(3, "tcp://10.0.0.3:9301");
$cluster->setReconnect(500, 15000, 30000);   // 起始 / 封顶 / 多久算稳定（毫秒）
```



| 方法                                                            | 说明                                                                |
| :------------------------------------------------------------ | :---------------------------------------------------------------- |
| `watchPeer(int $node, string $path)`                          | 登记并立刻拨一次。**返回 true 不代表连上了**，可没用要看 `hasPeer()`                     |
| `forgetPeer(int $node)`                                       | 不再保持连通（已建立的链路留着，断了不再连）                                            |
| `connect(int $node, string $path)`                            | 仍是一次性的，老行为                                                        |
| `setReconnect(int $minMs, int $maxMs, int $stableMs = 30000)` | 退避 0.5 秒起、翻倍、封顶 15 秒、带抖动。链路稳定过（保持 ≥ stableMs）再断，退避从头算；没稳定过就断的继续翻倍 |
| `setConnectTimeout(int $ms)`                                  | 一次连接多久没动静算失败，默认 3000。**不可达地址只能靠它判定**                              |

**为什么是"退避重连"而不是"断了立刻重连"**：对端刚重启那几秒里立刻重连只会连续失败；退避让两端在最坏情况下也只需 15 秒就能重新对上，同时不会在对端长时间宕机时刷日志。

**拨号是非阻塞的**。对端机器关机、网络分区的时候，同步 `connect()` 会挡住整个 worker 最长 2 秒——而这里是 1ms 一轮的调度循环，所有服务跟着停。实测对不可达地址单次 `poll()`：同步 2,001 ms，非阻塞 0.8 ms。

**两端同时互拨**：双方各自发起、同时到达时，按节点号裁决，只留节点号小的一方发起的那条。否则两条都会被对端关掉。

**谁该被 watch**：静态拓扑直接写；动态的，把注册表（`pkg/registry`）的成员变更喂给 `watchPeer()` / `forgetPeer()`。Cluster 自己不去查注册表——核心不依赖可选包。

## 五、服务 id 回收

24 位服务 id 原来只增不收：16,777,215 次 spawn 之后这个进程永久不能再建服务。对"一局一房间服"的游戏，按每秒开 10 个房间算约 19 天——到期表现是"新房间开不出来"，只能重启。

现在是**单调递增、到顶回绕**：一个 id 要隔一整轮地址空间才会被复用（每秒 1000 次 spawn 约 4.6 小时），外加 5 秒的最小宽限期兜底。代价是绕回之后同一个 id 会是另一个服务——**需要长期持有对方地址的地方，应该按名字重新解析，而不是把地址存起来**。`stats()["ids_left"]` 是还没用过的 id 数，接近 0 说明快回绕了。

## 六、连接准入：`Gateway::assemble()`

以前每个应用的 `main.php` 都手写五件套（会话 / 鉴权 / 限流 / 网关），漏一个不少一层防护，后来加的准入策略也没地方配。  
现在一个入口全部装好，值都读 `server.*`：

```php
// 片段
$gw = Gateway::assemble($server, $payloadCodec);   // 第二个参数在 payload=raw 时传 null
$gw->on(Msg::ECHO_REQ, function (int $fd, int $uid, string $body) use ($gw): void { /* ... */ });
$gw->boot();
```

| 策略         | 配置                                                              | 默认            | 说明                                                          |
| :--------- | :-------------------------------------------------------------- | :------------ | :---------------------------------------------------------- |
| 登录时限       | `login_timeout_sec`                                             | 30            | 连上之后这么久还没登录就断开。未登录的连接可以一直发心跳躲开空闲清扫，所以要有这条死线                 |
| 单连接失败上限    | `login_fail_per_conn`                                           | 5             | 同一条连接连续登录失败这么多次就断开                                          |
| 来源封禁       | `login_fail_per_ip` / `login_fail_window_sec` / `login_ban_sec` | 10 / 60 / 600 | 同一来源 IP 在窗口内失败这么多次，封这么久。封禁期间**正确的 token 也登录不了**，响应和普通失败一模一样 |
| 未登录流量按来源限流 | `peer_rate_per_sec` / `peer_rate_burst`                         | 同每连接限流        | 同一来源的未登录连接共用一个桶。每连接限流断开重连就是新桶，等于没有                          |
| 每连接限流      | `rate_per_sec` / `rate_burst`                                   | 50 / 100      | 登录之后的流量                                                     |

几条要知道的：

- 登录时限只在 `auth.require = true` 时生效，未登录限流同理。`require = false` 的压测 / 内网部署不受影响。
- 确实有"先连着、过一会儿再登录"的客户端：把 `login_timeout_sec` 调大或设 0。
- 已登录的流量不按 IP 限：同一个网吧的玩家共用出口 IP，登录后按 IP 限会误伤。
- 拿不到来源 IP（假传输、某些代理）时退化成只按连接算。前面有反向代理的话，这里看到的是代理的 IP。
- 手写装配的老项目也自动有了登录时限和失败处置（它们是 `Gateway` 的默认值）；按来源限流要自己调 `setPeerLimiter()`。

## 七、Token 作废

`TokenAuth::sign()` 现在签 `uid.iat.exp.sig`，多了签发时刻。有了它才能作废：

```php
// 片段
$auth->revokeUid(1001);            // 这个玩家当前所有 token 失效；重新登录拿到的新 token 不受影响
$auth->revokeAll();                // 全部失效（secret 疑似泄露）。之后还要换 secret
$auth->addPreviousSecret($old);   // 轮换 secret：旧 token 在过期前继续有效
$auth->allowLegacy(false);         // 不再接受永不过期的 2 段 token（生产应当关）
```

- **作废记录是进程内的**。多 worker / 多节点要全服生效，得让每个进程都执行一次（通过 GM 广播），或者把作废水位放到共享存储里（没做）。
- `sign($uid, 0)` 签的永不过期 token 没有签发时刻，一旦作废过就一律不认。新代码不要这么签。

## 八、集群：成员联动

`Cluster::watchPeer()` 解决了"断了会回来"，但"谁该被 watch"还得有人盯着注册表：

```php
// 片段
$membership = new Membership($registry, $cluster);      // 核心里的类，Registry 接口在核心
$node->afterBeat(function () use ($membership): void {   // 挂在心跳上，跟着心跳对账
    $membership->sync();
});
```

`sync()` 做两件事：注册表里活着、我还没 watch 的 → `watchPeer`；watch 过、但注册表里已经没了的 → `forgetPeer`。  
节点换了地址重启，下一次 `sync()` 会按新地址重新登记，旧链路断开之后连的就是新地址。

它**不主动断链路**（从注册表里消失不等于进程没了），也**不替你决定多久对一次账**。登记地址的 `host:port` 写法会被当成 TCP，裸路径当 unix socket，也可以自己给转换规则。

## 九、GM：一人一个 token

```php
// 片段
$gm->setOperators(["zhangsan" => $tokA, "lisi" => $tokB]);   // 审计里的 op 来自 token
$gm->removeOperator("lisi");                                 // 立刻失效
```

用操作人 token 发的指令，审计日志里的操作人是 **token 对应的名字**，请求头自报的不起作用。共享 token（构造函数那个）仍然可以并存，用它的指令仍按请求头记——想彻底停用，构造时传空串，只要至少有一个操作人 token 就允许启动。

## 十、内置指标

导出端点里现在自动有框架自己的指标，不用埋：

| 前缀                | 内容                                                                                                | 类型                       |
| :---------------- | :------------------------------------------------------------------------------------------------ | :----------------------- |
| `tsf_scheduler_*` | handled / spawned / killed / undelivered / overflow / errors / slow / `ids_left` / `id_wraps` …   | 计数与当前值                   |
| `tsf_gateway_*`   | frames / dropped / logins / loginfail / kicks / sessions / authed / login_banned / peer_dropped … |                          |
| `tsf_cluster_*`   | out / in / drop / dup / hs_fail / bad_pkt / reconnects / links / peers …                          |                          |
| `rpc_*`           | handled / errors / unrouted / sent / ok / failed / timeout / late                                 | RPC 是在每个事件点上计数的，多个实例自动汇总 |

`tsf_scheduler_ids_left` 接近 0 说明服务 id 快回绕了；`tsf_gateway_login_banned` 在涨说明有人在撞 token。  
自己的组件也可以登记：`Metrics::provide("tsf_room", fn () => $this->stats(), ["closed"])`——第三个参数列出哪些键是单调计数。

## 十一、自己验证

```bash
tsf verify shutdown     # SIGTERM 之后停机流程（3 个场景）
tsf verify log-rotate   # 轮转、保留份数、gm 通道、两进程并发
tsf verify tls          # TLS / WSS、拒绝明文、不配证书行为不变
tsf verify reconnect   # 对端重启、不可达、双向互拨
tsf verify id-recycle  # 含真的绕一轮 24 位地址空间（约 40 秒）
tsf verify admission  # 登录时限 / 失败封禁 / 来源限流 / token 作废
tsf verify membership # 注册表 → watchPeer
```

这些套件都做过反向验证：把对应的修复退回去，套件必须变红。
