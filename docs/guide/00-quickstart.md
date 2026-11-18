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

不确定环境齐不齐，先跑自检：

```bash
tsf doctor
```

```
TSF 环境自检   tsf 0.7.0
--------------------------------------------------------------

必要项（缺一个 tsf build 就跑不起来）
  ✓ PHP 8.5.12 (ZTS)           >= 8.5 且线程安全
  ✓ swoole                     6.2.5
  ✓ phpx 运行时                ~/.tsf/toolchain/typephp/vendor/swoole/phpx/lib/libphpx.so
  ✓ tpc 编译器                 ~/.tsf/toolchain/typephp/bin/tpc.php
  ✓ 编译用 PHP                 php85 → /usr/local/bin/php85
  ✓ php-config                 8.5.12  /usr/local/php-8.5-zts/bin/php-config
  ✓ C++ 编译器                 /usr/bin/g++

可选项（只有写 wasm 热更模块才需要，见 09-wasm-or-typephp.md）
  ✓ wasm32-wasip2-clang        ~/.local/bin/wasm32-wasip2-clang
  ✓ wasmtime                   ~/.local/bin/wasmtime
  ! wit-bindgen                缺 —— 生成接口绑定
  ! tp-precompile              缺 —— 预编译成 .cwasm

必要项全部通过（PHP 8.5.12 ZTS），可以 tsf new / tsf build
```

哪一项不是 `✓`，按它的提示补齐 —— **不要在环境不全的情况下往下走**，
否则后面每个报错都可能是环境问题伪装成的代码问题。

> CI 里用 `bash scripts/ci-preflight.sh`：检查更严格（含 wasm 工具链和 `tpc-patches/` 的补丁状态）。

## 1. 生成工程

```bash
cd /tmp
tsf new hello
```

```
  OK 工程已创建: /tmp/hello

下一步：
  cd hello
  tsf serve .            # 编译并启动
  php85 client.php       # 另开终端验证 echo
```

生成 5 个文件，各有明确职责：

```
hello/
├── project.json     工程声明：名字、启用哪些包、链接参数
├── project.yml      构建配置（tsf 生成，不要手改）
├── main.php         入口：必须有全局 main()
├── client.php       测试客户端（php85 直接跑，不参与 AOT）
└── config/tsf.json  运行时配置：端口、worker 数、日志级别
```

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
  OK 产物: /tmp/hello/hello  (2567 KB)
```

这一步做三件事：把 PHP 翻成 C++、调 g++ 编译、链接出原生二进制。
**2.5 MB 的二进制就是整个服务端**——没有 PHP 解释器、没有 VM。

第一次是**全量**编译（本项目 63 个文件，明显更久），之后走增量。

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
  OK 结构检查通过：100 个源文件（核心 + 10 个包），无 mixed 类型、命名空间与目录一致
```

## 5. 下一步

| 想做 | 看 |
|:--|:--|
| 写业务逻辑（Service、协议、调用） | [01-service.md](01-service.md) |
| 做玩法（房间、帧循环、AOI） | [02-room.md](02-room.md) |
| 存档与数据库 | [03-data.md](03-data.md) |
| 多进程 / 多节点 | [04-distributed.md](04-distributed.md) |
| 不停服改逻辑 | [06-hotreload.md](06-hotreload.md) |
| 编译报错看不懂 | [07-pitfalls.md](07-pitfalls.md) |

## 一个提醒

这个框架是 **AOT** 的：代码在编译期就变成了机器码，所以「改完立刻生效」这件事
不存在——必须重编。`tsf watch` 把重编做到 2 秒内是它的上限，也是这套方案的代价。

如果你需要「线上不停服改一行逻辑」，那是另一条轨道（wasm 模块热更），
见 [06-hotreload.md](06-hotreload.md)。**不要在开发期就为它设计**，
先用 `tsf watch` 把业务写完。
