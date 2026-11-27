# 脚本热更（pkg/script + ext/interp）

> 完整研究记录在仓库的 `docs/research/hot-reload.md`。这里只放**用法和坑**。
> 版本：tsf 0.8.0 / ext/interp 0.4.0 / tpc 0.9.6

## 一句话

AOT 二进制里内嵌 PHP 解释器（`libphp.so`，sapi=embed），脚本 `return` 闭包，
宿主传对象进去 —— **脚本改的就是宿主那个对象**。

## 用法

```php
use TSF\Script\Context;
use TSF\Script\Manager;

$mgr = new Manager();
$mgr->register("quest", "/opt/scripts/quest.php");
$mgr->register("activity", "/opt/scripts/activity.php");
$mgr->loadAll();

// 重载是**手动动作**，由 GM 命令触发（三种方式，见下）
// 不要把 watchAll() 挂到帧循环上

// 业务：用受限视图（推荐）
$ctx = new Context($player, ["uid", "level", "gold"], ["gold"]);
$mgr->get("quest")?->callWithContext("onQuest", $ctx);
```

**重载的三种方式（都不自动跑）**：

| 方法 | 语义 | 用在哪 |
|:--|:--|:--|
| `$mgr->reload($name)` | 指定脚本**强制**重读（不看 mtime） | GM 命令 `script.reload <name>` |
| `$mgr->reloadAll()` | 全部强制重读 | GM 命令 `script.reload-all` |
| `$mgr->watchAll()` | 扫一遍、**有变更的**才重载 | 开发期临时用，需显式配开关 |

**为什么不挂帧循环**：`watchAll` 每秒给每个脚本做一次 `stat`，脚本一多就是纯浪费；
更重要的是语义——自动重载 = **改文件即刻生效**，生产上任何人/任何同步工具
（rsync、IDE 自动上传、容器挂载）动一下目录，线上逻辑就换了，没有确认也没有审计。

GM 命令不能直接碰 `$mgr`（它在业务 Service 里），要经 `Scheduler::serviceOf()`
转发过去：

```php
// 业务 Service 里暴露方法
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

// main.php 里注册 GM 命令（结构照 demo/battle 的 config.reload）
Registry::on("script.reload", function (array $args) use ($questAddr): Result {
    $svc = Scheduler::instance()->serviceOf($questAddr);
    if (!$svc instanceof QuestService) {
        return Result::fail("quest 服务未就绪");
    }
    $r = $svc->gmReload(isset($args[0]) ? (string) $args[0] : "");
    if (!isset($r["ok"]) || $r["ok"] !== true) {
        return Result::fail(isset($r["msg"]) ? (string) $r["msg"] : "重载失败");
    }
    return Result::ok(implode(", ", $r["names"]));
}, "重载脚本：script.reload <name>；不带参数 = 全部");
```

调试期确实想"改完就生效"，用**低频**定时器 + 默认关闭的配置项：

```php
if ($cfg->getBool("script.autoReload", false)) {   // 默认 false
    $this->interval(5000, Msg::SCRIPT_WATCH);
}
// onMessage 里：if ($m->msgId === Msg::SCRIPT_WATCH) { $mgr->watchAll(); }
```

注意 `interval(ms, msgId)` 是"按毫秒投消息"，**不是回调**；
`every()` 收的是 cron 字符串，两者别混。

**脚本形态**：

```php
<?php
// 单个入口
return function ($ctx) {
    $ctx->addInt("gold", 350);
};

// 多个入口
return [
    "onQuest"   => function ($ctx) { ... },
    "onLevelUp" => function ($ctx) { ... },
];
```

## 六个坑（都实测过）

| # | 坑 | 现象 |
|:--|:--|:--|
| **1** | **脚本不能 `function xxx()`** | 符号表没清：直接写第二次 require 报 `Cannot redeclare`；用 `function_exists` 包住则不报错但永远拿旧实现。必须 `return` 闭包 |
| **2** | **脚本里不能用 `$GLOBALS`** | AOT 编译阶段就拒绝（`'GLOBALS' was not declared`） |
| **3** | **不能写 `$fn($obj)` 调闭包** | AOT 编译期要确定签名，运行期闭包会被 tpc 拒绝。必须走 `interp_invoke` |
| 4 | `zend_eval_stringl` 不能用 | ZTS + embed 下 `CG(compiler_options)` 上下文错乱，报 `expecting ";"`。用 `zend_compile_file` |
| 5 | `call_user_function` 第三个参数要 `zval*` | 传 `zend_string*` 会 `*** stack smashing detected ***` 直接崩 |
| 6 | `Context` 的可写必须是可读子集 | 否则能写但读不回来，逻辑很怪。构造时校验 |

## 脚本出错不会崩

`interp_invoke` 已做异常隔离（ext/interp 0.5.0）：

```php
$r = $s->callWithContext("main", $ctx);

if (is_array($r) && isset($r["ok"]) && $r["ok"] === false) {
    // throw  -> $r["class"] / $r["message"]
    // Fatal  -> $r["fatal"]（进程状态可能已不可信，要当成"这一局坏了"）
}
```

**Fatal 之后不要当普通业务错误继续跑** —— 拿 `fatal` 就要重启或摘流量，
这正是监督树要解决的问题。

## 扩展怎么装

**不用**装到系统目录（会要 sudo）：

```bash
cd ext/interp && phpize85 && ./configure --with-php-config=$(which php-config85) && make

mkdir -p /tmp/myconf
echo "extension=$PWD/modules/interp.so" > /tmp/myconf/interp.ini

# 跑 AOT 产物时
PHP_INI_SCAN_DIR='/tmp/myconf:/usr/local/php-8.5-zts/etc/conf.d' ./你的工程
```

**`/usr/local/php-8.5-zts/etc/conf.d` 必须保留**，否则 swoole 等扩展不加载。

## 什么时候别用

| 场景 | 为什么 |
|:--|:--|
| 高频帧循环里的计算 | 解释执行比 AOT 慢 10-50 倍 |
| 需要"脚本崩了不影响主服务" | 同进程，脚本崩 = 服务崩（要隔离就用别的方案） |
| 脚本要读写网络/文件 | WASI 和这里都没开这些权限 |

**脚本适合**：活动规则、任务判定、掉落公式这类「改得频繁、计算不重」的逻辑。
