# CI：全部跑在 GitHub 托管 runner 上

> 更新：2026-11-27。**不再需要自托管机器**。
>
> 之前的方案是 `lint` 走托管、`verify` 走 `runs-on: [self-hosted, tsf]`，理由写在
> 旧版文档里：tpc 不在本仓库、PHP 8.5 ZTS + swoole + phpx 装起来要十几分钟。
> 这个理由只对了一半——**装一次很贵，但装好之后可以缓存**。以下是现在的做法。

## 两个仓库，各管一段

| 仓库 | 管什么 | 触发 | 耗时 |
|:--|:--|:--|--:|
| `tsf`（子模块，发布到 Packagist） | 包完整性：`lint`、全量 `php -l`、`composer validate`、三种模板能生成且语法正确 | push / PR | ~3 分钟 |
| `TypeServer`（根，测试载体） | 全量回归：67 个套件（含连真库的） | push / PR | ~20 分钟 |

为什么不全放 tsf 仓库：**套件在根仓库**——`tests/`、`demo/` 里的回归都在这里，
tsf 仓库里没有它们，`verify` 跑不起来。（`ext/interp` 的源码自 2026-11-27 起在 tsf 仓库的
`ext/interp/`，随 composer 包分发；本仓库的 CI 从那里编译。）

为什么也不能只留根仓库：改了 `tsf/src/` 之后，tsf 仓库自己的 push 没有任何检查，
而它才是用户 `composer require` 拿到的东西。包完整性和全量回归是两件事，
前者几分钟、不需要工具链，后者十几分钟、要 PHP 8.5 + tpc。

两份 workflow 各管各的，不互相引用。

## 根仓库的两个 job

| job | 内容 | 典型耗时 |
|:--|:--|--:|
| `lint` | `tsf lint` + 全量 `php -l`，PHP 8.3 | 20 秒 |
| `verify` | 装环境（缓存命中则解包）+ 起 redis/mysql/pg + `tsf verify` 全部套件 | 首次 ~25 分，之后 ~18 分 |

两个都在 `ubuntu-24.04`（GitHub 托管）上跑，`concurrency` 会取消同一 ref 的旧任务。

## 环境怎么装：`scripts/ci-setup.sh`

```bash
bash scripts/ci-setup.sh            # 幂等安装，已装的步骤跳过
bash scripts/ci-setup.sh --check    # 只检查，缺什么列什么，退出码 1
```

它装六样东西，顺序固定：

1. **PHP 8.5.12 ZTS + `--enable-embed=shared`** —— 产出 `libphp.so`，AOT 产物要链它。
   同时把 `pdo_mysql` / `pdo_pgsql` / `pdo_sqlite` 直接编进核心，省掉本地那套
   "单独补 pdo_pgsql.so、还得在完整源码树里 phpize" 的折腾；`--with-zip`
   （`config-check` 套件用它手搓 xlsx，composer 装包也离不开）。
2. **swoole 6.2.5，`--enable-swoole-thread`** —— 要求 PHP 是 ZTS。
3. **phpredis 6.3.0** —— `pkg/redis` 直接 `new \Redis()`；缺了 `redis-test` / `lock-test` 必红。
4. **typephp 工具链**（`scripts/install-toolchain.sh`，tag `toolchain-0.9.6`）——
   tpc 编译器 + `libphpx.so`。**版本号是编译器的，不是框架的**，两者解耦。
5. **`ext/interp`** —— 脚本热更的运行时，`script-test` / `rule-hot` 都靠它。源码在 `tsf/ext/interp`。
6. **openpyxl** —— `config-test` 第 4、5 步要改 xlsx。

`--check` 模式在本地 WSL 上应该全绿（环境一样）。六样各有探测，
探测的是**能不能用**（`class_exists`、`php -m`、版本号比对），不是"文件在不在"。

### 第一次在 GitHub 上跑的结果（2026-11-27）

环境一次装成（约 25 分钟，全部六步都过），`verify` 跑了全部 67 个套件、0 跳过。
9 个红的全是**本地复现不了**的问题：写死的仓库路径（CI 的检出目录不是 `/data/TypeServer`）、
环境里缺 phpredis / zip / openpyxl，以及两个只在慢机器上才露头的套件。
顺着它们还挖出两个"红着也是绿的"套件（`schedule`、`eventlog`，见 CHANGELOG 的 v1.0.0-rc.3）。
教训与 §60.1 是同一条：**回归的可信度取决于它在多少种环境里跑过**。

### 两个不能改的路径

```
/usr/local/php-8.5-zts                                ← tsf build 的 rpath
$HOME/.tsf/toolchain/typephp/vendor/swoole/phpx/lib   ← phpx 的 rpath
```

`tsf build` 把这些写死进链接命令，换前缀的后果是产物启动时报
`error while loading shared libraries`。`docker/Dockerfile` 里有同一条注释，两处要保持一致。

## 缓存：为什么是 tar 不是目录

`actions/cache` 以 **runner 用户**身份恢复文件，而 `/usr/local` 归 root——
直接缓存目录会 restore 失败或权限错乱。所以：

```
缓存 path = $RUNNER_TEMP/tsf-env.tar          （一个文件）
命中 → sudo tar -xf ... -C /                 （还原到 /）
未命中 → 装完 → sudo tar -cf ... /usr/local/php-8.5-zts $HOME/.tsf
```

缓存的 key 里带三个版本号和 `ENV_SALT`。**改了 `ci-setup.sh` 的步骤就把 `ENV_SALT` +1**，
否则会拿着旧环境跑新脚本，出现"本地好的、CI 报缺依赖"。

缓存约 600MB，恢复约 1–2 分钟。

## 数据库为什么不用容器跑 verify

六个套件（`registry` / `mail` / `db-mysql` / `db-shards` / `db-pg` / `redis`）要连真库，
由 `scripts/dev-*.sh` 用 docker 起在 `127.0.0.1`。

如果 verify 本身跑在容器里，容器内的 `127.0.0.1` 是它自己，这六个套件只能 SKIP——
而 SKIP 会让"全绿"变成假象。所以环境装在 runner 上，数据库用 runner 自带的 docker 起。

## 三个容易踩的点

1. **子模块**。`tsf/` 是 submodule，`actions/checkout` 必须 `submodules: recursive`。
   少了它，verify 会先编译一堆、再报 `tsf/bin/tsf` 不存在。
2. **`php85` 的 symlink**。`ci-setup.sh` 建了 `/usr/local/bin/php85`，但它不在缓存里
   （缓存只有 `/usr/local/php-8.5-zts` 和 `~/.tsf`）。所以解包步骤里要重新 `ln -sf`。
3. **缓存不跨 OS**。key 里带 `runner.os`，将来加 macOS runner 不会误命中。

## 本地等价物

CI 里没有第二套逻辑，本地跑的就是 CI 跑的命令：

```bash
bash scripts/ci-setup.sh --check          # 环境
bash scripts/dev-redis.sh                 # 三个服务（缺哪个起哪个）
bash scripts/dev-mysql.sh
bash scripts/dev-pg.sh
TSF_PHP=php85 TPC=$HOME/.tsf/toolchain/typephp/bin/tpc.php ./tsf/bin/tsf verify
```

"CI 绿、本地红"或者反过来，都说明有一边在说谎——这时先查环境和版本，别查代码。
