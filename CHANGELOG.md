# 变更记录

## 未发布

### 新增：扣款、充值、对账（金币账本补齐贷方）

v0.6.0 的账本只有"发奖"一个方向，现在补上出账与核对。三条刻意的规矩：

- **余额不足不写日志**——被拒绝的操作不是"发生过的事"，写进去会让对账永远不平；
- **幂等键是调用方给的 `$ref`**（订单号/流水号），不是"扣了多少"；
- **不许扣成负数**——`Wallet::debit()` 够不够都返回 bool，不改账也不抛异常；
  "余额不足"是业务结果，该抛的是"日志写不进去"。

具体改动：

- `Wallet`：`debit()` / `has()` / `negative()`
- `Settle`：`debit()` / `credit()` / `reconcile()` / `isDebited()`；
  新增事件类型 `wallet.credit`、`wallet.debit`（`duel.settled` 保持不变）
- **对账**：独立重放一份账本与内存逐人比对，能查四类问题（余额不符 / 负数 /
  重复 id / 重放时余额不够的扣款）。不调 `rebuildFrom()`——那会顺手把账**修正**掉，
  而我们要的是"差了多少"
- `demo/duel`：运维命令 `wallet-debit` / `wallet-credit` / `wallet-check`
  （退出码 0 成功 / 2 业务拒绝 / 1 环境错误），0 号 worker 启动时跑一次对账
- 测试：`settle-test` **42 → 83**，`settle-crash` **10 → 14**（新增 3 轮借贷混合
  随机 `kill -9`：10 万局 × (100 − 30) = 7,000,000，与 `net` 和 `balance` 三方一致）

已知仍未做：余额快照与日志截断（对账会把日志再读一遍，大日志下是双倍启动成本）、
跨进程并发扣款（当前是单写入进程 + 串行调度，天然不存在并发扣同一人）。

### 多 worker：验证结论是"不支持"，并把闸门补全了

改 `worker_num = 2` 实测，发现缺口不止"事件日志撞号"一条：

1. **快照被每个 worker 各恢复一次**。一个快照两个 worker 启动，日志里出现两次
   `rooms restored done=1`（worker 0 与 1），同一房间两份状态，且都往同一个
   `duel_<id>.snap` 写——后写覆盖先写，一份进度静默丢失。
2. 事件日志的 `nextSeq` 构造时算，多进程撞号（120 条记录 61 个序号）。
3. 房间号各 worker 各从 1 数，跨 worker 重复 → 结算幂等键 `duel:<房间号>` 冲突 → 钱静默不发。

要支持多 worker，缺的是「房间归属」这一层（谁负责哪些房间、重启后怎么认领、
跨 worker 怎么路由）。**所以选择拒绝而不是半死半活**：`worker_num > 1` 直接抛，
异常信息写明这三条。

顺带修掉一个静默失败：`main.php` 的 `$opts` 里 `worker_num` 原先写死 1，
`server.worker_num` 配置被完全忽略——改了配置发现没反应，会误以为是 Swoole 的问题。

### 文档：房间归属设计稿

`docs/DESIGN-ROOM-OWNERSHIP.md`。核心结论：**不需要发明新层**——`Addr` 已有 node 位、
`Cluster` 已按 node 连通、`Ring`/`Router` 已能定位节点，缺的是三个动作：

1. **认领**：启动时 `ring->nodeFor("room:" . $rid)` 只恢复归自己的快照
   （陷阱：不能用 `Restorer` 的 `$rebuild` 返回 false 来跳过，那会被当成坏快照删掉）；
2. **落主**：建房必须发到归属节点，归属节点不在环里就拒绝建房，不做本地保底；
3. **移交**：冻结 → 落盘 → 标记（含 epoch）→ 新主认领，靠 epoch fencing 防双主。

### 房间归属：三个开放问题已定稿，其中两个落成代码

**① 移交时玩家：踢下线 + 重连找回。** 不选"无缝"不是因为难，是因为**不存在**——
TCP 连接绑在进程上，节点崩了 socket 就没了。要在旧节点做转发代理才能"不踢"，
那等于为一个不需要的性质引入一个必须比房间活得更久的常驻组件。
代价花在把重连做对：`REJOIN` 协议 + 认领时从快照重建 `addrOf` + 客户端保存 roomId
（比自己维护全局 uid→房间表便宜且没有一致性问题）。

**② 房间号：加宽到 10 亿/节点，明确不做号段回收（已实现）。**
`TSF\Room\RoomIds`：`nodeId * 10^9 + seq`，node ≤ 255 → 单节点每秒建 1 房能跑 31 年。
不做回收是决定性的——房间号进幂等键 `duel:<房间号>`，号一复用就会**钱静默不发**（P0-2 的形状），
用它省下的只是"号用得慢一点"，方向就是错的。
`nodeId=0` 时 `compose(0,n) === n`，老日志/老断言/`duel:1` 全都不变。
`Settle` 多了 `maxRoomIn($node)`（段内最大号），`DuelManager` 改为按段续号、
恢复时也只在本段内推进。

**③ 事件日志：按节点分文件（已实现读侧）。**
定稿时发现原论据不够硬——真正的理由是：**有了房间归属，同一个房间的钱只由一个节点写**，
所以同一房间的事件天然有序，跨房间顺序对正确性没有影响。全局序不是需求，是错觉。
新增 `TSF\EventLog\MergedLog::replay()`：按 `(t, 文件序号, s)` 归并，保证**确定性**
（同样输入给同样结果，否则对账会出现"两次跑不同"的假警报）。
`Settle` 加了第三个参数 `$readers`（读侧文件列表，默认只有自己那份），
`rebuildFrom` / `reconcile` 走归并；写入仍然只有一份，不需要多写者协调。

测试：`settle-test` **83 → 91**（跨节点归并、跨节点对账、重放确定性），
`duel-settle` **23 → 34**（分段编址、段内续号、node 0 向后兼容）。

顺带记一条 AOT 约束（§5.2.1）：**同一个函数的参数按首次调用点定型**。
`T::eq` 前九次传 int、第十次传 string 就编译失败，报错行指向调用处而非定义，很容易误判。

### 阶段 1a 落地：认领 + 落主（多进程的地基）

**先修正了设计里的一处自相矛盾**：原方案用 `ring->nodeFor("room:" . $rid)` 算归属，
但房间号本身是 `nodeId*1e9+seq`——号段说属于 node 0，环可能说属于 node 3，
同一个房间两个归属比没有归属更糟。改成**归属 = `RoomIds::node($roomId)`**，号段即归属。

这个改动连带砍掉一整块复杂度：房间不能跨节点搬家，所以"在线移交 / epoch fencing /
防双主 / rebalance 限速"都不需要了，节点退役改成 drain（停止接新房、等现有房间打完）。
对局类玩法房间只活几分钟，drain 够用；代价是长生命周期房间（大世界）不适用，等真要做世界服再说。

具体改动：

- **`Restorer::restore()` 加 `$accept` 过滤参数**。关键是它必须独立于 `$rebuild`：
  `$rebuild` 返回 false 的语义是"这条坏了，可以删"，拿来跳过别人的房间会**把别的节点
  的快照删掉**——不报错，只在那个节点的玩家回不来时才被发现
- `$accept` 在 `get()` 之前生效，返回值多了 `not_mine` 计数
- `DuelManager::restoreAll()` 传 `RoomIds::node($rid) === $nodeId` 作为过滤
- **落主**：`create()` 里显式断言号在自段内。并且**先校验后推进**——顺序反了的话，
  段末耗尽时 `compose` 返回 0，`seq(0)+1 = 1` 会把号重置回 1，那就是房间号复用、
  `duel:1` 结算过之后新一局的钱静默不发
- **事件日志按节点分文件**：写 `var/settle.n<id>.log`，读侧扫 `var/settle*.log` 归并
  （含历史单文件 `settle.log`，升级不用搬数据）。实测跨代归并：老文件 10 条 + 新文件 2 条
  = 12 条，账平

测试：`duel-settle` **34 → 45**（认领过滤 9 项：两个 node 从同一快照目录认领、
`not_mine` 计数正确、**别人的快照没被删**、各自清掉自己的坏快照；段末溢出返回 0）。

### M7 技术验证完成：wasm 模块从 PHP 源码到热更调用，全链路打通

任务表里唯一未做的大项（M7 / 12 人日）的技术前提，这次全部验证完毕——**不是设计，
是跑出来的**。最终形态：

```
PHP 源码（#[\WasmExport] function mul）
  → tpc（wasi-sdk 34 / clang 23）编出 wasm component
  → tp-precompile 预编译成 cwasm（带 fingerprint）
  → host 加载，自动枚举 interface 成员
  → wasm_call2($h, "mul", 6, 7) = 42
```

`spike/e1/cpp-src/wasm_bridge.cc` 里**搜不到 "mul" 一个字**，`TP_METHODS` 也没设。
新增一个 wasm 导出函数，只需要把模块编出来——桥接层、配置、宿主二进制都不用动。

**四处非改不可的地方**（每一处不知道就卡死）：

1. **host 的 wasmtime 47 → 50**。SDK 34 产出 `wasi:cli/*@0.2.12`，47 的
   `wasmtime_wasi::p2` 只 link 到 0.2.6，版本化 import **精确匹配**，`instantiate` 直接失败
   → `tp_open` 返回 -1。连带一处 API 迁移（`WasiHttpView` 从 `p2` 移到 crate root）。
2. **phpx 的 wasm 头要打 clang 22+ 补丁**：`phpx.h` 用了 `std::abort()`/`std::fill`
   但没 include `<cstdlib>`/`<algorithm>`，旧 libc++ 恰好间接包含。属上游适配缺口，
   需要时按"did you mean simply 'abort'"提示改。
3. **cwasm 必须用 `tp-precompile`，不能用 `wasmtime` CLI**：`fuel`/`epoch` 是编译期插桩，
   产物带 fingerprint 与 artifact_size，配置不一致**直接拒绝启动**。用 CLI 编会让
   **所有模块**句柄变 `-1000`，现象酷似"升级把一切都搞坏了"。
4. **升级后所有既有模块的 cwasm 都要重编**（v1/v2 也一样），否则一起打不开。

**两处实现**：

- `wasm_call2(handle, fn, a, b)` / `wasm_call1`：通用按名调用，替换掉"每个导出函数
  手写一个 PHP 绑定"的形态。名字在运行时解析（传 `no_such_fn` 返回 -2001）。
- `enum_members()`：从 component 类型信息枚举 interface 成员。`TP_METHODS` 那份人工表
  从此**不需要维护**（保留作为兜底）。

**wasmtime 50 的四个 API 细节**（都踩过，别再凭记忆写）：
`component_type()` 返回 `types::Component`；`ComponentItem` 在 `component::types` 子模块；
顶层 `exports()` 产出 `ComponentExtern`，取 `.ty` **字段**（不是方法）才是 `ComponentItem`；
`ComponentInstance::exports()` 的成员名**已带 `[method]runtime.` 前缀**，再拼一次会得到
`[method]runtime.[method]runtime.mul`，查询失败返回 -2001——很像"枚举没生效"。

**能力边界**：

| 变更类型 | 需要做什么 | 热更？ |
|:--|:--|:--|
| 同名函数换实现 | 换 cwasm + `wasm_swap`（4.4–5.5 ms） | ✅ 真热更 |
| 新增/删除导出函数 | 编 cwasm，其余全自动 | ✅ 不改代码、不改配置 |
| 改函数签名 | 编 cwasm + 改调用点（业务必然） | 🟡 |
| C++ 桥接层本身要改 | 重编 so + 重启 | ❌ 等价 L3 |

**环境基线**：`wasi-sdk 34`（clang 23.1.0）／`wit-bindgen 0.60.0`／`wasmtime 50.0.1`／
`~/.local/bin/wasm32-wasip2-clang` 是指向 SDK 34 的 wrapper（**不能是符号链接**，
且在符号链接路径上写文件会把编译器本体覆盖掉——踩过）。

**方法论**：跨大版本的第三方 API 只能查源码/文档。凭记忆写 `ComponentItem` 的旧路径，
换来一轮 1.5–2.5 分钟的编译加回滚；查一次源码就拿到准确签名。

### T7-02 发布流水线：`tsf module build` 一条命令

原来产出可发布模块要敲四步（tpc 编 wasm、`tp-precompile` 预编译、拷到目标路径、
设 `TP_METHODS` 重启）。漏一步的现象都很隐蔽，最坑的是拿 `wasmtime` CLI 代替
`tp-precompile`——所有模块句柄变 `-1000`，看起来像"升级把一切都搞坏了"。

现在：

```
tsf module build <模块目录>
1/3 编译 wasm component
2/3 预编译（tp-precompile）
3/3 写 manifest
OK  dist/mul-probe.cwasm  54,238,224 字节
    dist/mul-probe.manifest.json
```

**先检查再动手**：工具链缺什么直接说（`wasm32-wasip2-clang` / `wasmtime` /
`wit-bindgen` 三项逐个查），不让它走到 tpc 再抛一句笼统的 `toolchain check failed`。

**manifest 记什么**：`wasm_sha256`、`cwasm_sha256`、字节数、构建时间、
clang 与 wasmtime 版本。发布时校校验和，能挡住"发了个旧包"。

两个实现细节：预编译工具先查 PATH 再退到 `ld-flags` 的 `-L` 目录（模块工程不链接
宿主库，通常没有 `ld-flags`）；`tp-precompile` 的用法是 `tp-precompile <in> <out>`，
**没有** `build` 子命令——那是 `precompile.sh` 自己的分支，我按那个抄错过一次。

### T7-03 灰度切换：确定性分流 + 观测 + 原子切换

`tsf/pkg/wasm` 升到 0.2（主实例 + 灰度实例）。验收输出：

```
1) load v1      add(1,2) = 3
2) canary 50%   ok
   key 1..20 -> v1=10 v2=10        <- 精确五五开
3) key=7 twice: 1003 / 1003        <- 同一 key 恒定，确定性
   stats: main=11,canary=12,ratio=500
4) swap v2      3492 us            <- 3.5ms，与 spike 实测 4.4-5.5ms 同量级
   add(1,2) = 1003
   stats: main=12,canary=12,ratio=0  <- 切换后灰度实例被清掉、ratio 归零
```

**用 hash(key) 而不是随机数分流。** 游戏里 key 通常是玩家 id：随机分流会让同一局的
一半请求走旧逻辑、一半走新逻辑，状态直接分裂——这类 bug 比"发了个坏版本"难查得多。
实现是 Knuth 乘法散列，同一 key 永远落同一侧（第 3 项验收就是锁这个）。

**swap 是原子的**：先 `tp_open` 新实例，成功才替换指针，失败保持旧实例可用。
调用是同步的（无协程、无并发持有），所以旧实例立即关闭；spike 第三版的"延后释放"
是为并发调用准备的，这里不需要那层复杂度。

**灰度加载失败不影响主实例**（`canary()` 返回 false，主实例继续服务）——
这是灰度最需要的行为：新版本坏了，老版本要能顶住。

### 自托管 runner：注册文档 + 两处会真出问题的配置

先确认了现状：**这台机器上还没有 runner**（无 `actions-runner` 目录、无 `Runner.Listener`
进程）。所以现在手动触发 CI，`verify` job 会一直停在 "Waiting for a runner..."——
这是预期行为，已写进 `docs/CI-RUNNER.md`，否则会被当成配置错误排查很久。

**`docs/CI-RUNNER.md`** 给出 WSL 里的完整注册步骤（WSL 有 systemd，可以 `svc.sh install`
装成服务，关机重启自动恢复），以及两个具体的坑：

1. **runner 服务的 PATH 和你 shell 的不一样**，尤其不含 `~/.cargo/bin` 与
   `~/.local/bin`。不补的话会出现"我本地明明装了，CI 说没有"。
2. **`/etc/wsl.conf` 里 `systemd=false` 时**，`svc.sh` 装的服务不会自启。

**`ci.yml` 两处修正：**

- `runs-on: self-hosted` → `[self-hosted, tsf]`。不加标签的话，这台 runner 会被仓库里
  **所有** `self-hosted` 的 job 抢走——将来加第二个自托管任务时才会发现，且很难查。
- 增加 `$GITHUB_PATH` 步骤补 `~/.cargo/bin` 与 `~/.local/bin`，否则 `preflight` 会把
  已经装好的 `wit-bindgen` / `wasmtime` 报成缺失。

**没有 runner 也不影响验证**：`bash scripts/ci-preflight.sh` + `./tsf/bin/tsf verify`
就是 CI 里跑的那两条命令，没有第二套逻辑——CI 与本地不一致时，"绿"的意义就没了。

### 接入 GitHub Actions：lint 走托管 runner，verify 走自托管

`.github/workflows/ci.yml` 两个 job，分在不同 runner 上——这是被现实逼的，不是设计偏好：

| job | runner | 触发 | 内容 | 耗时 |
|:--|:--|:--|:--|:--|
| `lint` | ubuntu-latest | push / PR | `tsf lint` + 全量 `php -l` | 秒级 |
| `verify` | self-hosted | 手动 | `tsf verify` 197 项断言 | 分钟级 |

**为什么不把 verify 放托管 runner**：AOT 编译器 `tpc` 在 `../typephp-src`，**不在本仓库**；
还要求 PHP 8.5 **ZTS** + Swoole + phpx，托管 runner 从零装要十几分钟且极易版本漂移。
硬塞的后果是它长期红着，然后所有人学会忽略 CI——**装不上的测试比没有测试更糟**。

`verify` 只在手动触发跑：push 就全量验证会把 197 项断言的几分钟成本摊到每次提交，
而真正需要它的是"准备发布"和"动了核心之后"。

**新增 `scripts/ci-preflight.sh`**：把环境检查前置。原来 `tsf verify` 失败时的报错来自
最深那一层（`WASI toolchain check failed`、`function wasm_load is undefined`），
分不清是缺环境还是代码真坏了。现在先跑一遍检查，缺什么、缺在哪、怎么补，一眼可见。
本地也能用——新人接手第一件事就该跑它。

顺带修了两处判定标准不一致：`wit-bindgen` 装在 `~/.cargo/bin` 但默认不在 PATH 里，
`preflight` 曾误判"没装"、`tsf verify` 曾误判"装了"。现在 `findInPath()` 统一兜底
`~/.cargo/bin`，且 `verify` 支持 `needs` 字段——缺工具链时 **SKIP** 而不是 FAIL，
不让一个环境问题把整套判成失败。

### wasm 验证套件改自包含：fixture 从源码现编，不再依赖 spike 产物

`bench/wasm-link` 原来直接引用 `spike/s1`、`spike/e1` 的 cwasm，而 spike 是本地验证
现场、产物不入库——**干净克隆的仓库跑不了这个套件，CI 更跑不了**。验证集依赖考古现场，
这条依赖链的方向是反的。

现在 `fixtures/v1|v2/` 各有一份源码（`add` 分别是 `a+b` 与 `a+b+1000`，靠返回值分辨
世代），`fixtures/build.sh` 用 `tsf module build` 现编；`dist/` 与 `*.wasm` 已 gitignore。
验收仍是 6 项全 PASS（swap 5669 µs / 回滚 4316 µs）。

**路上踩了 4 个环境坑，全记在 build.sh 注释里**：

| 报错 | 真因 |
|:--|:--|
| `wasm32-wasip2-clang++ not found` | tpc 的 C++ 模式会检查，之前只建了 C 版 wrapper |
| `llvm-ar not found` | 只给 wrapper 不够，**SDK 的 bin 必须整套在 PATH** |
| `reported target: wasip1` | wrapper 必须显式 `--target=wasm32-wasip2`；**文件名改不了 clang 的默认 target** |
| `platform_check.php` 抛错 | PATH 里的 `php` 是 8.3，要加 `/usr/local/php-8.5-zts/bin` |

最后一条最坑：报错来自 Composer 平台检查，看起来像"依赖没装"，与"跑 tpc 的解释器
版本不对"毫无关系。

**顺带**：`wasi-sdk-34` 从 `/tmp` 迁到 `~/.local/wasi-sdk-34`（`/tmp` 会被清），
两个 wrapper 同步改路径。

**没能消除的依赖**：`tp-precompile` 仍是环境依赖——它是 host 的 Rust 构建产物，且
**不能换成 `wasmtime compile`**（fuel/epoch 是编译期插桩，产物带 fingerprint，配置
不一致宿主直接拒绝启动）。`build.sh` 做了三级查找并给出构建指引。

### AOI 按格批量：密集场景 166×，稀疏场景反而略慢

上一轮基准的结论是"容器层没得优化，该往算法层走"。落地了 `Grid::neighborsShared()`：
**同一格的所有玩家，3×3 范围完全一样，逐人调用时同一批格子被扫 N 遍**。

| 场景（同格人数） | 逐个（500 人） | 批量（500 人） | 加速 |
|:--|--:|--:|--:|
| 密集（500 人同格） | 14.94 ms | 89.8 µs | **166×** |
| 中（20 人同格） | 4.41 ms | 305.7 µs | **14.4×** |
| 稀疏（每格 1 人） | 484 µs | 552 µs | **0.9×** |

三条结论：

1. **加速比 ≈ 同格玩家数**（密集 500 人 → 166× 而非 500×，因为批量自身还要遍历 500 个 uid）。
2. **稀疏场景是负优化（0.9×）**。所以这个 API 是"按场景选用"，不是替换 `neighbors()`——
   出生点、主城、战斗区用它；全散开的地图用逐个。
3. **内存也更省**：返回的是共享数组（COW），内存占用从「玩家数 × 邻居数」变成
   「格数 × 邻居数」。代价是调用方**必须只读**，要排除自己得先拷一份。

**正确性已验证**：三档密度各 500 个 uid（共 1500 个）逐一与 `neighbors()` 对比，
结果集合完全一致：

```
OK   密集：500 个 uid 与逐个结果一致
OK   中：500 个 uid 与逐个结果一致
OK   稀疏：500 个 uid 与逐个结果一致
```

这一步不是形式主义。**性能优化最容易出的错不是慢，是"快但算错了"**——邻居集合算错
的表现是"有人看不见身边的玩家"，这种 bug 从日志和指标里几乎看不出来，只能靠断言。

### AOI 邻居查询基准：分配开销不是瓶颈，`std::vector` 改造不值得

体检时判断"AOI 邻居遍历是唯一有真实性能潜力的点"，但那只是推断。写了
`bench/aoi-bench` 实测：500 实体、每档 20000 次，只改**每格人数**这一个变量。

| 密度 | 平均邻居 | `neighbors()` | `neighborsOfKey()` | ns/邻居 |
|:--|--:|--:|--:|--:|
| 密集（1 格 500 人） | 500 | 29882 ns | 29393 ns | 59.8 |
| 中（25 格 × 20 人） | 120 | 8825 ns | 9092 ns | 73.5 |
| 稀疏（全散开） | 3 | 968 ns | 892 ns | 322.6 |

按 `F + c·n` 拆解（稠密+稀疏两组解方程，中组做校验）：

- **固定开销 F ≈ 794 ns/次**（数组分配 + 9 格循环 + `cellOf` 查找）
- **逐元素 c ≈ 58 ns/个**
- 交叉点：**邻居数约 14 个**。少于 14 个时固定开销占主导，多于 14 个时逐元素占主导。

**结论：不值得换 `std::vector`。**

1. 固定开销里能省掉的只有"分配"那一块，9 格循环和 `isset` 检查省不掉；乐观估计降 20–38%，
   而密集场景下 F 只占 2.7%，几乎无收益。
2. 代价是换格逻辑要重写（`foreach` 遍历 std 容器禁增删，得改成"先收集后统一改"），
   调用方语义也要从"返回数组"变成"填充缓冲"。
3. `neighbors()` 与 `neighborsOfKey()` 只差 5–10%，说明 `cellOf` 查找也不是瓶颈——
   之前怀疑的两个方向都被数据否掉了。

**真正值得的方向是减少调用次数**：现在每个玩家每帧各自扫 9 格，改成"按格汇总"
能把 9 格循环从 per-player 提到 per-cell；或者给格子加版本号，成员未变时复用上次结果。
这是算法层面的数量级优化，不是容器替换。

**基准自身的坑（值得记）**：「中」档最初用 `intdiv($i, 5)` 当 y 坐标，500 个实体的 y
一路涨到 row 99 之外被夹回边界，期望 180 个邻居实测只有 6 个——**密度基准的构造错了，
测出来的所有数字都是假的**。这种错误不会报错，只会给出看起来合理的曲线。

### 指标类型从字符串改为 enum（`bench/metrics-test` 37 项通过）

体检结论："未使用 TypePHP 特性"里大部分**不是性能欠债，是类型安全欠债**。
先落地确定性最高的那一处：`MetricSnapshot::$kind` 的三选一字符串。

```php
// 之前
public string $kind = "counter";
if ($s->kind === "counter") { ... }

// 现在
public MetricKind $kind = MetricKind::Counter;
if ($s->kind === MetricKind::Counter) { ... }
```

**换来的是编译期穷尽性**：以后加一种指标类型（比如 summary），所有 `match` 漏分支的
地方直接编译失败。字符串常量做不到——漏了只是静默走默认分支，等看板数字不对才发现。

**性能是中性偏负**：`enum case` 在 AOT 下是对象而非标量常量（`OBJECT_CREATION.md` 3.2），
不要把它当性能优化。顺带确认热路径未受影响：`inc` 81 ns/次、`observe` 400 ns/次，
都在 `metrics-test` 的阈值内（300 / 800 ns）。

**踩到的坑**：改公共数据结构的字段类型必须 grep **全仓库**。我第一次只查了 `tsf/`，
漏了 `bench/metrics-test/main.php` 里两处 `T::eq($s->kind, "counter")`——编译能过，
跑到第 1 组用例就 `TypeError: Argument #1 must be of type int|string|float|bool, object given`。
类型收紧的收益在编译期，但代价会在运行期以这种形式出现。

### `tsf verify` 一键回归：9 个套件收成一个入口

之前验证散在 9 个目录（`bench/*`、`demo/duel/*`），入口、前置条件、判定方式各不相同。
新人接手时最贵的成本不是跑脚本，而是**不知道有这些脚本**。

```
tsf verify              # 全部套件
tsf verify --list       # 只列清单
tsf verify wasm         # 按 id 子串过滤
```

```
结果    套件             内容                                耗时
--------------------------------------------------------------------
PASS  semantics      AOT 语言语义差分（45 用例）                  16.5s
PASS  wasm           wasm 热更链路（灰度/回滚）                  27.7s
--------------------------------------------------------------------
通过 2 / 失败 0 / 跳过 0
```

失败时会打印该套件输出的最后 4 行——没有这一步，表里一个 `FAIL` 等于零信息。

**只收录确实跑过、且能独立跑的 9 个**：semantics 45 项、swoole 6 项、settle 91 项、
settle-crash 14 项、fd 10 项、cluster 11 项、drain 8 项、rejoin 6 项、wasm 6 项。
`bench/` 下另有二十多个压测/专项脚本（bot、cluster-bench、probe-*），需要外部条件或
跑很久——塞进"一键回归"只会让一个长期红的套件把整套的可信度拖垮。

新增 `bench/wasm-link/run.sh`：6 项断言**全部看返回值，不看退出码**。只看"有没有
报错"测不出"换了个版本但行为没变"；v1 的 `add` 是 `a+b`（3），v2 是 `a+b+1000`（1003），
返回值是唯一能分辨的东西。

顺带修了中文表格对齐：`str_pad` 按字节算，一个汉字 3 字节会被当成 3 列，输出到终端
每一列都歪。加了 `padWidth()` 按显示宽度补齐。

### T7-05 演练：灰度 → 全量 → 回滚，含耗时与回滚验证

报告见 `bench/wasm-link/drill-report.txt`（脚本输出直接落盘，不是手抄的）。一次完整演练：

```
1) load v1      add(1,2) = 3
2) canary 50%   key 1..20 -> v1=10 v2=10        <- 精确五五开
3) key=7 twice: 1003 / 1003                     <- 确定性分流
4) swap v2      5531 us    add=1003             <- 全量切换
5) no_such_fn = -2001                           <- 错误路径
6) rollback v1  3750 us    add=3                <- 回滚后行为恢复
```

两次 `swap` 分别 3492 / 5531 us，回滚 3750 us，都落在 spike 实测的 4.4–5.5 ms 区间内。

**回滚这一步是刻意补上的。** 原来只验"能更上去"，不验"能退回来"——
那等于把出事时唯一的退路留成未测试状态。现在 `swap` 双向都跑，
且回滚后用返回值确认行为真的恢复了（1003 → 3），不是只看"没报错"。

**M7 三项验收到此齐全**：L2 换实现（4–5 ms，无感）、灰度分流（确定性 + 可观测）、
全量与回滚（双向可逆）。剩下没做的是 L1（C++ 函数级热更，工程上不可行）——
那条本来就不在 M7 的合理范围内。

### 文档：wasm 热更（M7 / T7-01）设计稿

`docs/DESIGN-WASM-HOTRELOAD.md`。M7 是任务表里唯一未做的大项，先确认前提再谈方案：

- **前提成立**：`spike/e1` 已端到端打通（PHP→stub→wasm 782 ns/次，与 Rust 宿主直调同量级；
  重建实例 3.6 ms；灰度 swap 4.4–5.5 ms）。**性能不是障碍**。
- **但接口形态不适合热更**：E1 给每个 wasm 导出函数手写了一个 PHP 全局函数
  （`wasm_add`、`wasm_burn`…），意味着**模块新增一个函数就要改 C++ 并重编宿主**——
  那优化的只是"实现热更"，代价是"接口变更不能热更"。
- **胜负手**：补一个通用入口 `c_wasm_call(handle, fn, args)`，C 侧只做"按签名解包 + 调
  wasmtime"，不感知业务函数名。有了它，L2 模块热更才成立；没有它，后面三级都是给
  逐函数绑定打补丁。
- **三级边界**：L1（C++ 函数级）工程上不可行，不做；**L2（模块换实例）是本次目标**；
  L3（进程级）已经落地（drain + 重连）。
- 设计里还标了五条风险，其中最要紧的是：**模块里一旦有"发钱"，必须回调宿主的 `Settle`**，
  否则多进程/崩溃重放的保证在这里就断了——这条要在接口设计时定，不能等跑起来再补。

状态：设计稿，未实现。第一步（通用入口）是唯一阻塞点，建议先只做它并用
"新增导出函数不重编宿主"来验收。

### 新增：Swoole 契约测试（`bench/swoole-contract`，6 项）

框架对 Swoole 有三条"注释里写了结论、从没验证过"的假设，这次逐条验：

1. **单帧超过 `buffer_output_size`(64KB) 也能发出去**——成立。1KB/64KB/128KB/1MB 四种
   尺寸都完整送达（`SEND_RET true`，客户端 `declared` 与预期一致）。原因是
   `buffer_output_size` 限制的是每连接**排队**缓冲，不是单次 `send()` 的大小；
   连接空闲时数据直写 socket。
2. **慢客户端不读时 `send()` 仍返回 true**——在 4MB 量级成立（4000×1KB，0 fails 0 threw），
   所以框架里 `publish` 忽略返回值暂时安全。**但不外推**：真正背压要"客户端长期读得比
   发得慢"，那时会返回 false 而框架没处理。
3. **`shutdown()` 会 flush 已 send 的数据**——成立（`send` 后立刻 shutdown，客户端仍收到）。
   这保证了 drain 的最后一步不会吞掉结算帧。

**这一版之前有一版全红的**，原因全在脚手架不在被测对象：端口复用导致第二轮在跟上一轮的
残留进程对话（于是把"1024 帧"当成"64KB 帧发不出去"），以及 `pkill -f` 把自己 shell 杀掉。
重写后三条规矩：端口每轮换、按 PID 回收、起服务后确认真在监听。
**脏 harness 会让结论看起来像是被测对象的问题**——这个教训比三条结论本身更值钱。

### 新增：AOT 语义差分测试（`bench/aot-semantics`，45 用例）

一天之内连踩三次"不报错但不干活"（Timer 回调不触发、Gateway 载荷被吞、master 信号
handler 不触发），说明缺的不是文档也不是 review，而是**对照**。这个 bench 用同一份
`main.php` 分别跑 php85 与 tpc 产物，逐用例比对输出：

```
一致 41 / 已知差异 4 / 新差异 0
```

**四条已知差异**（详见该目录 README）：

1. **闭包实参个数：AOT 严格，PHP 宽松** —— `function(){}` 被传 3 个参数，
   PHP 忽略、AOT 抛 `ArgumentCountError`。**这就是 Timer 坑的根因**：Swoole 固定传
   `$timerId`，空参闭包抛异常后被事件循环吞掉，表现是"注册成功、一次不触发"。
2. **整数溢出**：`PHP_INT_MAX + 1` 在 PHP 里降级为 float（不变号），AOT 回绕成
   `PHP_INT_MIN`（**正的变负的**）。对金币累加最危险，且不报错。
3. **闭包形参的引用传递失效**：`function (array &$a)` 在 AOT 下只给一条 warning，
   数组根本不被修改。注意 `use (&$x)` 捕获引用是正常的，坏的只有形参声明 `&`。
4. （编译期）代码必须在函数里；`switch` 每个分支必须以 `return/break/continue/exit/throw`
   收尾。这两个好在构建时就炸。

`run.sh` 把差异分成"已登记"和"新差异"：新差异一律失败退出——要么是 tpc 升级带来的
回归，要么是新代码踩到语义边界，两种都得有人看。

drain 闭环的另一半：退役/掉线之后，玩家要能回到自己那一局，而不是"重连上来一片空白"。

**先改了一个错误的默认**：原来 `onDisconnect` 直接 `forfeit()`——网络抖一下白输一局。
现在改成**宽限期**（`server.rejoin_grace_sec`，默认 15 秒）：断线不判负，期内重连不算输，
过期才判负。对面的操作不受影响，所以几乎没有作弊空间（你不操作就是站着挨打）。

协议：`Msg::REJOIN = 5003`（客户端 → 服务端）/ `Msg::REJOIN_OK = 5101`（回 room/foe/side）。
服务端据 uid 查 `DuelManager::$duelOf`——那是新增的一份 uid → {room, foe, side} 索引，
因为 `addrOf` 只答得出"在哪个房间服务上"，答不出"房间号是多少、对面是谁"。
这份索引在 `restoreAll()` 里也会从快照重建，所以**进程重启后重连同样能找回**。

**推送不需要换连接**：`$publish` 是按 uid 查 fd 的，重连重新登录后 Gateway 会把 uid→fd
指向新连接，房间那边什么都不用改。这一点是设计上省下来的（房间层不认识连接）。

```
PASS 断线 2 秒后还没判负（还在宽限期）
PASS 重连找回成功：[1001] REJOINED room 1 vs 1002 (side 1)
PASS 找回的是同一局（room 1）
PASS 服务端记了 player rejoined
PASS 对手收到了结算（正常打完，不是判负）
```

已知未做：**跨节点重连**。客户端如果连到另一个节点，那边没有这个 uid 的房间，
会回 `NOTICE no-room`。按设计 §9.1，正确做法是客户端保存 roomId、用
`{error:"room-on-node", node:N}` 提示它连回原节点——这一步要客户端配合，先把同节点做通。

### 阶段 2：drain（节点退役）——`run-drain.sh` 8 项

退役的语义是**先把存量做完**，不是"收到信号就退"：房间状态在内存里，硬退会让所有
进行中的对局退回快照（玩家视角是"打到一半被重置"）。

流程：写 `var/drain.n<id>` → 进程每秒轮询到 → 停止建新房（匹配请求回 `NOTICE draining`）
→ 等 `count() == 0` → 自己 `shutdown()`。超时（默认 300s）强退并记 error。

```
PASS 服务端轮询到 drain 文件，进入退役
PASS 新匹配收到 NOTICE draining（客户端退出码 3）
PASS 被打断不了：老对局拿到 RESULT
PASS 进程自己退出了（不需要 kill -9）
PASS 只结算了存量那一局
```

**为什么不用 SIGTERM**：试过两次让 master 收 SIGTERM——`\Swoole\Process::signal(SIGTERM, ...)`
在 master 顶层、以及在 `on('Start')` 回调里注册，**两次都返回成功但 handler 从不触发**
（SWOOLE_PROCESS 模式下 master 的 SIGTERM 由 Swoole 自己接管）。所以改成文件触发，
不依赖信号通道；k8s 用 `preStop` hook 执行 `./duel drain`。

**过程中踩到两个静默失败**（都已写进 `tsf/README.md` §5.2.2）：

1. `Swoole\Timer::tick` 的回调**必须声明 `int $timerId`**，空参闭包在 AOT 下一次都不触发、
   且没有任何报错。定位办法：在回调里写探针文件，比看日志快。
2. `Gateway::send()` 在 `payload: raw` 时**必须传 string**，传数组会抛异常，
   而消息 handler 外面有 try/catch —— 异常被吞，表现是"玩家一直等匹配"。

顺带修：`runDrain` 的 `drain` 命令要加进"不抢节点锁"的白名单，否则"通知正在跑的服务退役"
会被服务自己的 `node_id` 锁挡住。

### 阶段 1b：多进程真的跑起来（`run-cluster.sh` 11 项）

**先说端口这个坑**：同机多进程不能共用端口。实测 Swoole 在 `new Server()` 构造时就 bind，
`set(['reuse_port' => true])` 来不及生效——第二个进程直接 `Address already in use`。
所以本阶段用**每进程独立端口**（9101 / 9102），真部署时前面挂网关或 LB，
或者改用 `SWOOLE_BASE` + `SO_REUSEPORT`（留作后续）。

改动：

- **`node_id` 唯一性锁**：`mkdir var/node<N>.lock` 原子占位 + pid 文件，pid 还活着就拒绝启动。
  这是多进程下最要命的一条——两个进程用同一个 `node_id` 就是**同一个号段两个主人**，
  各自建出同一个房间号、各自往同一个快照写、`duel:<号>` 互相判重，也就是设计里最怕的"双主"，
  而且它不报错，只让账慢慢对不上。`kill -9` 留下的残骸会被下一个进程接管（读 pid + 查 `/proc`）。
- **只读命令不抢锁**：服务在跑时 `wallet-check` / `migrate-status` 仍然可用。
  会写的 `wallet-debit`/`wallet-credit` 必须抢锁（它们写的就是本节点那份日志，
  和运行中的服务同时写 = 两个写者）；要在服务运行时扣款，走 GM 通道（和 Scheduler 同一事件循环）。
- **闸门口径改了**：同进程内多 `Swoole worker` 仍不支持（没有共享调度器），
  但多**进程**可以——每个进程 `worker_num=1` + 各自 `node_id`/端口。异常信息里直接写了这两句。
- `config/tsf.node1.json`：用现成的 `TSF_ENV` 机制给 node 1 覆盖 `node_id`/`port`/日志文件，
  不需要改代码就能起第二个节点。

验收 `bash run-cluster.sh`，11 项全过：

```
PASS 两个进程都在监听（9101 / 9102）
PASS node 0 的房间号在 0 段（duel:1）
PASS node 1 的房间号在 1 段（duel:1000000001）
PASS node 0 的日志没有 1 段的号
PASS node 1 的日志没有 0 段的号
PASS 归并后看到两个节点的 2 条事件     ← wallet-check 读两份日志
PASS 重复 node_id 被拒绝
PASS 杀 node 1 后 node 0 不受影响
```

## v0.6.0 — 2026-11-16（首次封版）

首个可交付版本。对应里程碑 M0–M6 全部交付，M7（wasm 热更）未做。
细节与验收依据见 `docs/FRAMEWORK-TASK.md`；框架原理与实测过程见 `tsf/README.md`。

### 交付内容

**M0 骨架**
- 分层配置（`tsf.json` → `tsf.{env}.json` → `tsf.local.json`，点号键；加载时扁平化）
- 结构化日志（JSON Lines，error 额外写 stderr）
- `tsf` CLI：`new` / `build` / `serve` / `watch` / `migrate` / `migrate-status` / `lint` / `list` / `add` / `remove`
- 可选功能包机制：`tsf/pkg/<name>/` + `pkg.json`（ns/desc/requires），构建时展开依赖闭包。已拆出
  **fsm / entity / frameloop / room / aoi / match / db / snapshot / eventlog** 九个包

**M1 网络**
- TCP + 帧编解码双实现（`header` 纯 PHP / `native` C++ 扩展），运行期切换，配对实测 **+13.9%**
- 会话与鉴权：uid ↔ fd 双向索引、顶号、token 表 + HMAC
- 心跳空闲扫描（阈值 3 s 实测 4.6 s 断开）、每连接令牌桶限流（**74 ns/帧**）、背压断慢客户端
- WebSocket（复用同一套二进制帧）
- 连接规模 **8,500 连接零丢失**（68,005/68,005）；2 万连接 119,999/119,999、RSS 144 MB
- 容量守卫：按 `max_conn` 自动抬高 `memory_limit`、启动时校验 `RLIMIT_NOFILE` 并给出可行的修复写法

**M2 并发**
- `Service` / `Scheduler` / `Mailbox`：稳态 **1,161,289 msg/s**（每批 64 条），p99 20 µs
- Actor 复用 Service（不引入第二套概念）：1 万服务 spawn 1.5 µs/个
- 定时器：4096 槽时间轮 + 远端堆，10 万量级**误差 p50/p99/max 全为 0 ms**；cron 分钟粒度
- 跨进程消息：UDS + 攒批，2000 次往返 **p99 285 µs**
- 慢消息：**改判** —— `Coroutine::cancel()` 打不断运行中的 CPU 循环（实测返回 false），
  所以做成"检测 + 告警 + 可选销毁"（`setWatchdog`），开启代价 −13.8%

**M3 玩法**
- `pkg/fsm` 状态机（`fire()` 207 ns）、`pkg/room`（成员表/席位，零坐标）、
  `pkg/entity`（EntitySet，**258 ns/实体/帧**）、`pkg/frameloop`（真 delta，上限 200 ms）
- 房间分派两种模式：一房一 Service / 多房共用 RoomHost（1000 房定时器降 200 倍）
- `pkg/aoi` 九宫格：**不存视野表**（存储为 0），格内移动 322 ns / 跨格 21.5 µs
- `pkg/match`：1000 人单轮 2.4 ms；**配对率由评分密度决定**（验收线已改绑密度）
- `demo/duel`：登录 → 匹配 → 对战 → 结算，全走真实 TCP

**M4 数据**
- `pkg/db`：Attribute 映射（`#[Table]/#[Id]/#[Col]`）+ 懒连接 + 分片（`Source` 抽象让分片与单库
  调用代码完全一致）；主键读 **~4 µs**
- 迁移：SQL 文件 + 方言变体 + 记录表存库 + checksum 检测。**已命令化**：`tsf migrate` 部署跑一次，
  服务启动只 `verify()`
- `pkg/snapshot`：带版本 JSON + 原子写；200 实体恢复 **0.40 ms**，`kill -9` 后房间继续跑
- `pkg/eventlog`：追加写、两档持久性（FFLUSH 1.2 µs/条 / SYNC 4.3 ms/条）、幂等重放、截断修复；
  **1,000 笔 kill -9 零丢失、零坏行**

**M5 分布式**
- RPC：跨进程串行 **p99 377 µs**；协议分层（框架定消息头，载荷交给 `RpcCodec`）；
  **故意不做自动重试**（幂等性只有业务知道）；**不做** = 协议号收敛到 2 个
- 服务发现：`Contract\Registry` + `FileRegistry`（心跳用内容指纹，**不用 mtime**），
  `kill -9` 后 **3,016 ms 收敛**
- 分片环：一致性哈希 + 虚拟节点，**扩容 3→4 迁移 24.79%**（理论 25%），零老节点间搬迁
- 跨服路由：按 uid 透明调用，本地 **58 µs** / 远端 **217 µs**，四个提前拦截（含 codec-mismatch）
- 熔断：三态 + 单探针 + 冷却翻倍；半死节点 **250 ms 摘除**（验收 5 s），摘除后 30.6 µs 拒绝
- 集群压测 3 节点：**16.9k req/s、p99 1.75 ms**；拐点约 96 在飞；**上限 ~20k req/s**

**M6 工具链**
- 配置表导出：Excel 表头即 schema，校验失败一个字节都不写，**末尾追加列免重编**；1 万行加载 63 ms
- 配置热更：盯 **crc32 清单**（不用 mtime），小表 **34 ms 生效**，坏数据被拒仍服务旧值
- 协议生成：proto3 子集 → 类 + 编解码，**字节与标准 protobuf 一致**（前端可直接用 protobuf.js）
- 指标：指数分桶（误差恒定 ≤19%），`inc` **81 ns** / `observe` **390 ns**，慢调用日志限流
- GM 通道：独立端口迷你 HTTP，空 token 拒启动、默认只绑本机、头传 token、爆破限流、审计统一记
- `tsf watch`：改一行 **1.45 s**（9 包的 demo 1.85 s）

### 封版前修的

**迁移命令化**（2026-11-15）
- `tsf migrate` / `tsf migrate-status`；服务启动只校验不建表。原实现每个 worker 各调一次 `up()`，
  记录表挡得住重复执行但**挡不住并发建表**。锁用 `mkdir`（`flock` 不在 AOT std 表面里）

**金币结算接进真实对局**（2026-11-15）
- `demo/duel` 每局给赢家发 100；幂等键用业务主键 `duel:<房间号>`，顺序是**日志先行**

**架构审计**（2026-11-15，报告在 `tsf/AUDIT-2026-11-15.md`）
- **修 P0**：事件日志多写入进程下 `seq` 必然冲突（实测 120 条记录只有 61 个不同序号）——
  应用层启动断言 `worker_num > 1` 直接拒绝
- **修 P0**：房号跨重启复用 → 新一局被判"已结过账"，**钱静默不发、日志也不多一行**——
  `Settle::maxSettledRoom()` + 房号只增不回退
- **删** `Kernel\Container`（82 行零引用，能力表却打 ✅）、**删** `Config::reloadIfChanged()`
  （零调用，且用的是秒级 mtime——项目里同一个坑踩过四次）
- 补 `demo/README.md`、`tpc-patches` 的升级回归清单（db-test / watch-test / migrate-test）

**仓库整理**（2026-11-16）
- 3.8 G → 839 个文件 / 3.3 MB：白名单 `.gitignore`，只收源码、文档、配置
- 去掉 29 个脚本里写死的 `cd /data/TypeServer/<dir>`（克隆后会操作原目录）
- 配置支持 `${base}` 占位符（`Config::expandBase()`），不再写死绝对路径
- `tools/repo-guard.py`：抓「被 gitignore 误挡的源码」（`ext/codec/cpp-src/*.cc` 就这么漏过）

### 已知边界

| 场景 | 状态 |
|:--|:--|
| 单 worker 房间类玩法、内部框架、demo | 已验证（26 个 bench、1,111 条断言） |
| 同进程多 worker | **不支持**（启动会拒绝）；多**进程**可用（各配 node_id + 端口，见 `demo/duel/run-cluster.sh`） |
| 扣钱 / 余额对账 | v0.6.0 时只有发奖（见上「未发布」）。现在有扣款/充值/对账；余额仍靠启动全量重放，db 战绩与事件日志之间仍无关联 |
| 大日志量 | 未做 checkpoint/truncate 自动化，启动时间随日志线性增长 |
| 非 Linux | 只验过 WSL/Linux，Windows/macOS 未测 |
| NTS 构建 | 工具链断（`typephp-095` 编不动当前源码），`demo-echo-nts` 是无法重建的历史产物 |
| wasm 热更（M7） | 未做 |

### 版本兼容

- 无历史包袱：v0.6.0 是首个封版版本。
- AOT 编译器锁 `typephp 0.9.6` + 两个本地补丁（见 `tpc-patches/README.md`），**升级 tpc 后需重新应用**。
- 生成的 protobuf 字节与标准 wire format 一致，前端可用 protobuf.js 直读同一份 `.proto`。
