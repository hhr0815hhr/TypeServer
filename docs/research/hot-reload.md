# 研究记录：PHP 脚本热更（替 Lua）的可行性

> 2026-11-18。状态：**核心链路已验证，距离可用还差工程化**。
>
> 相关代码：`spike/interp-ext/`（扩展源码，`modules/*.so` 不入库）

## 一、背景与动机

项目初衷是**用 PHP 脚本代替 Lua**（游戏服务器的热更逻辑层）。Lua 的能力模型是：

1. 跑在宿主地址空间（能拿指针改 C++ 数据）
2. 运行时可换代码（`luaL_dofile` 重载，`_G` 状态保留）
3. 宿主能调脚本、脚本能调宿主

**两条路都试过了**：

| 路线 | 结论 |
|:--|:--|
| **wasm**（`pkg/wasm`） | ❌ 沙箱，跨边界只有 `tp_call2(2 个 long long) → long long`，**访问不到宿主数据** |
| **内嵌 PHP 解释器**（本文） | ⚠️ **核心链路通了**，还差工程化 |

## 二、验证方法

AOT 产物（`tsf build` 出的二进制）**动态链接了 `libphp.so`**：

```
$ ldd ./extprobe
  libphpx.so => ~/.tsf/toolchain/typephp/vendor/swoole/phpx/lib/libphpx.so
  libphp.so  => /usr/local/php-8.5-zts/lib/libphp.so        ← PHP 运行时在
```

既然运行时在，就能用 PHP 的编译/执行 API 执行外部脚本。验证方式是写一个最小扩展
（`spike/interp-ext/`）暴露两个函数：

```c
interp_eval_string(string $code)     // 执行字符串
interp_require_file(string $path)    // 编译并执行文件
interp_probe()                       // 报告运行时状态
```

## 三、三个实验的结果

### 实验 1：AOT 产物能加载扩展 —— ✅ 成功

**AOT 产物本身就加载了 57 个扩展**（`get_loaded_extensions()` 能看到 `swoole`、`redis` 等），
说明它**读了 php.ini**。

**加载自定义扩展的方式**（不需要 sudo）：

```bash
mkdir -p /tmp/myconf
echo 'extension=/path/to/interp.so' > /tmp/myconf/interp.ini

PHP_INI_SCAN_DIR='/tmp/myconf:/usr/local/php-8.5-zts/etc/conf.d' ./extprobe
```

结果：

```
count=58          ← 比之前多 1
interp : yes      ← 加载成功
swoole : yes
```

**关键**：`sapi = 'embed'`（AOT 产物用的是 embed SAPI，不是 cli）。

### 实验 2：`zend_eval_stringl`（执行字符串）—— ❌ 失败

```c
zend_eval_stringl(code, code_len, &retval, "interp_eval_string")
```

无论传 `return 6*7;` 还是 `echo 42;`，都报：

```
PHP Parse error: syntax error, unexpected token "return", expecting ";" in interp_eval_string on line 1
```

**代码传入是正确的**（加了 debug 打印：`code_len=11 code=[return 6*7;]`），
**原生 `eval("return 6*7;")` 在没有扩展时是 `int(42)`**。

**推测原因**：`zend_eval_stringl` 内部设 `CG(compiler_options) = ZEND_COMPILE_DEFAULT_FOR_EVAL`，
但 **ZTS 下 `CG()` 走 TSRM 的 TLS 槽**，扩展里读到的线程上下文和 `libphp.so` 内部用的不是同一个，
导致 `compiler_options` 读到错误值，解析器状态错乱（报"期望 `;`"正是"前面有未结束语句"的表现）。

**没继续追**——因为实验 3 找到了更好的路。

### 实验 3：`zend_compile_file`（执行文件）—— ✅ 成功

```c
zend_stream_init_filename(&fh, path);
zend_op_array *op = zend_compile_file(&fh, ZEND_REQUIRE);
zend_execute(op, &retval);
```

**脚本文件** `/tmp/script1.php`：

```php
<?php
echo "hello from dynamic file\n";
```

**AOT 产物里执行**：

```
--- require ---
hello from dynamic file      ← 脚本真的执行了
return: true
```

**这条路比 `zend_eval_stringl` 更贴合需求**——热更的对象本来就是**文件**，不是字符串。

## 四、踩到的两个坑

### ① AOT 不支持 `$GLOBALS`

最初的脚本里有 `$GLOBALS['loaded_at'] = microtime(true);`，编译报：

```
error: 'GLOBALS' was not declared in this scope
```

去掉后正常。**这是 AOT 的类型系统决定的**（`$GLOBALS` 是运行时动态表，AOT 里没有）。

### ② `zend_stream_init_filename` 在 8.5 返回 void

```c
// ✗ invalid use of void expression
if (!zend_stream_init_filename(&fh, path)) { ... }

// ✓ 直接调用，打开失败由 zend_compile_file 报
zend_stream_init_filename(&fh, path);
```

## 五、还剩三个问题

| # | 问题 | 现象 | 猜测 |
|:--|:--|:--|:--|
| **1** | **函数定义没注册** | 脚本 `echo` 能执行，但 `function_exists("onQuestComplete")` 是 `no` | 我在 `zend_execute` 之后调了 `destroy_op_array` + `efree`，函数表可能跟着销毁了；应该用 `zend_execute_scripts` 走完整的 require 流程 |
| **2** | **传 AOT 对象给脚本** | 未验证 | AOT 的 `php::Object` 和解释器的 `zend_object` 是否同一套结构，**最大不确定性** |
| **3** | **热更** | 未验证 | 改文件后重新 `zend_compile_file`，但 opcache 可能还在用旧版本，要清 |

## 六、结论

**核心命题成立**：

```
AOT 二进制（性能核心，持有 C++ 对象）
  ├── libphp.so（PHP 运行时，已初始化，sapi=embed）
  ├── interp.so（扩展：能编译文件、能执行）
  └── 外部脚本（可热更）
```

**和 Lua 的能力对照**：

| 能力 | Lua | 本方案 | wasm |
|:--|:--:|:--:|:--:|
| 同地址空间 | ✅ | ✅ | ❌ |
| 执行外部脚本 | ✅ | ✅ **已验证** | ⚠️ 只能整数 |
| 热更（改脚本） | ✅ | ⚠️ 待验证（opcache） | ⚠️ 换模块 |
| 状态保留 | ✅ `_G` | ⚠️ 待验证（对象传递） | ❌ |
| 宿主调脚本 | ✅ | ⚠️ 待验证（函数表） | ⚠️ 只有 `tp_call2` |

**剩下的都是工程量，不是"能不能"的问题。**

## 七、复现步骤

```bash
cd spike/interp-ext
phpize85
./configure --with-php-config=/usr/local/php-8.5-zts/bin/php-config
make
# 产物：modules/interp.so

mkdir -p /tmp/myconf
echo "extension=$PWD/modules/interp.so" > /tmp/myconf/interp.ini

# 在一个 AOT 工程里调
PHP_INI_SCAN_DIR='/tmp/myconf:/usr/local/php-8.5-zts/etc/conf.d' ./你的工程
```

## 八、下一步（按价值排序）

| 顺序 | 做什么 | 估算 |
|:--|:--|:--|
| **1** | 修函数注册（不用 `destroy_op_array`，改 `zend_execute_scripts`） | 2-4 小时 |
| **2** | 验证对象传递（传 AOT 的 `Player` 给脚本，脚本里读属性） | 1-2 天（**最大不确定性**） |
| 3 | 热更验证（改脚本 + 清 opcache + 重新编译） | 半天 |
| 4 | 生产化（错误处理、超时、内存泄漏、脚本沙箱边界） | 3-5 天 |

**第 2 步是成败关键**：如果 AOT 对象和解释器对象不兼容，整个方案要重新设计。
