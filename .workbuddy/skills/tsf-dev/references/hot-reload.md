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
$mgr->loadAll();

// 帧循环 / 定时器里每秒一次
$mgr->watchAll();          // 有变更的自动重载，返回重载了哪些

// 业务：用受限视图（推荐）
$ctx = new Context($player, ["uid", "level", "gold"], ["gold"]);
$mgr->get("quest")?->callWithContext("onQuest", $ctx);
```

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
| **1** | **脚本不能 `function xxx()`** | 热更时第二次 require 报 `Cannot redeclare`。必须 `return` 闭包 |
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
