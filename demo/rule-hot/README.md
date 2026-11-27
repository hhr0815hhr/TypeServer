# rule-hot — 哪里放 AOT，哪里放脚本

一个能跑的最小示例，回答三个问题：

1. **一条规则该写在哪一层**（AOT 还是脚本），判据是什么
2. **脚本怎么和 AOT 服务打通**（视图、能力、定时器、状态）
3. **热更怎么做才不出事**（灰度、重载、回滚、对拍）

只用 TCP 和本机文件，不需要数据库 / redis / docker。

---

## 跑

```bash
# 前提：ext/interp 已编译（脚本层的运行时）
cd ../../ext/interp && phpize85 && ./configure && make && cd -

./run.sh              # 编译 + 完整演示（约 15 秒）
./run.sh --no-build   # 已编译过
php85 bench.php 20000 1     # 规则成本对照（服务要在跑）

./run-cluster.sh      # 双节点：证明单机 reload 不够，广播才一致（约 10 秒）
```

演示输出（实测，2026-11-27）：

```
── 1. 契约对齐：脚本 v1 与 AOT 规则必须逐字节一致 ──
  compare    aot=3001:13;3002:24;3003:1 script=3001:13;3002:24;3003:1 same=yes
  判定       一致，后面看到的差异只可能来自规则本身

── 汇总（items 为累加值，差值是这 200 次的掉落件数）──
  段             件数   件/次   规则
  before         163    0.81    v1：武器 25% / 材料 50% / 稀有 2%
  after          230    1.15    hotfix：55% / 50% / 10%
  rollback       144    0.72    回滚到 v1
  gray 1001      155    0.78    30% 灰度未命中 → base
  gray 1002      232    1.16    30% 灰度命中 → hotfix

✓ 全程 pid 未变：三次规则切换都是热更，没有重启
```

---

## 一条请求的链路

```
客户端 raid
  → Gateway（鉴权 / 限流）
  → handler：只拆参数，投 SVC_RAID
  → RaidService：
       ① 建只读视图 RaidView（含确定性随机源）
       ② 问 loot 脚本：该掉什么      ← 规则（可热更）
       ③ Wallet 执行：物品入库        ← 执行（AOT，脚本碰不到）
       ④ 问 activity 脚本：要不要加奖  ← 副作用（只能"申请"）
       ⑤ Wallet 执行：加钱 + 审计
       ⑥ 回包
```

脚本永远不直接改钱包。它能拿到的只有两样东西：

| 给什么 | 用哪个 API | 脚本能做什么 | 不能做什么 |
|:--|:--|:--|:--|
| 只读视图 | `Script::call($entry, $view)` | 调方法读状态、问"成不成" | 改任何字段 |
| 能力上下文 | `Script::callWithContext($entry, $ctx)` | 读白名单字段、申请发奖、挂定时器、读配置 | 直接改钱/改库 |

---

## 决策表

判断一段逻辑放哪，问三个问题，**按顺序**：

| # | 问题 | 是 → | 否 → |
|:--:|:--|:--|:--|
| 1 | 它会动钱、动库存、落库、推送吗？ | **执行留 AOT**，脚本最多"申请" | 继续 |
| 2 | 它每帧跑，或者每人每秒 ≥10 次吗？ | **AOT** | 继续 |
| 3 | 线上会改吗（运营调数值、加条件、开活动）？ | **脚本** | AOT |

三条都"否"的（一次性、不变、不热），写哪都行——写在 AOT，省一层间接。

对应到这个工程：

| 逻辑 | 层 | 理由 |
|:--|:--|:--|
| 掉落判定（掉什么、多少概率） | 脚本 `loot.php` | 运营每周调；每人每分钟一两次 |
| 活动倍率、里程碑 | 脚本 `activity.php` | 活动一开一关；改的是规则不是状态 |
| 基础金币 | AOT `RaidService` | 每笔都走，改动要走版本 |
| 随机源 | AOT `RaidView::chance` | 必须可回放、可对账 |
| 加钱、加物品、审计 | AOT `Wallet` | 资损边界 |
| 灰度分流 | AOT `Manager::pick` | 决策依据是 uid，不是规则 |

### 成本不是拍脑袋

`bench.php` 让服务进程自己跑同一份输入：

| 场景 | AOT | 脚本 | 倍数 |
|:--|--:|--:|--:|
| 1 只怪（3 次判定） | 780 ns | 1,400 ns | 1.8× |
| 10 只怪（30 次判定） | 2,910 ns | 4,540 ns | 1.6× |

> 三轮取最优（WSL2 后台负载会让单轮差 30%）。与框架 `bench/script-cost` 的结论一致：
> 脚本的固定开销约 0.5 µs/次，之后按"方法调用"计价（约 45 ns/次），
> **不是**"慢十倍"。

换算成预算，按 1 万在线：

| 调用频率 | 脚本耗时占单核 |
|:--|--:|
| 每人每秒 1 次 | 1.4% |
| 每人每秒 10 次 | 14% |
| 每人每秒 50 次 | 70% |

判定线：**<5% 放脚本，>20% 回 AOT**。这条规则适合放脚本，因为它是"每人每分钟一两次"。

---

## 两种接法

### A. 纯判定：`call()` + 只读视图（推荐优先用这个）

AOT 侧：

```php
$view = new RaidView($uid, $kills, $level, $luck, $this->rollSeq);
$spec = $mgr->pick("loot", $uid)?->call("roll", $view);   // "3001:2;3002:1"
$wallet->addSpec(is_string($spec) ? $spec : "");
```

脚本侧：

```php
return [
    "roll" => function ($v) {
        if ($v->chance(1, 25)) { /* 掉了 */ }
        return "3001:1";
    },
];
```

要点：

- 视图只暴露方法，**没有 setter**，脚本写不了状态
- 方法调用比白名单字段快 9 倍（45 ns vs 410 ns，见 `bench/script-cost`）
- 随机数由 `$v->chance()` 提供，脚本只写"多少概率、什么条件"
- 返回字符串而不是数组：边界上只做一次收窄，调用点不用各自判断类型

### B. 副作用：`callWithContext()` + 能力

AOT 侧（`onStart` 里建一次，别每次请求 new）：

```php
$self = $this;
$this->caps["reward"] = function (int $amount, string $reason) use ($self): bool {
    return $self->award($amount, $reason);      // 额度、频控、幂等都在这里
};
$this->caps["timer"] = function (int $ms, string $entry) use ($self): void {
    $self->scheduleScriptEntry($ms, $entry);
};

$ctx = new Context($state, ["uid", "level", "baseGold"], []);  // 可写列表是空的
foreach ($this->caps as $name => $fn) { $ctx->bind($name, $fn); }
$script->callWithContext("onKill", $ctx, 500);
```

脚本侧：

```php
"onKill" => function (Context $ctx) {
    $n = $ctx->addStateInt("kills", 1);          // 跨 reload 存活
    $ctx->reward($ctx->getInt("baseGold"), "activity_multiplier");
    if ($n % 10 === 0) { $ctx->timer(1200, "onMilestone"); }
    return $n;
},
```

要点：

- 能力必须显式 `bind`，没绑就调 → **当场抛错**（静默失败最难查）
- 脚本只能"申请"，宿主可以拒绝（`award()` 里额度上限，超了记 warn 并返回 false）
- 定时器要**跟着状态走**：`scheduleScriptEntry` 把当前 state 存进 token 表，
  到点再取回来。不存的话回调时不知道是谁（见坑 5）

---

## 灰度与重载

```
registerAlt("loot", 2, "scripts/loot.hotfix.php")   ← 只登记，不生效
        ↓
GM script.gray loot 5        ← 5% 的 uid 走新版
        ↓
观察：掉落量、投诉、金币产出（script.status）
        ↓
GM script.gray loot 100      ← 全量
        ↓
把 hotfix 覆盖成 loot.php → GM script.reload loot → gray 归 0
```

分流是**按 uid 的乘法散列**，不是 `uid % 100`：后者会让 1001–1100 这一批
全部落在同一侧，那不叫灰度，叫"按注册批次灰度"。实测 `pct=30` 时
uid 1001 走 base、uid 1002 走 hotfix，且每次都稳定。

重载是**手动**的。`watchAll()` 能自动，但不要让它在生产默认开着——
改文件即刻生效意味着任何人（或 rsync）动一下脚本目录，线上逻辑就变了。

---

## 多节点：reload 一次不等于全服生效

单进程 reload 只换本进程内存里的脚本表。多节点部署下，另一个进程还在跑旧规则——
实测（`run-cluster.sh`，两个独立进程，Cluster 互连）：

| 阶段 | node1 | node2 |
|:--|--:|--:|
| before（v1） | 163 | 163 |
| 只 reload 本机 | **230** | **154** ← 还是旧规则 |
| 广播重载后 | 229 | **229** |

接线只有两步：

```php
// ① 把 Broadcast 的发送端接到 Cluster（注入，不是让 pkg 依赖 Cluster）
$svc->enableCluster($cluster);

// ② 一个每 10ms poll 的 Service：收包 → 交给 Broadcast → 非空就回 ACK
$cluster->poll();  // foreach($packets as $p) { $reply = $svc->onClusterMessage(...); }
$cluster->flush(); // 漏了会出现"发出去了但对端永远收不到"
```

协议是 `RELOAD reqId␟name` / `ACK reqId␟node␟ok␟versions`。三条语义要记住：

- **广播不等待**：`request()` 立刻返回 reqId，ack 到了再记。GM 返回 reqId，
  用 `script.cluster-status` 轮询 `done=1`。单线程里同步等会卡死整个 worker。
- **`missing` 和 `failed` 必须分开**：前者是"还没回"（网络慢/掉线），
  后者是"回了但对方重载失败"。处置方式完全不同，混在一起运维没法判断。
- **ack 的判据是版本号前进了**，不是"回了包"。这条防的是"对方 reload 返回 true
  但文件其实没变"。

## 六个坑（都是实际踩到的）

1. **每次请求都 new 视图 → 判定序列永远相同。**
   第一版 200 次请求掉 200 件，看着像 100% 掉率。原因：`seq` 从 0 开始，
   同一玩家的每次结算走同一段散列。修法：`seqBase` 由 AOT 传入全服累计序号。

2. **脚本返回数组，AOT 侧用错类型不会报错，只会静默变空。**
   边界上统一收窄成字符串（`is_string($r)`），比每处 `is_array` 判断便宜。

3. **GM 的 `data` 是裸字符串，不是 JSON 字符串。** `json_decode` 整包会失败，
   要按 `"data":` 切。`bench.php` 第一版就栽在这。

4. **`Context` 的 State 是"脚本级"的，不是"玩家级"的。**
   两个玩家共用一个 activity 脚本，`addStateInt("kills")` 是全局计数。
   要按玩家隔离，得自己在 AOT 侧按 uid 存，或者每个玩家一个 Script 实例（不推荐）。

5. **脚本挂了定时器，回调时状态已经没了。**
   结算结束 `$this->cur = null`，到点回调必须能从 token 表里取回当时的 state。
   漏了会表现为"里程碑奖励偶尔不发"，且日志里看不出异常。

6. **`rollSeq` 是进程内计数，重启会重放同一段序列。**
   两个节点的 before 段都是 163 件——这既证明"确定性随机可复现"，
   也暴露了问题：进程重启后同一玩家会再走一遍同样的掉落序列。
   生产里这个基线要来自持久化的玩家击杀数或全服计数器，不能用内存变量。

另外两条与代码无关但会卡住人：

- `scripts/` 下**不能定义 function / class**。不是语法限制——`function_exists` 包住就能
  重复 require——而是框架 **reload 时不清理符号表**：直接写会 `Cannot redeclare` 中止，
  包住之后不报错，但调用拿到的还是第一次的实现，热更等于没做。两条路都是死路。
  而且首次加载看不出来，要等第一次重载才炸。
- AOT 产物要**通过 ini 注入 `interp.so`**，不是 `dl()`：

  ```bash
  echo "extension=/path/interp.so" > /tmp/conf/interp.ini
  PHP_INI_SCAN_DIR="/tmp/conf:/usr/local/php-8.5-zts/etc/conf.d" ./rule_hot
  ```

---

## 什么时候不要用脚本

| 场景 | 为什么 |
|:--|:--|
| 帧循环里的战斗判定 | 30 Hz × 上百实体，脚本的 0.5 µs 固定开销会变成主要成本 |
| 伤害链、寻路、大数组遍历 | 脚本里的长循环没有抢占，会卡住整个 node |
| 发钱的最后一步 | 脚本只申请，执行必须在 AOT（这条是硬约束，不是性能问题） |
| 需要严格可回放的对局 | 随机、时间、ID 分配都要留在 AOT |

反过来说：**只要一条规则"会改、改得频繁、调用不密集、不动钱"，它就该在脚本里。**
活动、掉落、任务条件、商店折扣、登录奖励，都属于这一类。
