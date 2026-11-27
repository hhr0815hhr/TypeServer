# duel —— 1v1 匹配对战（3195 行）

**定位**：分布式能力的**回归载体**。它本身是个小对局，但配套了集群、崩溃恢复、
停机排空、认输、重连五个脚本，是验证"服务在多进程/多节点下是否还正确"的地方。

```bash
./run.sh                 # 单机，端口 9101
./run-cluster.sh         # 两节点（9101 + 9102）验证跨服
./run-crash.sh           # 杀掉一个节点，验证状态恢复
./run-drain.sh           # 优雅停机（排空进行中的对局）
./run-forfeit.sh         # 认输与结算
./run-rejoin.sh          # 断线重连
```

观战/排查用的两个脚本：

```bash
php85 show-records.php   # 看落库的对局记录
php85 show-snapshot.php  # 看快照文件
```

## 源码（7 个文件，平铺在 `src/`）

| 文件 | 职责 |
|:--|:--|
| `DuelService.php` | 服务入口，消息分发 |
| `DuelRoom.php` | 一局对战的状态机 |
| `DuelManager.php` | 房间管理、断线处理、停机排空 |
| `Settle.php` | 结算（735 行，规则最密的地方） |
| `Wallet.php` | 金币增减 |
| `DuelRecord.php` | 战绩落库 |
| `Msg.php` | 消息 ID |

## 为什么读它

- **分布式怎么落地**：`run-cluster.sh` + `main.php` 里的 `Cluster::watchPeer()`
- **停机不能丢对局**：`DuelManager::beginDrain()` 与 `run-drain.sh`
- **断线重连的完整闭环**：`run-rejoin.sh` 里有可复现的时序

它比 `ddz-hall` 多了"分布式"这一层，少了大厅/房卡。**两个都看**才完整。

## 注意

`pkg/` 的可选包只开了 `db`（战绩落库要用），其余走框架核心。
多开 worker 会被 Swoole 拒绝——要多进程请用 `run-cluster.sh`（不同端口 + 不同 `node_id`）。
