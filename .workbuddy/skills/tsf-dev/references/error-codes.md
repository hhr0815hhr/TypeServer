# AOT 错误对照表

> **用法**：把报错里的关键词（引号里的内容、`php::` 后面的类型名、`.cc:行号`）拿来搜这个文件。
>
> 这张表的价值在于**症状和病因不对应**：AOT 的报错经常指向"调用处"而不是"根因"，
> 或者干脆不报错但行为不对。光读文档学不到，只能踩过才知道 —— 所以把它沉淀下来。

## 先记下你的版本

```bash
tsf --version     # TSF 0.8.0
tpc --version     # TypePHP Compiler (AOT) v0.9.6
```

下面每条都标了「实测（tpc x.y.z）」。**版本不同，结论可能不一样** ——
`Cannot re-assign` 在 0.9.6 已经不报了（更早的会报），`php::Variant` 目前仍然会报。

**拿不定时先贴版本号**，比什么都强。

---

## 一、编译期（tpc 报错）

### `Cannot re-assign variable from php::Str to php::Int`

> **实测更新（tpc 0.9.6）**：这个报错**已经不再触发**。
> 用无类型声明的函数先传 `int` 再传 `string`，编译通过（AOT 做了联合处理）。
>
> 保留这条是因为：① 用 0.9.6 之前的 tpc 仍会报；② 报错行指向**调用处**而不是函数定义，
> 很多人会误判成"那个变量被重复赋值"。
>
> **当前真正会报的是下面那条 `php::Variant`** —— 那才是现在的坑。

**症状**（旧版）：报错行指向**调用处**，看起来像"那个变量被重复赋值"。

**真实原因**（旧版）：AOT 按**首次调用点**给形参定型。同一个函数前面传了 `int`，后面传
`string` 就编不过。

**现在的对策**：不用管（0.9.6 已修）。但如果仍在旧版工具链上，不同用途拆成不同函数。

---

### `xxx.cc:41:32: error: cannot convert 'php::Variant' to 'php::Int' {aka 'long int'}`

**症状**：报错是 **C++ 的文件和行号**（`.cc`），不是 PHP 行号。而且外层会先甩一句：

```
PHP Fatal error: Uncaught Exception: Compilation failed for: /path/build/tmp/<proj>/main.cc
```

**要往下翻找 `error:` 那一行**，那才是真正原因。

**两个实测会触发的写法**（tpc 0.9.6，2026-11 验证）：

```php
// ① switch 直接吃属性
switch ($m->msgId) { ... }

// ② 属性直接喂给需要具体类型的表达式
intdiv($m->msgId, 2)
```

**真实原因**：AOT 生成 `php::Int tmp = m.attr(...)`，而**属性访问返回 `php::Variant`**，
没有隐式转换就编不过。

**修法**：先落成局部变量（**声明类型**，别靠推断）：

```php
$msgId = $m->msgId;      // 这一行的返回值就是具体类型了
switch ($msgId) { ... }

$half = intdiv($m->msgId, 2);   // ✗
$id = $m->msgId;                 // ✓ 先取出来
$half = intdiv($id, 2);
```

**规律**：**任何要求具体类型的表达式，都不要直接喂属性访问**。`switch` / `intdiv` /
数学运算 / 字符串函数都可能踩。

---

### `Argument "mw" must be an instance of "Closure", "Foo" given`

**症状**：传了一个"有 `__invoke` 方法的对象"给声明为 `\Closure` 的参数。

**真实原因**：**AOT 严检参数类型**，不像 PHP 那样接受可调用对象（`callable` 语义被收紧）。

**修法**：改成显式接口。

```php
// ✗ AOT 报错
public function use(\Closure $mw): self

// ✓
interface Middleware { public function handle(Request $req): ?Response; }
public function use(Middleware $mw): self
```

**实例**：`pkg/http` 的中间件就是这么定的 —— 第一次用闭包写，编译直接失败。

---

### `parameter 1 must be array, got string`

> **实测更新（tpc 0.9.6）**：这个报错**未能复现**。
> 写了 `json_decode(..., true)` 拿到的嵌套数组连做三次 `$cur = $cur["a"]` / `$cur["b"]` / `$cur["c"]`，
> 编译通过、运行输出 `deep`，没有任何报错。
>
> 可能 0.9.6 已修，或者需要更特定的场景（多层 mixed 混用、类型收窄失败）。
> **保留这条是因为旧版会触发，而且"配置值明明存在却报类型错"这个症状很干扰排查。**

**症状**：读配置时抛，但配置值**确实存在**。

**真实原因**：从 JSON 解析出的嵌套数组元素类型是 `mixed`，连续做 `$cur = $cur[$k]`
这种下钻，AOT 会在某一步插入错误的类型校验。

**修法**：
- 配置在加载时**扁平化成 `"a.b.c" => 标量`** 的单层表，运行时只做一次 `array_key_exists`
- 业务代码里，嵌套的 `array<string, mixed>` 尽量在边界处转成强类型对象

---

### `class 'Xxx' is undefined`

> **实测更新（tpc 0.9.6）**：这个是**运行期**报错，不是编译期。
>
> 写 `new \App\NonExistent\Thing()`，`tsf build` **成功**（`Build successful`），
> 跑起来才抛：
>
> ```
> PHP Fatal error: Uncaught Error: class 'App\NonExistent\Thing' is undefined in main.php:6
> ```
>
> 这一点很关键：**编译通过不代表类都在**。AOT 不会在编译期校验所有类引用。

**症状**：编译过了，一跑就挂。

**真实原因**：类被引用但源码不在 sources 里（包没启用、文件没加进 sources）。

**修法**：检查 `project.json` 的 `packages` 和 `project.yml` 的 `sources`。

**实例**：`demo/duel` 把 `entity` 包从依赖里去掉后，运行时报 `class EntitySet is undefined`。

---

## 二、链接期（这阶段的报错最难懂）

### 一堆与代码无关的符号错误

> **未独立验证**：这一条来自项目文档，未在当前环境复现（复现需要装 NTS 版 PHP，
> 会破坏现有的 ZTS 环境）。**排查思路可信，报错文本未验证**。

**症状**：编译全过，链接时报 `undefined reference to php_xxx` / `multiple definition` /
`version mismatch`，看起来像依赖装错了。

**真实原因**：**NTS vs ZTS 不匹配**。`libphpx` 依赖 ZTS 的线程模型，
而系统里可能同时装着 NTS 版 PHP。

**排查**：

```bash
php85 -i | grep -i 'thread safety'      # 必须是 enabled
php-config85 --version                  # 必须和 tpc 编译时用的 PHP 一致
```

**修法**：`tsf doctor` 会直接报这一项。不要用 NTS 版。

---

### `cannot find -lphp` / `cannot find -lphpx`

> **未独立验证**：来自项目文档，未复现（会破坏环境）。排查路径（看 `php-config --prefix`）可信。

**症状**：链接器找不到 PHP 或 phpx 的库。

**真实原因**：
- `libphp.so` 在 PHP 的 `lib/` 下，`php-config` 没配对
- `libphpx.so` 在 `vendor/swoole/phpx/lib/`，工具链包没装全

**排查**：

```bash
ls $(php-config85 --prefix)/lib/libphp.so
ls ~/.tsf/toolchain/typephp/vendor/swoole/phpx/lib/libphpx.so
```

**修法**：`tsf doctor` 逐项检查，缺什么装什么。

---

## 三、运行期（静默失败，最难查）

### 注册的回调"一次都不触发"，也没有任何报错

**症状**：`Swoole\Timer::tick(1000, fn () => ...)` 注册成功、返回 timerId，**但一次都不跑**。

**真实原因**：**AOT 对闭包实参个数是严格的**（PHP 会忽略多余实参，AOT 抛 `ArgumentCountError`）。
Swoole 调回调时固定传 `timerId`，空参闭包抛异常，而**异常被事件循环吞掉**。

**修法**：形参精确匹配。

```php
// ✗ 静默不触发
\Swoole\Timer::tick(1000, function (): void { ... });

// ✓
\Swoole\Timer::tick(1000, function (int $timerId): void { ... });
```

**推广**：**凡注册给框架或 Swoole 的回调，形参个数和类型都必须精确匹配**。
`bench/aot-semantics` 里有 45 个用例专门对照"PHP 宽松 / AOT 严格"的差异。

---

### 闭包的引用参数不回写（有 Warning，但很容易被忽略）

> **实测（tpc 0.9.6）**：会打一条 **Warning**，但**程序继续跑**，结果确实是错的：
>
> ```
> PHP Warning: stdClass::{closure}(): Argument #2 ($consumed) must be passed by reference, value given
> out=decoded:hello
> consumed=0            ← 期望 24
> ```
>
> 注意 Warning 里的 `stdClass::{closure}` —— 闭包被编译成了 `stdClass` 的成员函数，
> 参数语义和 PHP 闭包不同。**Warning 不是 Fatal，很容易被日志级别过滤掉。**

**症状**：`function (string $buf, int &$consumed)` 里改了 `$consumed`，外面读还是原值。

```php
$dec = function (string $buf, int &$consumed): ?array {
    $consumed = 24;
    return [...];
};
$frame = $dec($buf, $consumed);   // $consumed 仍然是 0，不报错
```

**真实原因**：AOT 下闭包/动态调用的参数签名在编译期不可知，**引用参数不会自动写回**。

**后果**：循环里 `substr($buf, 0)` 导致同一帧被反复解析上千次 ——
**延迟从 0.2 ms 涨到 111 ms，但吞吐看起来还行**（重复劳动也算进度）。

**修法**：**输出参数一律改成返回值携带**。解码器返回 `[msgId, flags, body, consumed]`，
而不是用引用参数回传消费长度。

---

### 消息"发出去了"但客户端收不到

**症状**：handler 里调了 `Gateway::send()`，客户端一直等。

**真实原因**：`payload: raw` 时业务消息**必须是 string**，传数组会抛
`payload=raw 时业务消息必须是 string` —— 但 handler 外面有 try/catch，**异常被吞掉**。

**修法**：确认载荷形态和协议配置一致。`payload: json` 时传数组，`raw` 时传字符串。

---

### 连接数到 8,000 就 OOM，但 RSS 只有 80 MB

> **未独立验证**：来自项目文档（`tsf/README.md` §5.11），未做连接数压测。
> 结论（内核 socket buffer 不受 RSS 统计）可信，具体数字未复测。

**症状**：

```
PHP Fatal error: Allowed memory size of 536870912 bytes exhausted
(tried to allocate 2097152 bytes)
```

**真实原因**：不是内存真的用完了，是**每个连接的内核缓冲区**（`net.ipv4.tcp_rmem` 等）
在 `ulimit` / 系统参数下被限制。RSS 只统计用户态，看不到内核 socket buffer。

**修法**：调 `max_conn` 前先看 `ulimit -n`，以及
`sysctl net.core.rmem_max / wmem_max`。`max_conn` 会被 `ulimit -n` **静默截断**。

---

### `Unsupported statement: Stmt_Echo`（全局作用域的可执行语句）

> **实测（tpc 0.9.6）**：不是"静默不生效"，是**编译期直接失败**：
>
> ```
> Fatal error: Unsupported statement: Stmt_Echo in /tmp/pC/main.php:6
> ```
>
> 报错里的 `Stmt_Echo` 是 PHP-Parser 的节点类型名。换成顶层赋值可能是
> `Stmt_Expression` 之类——**总之顶层不能有语句**。

**症状**：`main.php` 顶层写了 `echo` / `var_dump` / 赋值，编译失败。

**真实原因**：AOT 的入口是 `main()` 函数，全局作用域只允许**声明**（函数、类、常量），
不允许**执行**。

**修法**：所有逻辑放进 `function main(): void { ... }`。

---

## 四、环境与工具链

### `tsf doctor` 报某一项不是 ✓

按它给的修复命令做。常见几类：

| 报错项 | 常见原因 |
|:--|:--|
| `PHP ... (NTS)` | 装了非线程安全版 |
| `php-config 版本不一致` | `php-config` 指向 8.3 而 tpc 用 8.5 编的 |
| `phpx 运行时 缺` | 工具链包没装全，或 `TPC` 指向了源码树但 `vendor/` 缺 |
| `tpc 编译器 缺` | `TPC` 环境变量没设，或 `~/.tsf/toolchain` 没装 |

### 改了 PHP 文件没生效

**症状**：改了源码，服务行为没变。

**真实原因**：AOT 是**编译型**，产物是二进制。`embedded-files` 的运行时 `require`
从**构建期生成的 opcode 表**加载，改磁盘文件不生效；`eval()` 和动态 `include` 也不可用。

**修法**：重新 `tsf build`。热更只能走三条路：配置数据、内嵌 PHP 脚本（pkg/script）、滚动重启。

---

## 四·五、脚本热更（ext/interp）特有的坑

### `*** stack smashing detected ***` 直接崩进程

**症状**：调 `interp_call_obj` 时进程直接终止，没有任何 PHP 层报错。

**原因**：`call_user_function` 的第三个参数（函数名）必须是 `zval*`，
传 `zend_string*` 会因为类型不匹配破坏栈。

**修法**：`ZVAL_STR_COPY(&fname_zv, fn)` 包一层。

### `syntax error, unexpected token "return", expecting ";"`（在扩展里）

**症状**：`zend_eval_stringl("return 6*7;")` 报这个，但原生 `eval("return 6*7;")` 是 `int(42)`。

**原因**：ZTS 下 `CG()` 走 TSRM 的 TLS 槽，扩展里读到的线程上下文和 `libphp.so`
内部用的不是同一个，`compiler_options` 读到错误值。

**修法**：**放弃 `zend_eval_stringl`**，改用 `zend_compile_file` + `zend_execute_scripts`。

### `'GLOBALS' was not declared in this scope`

**症状**：热更脚本里有 `$GLOBALS['x'] = 1;`，AOT 编译阶段报这个。

**原因**：AOT 没有运行时的全局符号表。

**修法**：脚本里别用 `$GLOBALS`，需要跨调用共享就用闭包捕获或者返回值。

### `Cannot redeclare function`

**症状**：热更时第二次加载脚本报这个。

**原因**：PHP 不允许重复定义同名函数，AOT 的 `zend_execute_scripts` 也一样。

**修法**：脚本改成 `return function () {...};`，每次 require 得到新闭包。

---

## 五、看起来像代码问题、其实不是

| 现象 | 真实原因 |
|:--|:--|
| 基准数据全线偏低约一倍 | 手写 `project.yml` 漏了 `-O2`（见 `tsf/README.md` §5.10） |
| `composer require` 报 `could not be found in any version` | 包没发到 Packagist，或者忘了加 `repositories` |
| 端口连不上 | `tsf new` 默认 **9001**，`demo/idle` 是 **9501**，登录服是 **9101** |
| `git commit -m "..."` 内容被吃掉 | message 里的 `<` `>` `` ` `` `$` 被 shell 解析，改用 `git commit -F 文件` |
| 测试只在某个实现上通过 | 测试依赖时序，不是真通过（见 §5.6） |

---

## 六、排查顺序（建议）

```
1. tsf doctor            环境齐不齐（90% 的问题在这一步暴露）
2. 看报错是编译期还是链接期
     编译期 → 找上面的类型/反射/属性访问类
     链接期 → 几乎都是 NTS/ZTS 或库路径
3. 运行时"不报错但不对"
     先确认回调形参个数、引用参数、载荷形态
4. 性能不对
     先确认是不是 -O0（用 tsf build，别手写 project.yml）
```
