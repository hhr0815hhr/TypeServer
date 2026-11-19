# 配置热更（默认手动，不默认自动）

> 版本：tsf 0.10.0 / tpc 0.9.6
>
> 脚本热更（改玩法逻辑）看 `hot-reload.md`；这篇讲**配置数据**。

## 一句话

配置在启动时加载进内存，**改文件不会自动生效**。要生效必须显式触发
（GM 命令，或生产上由发布系统推送）。

## 两条路，按规模选

| | Excel 工具链 | FileLoader + PHP 数组 |
|:--|:--|:--|
| 入口 | `tsf config build` → `.bin` + 强类型类 + 清单 | `TSF\Kernel\FileLoader::array()` |
| 谁维护 | 策划 | 程序 |
| 规模 | 上千行 | 几十个 key |
| 校验 | schema（类型/范围/尾列） | 无，自己兜底 |
| 变更检测 | 导出端算 crc32 写 `manifest.txt` | 运行时 `FileLoader::hash()` |
| 重载粒度 | 按表（`Tables::reload(["item.bin"])`） | 整个文件 |
| 热更编排 | `TSF\Config\Watcher`（Service，轮询清单） | GM 命令 / 手动 |
| 适合 | 正式数值表 | 调试旋钮、小开关、少量手写规则 |

**别自己写 `include $path`** —— 见下面第 1 个坑。

## 四条规矩（两条路由共享）

1. **判"变没变"看内容，不看 mtime**（crc32 / 清单）
2. **原子替换**：先建新数组/新实例，最后一次性赋值
3. **失败保留旧值**：坏数据不污染线上，只记错误、不自动重试
4. **变更必须有发起人**：默认不自动轮询（见下）

## 为什么默认不自动

自动轮询（每 N 帧算 crc32）的问题不是性能，是**说不清**：

- 生效时机取决于轮询节奏，不是任何人做的决定 —— 出事时答不上"谁让它变的"
- 可能读到半成品（编辑器保存中、导出到一半）
- 测试时配置被悄悄换了，行为不可复现

对齐后端常识：`nginx -s reload`、Redis `CONFIG SET`、K8s 改 ConfigMap 要 rollout restart。
**没有哪个是"自己变"的。**

**"手动"不等于"逐台敲命令"**：生产上是"触发显式、分发自动"——策划在发布系统点按钮，
系统推送到所有机器并调重载。发起人仍然是点按钮的人。

要开发便利就显式打开自动轮询（demo 里是 `hot.auto_reload => 1`），
但那是个"我知道我在牺牲可解释性"的选择，不该出现在生产配置里。

## FileLoader 用法

```php
use TSF\Kernel\FileLoader;

$conf = FileLoader::array(__DIR__ . "/config/tuning.php");   // 加载（内含 opcache 失效）
$hash = FileLoader::hash(__DIR__ . "/config/tuning.php");    // 内容指纹，判"要不要重载"
$fn   = FileLoader::closure(__DIR__ . "/hot/rule.php");      // 脚本（零扩展依赖的降级版）
```

- `array()` / `closure()` 返回 `null` 表示不可用 → **保留旧数据**，别清空
- `hash()` 返回空串表示文件不可读 → 当作"这次不重载"，而不是"内容变了"
- 它只管"加载 + 指纹"，不管"什么时候加载"——编排在调用方

## GM 命令手动重载（推荐做法）

```bash
curl -s -X POST http://127.0.0.1:9701/gm \
     -H "X-GM-Token: $TOKEN" \
     -d '{"cmd":"config.reload","args":{}}'
# {"ok":true,"data":{"keys":19,"hash":"3620235632"}}
```

实现要点（细节见 `gm.md`）：**GM 直接调 Service 的方法，同步返回结果**，
不要"投递消息 + 返回处理中"——那样的返回值对运维没有意义。

## 三个坑（都实测过）

### 1. `include` 在 AOT 下命中 opcache，且不随 mtime 失效

即使 `opcache.validate_timestamps=On` 也一样。症状极具误导性：文件监听正常、
重载计数在涨、mtime 也变了，但读到的永远是旧值，**没有任何报错**。

重启进程就好了（冷启动时 opcache 是空的），所以容易被误判成"缓存没清干净"。

→ 一律用 `FileLoader`，它内部做了 `opcache_invalidate($path, true)`。

### 2. mtime 判变更的三个失效场景

```
filemtime() 只到秒  → 同一秒改两次，第二次看不到
改等长值            → 12 → 45，连文件大小都不变
mtime + size 一起用 → 上述两种同时骗过
```

实测：同一秒内把 `base` 从 60 改成 75、再改成 90，mtime 只落一帧。
用 mtime 的实现会停在 75 并**永不更新**（`$mtime === $this->mtime` 一直成立）；
用 crc32 的实现一次 reload 直接读到 90。

框架里这条规矩踩过三次：T5-02 服务发现判活、T6-02 配置表、`pkg/script` 的 `Script::changed()`。

### 3. 轮询代码留着但默认关，别让它"顺手开着"

`reloadIfChanged()` 这种方法写出来就会有人调。要么第一行判断开关，
要么在文档里写死"这是开发期功能"。

## 验收

改一个值 → **等 3 秒不做任何操作** → 确认服务毫无反应（日志无新记录）→
手动触发 → 确认立即生效且日志带 hash。

这一步能同时验证"没有隐式变更"和"手动链路通"。
