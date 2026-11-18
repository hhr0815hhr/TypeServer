# 研究记录：PHP 脚本热更（替 Lua）的可行性

> 2026-11-18。状态：**五项能力全部验证通过，剩下是工程化**。
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

### 实验 4：对象传递 —— ✅ **成功（决定性）**

AOT 侧建对象、传给脚本里的函数、脚本改字段、回来看是否变了：

```
=== 1. 加载脚本 ===
require: true
onQuest exists: yes

=== 2. 传对象给脚本 ===
before  gold=500  name=alice
return  600
after   gold=600  name=alice
```

**`after` 是 600，说明脚本改的就是 AOT 那个对象** —— AOT 的 `php::Object` 和解释器的
`zend_object` 是同一套结构，同一个堆，传引用即可。

**这是整条路最关键的一步**：之前最大的不确定性就是"两边对象能不能互通"，现在确认能。

### 实验 5：热更 —— ✅ **成功**

把脚本里的 `+100` 改成 `+200`，**同一个二进制**重新跑：

```
before  gold=500
return  700      ← 新逻辑生效
after   gold=700
```

**没有 opcache 缓存问题**（AOT 环境下 opcache 不介入这条路径）。

到这里，「改脚本 → 重跑 → 新逻辑生效」这条闭环验证完毕。

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

## 五、三个问题都已经解决

| # | 问题 | 怎么解决的 |
|:--|:--|:--|
| **1** | 函数定义没注册 | 改用 `zend_execute_scripts(ZEND_REQUIRE, ...)` 走完整的 require 流程。原来的 `zend_execute` + `destroy_op_array` 会把函数表一起销毁 |
| **2** | 对象传递 | **本来就通**。`Z_PARAM_ZVAL` 拿到引用，`call_user_function` 传进去，脚本改的就是原对象。唯一要修的是 `call_user_function` 的第三个参数必须是 `zval*`（函数名），传 `zend_string*` 会栈破坏 |
| **3** | 热更 | **本来就通**。改文件后重新 `zend_compile_file` 就拿到新代码，AOT 环境下没有 opcache 干扰 |

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
| 同地址空间 | ✅ | ✅ **已验证** | ❌ |
| 执行外部脚本 | ✅ | ✅ **已验证** | ⚠️ 只能整数 |
| 热更（改脚本） | ✅ | ✅ **已验证** | ⚠️ 换模块 |
| 脚本改宿主数据 | ✅ userdata | ✅ **已验证**（`$p->gold += 100` 直接生效） | ❌ |
| 宿主调脚本 | ✅ | ✅ **已验证**（`interp_call_obj`） | ⚠️ 只有 `tp_call2` |
| 崩溃隔离 | ❌（脚本崩宿主崩） | ❌（同进程） | ✅ 沙箱 |

**五项能力全部打通。剩下的工作量在工程化，不在可行性。**

**和 Lua 唯一共同的短板**：脚本崩溃会带崩宿主（因为同进程）。这是"共享内存"的必然代价。

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

## 八、下一步：工程化

**可行性已经验证完，剩下是把 spike 做成产品**：

| 顺序 | 做什么 | 估算 |
|:--|:--|:--|
| **1** | `pkg/script`：把扩展包装成 PHP 类（`load/call/reload/watch`），带文件变更检测 | 3-5 天 |
| **2** | **对象传递的类型约束**：脚本里能访问哪些字段？要不要只读？越权怎么拦？ | 2-3 天（**安全边界**） |
| **3** | 错误处理：脚本抛异常不能带崩服务，要捕获成业务错误 | 2 天 |
| **4** | 热更编排：版本目录 + 灰度 + 回滚（参考 `pkg/wasm` 的 canary） | 3 天 |
| 5 | 性能测量：每次脚本调用的开销（和 native 比差多少） | 半天 |
| 6 | 脚本沙箱边界：禁用危险函数（`exec`/`file_put_contents`/网络） | 2 天 |

**第 2 项（安全边界）最容易被低估**：脚本能改 AOT 的任何对象，等于有全部权限。
Lua 时代也是这样（`lua_pushlightuserdata` 传什么就有什么权限），但 PHP 的反射/动态特性更多，
要明确"脚本只能做什么"。

## 九、一个提醒

这套方案**让脚本获得和宿主同等的权限** —— 脚本写错一行就能把内存搞乱、把服务搞崩。
Lua 也是这样，这是"共享内存"模式的固有代价。

如果将来需要"脚本出错不影响主服务"，那就回到 wasm（但要接受它只能传整数的限制）。
**两条路是互补的，不是替代的**：

| 场景 | 用哪个 |
|:--|:--|
| 玩家状态、背包、金币（必须直接操作） | **内嵌脚本**（本方案） |
| 活动规则、掉落公式（纯计算） | **wasm**（沙箱，崩了不影响） |
