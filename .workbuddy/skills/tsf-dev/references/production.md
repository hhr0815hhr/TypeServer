# 上线闭环：停机、日志、TLS、集群自愈、id 回收

> 完整说明在仓库的 `docs/guide/13-production.md`；这一页是速查。
> 这些能力都是 2026-11-25 / 26 的框架审计之后加的（`ARCHITECTURE-REVIEW` §56–§60），v0.12.0 的发布包里**没有**。

## 速查

| 要做的事 | 用什么 | 回归套件 |
|:--|:--|:--|
| 进程退出前保存状态 | `Service::onStop()`（一定会被调用，逆创建顺序） | `shutdown-guard` |
| 服务都停了之后做进程级收尾 | `Scheduler::instance()->onShutdown(fn)` | 同上 |
| 服务还活着时广播"即将重启" | `$server->onShutdown(fn(int $workerId))` | 同上 |
| 空闲进程收到 SIGTERM 秒退 | `TcpServer` 选项 `max_wait_time => 0`（默认 3） | 同上 |
| 日志别写满磁盘 | 配置 `log.max_bytes` / `log.max_files`（默认 64 MB × 5） | `log-rotate` |
| 审计日志单独落文件 | `Logger::initChannel("x", $file)` + `Logger::to("x", ...)` | 同上 |
| 网关加密 | `TcpServer` / `WsServer` 选项 `ssl_cert_file` + `ssl_key_file` | `tls-guard` |
| 集群对端断了自动重连 | `Cluster::watchPeer($node, $addr)` 代替 `connect()` | `cluster-reconnect` |

## 必须知道的边界

- **SIGTERM 的停机流程挂在 Swoole 的 `WorkerStop` 上**，不是接管信号（worker 里接管不了：
  `signal [15] processor has been registered by the system`）。日志里那行
  `ERRNO 9101 worker exit timeout, forced termination` 不代表有东西没处理完，看 `scheduler shutdown` 那行。
- **`Timer::after` 的回调不传参数**（`tick` 才传定时器 id）；照 `tick` 写 `int $timerId` 会 `ArgumentCountError`。
  `Timer::after(0)` 不合法，最小 1ms。
- **服务 id 会回绕复用**（以前永不复用）。长期持有对方地址的地方要按名字重新解析，别把地址存起来。
  `stats()["ids_left"]` 接近 0 说明快绕了。
- **`Cluster::connect()` 仍是一次性的**；`watchPeer()` 返回 true 只代表"登记并拨了一次"，连没连上看 `hasPeer()`。
- **集群内部是明文**（握手 mac 不加密），不能上不可信网络；TLS 只管客户端那一侧。
- **GM 的操作人字段**（请求头 `x-gm-operator`）是自报的，不是鉴权。
- **`max_wait_time` 只要还有持久定时器就一定会等满**，和忙不忙无关；它同时就是"发信号到退出"的最短时间。

## 接真服务的套件怎么跑

Redis / MySQL / PostgreSQL 的套件缺服务时标 SKIP（exit 77）。本机有 docker 的话：

```bash
bash scripts/dev-redis.sh     # tsf-redis，127.0.0.1:6379
bash scripts/dev-mysql.sh    # tsf-mysql，127.0.0.1:52315（首次初始化慢，脚本等 TCP 就绪）
bash scripts/dev-pg.sh        # tsf-pg，127.0.0.1:5432
```

三个容器都只绑 127.0.0.1。全量 `tsf verify` 55 个套件应当零 SKIP。

## AOT 的坑（这一轮又踩到的三条）

1. **变量的类型由第一次赋值决定**：`$sock = false; … $sock = stream_socket_client(...)` → 资源被静默转成
   `true`，下游报 `Argument #1 must be of type resource, true given`。分支各用各的变量。
2. **顶层不能有变量声明**（"顶层代码必须在函数内"）：模块级计数器写成 `final class X { public static int $n = 0; }`。
3. **同一个变量先后放两个类**（先 `TcpServer` 再 `WsServer`）不行——拆分支，或用父类型参数的函数接。

## 端口段

gw-guard 9411、cluster-guard 9412/9413、feed-resume 9414、shutdown-guard 9415、
cluster-reconnect 9416–9419、tls-guard 9420/9421、admission 9422、membership 9423–9427。

## 第二批（2026-11-26）：P1

| 要做的事 | 用什么 | 回归套件 |
|:--|:--|:--|
| 一次装好网关（含全部准入策略） | `Gateway::assemble($server, $codec)`，读 `server.*` | `admission` |
| 登录时限 / 失败封禁 / 未登录流量按来源限流 | 配置 `login_timeout_sec`、`login_fail_*`、`login_ban_sec`、`peer_rate_*` | `admission` |
| 作废某个玩家的 token / 全部作废 | `TokenAuth::revokeUid()` / `revokeAll()`；轮换密钥 `addPreviousSecret()` | `admission` |
| 注册表成员自动进集群 | `new Membership($registry, $cluster)` + `LocalNode::afterBeat(fn () => $m->sync())` | `membership` |
| GM 一人一个 token | `Gm\Server::setOperators([名 => token])`、`removeOperator()` | `gm-test` |
| 看框架自己的指标 | 导出端点自动带 `tsf_scheduler_*` / `tsf_gateway_*` / `tsf_cluster_*` / `rpc_*`；`Metrics::provide()` 登记自己的 | `prom-test` |
| 连接的对端 IP | `$server->peerOf($fd)`（可能为空串） | `admission` |

### 这一轮新立的规矩

- **`run.sh` 必须编译**。`tsf lint` 现在判红：注册的 `run.sh` 里没有 `build`（或 `run-svc-test.sh` / `tsf run` / `tsf watch`）。
  `gm-test` / `fd-test` / `settle-crash` 曾十天里跑的都是 11-16 的旧产物，"全绿"对它们是空的。
- 许可证是 MIT；根目录和 `tsf/`（包根）各一份 LICENSE，改许可证两处都要改。

### 又踩的 AOT / 工具坑

4. **循环变量不能复用**：`foreach ($a as $k => $v) {...}` 之后 `foreach ($b as $k => $v)`，如果两处对 `$v` 的推断类型不同
   （一处 string、一处数组值）→ `Cannot assign value to variable $dial of type php::Str with type php::Var`。换个变量名。
5. **`$x = null; ... $x = "str"` 不行**：用两个变量（`$found` + `$name`）。
6. **`sleep(1)` + 秒级 `time()` 的测试要留余量**：断言"后一秒"时用 `usleep(1100000)`；`sleep(1)` 本身在 AOT 下是正常的
   （实测 1000 ms），之前以为是它的问题，其实是产物没重编。
7. **Python 改文件别写 `open(p, 'wb').write(表达式)`**：参数求值抛异常时文件已被截断。先把字节算出来再 open。
   Windows 版 python 的 `/tmp` 和 Git Bash 的 `/tmp` 也不是同一个目录。
