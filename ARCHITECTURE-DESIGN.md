# TypePHP 游戏服务端 —— 架构设计与可行性评估

> **版本**：v2.1（2026-11-11 修订）
> **输入**：`typeGame.md`（架构构想）
> **核验基线**：TypePHP **v0.9.6**（`src/Translator.php: VERSION = '0.9.6'`，源码 HEAD `4fb6d1d`，51 篇中文文档 + 源码 + examples）
> 备注：本机 MCP 索引二进制为 v0.9.5，`CHANGELOG.md` 最新章节仍停在 0.8.0 —— 版本号三处不一致，升级判断以 `src/` 与 `docs/` 为准。
> **延伸**：技术全景与运行时复核的原文（`ARCHITECTURE-EVOLUTION.md`）、最初的架构构想
> （`typeGame.md`）、旧版实施计划（`IMPLEMENTATION-PLAN.md`）**均已移除**——它们的结论并入
> `FRAMEWORK-TASK.md`（§六 早期构想的两处修正、§九 架构决策记录），原文在 git 历史里可查。
> 本文中凡提到这三份的地方，都按此理解。

---

## 修订记录（v1 → v2）

v1 有一处结论性错误，必须更正：

| 项 | v1 表述 | v2 更正 | 依据 |
|:--|:--|:--|:--|
| 增量编译 | "增量编译是官方文档自己承认的弱项" | **错误。0.9.6 已实现完整增量体系**，v1 误把 `AOT_BUILD_SPEED_RESEARCH.md`（历史研究笔记，文首自述"记录现状判断与后续研究方向，暂不绑定具体 PR"）当成现状 | `src/Build/IncrementalBuildState.php`、`IncrementalCompilationTrait.php`、`PreparedProjectCacheTrait.php`、`PrecompiledHeaderManager.php`、`TranslationUnitSplitTrait.php` |
| clang-format | "每文件串行格式化是固定开销" | **已改为默认关闭**，需显式 `--format` | `CompilerBase.php:550`、`Translator.php:643` |
| 脚本层性能 | "运行时 include 无 OPcache，性能 ≈ 裸 PHP，每次调用都编译" | **不准确**。编译只发生在热更加载那一次，`op_array` 随 Closure 驻留内存；后续调用是解释执行 opcode，不是每次重新编译 | Zend 语义 + `tests/compiler/include_require/001.phpt` |
| 执行形态 | 只讨论 AOT 与解释脚本两种 | **漏了一档**：`embedded-files` 在构建期把非 AOT 的 PHP 编译为 OPcache opcode 写入二进制，运行时从内存 opcode 表加载 | `docs/zh-cn/EMBEDDED_FILES.md`、`src/Build/EmbeddedOpcodeGenerator.php` |
| Swoole 构建 | 未说明扩展来源 | 需 `php-builder.extensions: [swoole]`，CI 必须显式传 `--php-builder`，否则用宿主 libphp 找不到扩展 | `docs/zh-cn/COMPILER_CLI.md`、`LIBPHP_INSTALLER.md` |

以下结论经复核**保持不变**：热更模型必须重构、网络层应复用 Swoole、三套对象模型的硬边界、以及预发布版本的破坏性变更风险。

---

## 一、对 typeGame.md 论断的核验

| # | md 论断 | 核验结果 | 依据 |
|:--|:--|:--|:--|
| 1 | AOT 把 PHP 翻译为 C++ 再编译为原生码 | ✅ 成立 | `README-CN.md`；编译器全自举；`bin/lib/ext` 三模式 |
| 2 | 底层复用 C++ 库，`.stub.php` 桥接零 FFI 开销 | ✅ 机制成立 | stub 契约：C++ 侧函数 `php_` 前缀 + 全 `php::` 类型，PHP 侧空函数体 |
| 3 | 内嵌 Zend VM 动态加载 PHP 脚本 | ✅ 成立（"能执行"，不等于"能热换"） | 运行时 `include`/`require` 走 ZendVM；`eval()` 可用；`tests/compiler/include_require/001.phpt` |
| 4 | "完美支持不重启进程的热更新" | ❌ **不成立** | PHP 语义禁止同进程重定义类/函数；全仓检索无任何热更设施 |
| 5 | "纯逻辑性能接近 C++" | ⚠️ 有条件成立 | 标量/native 局部、`#[Native]`、`std` 容器成立；Zend Object 与动态回退不成立 |
| 6 | 自研网络与调度骨架 | ⚠️ 部分成立 | 调度/边界映射需自研；**网络层不必自研**——`examples/swoole/{echo,http,co}.php` 证明 Swoole Server 可直接 AOT 成独立二进制 |
| 7 | `php::Box` 由 PHP GC 接管 C++ 对象 | ✅ 成立 | `MIXED_CPP_PHP.md`：Box 以 `zend_resource` 承载，析构回调触发 `Box::destroy()` |
| 8 | （md 未提及）对象模型双堆 | ⚠️ 重大遗漏 | 三套模型：Zend Object / PHPX Box / `#[Native]`。`#[Native]` 无 zval 表示、**不可进入 ZendVM 边界**，与 Zend 类**禁止互相继承** |
| 9 | （md 未提及）构建体系 | ✅ 已成熟 | 见第 5 节：依赖图最小重编 + 内容哈希 + PCH + TU 拆分 + 整项目缓存 |

**总结**："全栈 PHP + 编译提速"成立；"进程内热替换业务类"不成立；构建体系不是短板。

---

## 二、三种执行形态：性能与灵活度的连续谱

这是理解整套架构的关键。TypePHP 实际提供三档执行形态，而不是 md 假设的两档：

| 形态 | 构建期行为 | 运行时 | 可热更 | 性能 | 生态兼容 |
|:--|:--|:--|:--:|:--|:--|
| **A 机器码** | PHP → C++ → 原生码 | CPU 直接执行 | ❌ | 最高 | 受 TypePHP 子集约束 |
| **B 内嵌 opcode** | `opcache_compile_file` 生成 opcode 写入二进制 | 从内存 opcode 表加载，零解析 | ❌ | 中（省掉解析与 IO） | Composer 包可直接用 |
| **C 磁盘脚本** | 不参与构建 | 运行时 `include`，Zend 编译一次 | ✅ | 较低（每次热更加载编译一次） | 完全兼容 |

**三档的用法**：
- 热点与核心逻辑 → A；第三方 Composer 依赖、暂不支持 AOT 的遗留代码 → B；需要运营热更的薄逻辑 → C。
- 一个文件可同时出现在 `sources` 与 `embedded-files`：成功 AOT 的走 A，未进入 AOT 的自动落到 B，不会重复。
- C 的成本要算准：**编译只发生在加载那一刻**，`op_array` 随 Closure 驻留，后续调用只是解释执行 opcode。所以 T1 热更的代价是"每次热更一次编译 + 持续的解释执行"，而不是"每次调用都编译"。

---

## 三、架构设计

### 3.1 总览：四层 + 一条硬边界

```
┌─────────────────────────────────────────────────────────────┐
│ L3 热更脚本层（形态 C：运行时 include 返回 Closure）           │
│    活动规则 / 掉落表 / 数值配置 / 事件钩子                     │
├─────────────────────────────────────────────────────────────┤
│ L2 业务逻辑层（形态 A：AOT 编译的普通 PHP 类 = Zend Object）   │
│    玩家 / 房间 / 背包 / 任务 / 商店 / 邮件 / 排行榜             │
├─────────────────────────────────────────────────────────────┤
│ L1 原生核心层（形态 A：#[Native] + std 容器，纯值不跨界）      │
│    协议编解码 / AOI 网格 / 战斗 tick / 序列化 / 定时轮          │
├─────────────────────────────────────────────────────────────┤
│ L0 接入层（Swoole，C++ 实现，AOT 只编胶水）                   │
│    TCP/WS / 长度前缀分帧 / 心跳 / 连接生命周期 / 协程          │
└─────────────────────────────────────────────────────────────┘
        ▲                                        ▲
        │ 硬边界：只传 array / string / Zend 对象 │
        │ 禁止：Native 指针、Box resource 跨界    │
   L4 状态与持久化：Swoole\Table（进程间）+ Redis（跨节点）+ MySQL（落地）
   旁路：第三方依赖走形态 B（embedded-files → 构建期 opcode）
```

### 3.2 L0 接入层：用 Swoole，不自研 libhv 骨架

| 能力 | 自研 libhv + stub | Swoole（AOT 编译） |
|:--|:--|:--|
| TCP/WS/UDP 服务 | 自己写分帧、粘包、背压 | 内置 |
| 多进程 + reactor 线程 | 自己写进程管理/守护/平滑重启 | 内置 `worker_num` / `reload()` |
| 协程 | 无（需引入协程库并跨层映射） | 内置，`co.php` 已验证 `sleep()` 挂起 |
| 进程间共享状态 | 自研共享内存 + 锁 | `Swoole\Table` / `Atomic` / `Lock` |
| 定时器 / TLS | 自研 / 接 OpenSSL | 内置 |
| 维护成本 | 永久维护一段 C++ 骨架 | 跟随上游 |

**结论**：除非有"确定性 tick + 亚毫秒 P99"的硬需求（帧同步战斗服），手写 libhv 骨架是用 3–6 人月换一个更差的基础设施。

**工程注意**：
- 进程模型：`worker_num = CPU 核数`，**不用 ZTS 线程共享 Zend 对象**（ZendVM 要求每线程独立请求上下文）。
- 玩家归属：`fd → worker_id` 一致性哈希；跨玩家交互走 `sendMessage`，不共享内存。
- 构建：`php-builder.extensions: [swoole]`。CI 必须显式传 `--php-builder`，否则回落到宿主 `libphp`，扩展找不到。
- 待验证项：`Swoole\Table` / `Atomic` / `Process` 在仓库的 examples 与 tests 中**零覆盖**，属于 PoC 必测项。

### 3.3 L1 原生核心层：只处理"纯值"

三条铁律（来自 v0.9.6 对象模型约束）：
1. `#[Native]` 对象**绝不跨 ZendVM 边界**——不能传参给脚本层、不能进 `mixed`/array。
2. `std` 容器跨函数传递需 `#[StdVector(Type::Int)]` 类注解或 `unsafe_cast` + 类型 ID 校验，**只在层内流动**，出层即转普通 array。
3. 原生层由 TypePHP 的 mark-sweep GC 管理，**与 Zend GC 是两个堆**——内存问题高发区，靠长期压测验证。

| 模块 | 为什么下沉 | 实现要点 |
|:--|:--|:--|
| 协议编解码 | 每帧都跑，字节操作密集 | `#[Native]` 结构体 + `std::vector<int>` 缓冲 |
| AOI 九宫格 | 每 tick 遍历邻居 | `std::map<int, std::vector<int>>` |
| 战斗 tick / 伤害公式 | 纯数值 | 标量局部，避免 `mixed` |
| 快照序列化 | 高频纯 IO | 预分配 buffer |
| 定时轮 | 每 tick 全量扫描 | 分层时间轮 |

### 3.4 L2 业务逻辑层：编译，但保持 Zend 可见

玩家、房间、背包必须能被脚本层看到，因此用普通 PHP 类，接受引用计数与动态分派成本。

性能关键在**写静态**：TypePHP 对静态可解析的调用降级为 Native Call（缓存 handler）；真正的断层在动态调用（`$obj->$method()`、反射、`call_user_func`）。业务层规范：**写静态、别写动态**。

### 3.5 L3 热更脚本层：三级热更

| 级别 | 内容 | 生效方式 | 生效时间 | 丢状态 |
|:--|:--|:--|:--|:--:|
| **T0 配置数据** | 数值表、掉落表、活动开关 | `include` 返回 array → 原子替换静态缓存 | < 100ms | 否 |
| **T1 行为脚本** | 活动规则、条件、钩子 | `include` 返回 **Closure** → 注册表原子替换 | < 1s | 否 |
| **T1.5 WASM 模块**（待验证） | 需隔离与算力上限的中等复杂度逻辑 | 加载 `.wasm` → 实例化 → 原子换指针；fuel/epoch 限流 | < 1s | 否 |
| **T2 逻辑代码** | 类、方法、新功能 | **worker 滚动重启 + 状态迁移** | 2–5s / worker | 可做到无感 |

> T1.5 是可选增强项：TypePHP 已能输出 WASI 0.2 Component，若 Spike S1 验证"可作为 library component 被宿主实例化并调用"，则用它承接"需要沙箱、可中断、甚至多语言（Rust/Zig/C#→wasm）"的热更逻辑；否则回退为 T1。详见 `ARCHITECTURE-EVOLUTION.md` 提案 E1。

**T1 实现**（唯一真正"进程内"的热更通道）：

```php
<?php
// hot/activity/double_exp.php —— 只能 return 一个 Closure
// 禁止声明 class / function / const（重复声明会 fatal）
return function (array $ctx): array {
    $rate = (int) ($ctx['vip_level'] ?? 0) * 10;
    return ['exp_mul' => 200 + $rate];
};
```

```php
<?php
// src/Hot/ScriptRegistry.php —— AOT 编译
final class ScriptRegistry
{
    /** @var array<string, Closure> */
    private static array $handlers = [];

    public static function load(string $name, string $file): bool
    {
        $fn = include $file;                 // 加载即编译一次，op_array 随 Closure 驻留
        if (!$fn instanceof Closure) {
            return false;
        }
        self::$handlers[$name] = $fn;        // 原子替换，旧 Closure 交 Zend GC
        return true;
    }

    public static function call(string $name, array $ctx): array
    {
        $fn = self::$handlers[$name] ?? null;
        if (!$fn instanceof Closure) {
            return [];
        }
        return $fn($ctx);
    }
}
```

**T2 为什么不能原地热更**：PHP 进程内类不可再定义，`include` 同名类直接 fatal。任何"VM 级脚本替换"都会撞上这条硬约束。可行只有两条：闭包化（T1）或进程级替换（T2）。

**T2 状态迁移**：
1. 新二进制落盘 `releases/<ts>/`。
2. `SIGUSR1`：旧 worker 停止接新连接 → 玩家快照写入 `Swoole\Table`（key = uid）。
3. 新 worker 启动，认领快照重建对象，客户端用短票据重连（< 1s）。
4. 旧 worker 退出，在线数不归零。

### 3.6 L4 状态与持久化

| 层 | 载体 | 数据 | 一致性 |
|:--|:--|:--|:--|
| 进程内 | `#[Native]` / Zend 对象 | 当前 tick 活数据 | 单 worker 单写，无锁 |
| 跨进程 | `Swoole\Table` | 在线索引、跨服匹配、热更快照 | 原子操作，允许短暂不一致 |
| 跨节点 | Redis | 会话票据、排行榜、分布式锁 | 主从 + 本地缓存 |
| 持久 | MySQL | 存档、订单、日志 | write-behind，5–10s 批量 |

**写路径**：请求只改内存 → 标 dirty → 定时批量落库。不在请求链路里同步写。

---

## 四、热更与性能的取舍（修订）

v1 的"热更半径与性能半径成反比"过于粗糙。加入形态 B 之后，实际是三段：

| 需求 | 该用的形态 | 代价 |
|:--|:--|:--|
| 追求极限性能 + 可接受重编译 | A | 迭代需构建；不可热更 |
| 需要 Composer 生态兼容 + 不热更 | B | 构建期定死；解释执行 opcode |
| 需要运营秒级生效 | C | 解释执行；每次热更一次编译 |

**取舍原则**：热更只放"配置驱动的薄逻辑"（几十行、无循环热点）；重逻辑必须 A 或 B。把战斗公式放进 T1 是本架构最典型的误用。

---

## 五、构建与发布工程（重写）

### 5.1 增量体系（v0.9.6 实际实现）

| 机制 | 作用范围 | 实现位置 |
|:--|:--|:--|
| make 风格依赖图 + 反向脏传播 | 跨文件的符号级重编 | `IncrementalBuildState::dirtyFiles()` |
| sha256 内容哈希 + 生成物哈希校验 | 源文件/生成文件被外部改动也能识别 | 同上 `artifactsExist` 回调 |
| generator 指纹（编译器版本/PHP 版本/模式/平台/flags/debug + 编译器快照哈希） | 编译器升级自动全量失效 | `getIncrementalGeneratorFingerprint()` |
| 整项目预处理缓存 | 全无改动时跳过 prepare 阶段 | `PreparedProjectCacheTrait`（`cache/prepared/<hash>.bin`） |
| 目标文件缓存 `.typephp-cache` | 跳过不变 `.cc` 的 C++ 编译 | `hasGeneratedObjectFileCache()` |
| PCH 指纹缓存（8 条 / 30 天） | 公共头预编译 | `PrecompiledHeaderManager` |
| 大 TU 自动拆分（≥2MB） | 打破单文件串行瓶颈，配合 `-j` 并行 | `TranslationUnitSplitTrait` |
| 内容不变不重写 | 避免 mtime 抖动引发下游全量重编 | `CompilerBase::writeFile()` |
| clang-format 默认关闭 | 去掉逐文件格式化开销 | `--format` 显式开启 |

**关键工程细节**：所有增量状态都在 `build-dir` 下（`cache/incremental/<target>/build-state.json`、`cache/prepared/`、`cache/parts/`、`.typephp-cache`）。**CI 必须持久化 build-dir**（缓存恢复），否则每次都是冷构建。这比"编译器快不快"更决定日常迭代速度。

### 5.2 project.yml

```yaml
name: game-server
version: 0.1.0
mode: bin            # mode / build-mode / type 三种写法均被接受
sapi: embed
optimize: 2
cxx-std: c++17
sources:
  - src
ignore:
  - tests
  - hot              # 热更目录不参与编译
php-builder:
  extensions: [swoole]
  zts: off           # 进程模型，不用 ZTS
embedded-files:
  - vendor           # 构建期转 opcode，运行时零解析
  - config
output: build/game-server
```

### 5.3 流水线

1. `tpc project.yml --dry` —— 只生成 C++，快速做类型/语法校验，不进 C++ 编译器。
2. `check_code`（MCP）作为门禁，拦下违反 TypePHP 子集的代码。
3. `tpc project.yml -O2 -j <核数>` —— 增量构建（需恢复 build-dir 缓存）。
4. **每周一次 `-f` 全量 clean build 对拍**：增量体系的真实风险不是"慢"，而是"该重编没重编"（依赖图漏边导致产物过期）。用 clean build 产物做二进制级对拍 + 端到端回归，是唯一可靠的兜底。
5. 产物 = 单文件二进制 + `hot/` 目录（可独立热更，不进二进制）。

**模块化**：通用模块（协议库、AOI、序列化）打成 `mode: lib` + 发布 `.stub.php`（自动带 `@import-library`），业务项目以库引用，缩短冷构建链路。

---

## 六、可行性评估

### 6.1 能力矩阵

| 能力 | 状态 | 风险 | 说明 |
|:--|:--:|:--:|:--|
| AOT 编译 + 单文件部署 | ✅ | 低 | 自举编译器，四平台 CI |
| **增量编译 / 构建体系** | ✅ | 低 | 依赖图 + PCH + TU 拆分 + 整项目缓存，均已实现 |
| **embedded opcode（形态 B）** | ✅ | 低 | 构建期 `opcache_compile_file`，运行时内存 opcode 表 |
| Swoole Server AOT | ⚠️ | 中 | 示例可编译运行；**无生产验证**，`Table`/`Atomic`/`reload` 零测试覆盖 |
| 协程 | ✅ | 低 | `co.php` 验证 `sleep` 挂起 |
| 运行时脚本加载 | ✅ | 低 | `include`/`eval` 走 ZendVM，异常可正常 unwind |
| 进程内代码热更 | ❌ | 高 | PHP 语义不支持，必须滚动重启 |
| Native ↔ Zend 互操作 | ⚠️ | 高 | 单向、硬隔离，设计错就要重写 |
| 反射 / 动态特性 | ⚠️ | 中 | 回退 ZendVM，能用但无优化，部分语义受限 |
| 调试体验 | ⚠️ | 高 | 生成 C++ 栈，无 xdebug；靠日志 + gdb + `--debug` 构建 |
| 冷构建耗时 | ⚠️ | 中 | 增量已解决，首次全量仍受 C++ 编译约束，需实测设预算 |
| 变更可追踪性 | ⚠️ | 中 | `CHANGELOG` 仅到 0.8.0、`COMPILATION_MODES.md` 仍是 2024 双模式叙述、MCP 索引落后源码一个补丁版本 |
| 生态成熟度 | 🔴 | 高 | v0.9.6 预发布；0.8.0 有破坏性类型变更 |
| 厂商依赖 | 🔴 | 高 | Swoole 团队单点维护；GPL-3.0 需法务确认分发方式 |

### 6.2 综合评分

| 场景 | 可行性 | 依据 |
|:--|:--:|:--|
| PoC / 技术验证 | 88% | 示例覆盖大部分链路；构建体系已不是障碍 |
| 新项目单玩法灰度（活动服、跨服排行、战斗服） | 72% | 边界清晰、状态可外置 |
| 存量 MMO 全量迁移 | 20% | 动态特性、反射、第三方包、热更依赖，返工面过大 |
| 替代 C++ + Lua 做新引擎 | 62% | 可行，但调度、边界、热更、迁移工具链需自研 |

**总判断**：技术上比 v1 更乐观 —— 构建体系这块短板已补齐，日常迭代速度不再是拦路虎。真正的风险收敛到三点：热更模型必须重构、双堆内存与调试体验、预发布版本的破坏性变更。

### 6.3 风险登记册

| ID | 风险 | 概率 | 影响 | 缓解 |
|:--|:--|:--:|:--:|:--|
| R1 | 版本破坏性变更导致返工 | 高 | 高 | 锁定 `tpc` 版本 + 私有镜像；升级前全量回归；不追新特性 |
| R2 | 业务代码违反子集（引用/动态/`switch` fallthrough/局部类型复用） | 高 | 中 | `check_code` 进 CI；编码规范前置；`use varint_types` 只用于确需 Zend 整数语义的文件 |
| R3 | Native/Zend 边界失误 → 泄漏或崩溃 | 中 | 高 | 边界白名单；ASAN 构建 + 24h 长稳压测 |
| R4 | 热更方案不满足运营节奏 | 中 | 高 | 先验 T0/T1；T2 做到 < 5s/worker 且玩家无感；备"配置驱动"降级 |
| R5 | **增量依赖图漏边 → 产物过期**（隐性正确性风险，比慢更危险） | 中 | 高 | 每周 clean build 对拍；CI 上 e2e 回归；不要手改 build-dir |
| R6 | CI 未持久化 build-dir，增量失效 | 高 | 中 | 缓存 `build-dir`（build-state.json / prepared / parts / .typephp-cache） |
| R7 | 冷构建时间超预算 | 中 | 中 | 先实测再定预算；模块 `lib` 化；`-j` 拉满 |
| R8 | 线程模型误用（ZTS 下共享 Zend 对象） | 中 | 高 | 强制进程模型；跨 worker 只走 `sendMessage` |
| R9 | 项目停产 / 厂商方向变化 | 低 | 高 | 业务不写进 C++ stub；保留"纯 PHP + Swoole"可编译分支 |
| R10 | 第三方库不兼容（反射重、动态类加载） | 中 | 中 | 依赖白名单；此类库走形态 B 或留在外部服务 |

---

## 七、PoC 计划（两周，四门）

| 门 | 验证内容 | 验收标准 | 失败处置 |
|:--|:--|:--|:--|
| **G1 编译与接入** | `tpc` + Swoole 编 echo server，systemd 托管 | 单文件二进制；1 万长连接 ping-pong；P99 < 5ms；CPU 正常 | 检查 `php-builder` 与 `--sapi embed` |
| **G2 边界与双堆** | `#[Native]` 编解码 + Zend 玩家对象混跑，30Hz tick | 24h RSS 平稳（±5%）；零崩溃；GC 计数不持续增长 | 收紧边界，原生层改纯值传递 |
| **G3 热更** | T0/T1 生效 < 1s；T2 滚动重启 | 100 次 T1 热更后类表不增长、内存不涨；T2 切换不掉线 | T1 降级为纯配置；T2 改维护窗口 |
| **G4 构建** | 冷构建 / 增量构建 / 增量正确性 | 冷构建实测并定预算；单文件改动增量 < 10s；clean build 与增量产物行为一致 | 检查 build-dir 缓存是否被正确恢复 |

G4 的验收重心已从"能不能快"改为"快得对不对"。G1/G2 通过 → 架构成立；G3 不通过 → 降级为"配置热更 + 滚动重启"仍可运营。

---

## 八、落地路线

| 阶段 | 周期 | 内容 | 出口标准 |
|:--|:--|:--|:--|
| P0 | 2 周 | 四个验收门 | 报告 + 继续/放弃决策 |
| P1 | 1–2 月 | 单模块灰度：活动服 / 跨服排行 | 线上 1 个月零事故 |
| P2 | 2–4 月 | 战斗服或新游戏立项：L1 + L2 + T2 热更 | 支撑目标在线，100 次热更无事故 |
| P3 | 持续 | 平台化：通用模块 `lib` 化、热更管理、灰度 | 新项目接入 < 1 周 |

---

## 九、明确不建议做的事

1. **不要自研网络库**：Swoole 已是同层能力且被 AOT 覆盖。
2. **不要做进程内类替换**：PHP 语义禁止，绕法（改名空间、动态 eval 类）会累积类表或直接 fatal。
3. **不要把业务逻辑写进 C++ stub**：stub 是基础设施边界，业务进去就失去"全栈 PHP"收益，也堵死退路。
4. **不要在热更层写重逻辑**：解释执行，性能与便利直接对冲。
5. **不要在脚本层依赖反射/动态属性**：回退 ZendVM，性能不可控。
6. **不要手改 `build-dir` 内容**：增量状态是缓存不是源码，手改会导致漏编。
7. **不要一开始就 `-O3` + Native 全覆盖**：先跑通、再压测、最后针对性下沉。

---

## 十、借鉴 Erlang / OTP

### 10.1 映射总表

| OTP 概念 | 本架构对应物 | 借鉴程度 |
|:--|:--|:--|
| `gen_server` + mailbox | 玩家/房间 Actor + `Coroutine\Channel` 邮箱 | ✅ 直抄语义 |
| `Pid` + 位置透明 | `ActorRef`（本地/跨 worker/跨节点同接口） | ✅ 直抄抽象 |
| `call` / `cast` | 同步有超时调用 / 单向投递 | ✅ 直抄 |
| mailbox 长度背压 | Channel 容量 + 阈值拒绝 | ✅ 直抄 |
| supervisor 树 + 重启强度 | 自建 `Swoole\Process\Pool` 监督层 | ⚠️ 改形 |
| `code_change/3` 热更 | T2 滚动重启 + 状态版本迁移链 | ⚠️ 改形（机制不可抄） |
| `error_logger` crash report | 每 Actor 环形消息缓冲 + 崩溃转储 | ✅ 直抄 |
| `sys:get_state` / `sys:log` | 调试端口按 uid 拉取状态 | ✅ 直抄 |
| ETS 固定 schema 表 | `Swoole\Table` | ⚠️ 部分 |
| refc binary / iolist | 分块缓冲一次性 `send` | ✅ 直抄 |
| 抢占式调度（reduction） | 无（协程协作式） | ❌ 不可抄 |
| per-process heap | 无（worker 内共享堆） | ❌ 不可抄 |
| 两版本模块热加载 | 无（PHP 类不可重定义） | ❌ 不可抄 |

### 10.2 Actor 引用与邮箱（P0）

业务代码只依赖 `ActorRef`，不关心目标在同协程、同 worker 还是另一节点——这是 OTP "位置透明"的收益：拓扑调整不需要改业务。

```php
interface ActorRef
{
    public function cast(string $cmd, array $payload): void;
    public function call(string $cmd, array $payload, float $timeout = 0.5): ?array;
}
```

**四条必须同时落地的纪律**（缺一条就会退化成分布式锁地狱）：
1. **跨边界只传可序列化数据**：array / string / int，不传 Zend 对象、闭包、resource。这等价于 Erlang 消息拷贝，天然无共享可变状态。
2. **`call` 只能自下而上**：业务 Actor → 基础服务（存档、排行）用 `call`；同层 Actor 之间一律 `cast` + 回包。禁止 `call` 成环，否则协程互等必死锁（Erlang 靠 monitor 检测，协程层没有等价设施，只能靠约束 + 超时兜底）。
3. **邮箱有上限**：`Channel(cap)` + 阈值检查，超限返回失败让上游降级。对应 Erlang 查 `message_queue_len`；协程没有系统级 mailbox 监控，必须自己埋。
4. **`cast` 天然可能丢**：凡是不能丢的操作（扣钱、发奖）必须走 `call` 或带序列号去重，不能图快用 `cast`。

### 10.3 监督树与重启强度（P1）

见前图。要点是**分级重启策略**，不是"崩了就整体重启"：

| 层 | 策略 | 理由 |
|:--|:--|:--|
| 网关 | `one_for_all` | 网关无状态，整体重启最干净 |
| 玩家分片 | 按 CPU 核数固定，不动态增减 | 避免重分片引发迁号风暴 |
| 房间 | `rest_for_one`，单房间独立子进程 | 战斗异常隔离在单房间，玩家重连一次即可 |

**重启强度**：单节点 60s 内重启超过 N 次 → 停止创建新房间并告警（防崩溃风暴）。这是 OTP 的 `max_restarts / max_seconds` 语义，Swoole 自身没有，需要在监督层自己实现计数器。

**"let it crash" 的前提**：崩溃后能重建状态。所以必须先有 10.4 的快照与版本迁移，否则 let it crash 等于让玩家回档。

### 10.4 `code_change` → 状态版本迁移链（P0，最值得抄）

Erlang 能热更，是因为每个进程通过 `code_change(OldVsn, State, Extra)` 把旧状态迁移到新代码。机制我们抄不了，但**协议可以抄**——这正是 T2 滚动重启缺失的那一环。

```php
<?php
final class PlayerState
{
    public const int VERSION = 7;

    /** 快照反序列化入口：只做向前迁移 */
    public static function migrate(array $s): array
    {
        $v = (int) ($s['_v'] ?? 0);
        if ($v < 5) { $s = self::to5($s); }
        if ($v < 7) { $s = self::to7($s); }
        $s['_v'] = self::VERSION;
        return $s;
    }
}
```

配套纪律：
- 每条快照必须带 `_v`；缺失视为 0，走全链迁移。
- **破坏性字段变更用两阶段**：先双写新旧字段 → 滚动重启 → 再清理旧字段。这样新旧二进制在滚动窗口内都能读，等价于 Erlang 的两版本共存。
- 迁移函数是纯函数，必须可重放、可对拍（旧快照样本入库做回归）。

有了它，T2 就不只是"重启"，而是"带状态迁移的重启"——玩家无感，且能回滚。

### 10.5 崩溃报告与内省（P0）

Erlang 最被低估的能力是事后取证。对应实现：

- **每 Actor 一个环形缓冲**（最近 20 条 cmd + 时间戳 + 状态摘要），崩溃时随异常一起落盘，按 uid 命名。
- **调试端口**：`ActorRef::call('__sys_state', [])` 返回状态快照，权限收紧、限流，线上排障不用重启。
- 这两个加起来，能把"玩家说卡了一下"变成可复现的证据链。

### 10.6 iolist 式组装（P2）

PHP 字符串拼接每次复制，协议打包里是主要 CPU 开销之一。借 Erlang iolist 思路：分块写入数组，最后 `implode('', $chunks)` 一次成型；L1 原生层用 `std::vector<int>` 预分配缓冲，出层转一次 string。

### 10.7 明确不可借鉴的三条

| 项 | 为什么不行 | 替代 |
|:--|:--|:--|
| 抢占式调度 | PHP 协程是协作式，无指令级中断，一个死循环卡死整个 worker | 编码规范 + tick 预算 + 高风险计算进独立进程池 |
| per-process heap | Zend 堆与 Native 堆都在 worker 内共享，GC 是批量的 | 控制单 worker 承载量、热路径避免环形垃圾、必要时拆进程 |
| 两版本模块热加载 | PHP 类表只增不减，无法并存两个版本的同一类 | 闭包热更（T1）+ 滚动重启（T2）+ 状态迁移 |

### 10.8 落地优先级

| 优先级 | 内容 | 成本 | 收益 |
|:--|:--|:--|:--|
| **P0** | `ActorRef` 抽象 + 四条纪律 + 状态版本迁移链 + 崩溃报告 | 3–5 人日 | 结构正确性；T2 从"重启"升级为"迁移" |
| **P1** | 房间级进程池 + 监督树 + 重启强度 | 1–2 周 | 崩溃半径从"整个 worker"降到"单个房间" |
| **P2** | `sys` 式内省 + iolist 组装 | 3–5 人日 | 排障效率、编解码吞吐 |

**一句话结论**：Erlang 值得抄的是**失效模型（监督 + 崩溃报告）与状态演化（版本迁移链）**，不是并发机制。抄对了这三样，本架构最大的两个短板——"崩溃半径过大"和"热更丢状态"——都能补上。
