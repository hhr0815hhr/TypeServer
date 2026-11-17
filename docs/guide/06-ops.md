# 06 · 运维：指标、GM、热更、工具链

> 本页代码块标 `片段` 的只讲结构；完整可运行的示例在 [07 篇](07-walkthrough.md)。

## 它解决什么问题

上线之后只剩三个问题：**看不见、改不了、救不回**。

| 问题 | 本层的答案 | 边界 |
|:--|:--|:--|
| 看不见（不知道慢在哪） | `Metrics`：计数 / 瞬时值 / 耗时分布 | 埋点有成本，不能乱埋 |
| 改不了（改一行要重启） | `pkg/wasm`：模块热更 + 灰度 + 回滚 | 只能换实现，不能改接口 |
| 救不回（线上要改数据） | `Gm`：带鉴权的管理通道 | 和业务共享事件循环，卡住就一起卡 |

## 核心概念

### Metrics：三条原语，成本决定埋在哪

```php
// 片段
Metrics::inc("rpc.call");                 // 计数
Metrics::gauge("room.active", 42.0);      // 瞬时值
Metrics::observe("db.find", 120.5);       // 耗时（µs）
```

实测开销（`tests/metrics-test` 的断言与实际值）：

| 操作 | 耗时 | 能不能埋在热路径 |
|:--|--:|:--|
| `inc` | **81 ns** | 可以，每消息一次没问题 |
| `observe` | **400 ns** | 谨慎，别在每帧内层循环里调 |
| `log()` | 2 ns | — |

`observe` 的 400 ns 主要花在直方图分桶。**如果一条路径每秒跑 10 万次，`observe` 会吃掉
4% CPU**——这种地方用 `inc` 记次数，用采样记耗时。

手动计时的写法：

```php
// 片段
$t0 = Metrics::now();       // 注意：返回的是计时起点，不是时间戳
// ... 业务 ...
Metrics::done("settle.us", $t0, "room=1001");
```

`watch($name, $slowUs)` 设慢调用阈值，超阈值的调用除了进直方图，还会打一条日志——
**并且限流**（`MetricSnapshot::$suppressed` 记录被压掉的条数），避免刷屏。

### GM：和业务共享事件循环，所以天然单写者

`Gm\Server` 是个独立 TCP 服务（带 token 鉴权和失败次数限制，防爆破），**但它跑在
同一个 `Scheduler` 事件循环里**。

这个设计带来一个很强的性质：**GM 改数据和业务逻辑天然串行，不需要任何锁**。所以
"服务运行中扣款/发金币"这类操作可以走 GM 通道而不用停服——它和房间逻辑不会并发。

> 这是 `tsf/README.md` §T6-05 的原话：真要在服务运行时扣款，走 GM 通道——它和
> Scheduler 同一个事件循环，天然单写者。

代价也很直接：**GM 处理慢会卡住整个 worker**。批量查数据、导大表这种事不要放在 GM 里，
它会把所有房间的帧一起拖慢。

### wasm 热更：能换实现，不能改接口

```php
// 片段
$m = Module::load("logic", "/path/v1/dist/logic.cwasm");
$m->canary("/path/v2/dist/logic.cwasm", 100);   // 10% 流量走 v2（千分比）
$m->swap("/path/v2/dist/logic.cwasm");          // 全量切换
```

| 能改 | 不能改 |
|:--|:--|
| 导出函数的实现（同一 package/world） | 导出的函数签名 |
| 内部逻辑、数值、算法 | 接口包的 `package`/`world` 名（宿主按名匹配） |
| — | 宿主里的状态结构（换实现不迁移内存） |

实测耗时（`tsf verify wasm`，6 项断言）：

```
swap v2      5669 us
rollback v1  4316 us
```

**灰度用千分比**（`permille`，100 = 10%），不是百分比——这样 0.1% 这种细粒度也能表达。

### 工具链

`tsf` 一共 13 个子命令，按用途分四组：

| 组 | 命令 | 用途 |
|:--|:--|:--|
| 开发 | `new` `build` `watch` `serve` `lint` | 建工程、编译、热重编、跑起来、结构检查 |
| 验证 | `verify` `verify --list` `verify <id>` | 9 个套件 197 项断言 |
| 数据 | `migrate` `migrate-status` | 部署期迁移 |
| 资源 | `config` `proto` `module` `list` `add` `remove` | 配置导出、协议生成、wasm 模块、包管理 |

## 公开 API

### Metrics（全静态）

| 方法 | 用途 |
|:--|:--|
| `inc(string $name, int $n = 1)` | 计数 |
| `add(string $name, int $n)` | 累加 |
| `gauge(string $name, float $v)` | 瞬时值 |
| `observe(string $name, float $us, string $ctx = "")` | 记录一次耗时 |
| `now(): int` / `done(string $name, int $t0, string $ctx = "")` | 手动计时（**`now()` 不是时间戳**） |
| `watch(string $name, float $slowUs)` | 设慢调用阈值 |
| `snapshot(): array` / `table(): string` / `dump()` | 导出 |

### Gm\Server

| 方法 | 用途 |
|:--|:--|
| `new Server(string $host, int $port, string $token, int $pollMs = 5)` | |
| `setAuthFailLimit(int $maxPerWindow, int $windowMs)` | 防爆破 |
| `onStart() / onMessage() / onStop()` | 它是 `Service` |
| `port() / stats()` | 查询 |

### wasm\Module

| 方法 | 用途 |
|:--|:--|
| `load(string $name, string $path): ?self` | 加载模块 |
| `canary(string $path, int $permille): bool` | 灰度，千分比 |
| `setRatio(int $permille): int` | 调整灰度比例 |
| `swap(string $path): int` | 全量切换（返回耗时 µs） |
| `stats(): string` / `bridgeVersion()` | 状态 |

## 边界与坑

1. **`Metrics::now()` 返回 `hrtime(true)` 的纳秒数**——单调时钟的计时起点，**不是 wall clock**。
   要记"当前时刻"得用别的东西；`$t = Metrics::now(); echo $t;` 得到的是一个无意义的纳秒数。

   顺带解释了它的形式为什么不设计成闭包（`Metrics::time("x", fn() => ...)`）：**闭包每次
   调用都要分配对象**，而这个 API 会出现在每一帧、每一个包的处理路径上。用"起点 + done"
   就为了避开这一次分配。

2. **`observe` 在热路径内层循环里会吃掉可观的 CPU**（400 ns/次）。高频路径用 `inc`
   记次数，耗时用采样。

3. **GM 卡住 = 全 worker 卡住**。它和业务共享事件循环，批量操作要分批、要 sleep。

4. **wasm 只能换实现**。改了导出签名、或换了 `package`/`world` 名，宿主匹配不上会拒绝加载。
   状态不迁移——新实现看到的是旧实现留下的内存，结构对不上就是脏读。

5. **灰度比例是千分比**。`canary($path, 100)` 是 10%，不是 100%。

6. **`migrate-status` 有待执行时返回非零**，可以直接用在部署脚本的 gate 里。

## 什么时候不该用它

| 情况 | 结论 |
|:--|:--|
| 本地开发 | 不用 GM，不用热更。`tsf watch` + 直接改代码。 |
| 没有线上多实例 | 不需要 wasm 热更——重启 3 秒的事 |
| 指标只是"想看看" | 先上 `table()` 打到日志，别急着接 Prometheus |
| 热更的是"状态结构" | 不可能。热更只换函数体，改状态要停服迁移 |
| GM 要跑批量任务 | 不要走 GM，写离线脚本直连数据库 |
