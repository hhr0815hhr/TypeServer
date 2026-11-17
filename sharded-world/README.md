# sharded-world

分片路由的最小示例：**一份可执行文件跑三种角色**。

| 角色 | 命令 | 作用 |
|:--|:--|:--|
| router | `./sharded_world router 9500` | 入口：按 uid 告诉客户端去哪个节点 |
| node | `./sharded_world node 1 9501` | 世界节点 1 |
| node | `./sharded_world node 2 9502` | 世界节点 2 |

## 跑起来

```bash
tsf build .

# 三个进程（顺序无所谓，但 router 要先能读注册表）
./sharded_world router 9500 &
./sharded_world node 1 9501 &
./sharded_world node 2 9502 &
```

节点启动时用 `LocalNode` 把自己注册到 `var/registry/`：

```
var/registry/
├── 1.json      注册信息（id / ep / tags / seen）
├── 1.beat      心跳（每次 beat 重写这个文件）
├── 2.json
└── 2.beat
```

**心跳不写在 json 里**：`register()` 写 `<id>.json`（`seen` = 注册时刻），
`heartbeat()` 只重写 `<id>.beat`。所以判断存活要拿 `.beat` 的 mtime，
只读 json 的 `seen` 会把活着的节点判死（5 秒后集体过期）。

## 分片规则

```php
$idx = $uid % count($nodes);
```

同一个 uid 永远落到同一个节点。真实项目会用一致性哈希（增删节点时迁移量小）。

## 客户端流程

```
连 router → 登录 → 发 WHERE(3101)
         → 收到 GO(3102) {"go":"ws://127.0.0.1:9502","node":2,"total":2}
         → 断开，连这个地址 → 正常 JOIN/MOVE
```

**不做数据转发**：router 只发一张"地图"，客户端自己过去。这比代理简单得多，
代价是客户端要能重连（多一次握手）。

## 两个 AOT 坑（这轮踩的）

**① 数组元素不能调方法**

```php
$alive = $registry->alive();   // 返回裸 array
$node = $alive[0];
$node->nodeId();               // ✗ Invalid callback ... does not have a method "nodeId"
```

报错信息说的是"NodeInfo 没有 nodeId 方法"，**但 NodeInfo 明明有**——
真实原因是 `$alive[0]` 的类型是 `php::Var`，在 Var 上调方法会被当成 callback 字符串解析。
加 `instanceof NodeInfo` 收窄也无效。

对比：`$registry->get(1)` 返回 `?NodeInfo`（类型明确），`->nodeId()` 正常。
**区别只在返回类型是否写明**。

这个 demo 里最后改成直接读注册目录的 JSON（全是数组操作，不碰对象方法）。

**② 启动前必须按端口清理进程**

`Swoole\Server->__construct()` 抛异常 = 端口被占。而 `pkill -x sharded_world`
**杀不干净**：Swoole 会 fork master/worker，它们的进程名会变，`-x` 精确匹配不到。

```bash
fuser -k 9500/tcp   # 按端口杀，这个可靠
fuser -k 9501/tcp
fuser -k 9502/tcp
```

不清理的后果很隐蔽：旧进程占着端口 → 新进程启动失败退出 →
但它**在退出前已经把注册信息写进 registry 了** → router 读到一条"新鲜"的
注册记录 → 把客户端分给一个死节点。

## 还没做

- 用 `Cluster` + `Rpc` 做跨节点通信（现在节点之间完全不知道对方）
- 一致性哈希（现在是取模，增删节点会打乱所有映射）
- 节点故障转移（分到死节点时客户端没有重试）
