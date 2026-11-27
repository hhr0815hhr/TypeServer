# demo 目录

两个 echo 示例，以及它们为什么不能合并。

> 除这两个之外，目录下还有若干独立工程，各自带 `run.sh`：
> `battle`（战斗最小闭环：AOI + 帧循环 + 配置热更 + GM，压测报告见仓库根的
> `VERIFY-BATTLE.md`）、`duel`（1v1 对战 + 快照恢复）、`doudizhu`（回合制 + 状态机）、
> `idle`、`mmo-mini`、`sharded-world`（分片 + 跨 worker 转发）、
> `rule-hot`（脚本规则热更：哪层放 AOT、哪层放脚本、灰度与成本对照）。
>
> 构建方式统一：`php85 ../tsf/bin/tsf build <dir>`（不各自装 composer）。

## `demo-echo` — 正常示例

最小可用的 echo 服务：登录 → 发消息 → 原样回显。协议分帧、限流、鉴权全走配置。

- 运行时：`/usr/local/php-8.5-zts`（ZTS 8.5）
- 编译器：`$HOME/typephp-src/bin/tpc.php`（typephp **0.9.6**，带本地补丁，见 `../tpc-patches/`）
- 构建：`tsf build demo/demo-echo`

## `demo-echo-nts` — NTS 对照产物（**历史，已无法重建**）

2026-11-13 做「ZTS vs NTS」对照时编出来的那一份。

| | demo-echo | demo-echo-nts |
|:--|:--|:--|
| `main.php` / `client.php` | **逐字节相同** | 同左 |
| `src/` | 空目录 | 空目录 |
| `config/tsf.json` | 限流 50/s、鉴权开 | 限流关、鉴权关（压测用） |
| 链接的 `libphp.so` | `/usr/local/php-8.5-zts/lib/` | `$HOME/php-8.5-nts/lib/` |
| 链接的 `libphpx.so` | `typephp-src/vendor/swoole/phpx/lib/` | `$HOME/phpx-nts-home/lib/` |
| 产物大小 | 1,484,856 | 1,440,472 |

### 为什么不能合成一个工程 + 环境配置

运行时是**链接期**决定的（`-L ... -lphp`），配置文件表达不了。分层配置
（`tsf.json` → `tsf.{env}.json`）只能改参数，改不了链哪个 PHP。

### 为什么现在重建不出来

NTS 那条链需要**同时**满足两件事：为 NTS 目标构建的 `libphpx.so`，以及一个运行在
NTS PHP 上的 tpc（tpc 要求目标 PHP 与自身运行时的 major.minor / ZTS 一致）。

- 现有的 `typephp-src`（0.9.6，带补丁）是 **ZTS** 的，拿去编 NTS 目标违反这条一致性要求；
- 现有的 NTS 编译器 `typephp-095` 是 **0.9.5**，比当前源码旧，直接编不过：

```
NativeHeaderFrame.cc:23: 'php_c_encode' was not declared in this scope
NativeHeaderFrame.cc:41: 'php_c_frame_len' was not declared in this scope
Timers.cc:137: cannot convert 'php::Variant' to 'php::Int'
```

（这三处正是 0.9.6 + 本地补丁才有的能力。）

### 要恢复 NTS 对照的话

1. 用 NTS 的 PHP 重建 phpx：
   `cmake -DPHP_CONFIG=$HOME/php-8.5-nts/bin/php-config ...`（依赖 `libmpfr-dev`）
2. 用同一套 NTS PHP 编一份 tpc（`typephp-src` 的源码 + 上面的 libphpx）
3. `TSF_PHP=$HOME/php-8.5-nts/bin/php TPC=<新的 tpc.php> tsf build demo/demo-echo`

### 当前建议

`demo-echo-nts` 的源码与 `demo-echo` 完全相同，**改 `demo-echo` 时不需要同步它**——
它连不上当前工具链，同步过去也编不出东西。要的是"能跑的 NTS 对照"，就按上面三步重建；
不要的是这份历史产物，整个目录可以删掉（删之前确认还有没有人拿它做基准对比）。

## 配置里的 `${base}`

三个 demo 的 `config/tsf.json` 里，日志、sqlite、快照路径都写成 `${base}/var/...`。
`Bootstrap::boot()` 在加载配置后会把 `${base}` 展开成**本次启动的工程目录**
（`Config::expandBase()`）。

不写绝对路径的原因：仓库 clone 到别处跑起来，会把日志和库写回**原来那个目录**——
看起来一切正常，实际在污染另一棵树。这个坑在 2026-11-16 整理仓库时实际验证过。


## 多进程（`run-cluster.sh`）

同一台机器起两个进程，各占一个号段：

```bash
bash run-cluster.sh      # 11 项断言
```

它干的事：

```
node 0: server.node_id=0, port=9101   → 房间号从 1 开始
node 1: server.node_id=1, port=9102   → 房间号从 1000000001 开始
```

node 1 的配置在 `config/tsf.node1.json`，靠现成的 `TSF_ENV=node1` 机制叠加
（`Config` 会按 `tsf.json` → `tsf.node1.json` 的顺序覆盖），不需要改代码。

**两个约束要知道**：

- **端口不能共用。** Swoole 在 `new Server()` 构造时就 bind，`reuse_port` 选项来不及生效
  （实测第二个进程直接 `Address already in use`）。真部署时前面挂网关/LB。
- **`node_id` 唯一。** 进程启动时用 `var/node<N>.lock`（mkdir 原子占位 + pid）占位，
  同号第二个进程直接拒绝。两个进程共用一个号段 = 两个主人，会各自建出同一个房间号，
  是设计里最怕的"双主"，而且不报错，只让账慢慢对不上。
  `kill -9` 留下的锁会被下一个进程接管（读 pid + 查 `/proc`）。

只读命令（`wallet-check` / `migrate-status`）不抢锁，服务在跑时也能查账；
会写的 `wallet-debit` / `wallet-credit` 必须抢锁——它们写的就是本节点那份日志。
要在服务运行时扣款，走 GM 通道（和 Scheduler 同一个事件循环，天然单写者）。

## 退役（drain）

```bash
bash run-drain.sh          # 8 项断言
./duel drain               # 手工：通知本节点退役（写 var/drain.n0）
```

语义是**先把存量做完**：不再建新房（匹配回 `NOTICE draining`）→ 等现有对局打完 →
进程自己退出。超时 `server.drain_timeout_sec`（默认 300 秒）强退并记 error。

**不用 SIGTERM**：SWOOLE_PROCESS 模式下 master 的 SIGTERM 由 Swoole 接管，
用户注册的 handler 不触发（顶层和 `Start` 回调都实测过）。k8s 里这样写：

```yaml
lifecycle:
  preStop:
    exec:
      command: ["/bin/sh", "-c", "cd /app/demo/duel && ./duel drain && sleep 30"]
```

多节点时记得带 `TSF_ENV`：`TSF_ENV=node1 ./duel drain` 写的是 `var/drain.n1`。

## 重连找回（rejoin）

```bash
bash run-rejoin.sh                                  # 6 项断言
php85 client.php 9101 tok-1001 aggressive --rejoin  # 手工：登录后直接找回自己那一局
```

断线**不立刻判负**：`server.rejoin_grace_sec`（默认 15 秒）内重连不算输，过期才判负。
协议 `REJOIN(5003)` → `REJOIN_OK(5101)`，回 `{room, foe, side}`；没有进行中的局则回
`NOTICE {code:"no-room"}`。

房间层不需要知道重连——`$publish` 按 uid 查 fd，重连重新登录后 Gateway 会把 uid→fd
指向新连接。uid → {room, foe, side} 的索引存在 `DuelManager::$duelOf`，进程重启时从
快照重建。

**跨节点重连还没做**：连到另一个节点会回 `no-room`，要客户端保存 roomId 并连回原节点。

## 验证脚本

根目录下的 `*.sh` / `*-test.php` 是**手工验证工具**，不是跑 demo 的必经步骤。
各自验证什么、什么时候用：见 [SCRIPTS.md](SCRIPTS.md)。
