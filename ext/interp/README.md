# ext/interp —— PHP 脚本热更探针（SPIKE）

> **状态：研究性代码**，核心链路已验证，未生产化。
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
| `interp_require_file(string $path)` | ✅ **能用** | `zend_compile_file` + `zend_execute` |
| `interp_eval_string(string $code)` | ❌ **不能用** | `zend_eval_stringl` 在 ZTS 下 `CG()` 上下文错乱，报 `expecting ";"` |
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

## 已知限制

1. **脚本里不能用 `$GLOBALS`** —— AOT 在编译阶段就会拒绝（`'GLOBALS' was not declared`）
2. **函数定义注册有问题** —— `echo` 能执行，但 `function_exists("xxx")` 返回 false
   （猜测是 `destroy_op_array` 把函数表跟着销毁了，要改用 `zend_execute_scripts`）
3. **对象传递未验证** —— 传 AOT 的 `Player` 给脚本能否读写，是最大的不确定性
4. **热更未验证** —— 改文件后重新编译，但 opcache 可能还在用旧版本

## 别做的事

- **不要用 `zend_eval_stringl`**（实验 2 已证明在 ZTS + embed 下不工作）
- **不要在脚本里用 `$GLOBALS` / 动态变量 / 运行时反射**（AOT 都不支持）
