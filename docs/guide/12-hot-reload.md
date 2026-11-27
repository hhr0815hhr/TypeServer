# 12 · 热更：为什么不用 wasm，而是内嵌 PHP

> 这一篇讲判断：**“改代码不重启”在 AOT 世界里怎么做**。
>
> 先给结论：用 `pkg/script`（AOT 二进制内嵌 PHP 解释器），**不是** wasm。
> 早期版本有过 wasm 路线，2026-11-18 整个删掉了，原因见第三节。
>
> 想直接看能跑的完整例子：`demo/rule-hot/`（AOT 结算 + 脚本掉落 + 脚本活动 +
> 灰度 + GM 重载 + 成本对照，一条 `./run.sh` 跑完）。本文讲原理，那个工程讲落地。

## 一、问题定义：热更到底要什么

Lua 在游戏服务器里做热更，靠的是三件事**同时成立**：

| # | 能力 | 为什么必须 |
|:--|:--|:--|
| 1 | **跑在宿主地址空间** | 脚本能拿指针直接改 C++ 的 `Player` |
| 2 | **运行时可换代码** | 改逻辑不重启，`_G` 里的状态保留 |
| 3 | **双向调用** | 宿主能调脚本函数，脚本能调宿主 API |

**缺一条就不算“替代 Lua”**：只有 2 没有 1，脚本改了逻辑但操作不了数据；
只有 1 没有 2，能操作数据但改代码要重启（那就是 AOT native）。

## 二、三条路的实测结果

### 路线 A：wasm —— ❌ 沙箱，只给整数

```c
// 宿主和模块之间的唯一通道
int tp_call2(void *h, const char *name, long long a, long long b, long long *out);
```

**两个 `long long` 输入，一个输出，外加函数名**。没有指针、没有字符串、没有结构体。

PHP 侧封装更窄：

```php
public function call2(string $fn, int $a, int $b, int $key = 0): int
```

**所以脚本访问不到 `Player`。** 这是 WASI 组件模型的安全边界，不是能力缺失。

### 路线 B：内嵌 PHP 解释器 —— ✅ 同进程，传对象

AOT 产物**动态链接了 `libphp.so`**（`ldd` 能看到），所以能用 PHP 的编译/执行 API 跑外部脚本。

实测（`ext/interp` 扩展 + `pkg/script`）：

```
=== 传对象给脚本 ===
before  gold=500  name=alice
return  600
after   gold=600  name=alice          ← 脚本改的就是宿主那个对象
```

**AOT 的 `php::Object` 和解释器的 `zend_object` 是同一套结构、同一个堆**，传引用即可。

改脚本再跑：

```
before  gold=500
return  700      ← +200（改造后的新逻辑）
after   gold=700
```

**改文件即生效，不重启、不丢状态。**

### 路线 C：AOT native —— 性能最好，但不能热更

这条不用比：编进二进制了，改一行代码就要重新编译 + 重启。

## 三、三条路的能力对照

| 能力 | C++ + Lua | **AOT + 内嵌 PHP** | AOT + wasm |
|:--|:--:|:--:|:--:|
| 同地址空间 | ✅ | ✅ | ❌ |
| 脚本改宿主数据 | ✅ userdata | ✅ **已验证** | ❌ |
| 热更（改脚本） | ✅ | ✅ **已验证** | ⚠️ 换模块 |
| 状态保留 | ✅ `_G` | ✅ **已验证** | ❌ 不迁移 |
| 宿主调脚本 | ✅ | ✅ **已验证** | ⚠️ 只有 `call2` |
| 脚本崩溃隔离 | ❌ | ❌（已做异常捕获） | ✅ 沙箱 |
| 性能开销 | Lua VM | **解释执行，慢 10-50×** | 跨边界开销 |

**AOT + 内嵌 PHP 在“替 Lua”这件事上，除了崩溃隔离（Lua 也没有），其他全对得上。**

## 四、为什么不用 wasm（三条硬理由）

**① 跨边界只有整数**

`tp_call2` 的签名决定了：**你能传的只有“两个数字”**。要传玩家状态，就得自己编码成一个整数——这在真实业务里不可行（背包是数组、技能是结构）。

**② 接口名写死在上游**

```php
// tsf/pkg/wasm/src/Iface.php（已删除）
public const PACKAGE = "typephp:s1-probe@1.0.0";
```

注释写得很清楚：

> 这个 world 名硬编码在 typephp 的 C++ host 里，**不在本仓库**。
> 想支持自定义接口，得改 typephp 上游。

**所以你连“多加一个 host 函数”都做不到。**

**③ 换实现不迁移状态**

wasm 的定位是“灰度换实现”，但**换的那一刻状态是不带过去的**。而热更的核心诉求恰恰是“换代码、留状态”。

## 五、内嵌 PHP 的代价（必须知道）

| 代价 | 说明 | 怎么应对 |
|:--|:--|:--|
| **慢 5–12 倍（实测）** | 早期写的"10–50 倍"是估算，2026-11-26 标定后修正。绝对值 0.5–3 µs/次 | 见下面的判定线，别放"每帧 × 每人"的热路径 |
| **脚本崩了进程也崩** | 同进程，没有隔离 | 已做异常捕获（catch throw / bailout）；再往上靠监督树 |
| **同权限** | 脚本能调 `exec` / `file_put_contents` | **靠"脚本作者可信"**，不对外开放 |
| **不能用 `$GLOBALS`** | AOT 编译阶段拒绝 | 用返回值或闭包捕获 |
| **不能重复定义函数** | 符号表没清：直接写会 `Cannot redeclare`，用 `function_exists` 包住则永远拿旧实现 | 脚本 `return` 闭包，不写 `function xxx()` |
| **`$fn($obj)` 不行** | AOT 编译期要确定签名 | 走 `interp_invoke` |

**最后三条是 AOT 的固有约束**，不是 `pkg/script` 的设计缺陷。

### 5.1 实测成本（`bench/script-cost`，2026-11-26）

PHP 8.5.12 ZTS + opcache，WSL2 4 核，每组 5 轮 × 20000 次取每轮总耗时中位数；
连跑 3 次，下表取中间那次。

| 用例 | 脚本 | AOT 直调 | 倍数 | 归因 |
|:--|--:|--:|--:|:--|
| `noop`（空调用） | 565 ns | 105 ns | **5.4×** | 跨边界本身的固定成本 |
| `noopCtx`（空调用 + Context） | 1092 ns | — | — | 包装比 `call` 多 **527 ns**，其中绝大部分是 `zend_set_timeout` |
| `fields`（3 读 1 写，白名单） | 2733 ns | 216 ns | **12.7×** | 每次字段访问约 **410 ns** |
| `view`（10×4 次方法调用） | 1808 ns | 3134 ns | **0.58×** | 每次方法调用约 **45 ns**，比白名单快 9 倍 |
| `state`（100 次 `addStateInt`） | 29758 ns | 4875 ns | **6.1×** | 每次状态读写约 **270 ns** |

三个非直觉的结论，都有复现命令：

1. **"慢 10–50 倍"是错的。** 真实是 5–12 倍，而且绝对值只有 0.5–3 µs——比"一个 DB 查询"便宜两个数量级。
2. **脚本调 AOT 方法比 AOT 自己调还快（0.58×）。** 解释器走 zend 的调用缓存，
   而 AOT 每次方法调用要走完整的调用协议（参数打包 + 返回值检查）。
   同一逻辑"拆开写"能快 15%（3702 → 2625 ns），但仍追不上解释器。
   **这说明 AOT 的优势在计算，不在调用。** 方法调用密集的代码，AOT 不一定赢。
3. **白名单字段访问是脚本层最贵的一环（410 ns/次），比方法调用贵 9 倍。**
   这条直接决定了下一条：类型化视图不只是"表达力更好"，它**也更快**。

### 5.2 判定线

按 `调用次数/秒 × 单次成本` 估，别只看倍数：

| 场景 | 估算 | 结论 |
|:--|:--|:--|
| 活动判定、任务达成（每人每分钟 1 次） | 1.7 万在线 × 1/60 × 3 µs ≈ **0.9 ms/s** | 随便放 |
| 玩家操作路径（每人每秒 10 次） | 1.7 万 × 10 × 3 µs ≈ **0.5 s/s** | **放不下**，这是 AOT 的地盘 |
| 帧循环里每帧 100 次调用 | 100 × 3 µs = **0.3 ms/帧**（50 ms 预算的 0.6%） | 可以放，但要盯住调用次数 |
| 一次脚本里做 100 次状态读写 | 100 × 270 ns ≈ **27 µs** | 相当于 0.05 个帧，别在帧里对每个人跑 |

**一句话**：脚本按"**次**"收费，不按"行"收费。低频高价值的规则放脚本毫无压力；
高频的判等、伤害、寻路放 AOT。


## 六、怎么用

> **2026-11 更新**：这一节按当时的实现重写过。脚本层现在的能力面比早期版本宽得多
> （定时器、发消息、读配置、脚本间调用、私有状态、执行预算），
> 早先"只能改白名单字段"的说法已经过时。

### 6.1 装配

```php
use TSF\Script\Context;
use TSF\Script\Manager;

$mgr = new Manager();
$mgr->register("quest", "/opt/scripts/quest.php");
$mgr->register("activity", "/opt/scripts/activity.php");
$mgr->loadAll();

// 重载是**手动动作**，由 GM 命令触发（见 6.1.1）。默认不在任何热路径里。

// 业务：受限视图（推荐）
$ctx = new Context($player, ["uid", "level", "gold"], ["gold"]);
$r = $mgr->get("quest")?->callWithContext("onQuest", $ctx, 1000);   // 1000ms 执行预算
```

#### 6.1.1 为什么重载默认是手动的

`Manager` 提供了三种重载方式，**没有一个会自动跑**：

| 方法 | 语义 | 用在哪 |
|:--|:--|:--|
| `reload($name)` | 指定脚本**强制**重读（不看 mtime） | GM 命令 `script.reload` |
| `reloadAll()` | 全部强制重读 | GM 命令 `script.reload-all` |
| `watchAll()` | 扫一遍，**有变更的**才重载 | 开发期临时用，见下 |

不把 `watchAll()` 挂到帧循环/每秒定时器上，理由不是性能（虽然每秒 N 次
`stat` 确实会随着脚本数增长），而是**语义**：

- **自动重载 = 改文件即刻生效。** 生产环境上，任何人、任何同步工具
  （rsync / IDE 自动上传 / 容器挂载）动一下脚本目录，线上逻辑就换了——
  没有确认、没有审计、出事也查不到是谁改的。
- **手动重载可审计。** GM 命令天然带操作人、时间、目标，出问题能回溯。
- **"改完立刻生效"在线上是个危险默认。** 改脚本的正确流程是：改 → 测试环境验证 →
  GM 重载 → 观察指标。自动重载把这个流程压成"改"，中间两道全没了。

GM 命令的参考实现。注意 **`Manager` 活在业务 Service 里**，而 GM 命令通常注册在
另一个上下文——所以要经 `Scheduler::serviceOf()` 转发过去（`demo/battle` 的
`config.reload` 就是这么写的，可以直接照抄结构）：

```php
// ① 业务 Service 里持有 Manager，并暴露一个方法给 GM 调
final class QuestService extends Service
{
    private Manager $mgr;

    /** @param string $name 空串表示全部 */
    public function gmReload(string $name): array
    {
        if ($name === "") {
            return ["ok" => true, "names" => $this->mgr->reloadAll()];
        }
        if (!$this->mgr->reload($name)) {
            return ["ok" => false, "msg" => "不存在或加载失败: " . $name];
        }
        return ["ok" => true, "names" => [$name]];
    }
}

// ② main.php 里注册 GM 命令，转发到那个 Service
Registry::on("script.reload", function (array $args) use ($questAddr): Result {
    $svc = Scheduler::instance()->serviceOf($questAddr);
    if (!$svc instanceof QuestService) {
        return Result::fail("quest 服务未就绪");
    }
    $name = isset($args[0]) ? (string) $args[0] : "";
    $r = $svc->gmReload($name);
    if (!isset($r["ok"]) || $r["ok"] !== true) {
        return Result::fail(isset($r["msg"]) ? (string) $r["msg"] : "重载失败");
    }
    $names = isset($r["names"]) && is_array($r["names"]) ? $r["names"] : [];
    return Result::ok(implode(", ", $names) === "" ? "（没有注册脚本）" : implode(", ", $names));
}, "重载脚本：script.reload <name>；不带参数 = 全部");

// ③ 别名（可选）：全部重载
Registry::on("script.reload-all", function (array $args) use ($questAddr): Result {
    $svc = Scheduler::instance()->serviceOf($questAddr);
    if (!$svc instanceof QuestService) {
        return Result::fail("quest 服务未就绪");
    }
    $r = $svc->gmReload("");
    $names = isset($r["names"]) && is_array($r["names"]) ? $r["names"] : [];
    return Result::ok(implode(", ", $names) === "" ? "（没有注册脚本）" : implode(", ", $names));
}, "重载全部脚本");
```

为什么绕一圈转发、不直接 `$mgr->reload()`：`Manager` 的实例在业务 Service 的
协程上下文里（脚本对象、`Context` 状态都归它管）。从 GM 的上下文直接调，
等于跨 Service 操作别人的状态——`serviceOf()` + 转发才是框架认可的方式。

调试期确实想"改完就生效"，可以自己挂一个**低频**定时器（5s 以上），
并且**只在开发环境配置里开**：

```php
// onStart 里：开一个 5 秒间隔的定时器（interval 是"按毫秒投消息"，不是回调）
if ($cfg->getBool("script.autoReload", false)) {   // 默认 false
    $this->interval(5000, Msg::SCRIPT_WATCH);
}

// onMessage 里：
if ($m->msgId === Msg::SCRIPT_WATCH) {
    $changed = $mgr->watchAll();
    if ($changed !== []) {
        Logger::info("script auto reload", ["names" => $changed]);
    }
    return;
}
```

（`every()` 收的是 cron 字符串，别和 `interval()` 混了：
`every("0 5 * * *", ...)` 是每天 5 点，`interval(5000, ...)` 是每 5 秒。）

关键在 `getBool("script.autoReload", false)` 这个**默认值 false**——
配置项存在是为了让人显式打开，而不是让它悄悄生效。

### 6.2 脚本能做的事

| 类别 | 方法 | 说明 | 未授权时 |
|:--|:--|:--|:--|
| 字段 | `getInt` `getString` `setInt` `addInt` | 白名单字段，超出即报错 | 抛 `RuntimeException` |
| 发奖 | `reward($amount, $reason)` | 宿主实现（走 Settle 还是直接加钱，业务定） | 抛错 |
| 日志 | `log($msg)` | | **静默**（日志失败不该打挂玩法） |
| 定时器 | `timer($ms, $entry)` | 到点回调用 `callWithContext($entry)` | 抛错 |
| 发消息 | `send($target, $msgId, $data)` | `target` 解析交宿主（uid / `room:123` / 服务名） | 抛错 |
| 配置 | `config($key, $default)` `configInt` | 活动参数、开关、价格表 | 返回默认值 |
| 脚本间调用 | `callScript($target, $entry, $arg)` | 递归由宿主兜底（8 层） | 抛错 |
| 私有状态 | `stateInt` `setStateInt` `addStateInt` `stateString` `stateBool` | **跨 reload 存活** | 返回默认值 |
| 入参 | `arg()` | 脚本间调用传进来的字符串 | 空串 |

能力由宿主授予：

```php
$ctx->bind("timer", function (int $ms, string $entry) use ($script): void {
    // 到点后：$script->callWithContext($entry, $ctx)
});
$ctx->bind("send", function (string $target, int $msgId, string $data): bool {
    return $gateway->push($target, $msgId, $data);
});
$ctx->bind("config", function (string $key): string {
    return Config::getString("activity." . $key);
});
```

**为什么必须显式授权**：`reward` / `send` / `timer` 都有副作用。静默成功等于
"脚本能越过宿主自己做决策"，静默失败等于"活动悄悄不生效"——两者都比报错难查。

#### 脚本间调用：能，但建议少用

`$ctx->callScript($target, $entry, $arg)` 支持脚本 A 调脚本 B：

```php
return [
    "onSettle" => function ($ctx) {
        // 入参是字符串；要传结构就 JSON —— 标准库在脚本里可用（实测 json/mb/preg/usort 都在）
        return $ctx->callScript("mail", "sendReward", json_encode([
            "uid" => $ctx->getInt("uid"),
            "gold" => 350,
        ]));
    },
];
```

三条约束，都是刻意的：

| 约束 | 原因 |
|:--|:--|
| 入参只有 `string` | 复合类型跨 interp 边界时所有权容易出错，字符串没有歧义 |
| 返回只收 `string/int/float/bool`，数组会被吞成 null | 同上；要结构化就自己 `json_encode` 再 decode |
| 最多 8 层嵌套，嵌套共用一次超时预算 | 防"A 调 B、B 又调 A"把进程拖死；每层各给 500ms 会被叠乘 |

**优先级：能力注入 > 脚本互调。** 互调会引入依赖图——reload 顺序、循环依赖、
"A 依赖 B 的旧版本"全要自己扛（Skynet 也踩过）。宿主把公共逻辑做成
`$ctx->bind("calcDamage", ...)` 的能力，既没有依赖图，又能让 AOT 侧统一校验。
代价是这类公共逻辑不能热更——这是取舍，不是遗漏。

### 6.3 一个完整的活动脚本

```php
<?php
return [
    // 玩家达成条件：判断 → 挂号 → 通知 → 发奖
    "onAchieve" => function ($ctx) {
        if ($ctx->configInt("enabled") === 0) {
            return 0;                          // 开关关了，什么都不做
        }
        $cnt = $ctx->addStateInt("issued", 1); // 跨 reload 的已发奖计数
        if ($cnt > $ctx->configInt("daily_limit", 100)) {
            $ctx->log("over limit: " . $cnt);
            return 0;
        }
        $ctx->timer(30000, "onSettle");        // 30 秒后结算
        $ctx->send("player:1001", 9001, "join");
        $ctx->reward(350, "activity_daily");
        return 1;
    },

    // 到点回调：可能调另一个脚本
    "onSettle" => function ($ctx) {
        return $ctx->callScript("mail", "sendReward", $ctx->arg());
    },
];
```

### 6.4 状态与生命周期

| 事件 | 状态 | 代码 |
|:--|:--|:--|
| 同一 Script 多次调用 | 保留 | |
| `reload()`（热更） | **保留** | 只换 `exports`，不碰 `state` |
| 进程重启 / 新建 Script | **丢失** | 需要 `state()->export()` 落 Redis/文件，启动时 `import()` |

`reload` 必然重置脚本里的 `static`（换的是代码，Lua 换 chunk 也一样），
所以"要活下来"的东西必须放 `state`。

### 6.5 安全边界

| 机制 | 值 | 作用 |
|:--|:--|:--|
| 执行预算 | 默认 1000ms | **防永久卡死**（死循环会被 `zend_set_timeout` 打断）。注意是秒级，1 秒对游戏服仍是明显停顿——它防的是"整服停机"，不是"卡顿" |
| 递归深度 | 8 层 | 脚本间调用成环时抛错，不拖死进程 |
| 字段白名单 | 逐字段声明 | 越权读写直接报错，不静默 |
| 错误隔离 | `{ok:false, class, message}` | 脚本 `throw` 不会把宿主带崩；Fatal 带文件名与行号 |

**这些边界是"敢把脚本交给运营改"的前提**，不是可选项。

其中两条已经落成门禁：`tsf lint <工程目录>` 会检查 `scripts/` 下
**不能有 `class` / `function` 定义**——**不是 PHP 不允许**：用
`if (!function_exists(...))` 包住完全能重复 require。真正的理由是
**reload 时不清理符号表**：

| 写法 | 第二次 reload | 结果 |
|:--|:--|:--|
| 直接 `function xxx()` | `Cannot redeclare` | 进程中止 |
| `if (!function_exists())` 包住 | 不报错 | **拿到的还是第一次的实现**，热更失效 |

两条路都不能用，所以约定只 `return` 闭包：入口每次从导出表现取，
reload 后自动是新版本，没有旧引用也没有符号表。

要支持定义也不是不行——`zend_hash_del(EG(function_table))` 就能删，uopz 这么干过。
但类要连带清静态属性、常量、接口/trait、已实例化对象的 ce 指针，闭包还可能
捕获旧 `op_array`；这是一次"自己重做一个 PHP 模块系统"的活儿，收益是写法好看一点。
**评估结论：不做**，理由与替代路径见 `ROADMAP-NO-GAP.md` §九。
另外**只能 `use TSF\Script\*`**（能力一律从 `Context` 取）。
不带参数的 `tsf lint` 行为不变，只查框架。

### 6.6 灰度：先让 10% 的人踩

新版本不该一次推给所有人。`pkg/script` 支持同名脚本多版本，按 uid 分流：

```php
$mgr->register("activity", "/opt/scripts/activity_v1.php");        // 基线
$mgr->registerAlt("activity", 2, "/opt/scripts/activity_v2.php");  // 新版
$mgr->setGray("activity", 10);                                     // 10% 走 v2

// 调用：同一个 uid 永远走同一个版本
$mgr->callGray("activity", $uid, "onJoin", $ctx);
```

| `setGray` | 行为 |
|--:|:--|
| 0（默认） | 全部走 `register()` 的版本。**注册了 alt 不等于启用** |
| 1–99 | 按 `uid` 分流，同一 uid 结果恒定 |
| 100 | 全部走 alt |

三个设计取舍：

- **按 uid 而不是按请求分流**。同一个玩家在一次会话里换版本，行为会跳（任务进度对不上、
  奖励发两次）。分流必须是 `uid` 的纯函数。
- **不用 `$uid % 100`**。uid 通常连续，取模会按注册批次成段划分（1001–1100 全进新逻辑），
  那就成了"按批次灰度"，出问题一波全中。这里用 Knuth 乘法散列，相邻 uid 跳 61 个桶。
- **`reload` 覆盖所有版本**。只换基线会出现"灰度中的那批人继续跑旧代码"，
  日志里还看不出差异。

回滚：`setGray("activity", 0)`，不需要改文件。`tests/script-test` 的 9 项灰度用例覆盖了
命中率、稳定性、不按段划分、reload 联动。

### 6.7 类型化视图：让脚本既能表达、又改不了数据

白名单字段（`new Context($p, ["uid","lv","gold"], ["gold"])`）只能按**字段**给权限，
写不了"三段式副本状态机""连击判定"这类需要读多处状态、调多个判断的逻辑。
类型化视图解决这个，而且**更快**——实测方法调用 45 ns/次，白名单字段访问 410 ns/次。

L1 侧（AOT 编译）写一个只读视图：

```php
final class PlayerView
{
    private Player $p;

    public function __construct(Player $p) { $this->p = $p; }

    public function level(): int           { return $this->p->level; }
    public function gold(): int            { return $this->p->gold; }
    public function hpRatioPct(): int      { return intdiv($this->p->hp * 100, $this->p->hpMax); }
    public function hasBuff(int $id): bool { return isset($this->p->buffs[$id]); }

    /** 能力方法：判定留在 AOT，规则（用不用、什么时候用）可热更 */
    public function canJoin(int $needLevel): bool { return $this->p->level >= $needLevel; }

    /**
     * **必须加**。不加这一条，脚本写 `$v->level = 99` 在 PHP 8.2+ 只是 Deprecated，
     * 会静默创建动态属性——脚本以为写成功了，宿主状态其实没变。
     * "写了但没用"比报错难查得多。
     */
    public function __set(string $name, string|array|object|int|float|bool|null $value): void
    {
        throw new \RuntimeException("PlayerView 是只读视图，不能写 " . $name);
    }
}
```

脚本侧只调方法：

```php
return [
    "describe" => function ($v) {
        $n = $v->level() * 1000 + $v->gold() + $v->hpRatioPct();
        return $v->hasBuff(7) ? $n + 1 : $n;
    },
    "canJoin" => function ($v) {
        return $v->canJoin(20) ? "yes" : "no";   // 判定在 AOT，脚本只决定何时用
    },
];
```

调用：`$script->call("describe", new PlayerView($player));`

**这个坑是实测出来的**：`tests/script-test` 的 `View：脚本写属性被拦下` 第一次跑是 FAIL
（返回 `wrote`），因为 `PlayerView` 没有 `__set`，PHP 给它挂了个动态属性。
加上 `__set` 之后才是 `blocked`。

### 6.8 和 Lua 方案对照
| | C++ + Lua | TSF |
|:--|:--|:--|
| 引擎层 | C++（编译） | AOT PHP（编译） |
| 逻辑层 | Lua（解释） | PHP 脚本（Zend 解释） |
| 换逻辑 | 换 chunk | 改文件 + GM 命令 `script.reload`（手控，可审计） |
| 状态 | 宿主侧表 / `_ENV` | `Context` 的 state（跨 reload） |
| 宿主 API | 注册 C 函数 | `bind($cap, $fn)` |
| 崩溃隔离 | 进程 / `pcall` | `zend_try` + 结构化错误 |
| 死循环保护 | debug hook | `zend_set_timeout`（秒级） |
| 表达力 | 任意表 | 白名单字段 + 标量状态（**安全优先**） |

最后一行是**设计取向**而不是缺陷：TSF 的脚本碰不到任意业务对象，
所以复杂状态机仍应写在 AOT 里。这是拿"表达力"换"不会误改数据"。

### 6.9 脚本的单测与错误定位

脚本是运营每周改的东西。两件事决定它能不能上生产：**改完多久知道错了**，
**错了能不能看到在哪一行**。

#### 不需要 AOT 的单测

```bash
php85 tests/script/run.php      # 不编译、不起服务
```

成立的前提是骨架的第一条规矩：`src/Domain/` 不依赖 TSF。所以原生 PHP 直接
`require` 就能当脚本的 stub 视图：

```php
require __DIR__ . "/../../src/Domain/PlayerView.php";
$e = interp_require_file(__DIR__ . "/../../scripts/levelup.php");
$e["onLevelUp"](new PlayerView(1001, 5, 0, 0));   // 直接断言
```

脚本由 `interp.so` 加载，和线上**同一份运行时**——不是"测试用另一套实现"，
所以它验证的东西线上成立。实测 `demo/rule-hot/tests/script/run.php`：
7 项断言（含"脚本与 AOT 规则逐字节一致"）在 0.05 秒内跑完。

这条通道填的是"脚本改完只能上线验"。没有它，每次改脚本都是一次小赌博。

#### 错误带位置

`interp_invoke` 的异常返回从 v0.x 起带 `file` / `line` / `trace`：

```
{
    "ok": false,
    "class": "LogicException",
    "message": "from nested closure",
    "file": "/opt/scripts/loot.php",
    "line": 9,
    "trace": "#0 /opt/scripts/loot.php(11): {closure}()\n#1 ..."
}
```

三条实现细节，改这段 C 时会再遇到：

1. `zend_call_method` / `zend_clear_exception` 不在 `php.h` 里，要显式
   include `Zend/zend_interfaces.h` 和 `Zend/zend_exceptions.h`。隐式声明会把
   `zval*` 截成 `int`，症状是"方法调了但什么都没拿到"。
2. `getTraceAsString()` 要在 `EG(exception)` **清掉之后**调。`zend_call_function`
   在已有异常时拒绝执行；所以顺序是 `GC_ADDREF` → `zend_clear_exception` →
   读属性/取 trace → `OBJ_RELEASE`。
3. trace 的第一帧是抛出点的**调用者**，抛出点本身由 `file` / `line` 补。

## 七、什么时候还是该用 AOT

| 场景 | 选 | 依据（实测见 §5.1） |
|:--|:--|:--|
| 帧循环、AOI、序列化 | **AOT** | 慢 5–6×，且这类是"每帧 × 每人"，乘起来就是几秒/秒 |
| 活动规则、任务判定、掉落公式（改得频繁、计算不重） | **脚本** | 低频，单次 3 µs 可忽略 |
| 每次玩家操作都要跑的条件判定 | **AOT** | 1.7 万在线 × 10 次/秒 × 3 µs ≈ 0.5 s/s，一个核没了 |
| 玩家状态、背包、金币 | **AOT** | 白名单访问 410 ns/次；且写操作必须受限 |
| 需要"脚本崩了不影响主服务" | 都不合适 | 要进程隔离 |

**一句话**：**脚本管"规则"，AOT 管"状态和热路径"。** 判据不是"这段代码看起来复杂不复杂"，
而是"它一秒被调多少次"。

## 八、如果你要自己验证

```bash
# 1. 编译扩展
cd ext/interp && phpize85 && ./configure --with-php-config=$(which php-config85) && make

# 2. 让 AOT 产物能加载它（不用 sudo）
mkdir -p /tmp/myconf
echo "extension=$PWD/modules/interp.so" > /tmp/myconf/interp.ini
PHP_INI_SCAN_DIR='/tmp/myconf:/usr/local/php-8.5-zts/etc/conf.d' ./你的工程

# 3. 跑回归
tsf verify --only script    # script-test 52 项
tsf verify --only http      # http-test
```

**完整研究记录**（含每个实验的原始输出）在 `docs/research/hot-reload.md`。

## 九、这段历史值得记一笔

**2026-11-18，wasm 全部删除**：`pkg/wasm`、`tsf module` 命令、`modules/` 目录生成、
`docs/DESIGN-WASM-HOTRELOAD.md`、教程第 9 篇。

**不是因为 wasm 不好**，是因为**它解决的不是这个问题**。wasm 适合“跑不可信代码、要求崩溃隔离”；
而游戏热更要的是“同一份数据上换逻辑”——这两件事的诉求正好相反。

**把这个判断写下来，是因为以后一定还会有人提“要不试试 wasm”。**
到时候有这篇能省一轮讨论。
