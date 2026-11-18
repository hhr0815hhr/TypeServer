---
name: tsf-dev
description: |
  TSF（TypePHP Server Framework，PHP → C++ AOT 编译的游戏服务器框架）项目开发。

  适用场景：
  - 基于 tsf 写游戏服（TCP/WS + 帧协议）或登录服（HTTP + 路由/中间件）
  - 加 HTTP 接口、加玩法（房间/帧循环/状态机/AOI）、加 pkg、加定时任务
  - 排 AOT 编译错误（php::Variant / cannot convert / Closure / mixed / 链接期 NTS-ZTS）
  - 调试运行期静默失败（回调不触发、引用参数不回写、消息发不出）
  - 判断"慢"是否真慢（性能基线 + 确认 -O2）
  - 发布新版本（tag、工具链包、GitHub release、Packagist）

  触发词：tsf、TypePHP 游戏服、tsf new、tsf build、tsf serve、tsf doctor、tsf lint、
  pkg/http、登录服、中间件、TokenAuth、AOT 编译错误、php::Variant、cannot convert、
  mixed、TypeServer 仓库、submodule、性能基线、吞吐、bench、tsf-dev。

  不适用：只问 TypePHP 语言本身（原生类型、tpc 构建链）—— 那用 typephp-refactor。
---

# TSF 开发

> **仓库**：`D:\hhr\TypeServer`（主仓库）+ `D:\hhr\TypeServer\tsf`（submodule → `hhr0815hhr/tsf`）
>
> **文档在哪**：教程在 `docs/guide/`（12 篇，3065 行）；框架详细说明在 `tsf/README.md`
> （3663 行，§5 是 AOT 约束，§9~§25 是各组件）。**别重复读，按下面导航过去。**

## 这个 skill 里有什么

| 文件 | 用途 |
|:--|:--|
| `references/setup.md` | **初始化**：composer → 工具链 → doctor，含 7 个常见卡点（新环境必看） |
| `references/error-codes.md` | **编译 / 链接 / 运行期 / 环境的错误对照表**，按报错关键词搜（最常用） |
| `references/add-feature.md` | 加接口 / 加玩法 / 加 pkg / 加定时任务 —— 四类场景的三步流程 + 验证命令 |
| `references/perf-baseline.md` | 性能基线 + "假性能问题"清单 + 怎么测才可信 |
| `templates/handler.php` | HTTP handler 模板（参数校验 + 业务码约定） |
| `templates/pkg.json` | 新建 pkg 的清单模板 |
| `scripts/preflight.sh` | 一键自检：doctor + lint + 常见错误扫描 + build |

**preflight.sh 用法**：

```bash
# 主仓库内的工程（自动逐级找 tsf/bin）
bash scripts/preflight.sh tests/db-mysql-test

# 独立工程（composer require 装的，或手动指定）
TSF_BIN=./vendor/bin/tsf bash scripts/preflight.sh .
```

## 0. 开工先确认（按顺序，别跳）

### 0.1 环境能不能跑？

```bash
./vendor/bin/tsf doctor      # 业务工程（composer 装的）
./tsf/bin/tsf doctor         # 本仓库内（submodule 里的）
```

**三种情况都要停下来先解决**：

| 现象 | 去看 |
|:--|:--|
| `tsf: command not found` | **`references/setup.md`** —— 还没装框架 |
| doctor 报「tpc 编译器 缺」 | **`references/setup.md`** —— 工具链没装 |
| doctor 报 NTS / php-config 不一致 | **`references/setup.md`** 的「常见卡点」 |

**新 clone 的仓库、新机器、换了 PHP 版本 —— 都必须先过这一关。**
环境不齐的时候，后面每个报错都可能是环境问题伪装成的代码问题，排查会跑偏。

### 0.2 是哪个工程？

游戏服（`tsf new`，TCP/WS，端口 9001）还是登录服（`tsf new --login`，HTTP，端口 9101）。

### 0.3 改的是框架还是业务？

- 业务工程：直接改，`tsf build` 验
- `tsf/` 下的框架代码：要先进 submodule 提交（`cd tsf && git commit && git push`），
  再回主仓库更新指针（`git add tsf && git commit`）—— **两步，漏一步别人拉不到**

## 1. 任务 → 去哪看

| 任务 | 主要参考 |
|:--|:--|
| 加 HTTP 接口、路由、中间件 | `docs/guide/12-http-api.md` |
| 登录服怎么和网关对接（token） | `docs/guide/11-login-service.md` |
| 加玩法（房间 / 帧循环 / 状态机 / AOI） | `docs/guide/03-gameplay.md` |
| 用 db / redis / mail / schedule | `docs/guide/04-data.md` + `tsf/pkg/<name>/pkg.json` |
| 分布式（RPC / 服务发现 / 分片 / 跨服 / 熔断） | `docs/guide/05-distributed.md` |
| 该用 native 还是 wasm | `docs/guide/09-wasm-or-typephp.md` |
| **编译报错 / 运行期异常 / 静默失败** | **`references/error-codes.md`** |
| 类型契约（什么类型能编过） | `docs/guide/08-aot-types.md` + `typephp-refactor` skill |

## 2. AOT 硬约束（违反必踩，全是实测过的）

完整清单见 `typephp-refactor` skill 的 `references/restrictions.md`。最常踩的五条：

1. **不用 `mixed` 类型声明** —— `tsf lint` 会拦，给替代类型
2. **不用运行时反射 / 容器** —— 依赖从构造函数显式传
3. **回调形参个数必须精确匹配** —— 多一个少一个都静默失败（异常被事件循环吞掉）
4. **闭包引用参数不回写** —— 输出参数改成返回值携带
5. **属性访问不能直接喂给需要具体类型的表达式** —— 先落局部变量

## 3. 改完必做

```bash
tsf lint .        # 禁 mixed + 命名空间与目录一致，违反返回非 0
tsf build .       # 编译（约 1.9s）
```

框架层改动（`tsf/` 下）还要跑：

```bash
bash scripts/verify-all.sh      # 197 项断言
```

## 4. 明确不要做的事

| 别做 | 为什么 |
|:--|:--|
| **手写 `project.yml`** | 漏 `-O2` 会让基准全线偏低约一倍（README §5.10 的测量事故） |
| **commit message 里用 `<` `>` `` ` `` `$`** | 被 shell 解析，改用 `git commit -F 文件` |
| **把 HTTP 塞进游戏服进程** | 短连接和长连接的连接管理会打架（max_conn / 心跳 / fd 复用） |
| **让登录服直接改游戏内状态** | 游戏服内存有副本，会被下次刷盘覆盖；应该写邮件/订单再由游戏服发放 |
| **用第三方 Web 框架（Slim/FastRoute）** | 依赖反射，AOT 编不过；用 `pkg/http` |

## 5. 发布链路（要发版时）

| 做什么 | 命令 / 位置 |
|:--|:--|
| 打 tag | `cd tsf && git tag -a v0.x.0 && git push origin v0.x.0` |
| 打工具链包 | `bash scripts/package-toolchain.sh`（42MB，含 vendor） |
| 传 release | **必须 `gh release create`**，网页上传限 25MB |
| Packagist | 已发布 `hhr0815hhr/tsf`；推 tag 后在包页面点 Update |

**工具链包体积降不下来**：`composer install --no-dev` 能得到 11MB，但缺 `php-nano`（46MB，
裁剪版 PHP 源码）和 `libphpx.so` —— 都是本地构建产物。

## 6. 三个典型场景的处理流程

### A. 编译失败

1. **先找 `error:` 真行。** 外层那句 `Compilation failed for: /path/build/tmp/<proj>/main.cc` 是包装，
   往下翻才是真正的 C++ 错误（带 `.cc:行号`）
2. 拿 `error:` 里的关键词搜 `references/error-codes.md`
3. `cannot convert 'php::Variant'` → 属性先落局部变量，再 switch / intdiv / 数学运算
4. `must be an instance of` → 检查是不是把有 `__invoke` 的对象传给声明 `\Closure` 的参数
5. 实在看不懂 → 直接看 `<proj>/../../build/tmp/<proj>/main.cc` 的那个行号，
   AOT 生成的 C++ 不复杂，对着看能明白

### B. 跑起来了但行为不对

**不要先改代码**，按这个顺序排除：

1. **`tsf build` 了吗？** AOT 不是解释执行，改了不重编**完全不生效**（而且不报错）
2. **回调形参个数对了吗？** `Timer::tick` 必须是 `function (int $timerId)`，空参**静默不触发**
3. **引用参数改了吗？** 闭包 `&$x` 不回写 → 改成返回值携带
4. **载荷形态和协议一致吗？** `payload: raw` 必须传 string，传数组会抛异常但被 handler 的 try/catch 吞掉
5. **日志能打出来吗？** `log.level` 至少 `debug`，否则上面这些 Warning 都看不到

### C. “性能不行”

**先怀疑测量，再怀疑配置，最后才怀疑代码**：

1. 对比 `references/perf-baseline.md` 的表，差多少？**差 10% 不值得动**
2. 差一倍 → **查 `-O2`**：`grep cxx-flags project.yml`，漏了就全线偏低
3. 延迟爆炸但吞吐正常 → 查闭包引用参数（同一帧被重复解析）
4. 以上都不是，才去读代码

## 7. 三个“先做这个”的习惯

| 场景 | 先做什么 |
|:--|:--|
| 任何新任务 | `tsf doctor` —— 90% 的环境问题在这一步暴露 |
| 改完代码 | `tsf build` —— 不是“看起来对就行” |
| 说“完成”前 | **实际跑一遍，贴真实输出** |

## 8. 一个纪律

**报"跑通了"之前必须实际跑过。** 这个项目的坑集中在"AOT 和 PHP 语义不同"，
而这类差异**看代码看不出来**，只有跑过才知道。

写文档、写 README 里的命令也一样 —— 没执行过的命令不要写进去（我犯过两次：
`composer config repositories.X vcs <url>` 在 2.9 不可用；端口写了 9501 但默认是 9001）。
