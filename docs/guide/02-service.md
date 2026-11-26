# 02 · 服务层：Service、定时器、配置

> 本页代码块中，标 `片段` 的是核心逻辑摘录（缺上下文，不能单独编译）；
> 标 `完整` 的可直接放进你的工程（API 与类型都对照源码确认过，但仍需要 `main()` 等入口）。

## 它解决什么问题

上一层的 Gateway 已经把"消息"送到了 handler 里。接下来四个问题躲不开：

1. **业务逻辑放哪**？全塞在 handler 里，一个房间的状态会被多个 handler 交叉修改
2. **并发怎么办**？AOT 框架跑在 Swoole 协程里，共享状态就要加锁
3. **定时任务怎么管**？每个房间有自己的倒计时、阶段切换
4. **配置怎么读**？策划改一张表，要不要重启

本层的答案是：**用服务（Service）组织状态，用消息代替共享，用调度器串行化**。

## 核心概念

### Service 的三条规矩

```php
// 片段
abstract class Service
{
    abstract public function onMessage(Message $m): void;
}
```

继承它就能成为一个"服务"。三条规矩决定了整个并发模型：

| 规矩 | 含义 | 换来的好处 |
|:--|:--|:--|
| **不共享内存** | 服务之间只能发消息，不能互相持有对象引用 | 依赖变成显式的、可追踪的边 |
| **单线程执行** | 一个服务的 `onMessage` 不会和它自己的下一次执行并发 | **服务内部状态不需要任何锁** |
| **本地/远程同构** | 地址里带节点号，`send()` 写法一致 | 将来把服务拆到别的进程，业务代码不改 |

第二条是最大的价值：**你在 Service 里写的状态，天然是线程安全的**——因为根本不会并发。
代价是所有跨服务交互都得过一遍消息队列。

### 地址与服务注册

每个服务有一个整数地址（`addr`），高位是节点号。所以：

```php
// 片段：本地与远程写法完全一样
$this->send($roomAddr, Msg::ROOM_JOIN, ["uid" => $uid]);
```

`Scheduler` 是全局单例，负责注册、生成、调度、销毁：

```php
// 片段
Scheduler::instance()->register("room", function (): Service {
    return new RoomService();
});
$addr = Scheduler::instance()->spawn("room");
```

### Mailbox：单消费者 FIFO

每个服务一个信箱。实现是 **数组 + 头指针**，不是 `SplQueue`——这个选择有实测依据：

| 实现 | 耗时 |
|:--|--:|
| **数组 + 头指针** | **54 ns/条** |
| `SplQueue` | 178 ns/条 |
| `array_shift` | 12,299 ns @1 万；351,666 ns @10 万 |

`array_shift` 每次搬移整个数组（O(n)），队列一长就爆炸。队列排空后整个数组丢弃重置，
避免底层哈希表反复 push/pop 无限增长。

**这条对使用者的意义**：信箱不是无限的。`setMailboxLimit()` 设上限，超了 `push()` 返回
`false`——发送方要处理这个返回值，否则消息静默丢失。

### 定时器长在服务上

不需要直接碰 `Timers`，`Service` 自带四个方法：

```php
// 片段：在 onStart() 里设，在 onMessage() 里响应
$this->timeout(3000, Msg::ROUND_TIMEOUT);            // 3 秒后一次
$this->interval(100, Msg::TICK);                      // 每 100ms 一次
$this->every("*/5 * * * *", Msg::HOURLY_REPORT);      // cron 表达式
$this->cancelTimer($id);                              // 取消
```

这三个方法返回 timerId。**服务销毁时，属于它的定时器会被一起清掉**（`cancelAllOf(addr)`），
所以不用在 `onStop` 里手动取消。

### 配置：两种用法

**读配置表**（策划导出的 Excel/CSV）用 `Reader`，它是**流式**的：

```php
// 片段
$r = Reader::open("/path/to/item.csv", "item", $schema);
while ($r->next()) {
    $id    = $r->int();
    $name  = $r->str();
    $price = $r->float();
}
```

**流式意味着不能随机访问**——它一行一行读，读完就过了。要在运行时按 id 查表，
自己读进数组/`std::map` 里。

**配置热更**用 `Watcher`，注意它**本身就是一个 Service**：

```php
// 片段
Scheduler::instance()->register("config_watcher", function () use ($dir): Service {
    return new Watcher($dir, function (): void {
        // 重载回调：重新读表、替换运行时数据
    }, 30);   // 30ms 轮询一次
});
```

它靠轮询 + 消息来触发重载，所以**热更能力依赖调度器在跑**。单进程、无 Scheduler 的
场景下它不会工作。

## 公开 API

### Service

| 方法 | 用途 |
|:--|:--|
| `onStart(): void` | 服务创建后调用一次（设定时器、初始化状态） |
| `onStop(): void` | 销毁前调用一次（存盘、通知对端） |
| `onMessage(Message $m): void` | **必须实现**：所有消息进这里 |
| `send(int $to, int $msgId, $data, int $session = 0): bool` | 发消息给别的服务 |
| `timeout(int $ms, int $msgId, $data = "")` | 一次性定时器 |
| `interval(int $ms, int $msgId, $data = "")` | 周期定时器 |
| `every(string $cron, int $msgId, $data = "")` | cron 定时器 |
| `cancelTimer(int $timerId): bool` | 取消 |
| `addr(): int` | 自己的地址 |

### Scheduler

| 方法 | 用途 | 注意 |
|:--|:--|:--|
| `instance()` | 单例 | |
| `register(string $name, \Closure $factory)` | 注册"服务类型" | 只是登记工厂，不创建 |
| `spawn(string $name): int` | 创建一个实例，返回地址 | 每次调用都是新实例 |
| `spawnOf(\Closure $factory): int` | 用匿名工厂创建 | 适合一次性服务 |
| `kill(int $addr): bool` | 销毁 | 幂等 |
| `setMailboxLimit(int $n)` | 信箱上限 | 超了 `push` 返回 false |
| `setBudget(int $n)` | 单次调度的消息预算 | 防止某个服务饿死别人 |
| `setSlice(int $ms)` | 单服务单次调度的**时间**片 | `0`=关；只在消息边界生效 |
| `setTickBudget(int $ms)` | 单次 `tick()` 的总时间预算 | `0`=关；超了留到下一轮，不丢消息 |
| `setMemGuard(int $perMsgMb, int $perServiceMb, string $action = "log")` | 单服务内存归因 | `0`=关；`action` 只能 `log`/`kill` |
| `memByService(): array` | 地址 → 累计净增长 | 排障用；不是当前占用 |
| `setWatchdog(int $ms, string $action = "log")` | 单条消息超时 | `action` 只能是 `log` 或 `kill` |

### Config

| 方法 | 用途 |
|:--|:--|
| `Reader::open($path, $table, $schema): Reader` | 打开表（**静态工厂**，注意不是 `new`） |
| `$r->next(): bool` | 前进一行，返回是否还有 |
| `$r->int() / float() / str() / bool()` | 取当前行的字段 |
| `new Watcher($dir, \Closure $reload, int $pollMs = 30)` | 目录轮询 + 重载 |

## 最小用法

一个带倒计时的房间服务：

```php
// 完整
use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

final class RoomService extends Service
{
    private int $round = 0;

    public function onStart(): void
    {
        $this->round = 1;
        $this->interval(1000, 9001);          // 每秒推一次计时
        $this->timeout(30000, 9002);          // 30 秒后本局结束
    }

    public function onMessage(Message $m): void
    {
        if ($m->msgId === 9001) {
            $this->round++;
            return;
        }
        if ($m->msgId === 9002) {
            $this->send($m->from, 9003, ["round" => $this->round]);
            Scheduler::instance()->kill($this->addr());
        }
    }
}

// 注册与创建
Scheduler::instance()->register("room", function (): Service {
    return new RoomService();
});
$addr = Scheduler::instance()->spawn("room");
```

**这段代码实测编译并运行过**（2532 KB 二进制）。为了快速验证，把定时器缩到
`interval(10ms)` / `timeout(50ms)`，并用 `Scheduler::tick()` 手动推进而不是 `run()`：

```
spawned addr=1
count=1          ← spawn 成功，服务数为 1
rounds=5         ← interval(10ms) 在 50ms 内触发了 5 次
killed=yes       ← kill 后 has($addr) 为 false
final count=0    ← 服务确实被销毁
```

`Message` 只有四个字段（构造签名 `new Message($from, $msgId, $data, $session = 0)`）：

| 字段 | 含义 |
|:--|:--|
| `$m->from` | 发送方地址，**回包就发给它**；0 表示系统（网络层/定时器）发起 |
| `$m->msgId` | 应用层协议号 |
| `$m->data` | 载荷，类型是 `string\|array\|object`（全框架无 `mixed`） |
| `$m->session` | 请求 id，回应时原样带回；0 表示单向 |

**没有时间戳字段**，这是刻意的：`hrtime()` 单次 236 ns，占单条消息预算的 12%。
要做延迟统计，在压测端自己记发送时刻。

## 边界与坑

1. **服务抛异常 = 该服务被销毁**。`Scheduler` 捕获后会记录 error 并按 Watchdog 的
   `action` 处理（`kill` 时连服务一起销毁）。这是刻意的"失败要显式"：
   一个房间的状态机跑飞了，继续处理后续消息只会更乱。

2. **不要在 Service 里放 per-message 统计**（计数、时间戳）。每条消息都要多付几百纳秒，
   而这些数据该在压测端/监控层按批次采。

3. **`register()` 不创建实例**。它只是登记工厂；`spawn()` 才真创建。名字只是给
   `spawn` 用的键，重复 register 同名会覆盖。

4. **信箱满了会静默丢消息**（`push` 返回 `false`）。发送方不检查返回值的话，
   表现为"偶尔少一条消息"，极难排查。

5. **`Reader` 是流式、不可回退**。`next()` 只前进。需要反复查询的数据要自己建索引。

6. **`Watcher` 依赖调度器**。它是 Service，靠 `interval` 轮询——没有 Scheduler 在跑
   就不会热更。

7. **写测试用 `tick()`，不要 `run()`**。`run()` 会阻塞并接管 Swoole 事件循环；
   `tick($maxMsgs = 0)` 只推一轮就返回，配合 `usleep()` 可以确定性地驱动定时器和消息，
   不需要起服务。上文的验证就是这么做的——**这是框架里少有的、能对并发逻辑做确定性
   测试的口子**，值得记住。

8. **定时器靠传输层驱动，`Scheduler` 自己不会跑**。`WsServer` / `TcpServer` 在
   `workerStart` 里挂了 `\Swoole\Timer::tick(1, fn() => Scheduler::instance()->tick(2000))`，
   调度器才有心跳。这意味着：

   - 用 `ServerFactory::make(...)` + `$server->start()` 的常规服务 → 定时器正常
   - 写"纯服务进程"（没有 Server）→ **必须自己调 `Scheduler::run()` 或定期 `tick()`**，
     否则 `timeout` / `interval` / `every` 全部静默失效
   - 连带失效的还有 `Config\Watcher`（配置热更）和 `pkg/frameloop`（帧循环），
     它们都建立在 `interval` 上

   这个失效**没有任何报错**，表现为"定时器不触发"。排查时先确认调度器在被驱动。

9. **`setBudget` 限条数，不限时间**。一个服务处理一条消息花 200 ms，照样跑完，
   同一 tick 里其他几千个服务全部等它——这不是"慢"，是"全体停顿"。
   `setSlice($ms)` 让它在**消息边界**让出（剩余消息排到队尾），
   `setTickBudget($ms)` 保证单轮 tick 不超时（每 64 条检查一次，检查点在 `pop()` 之前，不丢消息）。

   **两者都拦不住单条长消息**：一条消息自己跑 100 ms，slice 设 2 ms 也没用——
   PHP 无法中断正在执行的代码。它们防的是"一个服务长期霸占"，不是"一件事做太久"。
   协程模式下 slice 不生效（墙钟包含挂起等 I/O 的时间，会把"等 redis 30 ms"误判成慢），
   那里该用 `setBudget` 限条数。回归见 `tests/slice-test`。

10. **内存是共享的，但可以定位到服务**。所有 Service 在同一个 PHP 堆上，一个服务吃 500 MB，
    整个 node 跟着死——没有 Erlang 的 per-process heap。
    `setMemGuard(4, 128, "kill")` 会在 tick 边界量差值，单批超 4 MB 或累计超 128 MB 就处置。
    它**只能抓数量级异常**：一次捞整张表、缓存不设上限。每次 1 KB 的缓慢增长抓不到。
    `memByService()` 是累计净增长，不是当前占用。回归见 `tests/mem-attr-test`。

## 什么时候不该用它

- **无状态的纯函数逻辑**（伤害计算、数值公式）不需要 Service，写成静态类更直接，
  也更容易单测。
- **只有一两个玩家的小工具**可以直接在 handler 里处理，不必为每个玩家建 Service——
  Mailbox 和调度都是要付成本的。
- **配置只读不改**不需要 `Watcher`，启动时读进内存即可。
