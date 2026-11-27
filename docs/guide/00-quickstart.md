# 00 · 30 分钟跑起来

> 目标：从零到一个能连、能调用的服务端。
> 每一步的命令和输出都是**实际跑过的**，照着粘贴即可。

## 0. 前置条件

**需要 PHP 8.5 ZTS（线程安全版）。** NTS 版能装上，但会在**链接期**报一堆和版本无关的错误
（phpx 依赖 ZTS 的线程模型）。

| 依赖 | 版本 | 说明 |
|:--|:--|:--|
| PHP | **8.5 ZTS** | 必须是线程安全版 |
| swoole | 6.2+ | 产物运行时的基础 |
| typephp（tpc） | 0.9.6 | AOT 编译器，随工具链包分发 |
| C++ 编译器 | clang 或 g++ | tpc 的后端 |
| 操作系统 | WSL / Linux | 目前只在 WSL 上验证过 |

**两步装好**（详见仓库根 README）：

```bash
composer require hhr0815hhr/tsf        # 框架 + tsf 命令

curl -fsSL https://raw.githubusercontent.com/hhr0815hhr/TypeServer/main/scripts/install-toolchain.sh | bash
export TPC=~/.tsf/toolchain/typephp/bin/tpc.php
export TSF_PHP=php85
```

### 不想在本机装工具链：用 docker

镜像里已经装好 PHP 8.5 ZTS + swoole + tpc 补丁 + phpx + composer，**PHP 的安装路径
和本机方案完全一致**（`/usr/local/php-8.5-zts`），所以两种方式编出来的产物可以互换跑。

```bash
# 1) 打镜像（PHP 从源码编，约 25 分钟）
cd tsf
docker build -f docker/Dockerfile -t tsf:latest .

# 2) 编译并运行仓库里的 demo（把整棵树挂进去，demo 用的是 tsf/ 子模块）
cd ..
docker run --rm -v "$PWD":/work -w /work/demo/battle tsf:latest \
  php85 /work/tsf/bin/tsf build .
docker run --rm -p 9002:9002 -v "$PWD":/work -w /work/demo/battle tsf:latest ./battle
```

**两个注意点**：

- 镜像里**没有**把 `tsf` 和 `tpc` 放进 PATH，必须用完整路径调
  （`php85 /work/tsf/bin/tsf` 或工程里的 `vendor/bin/tsf`）
- 编译产物会带 `rpath=/usr/local/php-8.5-zts/lib`。容器里编的能在容器里跑，
  宿主机上编的能在宿主机跑——**混用会报找不到 `libphpx.so`**

构建期的四个坑（工具链脚本放错仓库、CMakeCache 指向打包机、composer 的 shebang、
缺 `libgmp-dev`）和网络环境处理见 `tsf/docker/README.md`。

不确定环境齐不齐，先跑自检：

```bash
tsf doctor
```

```
TSF 环境自检   tsf 0.11.0
--------------------------------------------------------------

必要项（缺一个 tsf build 就跑不起来）
  ✓ PHP 8.5.12 (ZTS)           >= 8.5 且线程安全
  ✓ swoole                     6.2.5
  ✓ phpx 运行时                ~/.tsf/toolchain/typephp/vendor/swoole/phpx/lib/libphpx.so
  ✓ tpc 编译器                 ~/.tsf/toolchain/typephp/bin/tpc.php
  ✓ 编译用 PHP                 php85 → /usr/local/bin/php85
  ✓ php-config                 8.5.12  /usr/local/php-8.5-zts/bin/php-config
  ✓ C++ 编译器                 /usr/bin/g++

可选项
  ! wit-bindgen                缺 —— 生成接口绑定

必要项全部通过（PHP 8.5.12 ZTS），可以 tsf new / tsf build
```

哪一项不是 `✓`，按它的提示补齐 —— **不要在环境不全的情况下往下走**，
否则后面每个报错都可能是环境问题伪装成的代码问题。

> CI 里用 `bash scripts/ci-preflight.sh`：检查更严格（含 `tpc-patches/` 的补丁状态）。

## 1. 生成工程

```bash
cd /tmp
tsf new hello
```

生成时会**交互问你启用哪些可选功能**（db / room / aoi / redis …），
直接回车就是一个都不用，以后改 `project.json` 也能加：

```
要启用哪些可选功能？（直接回车 = 都不用；以后改 project.json 也能加）

   1) aoi        九宫格视野（无状态，格子坐标的纯函数）
   2) db         数据库：连接管理（重连/事务）+ 基于 Attribute 的轻量仓储
  ...
  13) room       房间：状态机 + 成员表（含身份与席位，不含坐标）  [自动带上 fsm,frameloop]
  ...

  输入编号（逗号或空格分隔，如 1,5,9），回车跳过 >
```

非交互环境（CI、`< /dev/null`、管道）不会卡住，按"一个都不用"处理。
脚本里想指定就直接给参数：

```bash
tsf new hello --packages=db,room     # 显式指定（依赖自动补齐）
tsf new hello --no-packages          # 明确一个都不用
```

```
  OK 工程已创建: /tmp/hello

下一步：
  cd hello
  tsf serve .            # 编译并启动
  php85 client.php       # 另开终端验证 echo
```

生成的骨架（`ls` 实际顺序）：

```
hello/
├── project.json     工程声明：名字、启用哪些包、链接参数
├── project.yml      构建配置（tsf 生成，不要手改）
├── main.php         入口：必须有全局 main()
├── client.php       测试客户端（php85 直接跑，不参与 AOT）
├── config/tsf.json  运行时配置：端口、worker 数、日志级别
├── src/             业务代码目录（`tsf new` 时就存在，可以是空的）
└── var/             运行时产物：日志、快照、迁移记录（不要提交）
```

`src/` 和 `var/` 也在 `tsf new` 时就建好了——前者放你后面写的 Service，
后者是运行期目录，`.gitignore` 里已经排除。

**`project.json` 和 `project.yml` 的关系是最容易搞错的一处**：前者是源码（要提交），
后者是生成物——**`tsf new` 时就已产出，`tsf build` 每次覆盖**。改配置改 `project.json`，
别去改 `project.yml`（改了也会被冲掉，而且它里面的路径是绝对路径，换机器就失效）。

## 2. 编译

```bash
cd hello
tsf build .
```

```
Build successful: hello
  OK 产物: /tmp/hello/hello  (3096 KB)
```

这一步做三件事：把 PHP 翻成 C++、调 g++ 编译、链接出原生二进制。
**2.5 MB 的二进制就是整个服务端**——没有 PHP 解释器、没有 VM。

第一次是**全量**编译（新建工程 **68 个文件**，实测 **23.5 秒**），之后走增量。

增量有多快？实测：改一行到编译完成 **1651 ms**（100 ms 轮询精度，含 63 文件重编 +
增量链接）。所以别拿第一次的体感判断这个工具。

## 3. 启动并验证

开两个终端。第一个：

```bash
cd hello
./hello
```

第二个：

```bash
cd hello
php85 client.php 9001
```

```
login ok uid=1001
resp msgId=1002 body=hello-tsf-1794822609
OK: echo matched
```

三行分别对应：协议握手（`login`）、业务响应（`echo` 把 body 原样带回）、
客户端断言比对。

**这一刻你已经有了一个原生的 TCP 游戏服务端**，剩下的是往里写业务。

## 4. 开发循环：改一行看效果

服务端平时不用手动敲 `tsf build`，用 watch：

```bash
tsf watch .
```

它在源码变动后自动重编、重启、跑预设的验证脚本。

**实测**：改一行到编译完成 **1651 ms**（同上，watch 的增量路径与手动 `tsf build` 一致）。
加上服务重启与一次客户端往返，2 秒内能看到变化——这也是这套 AOT 方案在开发期的
体验上限。

提交前跑一次 lint（会把 `mixed`、命名空间与目录不一致等问题拦下）：

```bash
tsf lint
```

```
  OK 结构检查通过：137 个源文件（核心 + 16 个包），无 mixed 类型、命名空间与目录一致
```

## 5. 下一步

| 想做 | 看 |
|:--|:--|
| 写业务逻辑（Service、协议、调用） | [02-service.md](02-service.md) |
| 做玩法（房间、帧循环、AOI） | [03-gameplay.md](03-gameplay.md) |
| 存档与数据库 | [04-data.md](04-data.md) |
| 多进程 / 多节点 | [05-distributed.md](05-distributed.md) |
| 不停服改逻辑 | [12-hot-reload.md](12-hot-reload.md) |
| 编译报错看不懂 | [08-aot-types.md](08-aot-types.md) |

## 一个提醒

这个框架是 **AOT** 的：代码在编译期就变成了机器码，所以「改完立刻生效」这件事
不存在——必须重编。`tsf watch` 把重编做到 2 秒内是它的上限，也是这套方案的代价。

如果你需要「线上不停服改一行逻辑」，那是另一条轨道（`pkg/script` 热更），
见 [12-hot-reload.md](12-hot-reload.md)。**不要在开发期就为它设计**，
先用 `tsf watch` 把业务写完。
