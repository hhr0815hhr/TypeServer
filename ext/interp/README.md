# ext/interp —— PHP 脚本热更探针（SPIKE）

> **状态：可行性已验证**（五项能力全通），未生产化。
> 完整记录见 `docs/research/hot-reload.md`。

## 它解决什么

让 `tsf build` 出来的 AOT 二进制**执行外部 PHP 脚本文件** ——
这是「PHP 脚本代替 Lua」的核心能力（AOT 管性能，脚本管可热更的逻辑）。

```
AOT 二进制（C++ 对象、帧循环）
  ├── libphp.so（运行时，sapi=embed）
  ├── interp.so（本扩展：能编译文件、能执行）
  └── /opt/scripts/*.php（改了就重新 require）
```

## 三个函数

| 函数 | 状态 | 说明 |
|:--|:--:|:--|
| `interp_require_file(string $path)` | ✅ | `zend_execute_scripts`，脚本里的函数会被正确注册 |
| `interp_call_obj(string $fn, object $obj)` | ✅ | 调脚本函数并传对象，**脚本改的就是宿主那个对象** |
| `interp_eval_string(string $code)` | ❌ | `zend_eval_stringl` 在 ZTS 下 `CG()` 上下文错乱，报 `expecting ";"` |
| `interp_probe()` | ✅ | 诊断：编译器指针是否就绪、sapi 名、EG 是否初始化 |

## 编译

```bash
cd ext/interp
phpize85
./configure --with-php-config=/usr/local/php-8.5-zts/bin/php-config
make
# 产物 modules/interp.so
```

## 在 AOT 产物里加载

扩展不进系统目录也能用：

```bash
mkdir -p /tmp/myconf
echo "extension=$PWD/modules/interp.so" > /tmp/myconf/interp.ini

PHP_INI_SCAN_DIR='/tmp/myconf:/usr/local/php-8.5-zts/etc/conf.d' ./你的工程
```

**注意**：`/usr/local/php-8.5-zts/etc/conf.d` 必须保留，否则 swoole 等扩展不会加载。

## 验证过的

```
$ PHP_INI_SCAN_DIR=... ./extprobe
interp loaded: yes
script exists: yes

--- require ---
hello from dynamic file      ← 脚本真的执行了
return: true
```

## 验证过的完整链路

```
$ PHP_INI_SCAN_DIR=... ./extprobe
interp: yes

=== 1. 加载脚本 ===
require: true
onQuest exists: yes

=== 2. 传对象给脚本 ===
before  gold=500  name=alice
return  700
after   gold=700  name=alice          ← 脚本改了宿主对象

结论：对象共享，脚本改的就是 AOT 的同一个对象
```

**热更**：把脚本的 `+100` 改成 `+200`，同一个二进制重跑，`after` 从 600 变 700。

## 已知限制

1. **脚本里不能用 `$GLOBALS`** —— AOT 编译阶段就拒绝（`'GLOBALS' was not declared`）
2. **脚本和宿主同权限** —— 脚本能改任何对象，写错就崩。这是共享内存的固有代价
3. **崩溃不隔离** —— 脚本抛未捕获异常会带崩进程（要和 wasm 搭配使用）

## 别做的事

- **不要用 `zend_eval_stringl`**（实验 2 已证明在 ZTS + embed 下不工作）
- **不要在脚本里用 `$GLOBALS` / 动态变量 / 运行时反射**（AOT 都不支持）
