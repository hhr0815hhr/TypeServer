# 04 · 数据层：仓储、迁移、事件日志、快照

> 本页代码块标 `片段` 的只讲结构；完整可运行的示例在 [07 篇](07-walkthrough.md)。

## 它解决什么问题

存档、结算、审计——数据层的三个需求对应三个包，而且**每一个都有替代方案的实测否决记录**：

| 需求 | 包 | 被否决的方案 |
|:--|:--|:--|
| 存玩家/对局状态 | `pkg/db` | 引入 `doctrine/dbal`——全量 AOT 死在 `readonly` 属性反射 |
| 记录发生了什么（审计、对账） | `pkg/eventlog` | 直接写日志文件——多进程下序号冲突 |
| 快速恢复状态 | `pkg/snapshot` | 每次全量重建——慢，且浪费 IO |

## 核心概念

### 仓储（Repo）不是 ORM

```php
#[Table("players")]
final class Player
{
    #[Id]
    public int $uid = 0;

    #[Col]
    public int $hp = 100;

    #[Col("nick")]              // 列名与属性名不同时显式指定
    public string $name = "anon";
}
```

**属性只反射一次**（`Meta` 缓存），之后 `find/insert` 都走缓存好的列映射，不再走反射。
这是这套映射方式能用在热路径上的前提。

元数据用属性声明，`Meta` **只反射一次**，之后走缓存。API 极窄：

```php
// 片段
$repo = new Repo(Player::class, new Db("sqlite:/var/app.db"));
$p = $repo->find(1001);                    // 主键查
$list = $repo->findAll("level > ?", [50]);  // 条件查
$id = $repo->insert($p);
$n = $repo->update($p);
```

写 SQL 用占位符，不拼字符串。**查询构造只到 `$where` + 占位符**——它不做完整 ORM 那套
（关联、懒加载、级联）。这是刻意的：AOT 下反射元数据是编译期的事，运行时再解析对象关系
没有收益，只有开销。

### 数据库连接：每 worker 一条，不是连接池

`Db` 持有的是**一条懒建的 PDO 连接**。这是改过口径的决定：

> 原计划是连接池（T4-01），实测后改判：服务调度是**串行**的，同一时刻没有并发查询，
> 池是多余的一层。协程化之后才需要它。

验收数据：1,000 次连续查询 **1,000/1,000 零重试**，主键读 **~4 µs**
（复用预处理句柄；不复用则 12.8 µs）。

**换句话**：如果你的业务里出现了真正的并发查询（多协程同时查库），这个设计会成为瓶颈——
但那要求你先改调度模型。

### 分片：调用代码与不分片完全一样

```php
// 片段：不分片
$repo = new Repo(Player::class, new Db("sqlite:/var/app.db"));

// 分片：只换第二个参数
$repo = new Repo(Player::class, new Shards("sqlite:/var/app_%d.db", 4));
```

`Shards` 按 key 取模选库（`forKey($key)` / `shardOf($key)`）。**调用方代码零改动**——
这是 `Source` 抽象的全部意义。`$repo->isSharded()` 可以在需要时判断。

### 迁移是部署步骤，不是启动步骤

```bash
tsf migrate .        # 部署时跑一次就退出
tsf migrate-status . # 只读；有未执行则返回非零
```

服务启动时**只做 `verify()` + 检查有无未执行，不建表**。

**`main.php` 必须自己处理 `migrate` 参数**。`tsf migrate .` 的实现是
"编译工程 → 运行 `./<binary> migrate`"，所以产物里要有一段分支：

```php
// 片段
$cli = isset($_SERVER["argv"][1]) ? (string) $_SERVER["argv"][1] : "";
if ($cli === "migrate" || $cli === "migrate-status") {
    $migrator = new Migrator($db, $base . "/migrations");
    if ($cli === "migrate") {
        $migrator->up();
        exit(0);
    }
    $pending = $migrator->status();
    exit($pending === [] ? 0 : 1);
}
```

漏了这段，`tsf migrate` 会把 `migrate` 当成普通参数忽略、直接起服务——部署脚本
挂在一个永远不返回的迁移上，最后报的是 `Swoole\Server->__construct()` 异常，
和"迁移"毫无关系。（`demo/duel/main.php` 里也有同样的分支，可以对照。）

原因很具体：原来每个 worker 启动时各调一次 `up()`，记录表能挡住重复执行，
**但挡不住并发建表**——多个 worker 同时 `CREATE TABLE` 会互相冲突。改成部署步骤，
这个竞态从根上消失。

### 事件日志：单写者

`EventLog` 是 append-only，每条带自增 `seq`。**一个文件只允许一个写入进程**：

> 两个进程写同一份日志，`seq` 必然冲突。实测：120 条记录只有 61 个不同序号。

所以多进程时**按节点分文件**（`var/settle.n<节点号>.log`），读侧用 `MergedLog` 归并。

```php
// 片段
$log = new EventLog("/var/settle.n1.log");
$seq = $log->append("order-1001", "settle", ["gold" => 500]);
$log->commit();
// 恢复/对账
foreach ($log->replay(1, 0) as $record) { /* ... */ }
```

`repair()` / `truncate($uptoSeq)` 用于崩溃后的修复与裁剪。

### 快照：按块组织

`Snapshot` 不是"序列化整个对象"，而是**一组命名块**：

```php
// 片段
$snap = new Snapshot("room", $roomId);
$snap->put("meta", ["state" => 2, "round" => 5]);
$snap->put("members", [/* ... */]);
// 只更新变化的块，其余块不动
```

`blocks()` 列出已有块，`ageMs()` 是年龄（用于判断是否过期）。`Store` 决定存哪：
`MemoryStore`（进程内）或 `FileStore`（磁盘）。

## 公开 API

### Repo

| 方法 | 用途 |
|:--|:--|
| `new Repo(string $cls, Source $src)` | 类名 + 数据源（`Db` 或 `Shards`） |
| `find(int $id): ?object` | 主键查 |
| `findAll(string $where = "", array $args = [], string $orderBy = "", int $limit = 0): array` | 条件查 |
| `count(string $where = "", array $args = []): int` | 计数 |
| `insert(object $e): int` / `update(object $e): int` / `delete(int $id): int` | 写 |
| `isSharded(): bool` / `meta(): Meta` / `source(): Source` | 元信息 |

### Db / Shards

| 方法 | 用途 |
|:--|:--|
| `new Db(string $dsn, string $user = "", string $pass = "")` | 单库 |
| `new Shards(string $dsnTemplate, int $count, ...)` | 分片，模板里的 `%d` 被替换 |
| `$db->conn(): \PDO` | 拿底层连接 |
| `$shards->forKey($key): Db` / `shardOf($key): int` | 选库 |

### Migrator

| 方法 | 用途 |
|:--|:--|
| `up(int $to = 0): array` | 执行到指定版本（0 = 全部） |
| `status(): array` / `statusAll(): array` | 待执行列表 |
| `verify(): array` | 校验已执行文件的 checksum 是否被改过 |
| `stats(): array` | 统计 |

### EventLog

| 方法 | 用途 |
|:--|:--|
| `new EventLog(string $path, int $mode = MODE_FLUSH)` | 打开（一个文件一个写者） |
| `append(string $id, string $kind, array $data): int` | 追加，返回 seq |
| `commit(): bool` | 落盘 |
| `replay(int $fromSeq = 1, int $limit = 0): array` | 重放 |
| `repair(): int` / `truncate(int $uptoSeq): int` | 修复 / 裁剪 |

### Snapshot

| 方法 | 用途 |
|:--|:--|
| `new Snapshot(string $kind, int $key, int $version = Codec::VERSION)` | 命名 + 版本 |
| `put(string $name, array $data)` / `get(string $name): array` / `has($name)` | 按块读写 |
| `blocks(): array` / `ageMs(): int` | 块列表 / 年龄 |

## 边界与坑

1. **`Repo` 不做关联查询**。没有 join、没有懒加载。需要联表就自己写 SQL 走 `conn()`，
   或者把常用视图做成表。

2. **迁移不是启动时自动跑的**。忘跑 `tsf migrate` 的表现为"服务能起但一查就炸"——
   因为启动只 `verify()` 不建表（刻意如此，见上文）。

3. **`EventLog` 一个文件只能一个写者**。多进程必须分文件 + `MergedLog` 归并。
   这不是建议，是硬约束：并发写必然产生重复 seq。

4. **`Db` 的一条连接对并发查询不安全**。串行调度下没问题；一旦引入多协程并发查库，
   要么加连接池、要么每协程一条。

5. **快照的 `version` 参与编解码**。改了 `Codec::VERSION` 之后旧快照可能读不出来——
   升级时要么保留旧版本兼容、要么走一次"读旧-写新"的迁移。

6. **`verify()` 只查 checksum，不查语义**。改了一个已执行的迁移文件会被它发现，
   但改错 SQL 本身它管不了。

## 什么时候不该用它

- **只存几个整数**（计数、开关）：直接写文件或用 `pkg/snapshot` 的 `MemoryStore`，
  不必上数据库。
- **纯内存游戏、关服即清**：不需要 `db`，也不需要迁移。
- **审计量很小**（每天几十条）：直接写文本日志 + 按天切分，`EventLog` 的 seq 机制
  是为"崩溃恢复与对账"设计的，用不上时是负担。
- **不需要回滚/重放的状态**：`Snapshot` 的价值在于"快速恢复到某一刻"，没有这个需求
  就存数据库即可。
