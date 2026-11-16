# demo 目录

两个 echo 示例，以及它们为什么不能合并。

## `demo-echo` — 正常示例

最小可用的 echo 服务：登录 → 发消息 → 原样回显。协议分帧、限流、鉴权全走配置。

- 运行时：`/usr/local/php-8.5-zts`（ZTS 8.5）
- 编译器：`$HOME/typephp-src/bin/tpc.php`（typephp **0.9.6**，带本地补丁，见 `../tpc-patches/`）
- 构建：`tsf build demo/demo-echo`

## `demo-echo-nts` — NTS 对照产物（**历史，已无法重建**）

2026-11-13 做「ZTS vs NTS」对照时编出来的那一份。

| | demo-echo | demo-echo-nts |
|:--|:--|:--|
| `main.php` / `client.php` | **逐字节相同** | 同左 |
| `src/` | 空目录 | 空目录 |
| `config/tsf.json` | 限流 50/s、鉴权开 | 限流关、鉴权关（压测用） |
| 链接的 `libphp.so` | `/usr/local/php-8.5-zts/lib/` | `$HOME/php-8.5-nts/lib/` |
| 链接的 `libphpx.so` | `typephp-src/vendor/swoole/phpx/lib/` | `$HOME/phpx-nts-home/lib/` |
| 产物大小 | 1,484,856 | 1,440,472 |

### 为什么不能合成一个工程 + 环境配置

运行时是**链接期**决定的（`-L ... -lphp`），配置文件表达不了。分层配置
（`tsf.json` → `tsf.{env}.json`）只能改参数，改不了链哪个 PHP。

### 为什么现在重建不出来

NTS 那条链需要**同时**满足两件事：为 NTS 目标构建的 `libphpx.so`，以及一个运行在
NTS PHP 上的 tpc（tpc 要求目标 PHP 与自身运行时的 major.minor / ZTS 一致）。

- 现有的 `typephp-src`（0.9.6，带补丁）是 **ZTS** 的，拿去编 NTS 目标违反这条一致性要求；
- 现有的 NTS 编译器 `typephp-095` 是 **0.9.5**，比当前源码旧，直接编不过：

```
NativeHeaderFrame.cc:23: 'php_c_encode' was not declared in this scope
NativeHeaderFrame.cc:41: 'php_c_frame_len' was not declared in this scope
Timers.cc:137: cannot convert 'php::Variant' to 'php::Int'
```

（这三处正是 0.9.6 + 本地补丁才有的能力。）

### 要恢复 NTS 对照的话

1. 用 NTS 的 PHP 重建 phpx：
   `cmake -DPHP_CONFIG=$HOME/php-8.5-nts/bin/php-config ...`（依赖 `libmpfr-dev`）
2. 用同一套 NTS PHP 编一份 tpc（`typephp-src` 的源码 + 上面的 libphpx）
3. `TSF_PHP=$HOME/php-8.5-nts/bin/php TPC=<新的 tpc.php> tsf build demo/demo-echo`

### 当前建议

`demo-echo-nts` 的源码与 `demo-echo` 完全相同，**改 `demo-echo` 时不需要同步它**——
它连不上当前工具链，同步过去也编不出东西。要的是"能跑的 NTS 对照"，就按上面三步重建；
不要的是这份历史产物，整个目录可以删掉（删之前确认还有没有人拿它做基准对比）。

## 配置里的 `${base}`

三个 demo 的 `config/tsf.json` 里，日志、sqlite、快照路径都写成 `${base}/var/...`。
`Bootstrap::boot()` 在加载配置后会把 `${base}` 展开成**本次启动的工程目录**
（`Config::expandBase()`）。

不写绝对路径的原因：仓库 clone 到别处跑起来，会把日志和库写回**原来那个目录**——
看起来一切正常，实际在污染另一棵树。这个坑在 2026-11-16 整理仓库时实际验证过。

