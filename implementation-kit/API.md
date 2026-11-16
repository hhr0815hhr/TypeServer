# 接口速查

---

## 一、宿主 C ABI（`libtp_host.so`）

```c
/* 生命周期 */
void       *tp_open(const char *artifact_path);   /* 成功返回句柄，失败 NULL */
void        tp_close(void *h);

/* 预算治理 */
int         tp_set_budget(void *h, unsigned long long ticks, unsigned long long fuel);
int         tp_is_dead(void *h);                  /* 1 = trap 后作废，必须丢弃重建 */

/* 调用：按名字（慢，含哈希查找） */
int         tp_call1(void *h, const char *method, long long a, long long *out);
int         tp_call2(void *h, const char *method, long long a, long long b, long long *out);

/* 调用：按索引（推荐，加载时解析一次） */
int         tp_method_index(void *h, const char *name);   /* 不存在返回 -1 */
int         tp_call_idx1(void *h, int idx, long long a, long long *out);
int         tp_call_idx(void *h, int idx, long long a, long long b, long long *out);

/* 诊断 */
unsigned long tp_last_error(char *buf, unsigned long len);
long long     tp_ping(long long x);               /* 纯 C 边界基线，不碰 wasm */
```

### 返回码约定

| 码 | 含义 | 调用方该做什么 |
|:--:|:--|:--|
| `0` | 成功 | 读取 `*out` |
| `1` | 业务错误（wasm 返回 `result::err`） | 按业务处理 |
| `2` | **trap**（超时/超预算/非法指令），实例已作废 | 丢弃 `h` 并重建；当次调用失败 |
| `-1` | 未知方法 / 句柄为空 | 检查方法名与句柄 |
| `-2` | 返回形状异常（非 s64） | 检查模块导出签名 |
| `-3` | 参数/形状不匹配 | 检查 arity |

### `ticks` 语义（重要）

`set_epoch_deadline(N)` = "再过 N 个心跳就 trap"，心跳间隔由 `TP_TICK_MS`（默认 10 ms）控制。

- 允许时长 ≈ `(N-1) × 心跳间隔`
- **`N=1` 会误杀任何跨越心跳点的调用**（实测 20 万次约 16 次），禁止使用
- 推荐 `N=3`（≈20–30 ms）

### 环境变量

| 变量 | 默认 | 说明 |
|:--|:--:|:--|
| `TP_TICK_MS` | 10 | epoch 心跳间隔（ms） |
| `TP_NO_EPOCH` | 未设 | 设为任意值则关闭 epoch 切断 |
| `TP_NO_FUEL` | 未设 | 设为任意值则关闭 fuel 计数（**必须与预编译一致**） |

---

## 二、PHP 侧（桥接层提供）

```php
// 生命周期：返回句柄 >=0；-1 打开失败，-2 重名，-3 表满（当前 8 槽）
function wasm_load(string $name, string $path): int;
function wasm_close(int $h): void;
function wasm_shutdown(): void;
function wasm_count(): int;

// 调用（索引绑定，推荐）
function wasm_add(int $h, int $a, int $b): int;
function wasm_sim(int $h, int $entities, int $seed): int;
function wasm_burn(int $h, int $iters): int;        // 用于演练 trap

// 对照：按方法名查找（慢约 300 ns，仅供对比）
function wasm_add_byname(int $h, int $a, int $b): int;

// 灰度切换：返回切换耗时（微秒），失败 -1
function wasm_swap(int $h, string $newPath): int;

// 观测：which 0=calls 1=traps 2=rebuilds 3=max_us 4=alive
function wasm_stats(int $h, int $which): int;
function wasm_set_timing(int $on): void;            // 开启后每次调用取时钟（+20~30 ns）
```

**桥接层的额外行为**（业务侧不用写）：
- 每次调用前重置预算；
- 返回 `2` 时自动 `close + 重新 open`（同路径），并把 `rebuilds + 1`，对业务返回 `-3000`；
- `wasm_swap` 是"新实例就绪 → 换句柄 → 释放旧实例"，失败时不改变现有实例。

---

## 三、产物 manifest

```ini
# 由 tp-precompile 自动生成，请勿手改
format=1
fingerprint=component=1;epoch=1;fuel=1;os=linux;arch=x86_64
artifact_size=54138960
source_size=23806403
source=/abs/path/module.wasm
source_sha256=265a805b...
artifact_sha256=485c31a7...
built_at=2026-11-12T10:54:51+08:00
```

| 校验方 | 校验项 | 失败表现 |
|:--|:--|:--|
| 运行期 `tp_open` | `fingerprint` + `artifact_size` | 返回 NULL，`tp_last_error` 给出两侧指纹 |
| CI `precompile.sh verify` | `source_sha256` / `artifact_sha256` | 退出码 1，提示"产物陈旧"或"产物损坏" |

运行期**不做**全量 sha256（54 MB 要 60–80 ms，会把 16 ms 的加载拖到 70 ms+）。完整性交给 CI。

---

## 四、房间监督

### 4.1 状态文件（`rooms/<key>.state`）

```
<pid> <ticks> <tier> <restarts> <state>
```

| 字段 | 说明 |
|:--|:--|
| `pid` | 进程号；`0` 表示当前无进程（退避中或已隔离） |
| `ticks` | 该房间的 tick 计数（用于判断是否卡死/是否连续） |
| `tier` | `big` / `smallhost` / `small` |
| `restarts` | 窗口内重启次数 |
| `state` | `running` / `quarantined` |

### 4.2 监督参数

| 参数 | 建议值 | 含义 |
|:--|:--:|:--|
| 窗口 `WINDOW_SEC` | 60 | 滑动窗口长度 |
| 阈值 `MAX_RESTARTS` | 5 | 窗口内超过即隔离 |
| 退避起点 | 第 3 次 | 前 2 次立即重启（覆盖偶发故障） |
| 退避阶梯 | 100 / 200 / 400 / 800 ms | `100 × 2^(n-3)`，上限 800 |
| 轮询 | 1 ms（有任务）/ 4 ms（空闲） | 空闲占单核 0.66% |

### 4.3 管理命令（写文件）

| 命令 | 效果 |
|:--|:--|
| `echo reset > rooms/<key>.cmd` | 清零计数、解除隔离、重新拉起 |

### 4.4 持久化（`supervisor.state`）

```
# key	restarts	window_start	quarantined
big2	6	1794456065	1
```

监督器启动时恢复：`quarantined=1` 的房间**只写状态标记、不 fork**。这样监督器重启（发布/OOM）不会让隔离失效。

---

## 五、观测口径

| 指标 | 来源 | 关注点 |
|:--|:--|:--|
| `calls` | `wasm_stats(h, 0)` | 调用量是否符合预期 |
| `traps` | `wasm_stats(h, 1)` | **持续增长说明模块有问题**，不是偶发 |
| `rebuilds` | `wasm_stats(h, 2)` | 重建次数；与 traps 接近说明每次 trap 都重建成功 |
| `max_us` | `wasm_stats(h, 3)` | 单次最坏耗时（需 `wasm_set_timing(1)`） |
| `PSS/RSS` | 外部采集 | 单模块约 55 MB |
| `alarms.log` | 监督器 | 隔离告警，必须接采集器 |
