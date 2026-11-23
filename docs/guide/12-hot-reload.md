# 12 · 热更：为什么不用 wasm，而是内嵌 PHP

> 这一篇讲判断：**“改代码不重启”在 AOT 世界里怎么做**。
>
> 先给结论：用 `pkg/script`（AOT 二进制内嵌 PHP 解释器），**不是** wasm。
> 早期版本有过 wasm 路线，2026-11-18 整个删掉了，原因见第三节。

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
| **慢 10-50 倍** | 解释执行，别放帧循环热路径 | 高频逻辑仍用 AOT |
| **脚本崩了进程也崩** | 同进程，没有隔离 | 已做异常捕获（catch throw / bailout）；再往上靠监督树 |
| **同权限** | 脚本能调 `exec` / `file_put_contents` | **靠“脚本作者可信”**，不对外开放 |
| **不能用 `$GLOBALS`** | AOT 编译阶段拒绝 | 用返回值或闭包捕获 |
| **不能重复定义函数** | `Cannot redeclare` | 脚本 `return` 闭包，不写 `function xxx()` |
| **`$fn($obj)` 不行** | AOT 编译期要确定签名 | 走 `interp_invoke` |

**最后三条是 AOT 的固有约束**，不是 `pkg/script` 的设计缺陷。

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

// 帧循环 / 定时器里每秒一次：有变更的自动重载
$mgr->watchAll();

// 业务：受限视图（推荐）
$ctx = new Context($player, ["uid", "level", "gold"], ["gold"]);
$r = $mgr->get("quest")?->callWithContext("onQuest", $ctx, 1000);   // 1000ms 执行预算
```

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

### 6.6 和 Lua 方案对照

| | C++ + Lua | TSF |
|:--|:--|:--|
| 引擎层 | C++（编译） | AOT PHP（编译） |
| 逻辑层 | Lua（解释） | PHP 脚本（Zend 解释） |
| 换逻辑 | 换 chunk | 改文件 + `watchAll()` 自动 reload |
| 状态 | 宿主侧表 / `_ENV` | `Context` 的 state（跨 reload） |
| 宿主 API | 注册 C 函数 | `bind($cap, $fn)` |
| 崩溃隔离 | 进程 / `pcall` | `zend_try` + 结构化错误 |
| 死循环保护 | debug hook | `zend_set_timeout`（秒级） |
| 表达力 | 任意表 | 白名单字段 + 标量状态（**安全优先**） |

最后一行是**设计取向**而不是缺陷：TSF 的脚本碰不到任意业务对象，
所以复杂状态机仍应写在 AOT 里。这是拿"表达力"换"不会误改数据"。

## 七、什么时候还是该用 AOT

| 场景 | 选 |
|:--|:--|
| 帧循环、AOI、序列化 | **AOT**（脚本慢 10-50×） |
| 活动规则、任务判定、掉落公式（改得频繁、计算不重） | **脚本** |
| 需要“脚本崩了不影响主服务” | 都不合适（要进程隔离） |
| 玩家状态、背包、金币 | **AOT**（脚本通过 `Context` 改，只能按白名单） |

**一句话**：**脚本管“规则”，AOT 管“状态和热路径”。**

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
