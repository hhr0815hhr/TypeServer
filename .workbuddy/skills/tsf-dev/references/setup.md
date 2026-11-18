# 初始化：从零到能跑

> **这个文件是给“新环境”用的。** 已经能跑 `tsf doctor` 的话，跳过它直接看 `SKILL.md`。

## 一、先判断你要不要走这一遍

三个检查，任意一个不过就需要初始化：

```bash
ls vendor/bin/tsf                              # ① composer 装的框架和命令
ls ~/.tsf/toolchain/typephp/bin/tpc.php        # ② 工具链（编译器）
php85 -i | grep 'Thread Safety.*enabled'       # ③ PHP 必须是 ZTS
```

**或者直接跑**：

```bash
bash .workbuddy/skills/tsf-dev/scripts/preflight.sh .
```

它会告诉你缺哪一步。

## 二、三步装好

### 第 1 步：框架（约 3.2 MB）

```bash
composer require hhr0815hhr/tsf
```

装完得到 `vendor/bin/tsf`。

> **前提**：PHP 8.5 ZTS。8.4 及以下会被 composer 拒绝，NTS 版能装上但链接期会报一堆
> 和代码无关的错误。先确认：`php -v`。

### 第 2 步：工具链（约 42 MB，解压 168 MB）

```bash
curl -fsSL https://raw.githubusercontent.com/hhr0815hhr/TypeServer/main/scripts/install-toolchain.sh | bash
```

装到 `~/.tsf/toolchain/`。然后加两个环境变量到 `~/.bashrc`：

```bash
export TPC=~/.tsf/toolchain/typephp/bin/tpc.php
export TSF_PHP=php85
```

> **为什么不走 composer**：tsf 不是纯 PHP 框架，它调用 typephp 编译器（`tpc`）把 PHP 编成
> C++。Composer 只能装 PHP 代码，装不了编译器本体和 C++ 后端（clang）。
>
> **为什么 42 MB**：里面有 `php-nano`（46 MB 的裁剪版 PHP 源码，用来生成 stub）和
> `libphpx.so`。都是本地构建产物，没法从 composer 拿。

### 第 3 步：自检

```bash
./vendor/bin/tsf doctor
```

**七项必要项全绿才能往下走**：

```
必要项（缺一个 tsf build 就跑不起来）
  ✓ PHP 8.5.12 (ZTS)
  ✓ swoole
  ✓ phpx 运行时
  ✓ tpc 编译器
  ✓ 编译用 PHP
  ✓ php-config
  ✓ C++ 编译器
```

**不是全绿就别往下走** —— 后面每个报错都可能是环境问题伪装成的代码问题。

## 三、常见卡点

| 现象 | 原因 | 修法 |
|:--|:--|:--|
| `composer require` 报 `could not be found in any version` | PHP 版本不够，或者包名打错 | 确认 `php -v` ≥ 8.5 且是 ZTS |
| `doctor` 报 `PHP ... (NTS)` | 装的是非线程安全版 | 换 ZTS 版（`php-config --version` 看） |
| `doctor` 报 `php-config 版本不一致` | `php-config` 指向 8.3 而 tpc 用 8.5 编的 | 设 `TSF_PHP=php85`，或改 `php-config` 软链 |
| `doctor` 报 `tpc 编译器 缺` | `TPC` 没设，或工具链没装完 | `ls ~/.tsf/toolchain/typephp/bin/tpc.php` |
| `doctor` 报 `C++ 编译器 缺` | 没装 clang/g++ | `apt install g++ clang` |
| `install-toolchain.sh` 下载卡住 | GitHub 直连慢 | 用镜像：`TSF_TOOLCHAIN_URL=<镜像地址> bash install-toolchain.sh` |
| `tsf: command not found` | 用了 `tsf` 而不是 `./vendor/bin/tsf` | 要么加 PATH，要么用完整路径 |

## 四、初始化之后

```bash
# 生成一个工程试试
./vendor/bin/tsf new hello
cd hello
../vendor/bin/tsf serve .        # 或者把 vendor/bin 加进 PATH
```

**能连上、能 echo，就说明环境真的好了。**

## 五、这个仓库本身（不是用 composer 的场景）

如果你想在 **TypeServer 仓库内**开发（改框架、跑测试），不需要 `composer require`：

```bash
git clone --recursive git@github.com:hhr0815hhr/TypeServer.git
cd TypeServer

# 框架就在 tsf/（submodule），命令是 tsf/bin/tsf
./tsf/bin/tsf doctor

# 跑测试
bash scripts/verify-all.sh
```

**区别**：

| 场景 | 用哪个 `tsf` |
|:--|:--|
| 用 tsf 写业务（独立工程） | `vendor/bin/tsf`（composer 装的） |
| 改 tsf 本身（本仓库内） | `tsf/bin/tsf`（submodule 里的） |

`preflight.sh` 两种都能自动探测。
