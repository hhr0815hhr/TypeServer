# 集成指南

> 三块能力，按依赖顺序接：**① wasm 宿主 → ② 业务模块 → ③ 房间监督**。
> 每块都给出：做什么、改哪里、怎么验、容易踩什么坑。

---

## 第 1 步：编译 wasm 宿主库

宿主库是一份 Rust 实现的 `libtp_host.so`，负责"加载 `.cwasm`、跑实例、管预算、报错误"。业务侧不直接碰它，通过 C++ 桥接层调用。

```bash
cd src/wasm-host
export PATH="$HOME/.cargo/bin:$PATH"
cargo build --release
# 产物：target/release/libtp_host.so（约 20 MB）
```

**验收**：`libtp_host.so` 存在，且 `nm -D` 能看到 `tp_open` / `tp_call_idx` / `tp_set_budget`。

```bash
nm -D target/release/libtp_host.so | grep -E " T (tp_open|tp_call_idx|tp_set_budget)$"
```

**坑**：这个库只导出 8 个符号，与 `libphp.so` 的 5408 个导出**交集为空**（S2 实测），所以可以安全加载进 PHP 进程，不会符号冲突。

---

## 第 2 步：写业务模块并预编译

### 2.1 模块（PHP → wasm）

模块就是普通 PHP 文件，加 `#[\WasmExport]`：

```php
<?php
#[\WasmExport]
function add(int $a, int $b): int { return $a + $b; }
```

工程配置（`project.yml`）关键在包名与 world，**宿主按 `typephp:<name>/api@1.0.0` 找接口**：

```yaml
name: my-module
wasm: component
wasm-mode: library
wasm-package: typephp:my-module@1.0.0
wasm-world: my-module
sources:
  - lib.php
```

编译：

```bash
export PATH="$WASI_SDK/bin:$WASMTIME_BIN:$WIT_BINDGEN_BIN:/usr/local/php-8.5-zts/bin:$PATH"
php85 /path/to/tpc.php project.yml --wasm
# 产物：my-module.wasm（约 23 MB）
```

### 2.2 预编译成 `.cwasm` + manifest

```bash
./precompile.sh build my-module.wasm my-module.wasm.cwasm
```

会产出两个文件：

```
my-module.wasm.cwasm            54 MB   预编译产物
my-module.wasm.cwasm.manifest   400 B   指纹 + 尺寸 + sha256
```

**验收**：

```bash
./precompile.sh verify my-module.wasm my-module.wasm.cwasm
# 输出 "OK: 产物与源文件一致"
```

**坑**：
- 预编译与运行期的 **fuel / epoch 配置必须一致**，否则加载时报 `Module was compiled without fuel support but it is enabled for the host`；
- `precompile.sh` 与宿主共用同一份 `build_config()`，**只要两侧环境变量一致就对得上**；
- 产物**不要跨环境分发**，一律在目标环境重新生成。

---

## 第 3 步：接进 PHP（stub + C++ 桥接）

### 3.1 声明（`php-src/xxx.stub.php`）

只写签名、空实现。**参数与返回值只允许 `php::` 支持的类型**（int / float / string / bool / mixed）。

```php
function wasm_load(string $name, string $path): int {}
function wasm_add(int $h, int $a, int $b): int {}
```

### 3.2 实现（`cpp-src/xxx.cc`）

函数名必须是 `php_` + stub 名。桥接层已经在 `src/wasm-host/cpp-src/wasm_bridge.cc` 里写好，通常**不需要改**，直接复用它的 API：

```cpp
Int php_wasm_load(Str name, Str path);        // 返回句柄
Int php_wasm_add(Int h, Int a, Int b);        // 索引绑定调用
Int php_wasm_swap(Int h, Str newPath);        // 灰度切换
Int php_wasm_stats(Int h, Int which);         // 观测
```

### 3.3 工程配置（`project.yml`）

```yaml
name: my-service
cxx-std: c++17
cxx-flags: |
  -O2
ld-flags: |
  -L/abs/path/to/lib
  -Wl,--no-as-needed
  -ltp_host
  -Wl,-rpath,/abs/path/to/lib
sources:
  - php-src
  - cpp-src
  - main.php
```

**坑（最容易卡住的一条）**：配置键名是 **`ld-flags` / `cxx-flags`**（带连字符）。写成 `ldflags` 会被**静默忽略**，症状是链接时报 `undefined reference to tp_open`。

**坑二**：Ubuntu 默认 `--as-needed` 会把排在目标文件前的 `-ltp_host` 丢弃。必须加 `-Wl,--no-as-needed`。

### 3.4 业务代码调用

```php
$h = wasm_load("battle", "/opt/mod/battle.wasm.cwasm");
if ($h < 0) { /* -1 打开失败 -2 重名 -3 表满 */ }

$dmg = wasm_add($h, atk, def);       // 成功返回结果；失败见返回码表
```

**验收**：跑通 `./build.sh && ./run.sh`，看到 `main.add(1,2) = 3` 与 `canary.add(1,2) = 1003`。

---

## 第 4 步：房间监督

### 4.1 编译

```bash
cd src/supervisor
php85 /path/to/tpc.php project.yml
# 产物：e4_supervisor
```

### 4.2 配置（改 `src/supervisor/main.php` 顶部常量）

```php
const BIG_ROOMS = 6;        // 大房间数（每房一个进程）
const SMALL_HOSTS = 1;      // 小房间宿主进程数
const SMALL_ROOMS = 50;     // 每个宿主内的协程房间数
const MAX_RESTARTS = 5;     // 窗口内重启超过即隔离
const WINDOW_SEC = 60;      // 滑动窗口
```

### 4.3 运维接口（写文件即可，无需 RPC）

| 操作 | 方式 |
|:--|:--|
| 解除隔离 | `echo reset > rooms/<key>.cmd`，1 秒内生效 |
| 查看状态 | `cat rooms/<key>.state`（pid / ticks / tier / restarts / state） |
| 查看告警 | `cat alarms.log` |
| 查看事件流 | `cat supervisor.log`（spawn / exit / respawn / QUARANTINE / cmd） |

**验收**：

```bash
cd ../e4 2>/dev/null || cd spike/e4
./test.sh     # 分级 + 隔离
./test2.sh    # 非阻塞退避 A/B + 告警 + 解除
./test3.sh    # 状态持久化
```

---

## 第 5 步：接入线上前的三件事

1. **告警接出去**：`alarms.log` 与 stdout 需要被采集器抓走（filebeat / vector → 企微/邮件）。不接的话，隔离告警没人看得到。
2. **监督器交给 init 管**：systemd 或 k8s，崩溃自动拉起；**拉起时会被视为一次全量重启**，注意限流。
3. **恢复限流**：如果房间数较多，重启后不要同时拉起全部房间（S5 实测 60 房同时恢复 152 ms，是单房的 84 倍），按每批 5–10 个、间隔 100 ms 分批。

---

## 常见问题

**Q：加载模块报 `配置漂移`？**
A：产物与当前宿主的 fuel/epoch 配置不一致。用相同环境变量重新 `precompile.sh build`。

**Q：加载报 `缺少 manifest`？**
A：产物是早期版本或被人删了。重新预编译。

**Q：调用返回 -3000？**
A：wasm 内 trap（超时/超预算/非法指令）。实例已被自动丢弃重建，当次调用失败，下次正常。频繁出现说明模块里有死循环或计算量过大，应调大 `ticks` 或优化模块。

**Q：两个模块行为一样？**
A：检查宿主版本——早期版本只缓存一个组件，第二个 `tp_open` 会复用第一个。当前版本按路径缓存，不会出现。

**Q：调用比预期慢（>1.5 µs）？**
A：检查调用路径上是否传了字符串（模块名/方法名）。实测按名字查会把 0.78 µs 推到 1.10 µs。
