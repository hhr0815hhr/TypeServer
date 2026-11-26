# Swoole 契约测试

验证框架对 Swoole 扩展的**四组关键假设**——都是注释里写了结论、但从没被验证过的。

```bash
bash run.sh
# PASS 10 项 / FAIL 0 项
```

只跑 `php85`（验的是扩展/内核行为：缓冲、flush、shutdown），和 AOT 无关。

## 结论

### 1. 单帧超过 `buffer_output_size` 也能发出去 ✅

框架把每连接输出缓冲设成 64KB，注释里写"单帧上限本身就是 1MB，但队列深度不需要那么深"，
等于假设**大帧也能发**。实测：

```
body=1024     SEND_RET true   客户端 declared=1032      intact=yes
body=65536    SEND_RET true   客户端 declared=65544     intact=yes
body=131072   SEND_RET true   客户端 declared=131080    intact=yes
body=1048576  SEND_RET true   客户端 declared=1048584   intact=yes
```

假设成立。**原因**：`buffer_output_size` 限制的是每连接的**排队**缓冲，不是单次 `send()`
的大小；连接空闲时 Swoole 直接把数据写进 socket（内核再吸收一部分），不会因为
"一帧比缓冲大"就拒绝。

### 2. 慢客户端不读时，`send()` 在 4MB 量级仍然返回 true ✅（有限结论）

客户端连上后**完全不读**，服务端连发 4000 个 1KB 帧（约 4MB）：

```
rounds=4000  fails=0  threw=0  first_bad=-1
```

即在这个量级下 `send()` 从不返回 false，框架里 `publish` 忽略返回值暂时是安全的。

**这是有限结论，不要外推**：4MB 没触发背压不等于永远不会。真正的背压要在
"客户端读得比服务端发得慢得多 + 持续很久"下才会出现，那时 `send()` 会返回 false，
而框架目前**没有**处理这个返回值（`publish` 直接忽略）。要坐实需要更极端的压测
（更慢的客户端、更长的持续时间、更小的 buffer），现在没做。

### 3. `shutdown()` 会 flush 已 send 的数据 ✅

drain 的最后一步就是 `shutdown()`。如果它把未刷出的帧丢掉，玩家就看不到最后一局结算。实测：

```
服务端: SENT_FINAL true
客户端: CLI_SHUTDOWN got=13 final=yes
```

`send()` 之后立刻 `shutdown()`，客户端仍收到那 13 字节。所以 drain 流程是安全的
（这也和 `demo/duel/run-drain.sh` 里"老对局拿到 RESULT"的实测一致）。

### 4. `Timer::after` 和 `Timer::tick` 的回调签名不一样 ✅

| 调用 | 回调收到什么 | 写错会怎样 |
|:--|:--|:--|
| `Timer::tick(ms, fn)` | **定时器 id**（`function (int $timerId)`） | — |
| `Timer::after(ms, fn)` | **什么都不传** | 声明了形参 → `ArgumentCountError`，回调不执行 |
| `Timer::after(0, fn)` | — | 返回 false + `Timer must be greater than or equal to 1`，**不触发** |

框架源码里此前只出现过 `tick`。`Server::resumeLater()` 照着它给 `after` 写了 `int $timerId`，
在 AOT 产物里每轮续处理都抛一次 `ArgumentCountError`（2026-11-25，`tests/feed-resume` 的修复过程）。
这组的四个子模式各跑一个独立进程，其中 `after-arg` **本来就应该崩**。

## 脚手架的三条规矩（上一版就死在这）

第一版同样的用例全红，原因全在脚手架，不在被测对象：

1. **端口每轮换**。复用同一端口时，上一轮的残留进程还占着，第二轮实际在跟**旧服务端**
   对话——`body=65536` 那轮收到的是 1024 的帧，于是得出"大帧发不出去"的**假结论**。
2. **按 PID 回收**（`kill $SRV_PID`），不要 `pkill -f "<路径>"`——后者会匹配到自己那条
   `bash -lc` 命令行，把 shell 一起杀掉（退出码 9、无输出）。
3. **每个 case 开始前确认端口空闲**，起服务后轮询直到真在监听。

一个脏 harness 会让所有结论都带问号，而且**看起来像是被测对象的问题**。
这次的教训比三条结论本身更值钱。

## 没覆盖的

- **真正的背压**：见结论 2 的边界。
- **`max_conn` 与 fd 上限**：`ensureFdLimit` 的调整逻辑没验。
- **`enable_coroutine=false` 的实际影响**：注释说关掉是为了省内存，没量化。
- **`backlog` 是否真的 8192**：需要用建连风暴压出来。
