# GM 通道（`TSF\Gm`）

> 版本：tsf 0.10.0。核心在 `src/Gm/`（不是 pkg），默认就编进去。

## 一句话

独立端口 + token 的管理通道，跑在 Scheduler 线程里，所以**能改游戏内状态**。

## 起服务 + 注册命令

```php
use TSF\Gm\Registry;
use TSF\Gm\Result;
use TSF\Gm\Server as GmServer;

// ① 起服务（Service，要在 start() 之前 spawn）
$gmAddr = Scheduler::instance()->spawnOf(function () use ($token): Service {
    return new GmServer("127.0.0.1", 9701, $token);
});

// ② 注册命令（handler 是同步闭包，返回值就是响应）
Registry::on("config.reload", function (array $args) use ($battleAddr): Result {
    $svc = Scheduler::instance()->serviceOf($battleAddr);
    if (!$svc instanceof BattleService) {
        return Result::fail("battle 服务未就绪");
    }
    $r = $svc->gmReloadConfig();
    if (!isset($r["ok"]) || $r["ok"] !== true) {
        return Result::fail((string) ($r["msg"] ?? "失败"));
    }
    return Result::ok(json_encode(["keys" => $r["keys"]]));
}, "重载配置");
```

调用：

```bash
curl -s -X POST http://127.0.0.1:9701/gm \
     -H "X-GM-Token: $TOKEN" \
     -d '{"cmd":"config.reload","args":{}}'
```

`Registry::docs()` 能列出所有命令 —— 值得加一条 `help` 命令把它暴露出来
（GM 是"出事时才想起来"的地方，文档得自己能报）。

## 同步 vs 异步：由命令自己判断

**这是最容易做错的地方。**

| | 同步 | 异步 |
|:--|:--|:--|
| 适用 | 扣钱、改属性、封禁、重载配置 | 全服邮件、批量补发、重建索引 |
| 耗时 | 微秒~毫秒 | 秒~分钟 |
| 返回 | 改前/改后/实际结果 | task id |
| 失败 | 直接报错 | `task.status` 里看 |

**不要一刀切**：

- 说"管理面必须异步"是错的 —— 扣金币返回"处理中"，运维不知道扣没扣，
  重复操作就是重复扣。**必须同步。**
- 说"管理面必须同步"也是错的 —— 全服邮件跑三分钟，HTTP 早就超时了。**必须异步。**

框架不替命令选，只看 `args.async`：

```php
$async = isset($args["async"]) && $args["async"] === true;
if (!$async) {
    return Result::ok(json_encode($svc->gmBatchSetHp($hp)));   // 同步
}
$total = $svc->online();
$id = Task::create("player.batchSetHp", $total);
$svc->gmStartBatchHp($id, $hp);
return Result::ok(json_encode(["task" => $id, "status" => "running", "total" => $total]));
```

理由：快慢是命令的固有属性，只有写它的人知道「扣 100 金币」是微秒级、
「给 5 万人发邮件」是分钟级。放在调用方会漏，放在框架里又猜不准。

## 异步的最小契约（三条，缺一不可）

**"返回处理中"不是异步，是废的。** 必须：

1. 立刻返回一个 **task id**
2. 能按 id 查**进度和最终结果**
3. 失败要带**原因**

```php
use TSF\Gm\Task;

$id = Task::create("mail.sendAll", $total);   // 建任务
Task::progress($id, $done);                    // 执行侧每批推进
Task::finish($id, '{"sent":15302}');           // 完成，带结果
Task::fail($id, "邮件服务不可达");               // 失败，带原因
Task::status($id);                             // 查：状态/进度/耗时/结果/错误
Task::gc();                                    // 清理已完成且过期的
```

查：

```bash
curl ... -d '{"cmd":"task.status","args":{"id":"t2"}}'
# {"status":"running","total":504,"done":0,"elapsed_ms":9}
# {"status":"done","done":504,"elapsed_ms":90,"result":{"affected":504}}
```

**进度推进放执行侧，不放 Task**：只有业务 Service 知道"一批"多大。
1 万人一口气做完要几百毫秒，直接卡帧；实测实现是每帧 200 个
（504 人分 3 帧、90ms；若一口气跑完会占掉整帧预算）。

**`Task::gc()` 必须调**：静态数组不清会一路涨。demo 里挂在帧循环的低频分支上。

## 为什么 GM 可以直接调 Service（不违反 actor）

```php
$svc = Scheduler::instance()->serviceOf($addr);   // 拿实例
if (!$svc instanceof BattleService) { ... }
$r = $svc->gmSetHp($uid, $hp);                    // 同步调用
```

GM 和 Service 在**同一个 Scheduler 线程**里，不会与自己的 `onMessage` 并发。
actor 模型禁止的是「跨线程共享可变状态」，这里没有跨线程。

**但要划清边界**：

- `gm*` 前缀的方法只给管理面调用，内部**不得 spawn、不得投递消息后再等回复**
- **数据面（玩家消息）仍然必须走信箱**：高频、可丢、可异步，同步化直接吃帧预算

| | 数据面 | 管理面 |
|:--|:--|:--|
| 频率 | 每帧成千上万 | 一天几十次 |
| 可丢 | 是 | **绝不** |
| 需要结果 | 否 | **必须** |
| 走消息 | ✅ | ❌ 直接调 |

## 返回 `before` 不是装饰

```json
{"ok":true,"data":{"uid":5001,"before":999280,"hp":12345}}
```

GM 操作必须**可审计**："原来多少、改成多少"要能对上，改错了能立刻改回去。
扣钱这类更要带 trace id，事后对账能定位到是哪条指令。

## 四条"默认安全"（不是"记得配"）

| 约束 | 为什么 |
|:--|:--|
| token 为空就拒绝启动 | 不提供"方便调试的空口令模式"，那种模式一定会被带到线上 |
| 默认只绑 127.0.0.1 | 要对外必须显式改 host，改的时候会撞见那行注释 |
| token 走请求头，不走 URL | URL 会进访问日志、浏览器历史、`ps` 输出 |
| 鉴权失败限流 | 否则就是可以无限猜的口令框（同 IP 一分钟 10 次 → 429 + warn） |

**别加 `--no-auth` 开关**。"方便"是安全配置最常见的杀手。

## 坑

| # | 坑 | 现象 |
|:--|:--|:--|
| 1 | 返回"已提交/处理中" | 运维无法判断成败，重复操作造成重复执行 |
| 2 | 异步不给 task id 或查不到结果 | 同上，只是延迟暴露 |
| 3 | 长时间命令一口气跑完 | 卡住整帧，全场掉线 |
| 4 | `json_encode` 不带 `JSON_UNESCAPED_UNICODE` | 中文变 `\uXXXX`，`help` 输出看不懂 |
| 5 | 用 `deliver()` 后又想拿结果 | `deliver` 是入队（下一帧处理），当场读不到 |
