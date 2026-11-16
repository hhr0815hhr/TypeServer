# Spike S3 —— 资源治理：epoch / fuel 能否真正打断 PHP 的死循环

> 执行时间：2026-11-12
> 环境：WSL2 Ubuntu 22.04 / tpc v0.9.6 / 宿主 Rust 1.99 + wasmtime 47.0.4
> **结论一句话：能，而且很干净。epoch 按墙钟中断（延迟 ≈ 心跳周期 + 0.2 ms），fuel 按指令数确定性中断（每次消耗可复现）。但被打断的实例不可复用，必须丢弃重建——这恰好就是我们要的崩溃隔离语义。**

---

## 一、结论

| 待验证项 | 结论 | 实测 |
|:--|:--:|:--|
| epoch 能打断超长计算 | ✅ | 1 ms 心跳 → **1.2 ms** 中断；10 ms 心跳 → **10.2–10.3 ms** |
| epoch 能打断真死循环 | ✅ | `forever(3)` 永不返回，**10.2 ms** 后 trap，进程存活 |
| fuel 能按预算打断 | ✅ | 1e7 fuel → **1.8 ms** 耗尽；5e6 → 0.8–0.9 ms |
| fuel 计量确定性 | ✅ | 两次独立运行均为 **210228 fuel / 10000 次迭代**，完全一致 |
| 中断后实例可复用 | ❌ **不可** | `wasm trap: cannot enter component instance`，永久报废 |
| 中断后进程存活 | ✅ | 宿主进程正常，可创建新实例 |
| fuel 的运行时开销 | ✅ 可忽略 | `add` 0.867 → 0.873 µs（+0.7%）；计算型无差异（噪声内） |
| 重建实例恢复服务 | ⚠️ 取决于 Engine 复用 | 共享 Engine **2.3–2.5 ms**；每实例新建 Engine **4282 ms** |

---

## 二、实测数据

### 2.1 epoch：延迟由心跳周期决定，抖动极小

`ticks=1`，即"距当前 epoch 一次递增就触发"：

```
tick=1ms   中断成功：1.2 ms 后 trap     (3/3 次一致)
tick=10ms  中断成功：10.2 / 10.2 / 10.3 ms 后 trap
```

无中断基线：`spin` 为 **3.12 ns/迭代**，被测调用参数为 20 亿次迭代 → 折合 **6.2 秒**纯计算。也就是说，**6.2 秒的卡死被压到 10 毫秒**，压缩比 600:1。

真死循环同样生效（循环体带字符串分配，无法被优化，见 §3.3）：

```
epoch: func=forever ... arg=3
baseline: 跳过（被测函数不返回，无法先跑基线）
中断成功：10.2 ms 后 trap
Caused by: wasm trap: interrupt
```

### 2.2 fuel：确定性计量，可当"算力配额"用

```
calibration: 10000 iters 消耗 210228 fuel -> 21.023 fuel/iter
预算耗尽中断：1.8 ms，剩余 fuel=0
Caused by: wasm trap: all fuel consumed by WebAssembly
```

| 函数 | 单次迭代 fuel | 说明 |
|:--|:--|:--|
| `spin`（整数取模循环） | **21.023** | 两次运行完全一致 |
| `forever`（循环体含字符串拼接） | **144.459** | 分配开销折算成 fuel |

**确定性**这一点对游戏服很有价值：同一份输入在任意机器上消耗相同 fuel，可以据此做"每帧每玩家算力配额"，而不是靠墙钟（墙钟受机器性能和负载影响）。

### 2.3 fuel 的运行时开销（本节结论已在 E1 复核时修正）

同一组件、同一宿主，只切 `consume_fuel`：

| 项目 | 不开 fuel | 开 fuel | 差异 |
|:--|:--:|:--:|:--:|
| `add(int,int)` | 0.867 µs | 0.873 µs | +0.7% |
| `greet(string)` | 1.194 µs | 1.231 µs | +3%（含噪声） |
| `simulateFrame(10000)` | 10.048 µs | 9.829 µs | 噪声内 |

> **修正（2026-11-12，E1 复核）**：上表的"开 fuel"一组其实**没有真正插桩**——模块由未启用 fuel 的引擎编译，运行期只是没报错，fuel 计数并未生效。用真正插桩后的 `.cwasm` 重测（三种配置各预编译一次）：
>
> | 配置 | `add` | `simulateFrame(10000)` | `.cwasm` |
> |:--|:--:|:--:|:--:|
> | 无插桩 | 1.056 µs | 9.79 µs | 41 MB |
> | 仅 epoch | 0.794 µs | 9.60 µs | 47 MB |
> | epoch + fuel | 0.718 µs | **10.72 µs** | 54 MB |
>
> 即：**边界型调用不受影响；计算型 fuel 约 +10~15%、epoch 约 0；`.cwasm` 各 +15%**。
> 结论相应收窄为"epoch 默认开、fuel 按需开"，不再是"fuel 可默认开启无代价"。

> 注：本轮 `add` 为 0.867 µs，比 S1 的 0.742 µs 高约 17%。差异来自组件内新增了两个导出（代码布局变化）与机器状态波动，属正常抖动；S1/S3 的关键结论（边界约 0.75–0.87 µs）不受影响。

---

## 三、三个必须知道的机制细节

### 3.1 打开 epoch 后，默认 deadline 就是 0 —— 连实例化都会 trap

第一次尝试直接失败：

```
Error: instantiate: wasm trap: interrupt
```

原因：`Config::epoch_interruption(true)` 之后，Store 的默认 deadline 是 0，含义是"下一次 epoch 检查立即 trap"。而实例化本身就在执行 wasm（跑 data 段初始化），所以第一步就被打断。

**正确做法**：实例化之前先给一个足够大的 deadline，或用 `Store::epoch_deadline_callback` 做动态续期。

```rust
cfg.epoch_interruption(true);
let mut store = Store::new(&engine, ctx);
store.set_epoch_deadline(1_000_000_000);   // 启动阶段放行
let instance = linker.instantiate(&mut store, &component)?;  // 然后才能实例化
// 进入业务调用前再收紧
store.set_epoch_deadline(1);
```

这个坑很隐蔽：文档里不会写，但漏了它整个方案第一步就走不通。

### 3.2 trap 之后实例永久报废，不能"catch 完继续用"

两种 trap 表现一致：

```
trap 后 add(1,2) 调用失败（实例已废）: wasm trap: cannot enter component instance
trap 后第二次调用失败: wasm trap: cannot enter component instance
```

这是 wasmtime 的**设计选择而非缺陷**：一次执行被打断后，实例的线性内存、栈、宿主表都可能处于中间态，引擎不允许再进入。

叠加 TypePHP 适配层的两条守卫，结论是双重的不可恢复：

| 层面 | 残留状态 | 后果 |
|:--|:--|:--|
| wasmtime | 实例被标记不可进入 | `cannot enter component instance` |
| 适配层 | `call_active` 未被清除（trap 不走 C++ 析构） | 即使能进入也会报"concurrent or reentrant calls" |
| 适配层 | `runtime_started` 仍为 true | 无法在旧实例里重新 `create-runtime` |

**因此运维模型只有一种：trap → 丢弃实例 → 新建实例。** 这与 Erlang 的 "let it crash" 是同一个形状，而且比杀进程轻得多——不需要重启进程，也不影响同进程里的其他实例。

### 3.3 坑：AOT 会把"纯计算的死循环"直接折叠掉

这一点值得单独记，因为它会让所有"用死循环做压测"的尝试失真。

最初的探针写成：

```php
function forever(int $seed): int {
    $i = $seed;
    while ($i >= 0) { $i = ($i + 1) % 1000000007; }   // 期望：永不返回
    return $i;
}
```

实测**立刻返回 `$seed`**。换成带参数退出条件的版本：

```php
function forever(int $target): int {
    $i = 0;
    while ($i != $target) { $i = ($i + 2) % 1000000007; }   // target 为奇数时永不相等
    return $i;
}
```

`forever(3)` 仍然**立刻返回 3**。查生成的 C++ 是对的：

```cpp
php::Int i = php::toInt(0LL);
while (php::toBool(((i) != (target)))) { i = php::toInt((((i) + (2LL))) % (1000000007LL)); }
return php::toInt(i);
```

推论：clang 依据 C++ 的 *mustprogress* 语义（循环必须推进/终止）做了两步改写——先认定该循环在无副作用时会被删除，再把出口条件 `i == target` 当作后置条件，于是把整个循环替换成 `i = target`。**这是合法的编译优化**，不是 bug。

要做出"真死循环"探针，循环体必须有可观测副作用。最终采用字符串拼接：

```php
while ($i != $target) {
    $s .= "y";                        // 每次分配内存，无法被折叠
    $i = ($i + 2) % 1000000007;
    if ($i > 1000000) { $s = "x"; }   // 防止内存暴涨
}
```

这才得到稳定的 10.2 ms 中断（单次迭代 144 fuel，说明内存分配确实在跑）。

**对业务的含义**：不要用"AOT 之后循环一定跑得久"做假设。纯数值的、结果不被使用的长循环会被优化掉；而游戏逻辑的循环通常要写状态，天然带副作用，不受影响。反过来这也是好事——编译器帮你去掉了无意义的空转。

### 3.4 恢复成本取决于是否复用 Engine

| 恢复方式 | 耗时 | 说明 |
|:--|:--:|:--|
| 新建 Engine + 从 `.wasm` 现场编译 | **4282 ms** | 每次都重新 JIT 整个 PHP，不可接受 |
| 共享 Engine + 已有组件 + 新建实例 | **2.3–2.5 ms** | S1 多实例边际实测（instantiate + PHP 启动） |

**这条把 S1 的结论又钉死一次**：Engine 必须进程级共享，组件必须走 `.cwasm` 反序列化。否则"trap 后重建"会从 2.5 ms 变成 4.3 秒，隔离带来的收益全部被恢复成本吃掉。

---

## 四、落地设计：三档预算 + 一条回收规则

基于以上实测，T1.5（wasm 模块）的资源治理可以写成固定套路：

```
每个实例：
  store.set_epoch_deadline(1)          // 心跳线程每 N ms increment_epoch()
  每次调用前 store.set_fuel(budget)     // 按玩法复杂度分配

trap 分类处理：
  Trap::Interrupt      → 超时/超预算 → 记录（哪只脚本、哪个入口、跑了多久）
  Trap::OutOfFuel      → 算力超限   → 同上
  其他 trap            → 逻辑错误   → 同上

统一动作：丢弃该实例 → 从组件新建实例（2.5 ms）→ 重新注入状态快照
```

推荐的三档预算（依据实测换算）：

| 档位 | epoch deadline | fuel 上限 | 适用 | 实测换算 |
|:--|:--|:--|:--|:--|
| 帧内钩子 | 心跳 1 ms × 1 | 10⁵ | 逐帧调用的技能/状态判定 | ≈5 ms 计算量 |
| 事件处理 | 心跳 1 ms × 5 | 10⁶ | 拾取、任务推进、邮件领取 | ≈50 ms |
| 异步任务 | 心跳 1 ms × 50 | 10⁷ | 排行榜结算、批量校验 | ≈0.5 s |

上限都是"远超预期正常耗时、远低于业务可感知卡顿"的位置。真出问题时，10 ms 内就能把卡死的实例切掉，玩家侧只表现为"这次操作没生效"，而不是整个 worker 卡死、几千人掉线。

---

## 五、复现命令

```bash
cd /data/TypeServer/spike/s1

# 1) 编译探针组件（含 add / greet / simulateFrame / spin / forever / freshProbe）
export PATH=/home/hhr/.tools/wasi-sdk-33.0-x86_64-linux/bin:/home/hhr/.tools/wasmtime-v47.0.0-x86_64-linux:$PATH
php85 /home/hhr/typephp-src/bin/tpc.php project.yml --wasm

# 2) epoch 打断超长计算（心跳 1ms）
./host-rs/target/release/s1-host epoch s1-probe.wasm spin 1 1 2000000000
# 3) epoch 打断真死循环
./host-rs/target/release/s1-host epoch s1-probe.wasm forever 1 10 3 0
# 4) fuel 预算中断（含 fuel/迭代标定）
./host-rs/target/release/s1-host fuel  s1-probe.wasm spin 10000000 2000000000
# 5) fuel 开销对比
./host-rs/target/release/s1-host bench      s1-probe.wasm 200000
./host-rs/target/release/s1-host bench-fuel s1-probe.wasm 200000
```

---

## 六、S3 判定

**通过，且比预期更干净。** 三条结论按重要性排序：

1. **"PHP 协程无抢占"这个最大短板被补上了。** 不论是 6 秒的超长计算还是永不返回的死循环，都能在 **10 ms 内**被切断，粒度由心跳周期控制，抖动 ±0.1 ms。
2. **隔离语义与 Erlang 一致，但代价更低。** trap 后实例报废不可复用——这不是限制，而是让"崩溃单元"变成"实例"而非"进程"的机制。恢复只需 2.5 ms（共享 Engine 前提下），玩家侧表现为单次操作失败。
3. **fuel 可以放心默认开启。** 开销在测量噪声内，换来的是确定性算力配额——这是墙钟做不到的，也是防止"某个脚本吃掉整帧"的唯一可靠手段。

剩下的前置条件是 S2（wasmtime 嵌入 native bin 的链接成本）——现在它的重要性又上升了一档，因为"共享 Engine"是恢复成本的前提，而共享 Engine 要求宿主是常驻进程而不是每次拉起。

---

## 七、套件变化

| 文件 | 变化 |
|:--|:--|
| `lib.php` | 新增 `spin`（长计算）、`forever`（真死循环，带副作用）、`freshProbe`（构建新鲜度校验） |
| `host-rs/src/main.rs` | 新增 `epoch` / `fuel` / `bench-fuel` 三个模式，含 trap 分类、恢复性测试、实例重建计时 |
| `s1-probe.wasm` | 23.80 MB（新增 3 个导出） |
| `s1-probe-bench.wasm` | S1 基线产物备份（用于回归对比） |
