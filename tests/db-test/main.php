<?php

/**
 * pkg/db 测试。用 SQLite 内存库，不依赖任何外部服务。
 *
 * 测的重点不是「SQL 能不能跑」，而是三件容易出错的事：
 *   1. Attribute 解析（含错误配置要早失败）
 *   2. 类型在「库里的字符串」和「实体的类型声明」之间怎么走
 *   3. 批量写的分批边界与事务语义
 */

use TSF\Db\Col;
use TSF\Db\Db;
use TSF\Db\Id;
use TSF\Db\Repo;
use TSF\Db\Table;

#[Table("players")]
final class Player
{
    #[Id]
    public int $uid = 0;

    #[Col]
    public int $hp = 100;

    #[Col("nick")]
    public string $name = "anon";

    #[Col]
    public bool $vip = false;

    #[Col]
    public float $rate = 1.0;

    /** 不带 #[Col]：不落库，随便用 */
    public string $cache = "";
}

#[Table("logs")]
final class LogRow
{
    #[Id(true)]
    public int $id = 0;

    #[Col]
    public string $body = "";
}

/** 缺 #[Table] */
final class Bad1
{
    #[Id]
    public int $uid = 0;

    #[Col]
    public int $x = 0;
}

/** 缺 #[Id] */
#[Table("bad2")]
final class Bad2
{
    #[Col]
    public int $x = 0;
}

/** 多个 #[Id] */
#[Table("bad3")]
final class Bad3
{
    #[Id]
    public int $a = 0;

    #[Id]
    public int $b = 0;

    #[Col]
    public int $x = 0;
}

final class T
{
    public static int $pass = 0;

    public static int $fail = 0;

    public static function ok(bool $cond, string $label, string $detail = ""): void
    {
        if ($cond) {
            self::$pass++;
            echo "  OK   " . $label . PHP_EOL;
        } else {
            self::$fail++;
            echo "  FAIL " . $label . ($detail === "" ? "" : "  → " . $detail) . PHP_EOL;
        }
    }

    public static function eq(int|string|float|bool $got, int|string|float|bool $want, string $label): void
    {
        self::ok($got === $want, $label, "got=" . var_export($got, true) . " want=" . var_export($want, true));
    }
}

function freshDb(): Db
{
    $db = new Db("sqlite::memory:");
    $db->exec("CREATE TABLE players (
        uid   INTEGER PRIMARY KEY,
        hp    INTEGER NOT NULL,
        nick  TEXT    NOT NULL,
        vip   INTEGER NOT NULL,
        rate  REAL    NOT NULL
    )");
    $db->exec("CREATE TABLE logs (
        id   INTEGER PRIMARY KEY AUTOINCREMENT,
        body TEXT NOT NULL
    )");
    return $db;
}

/** 1. 元数据解析 */
function caseMeta(): void
{
    echo "== [1] 元数据解析 ==" . PHP_EOL;

    $m = \TSF\Db\Meta::parse(Player::class);
    T::eq($m->table, "players", "表名");
    T::eq($m->idProp, "uid", "主键属性名");
    T::eq($m->idCol, "uid", "主键列名");
    T::eq($m->idAuto, false, "主键非自增");
    T::eq(count($m->cols), 4, "普通列 4 个（hp/name/vip/rate）");
    T::eq(count($m->colOf), 5, "映射总数 5（含主键）");
    T::ok(!isset($m->cols["cache"]), "cache 未被映射");

    $m2 = \TSF\Db\Meta::parse(LogRow::class);
    T::eq($m2->idAuto, true, "#[Id(true)] 解析为自增");

    // 列名映射
    $col = $m->cols["name"];
    T::eq((string) $col, "nick", "#[Col(\"nick\")] 改了列名");
}

/** 2. 错误配置必须早失败 */
function caseBadMeta(): void
{
    echo "== [2] 错误配置 ==" . PHP_EOL;

    $msg = "";
    try {
        \TSF\Db\Meta::parse(Bad1::class);
    } catch (\RuntimeException $e) {
        $msg = $e->getMessage();
    }
    T::ok(str_contains($msg, "Table"), "缺 #[Table] 报错", $msg);

    $msg2 = "";
    try {
        \TSF\Db\Meta::parse(Bad2::class);
    } catch (\RuntimeException $e) {
        $msg2 = $e->getMessage();
    }
    T::ok(str_contains($msg2, "Id"), "缺 #[Id] 报错", $msg2);

    $msg3 = "";
    try {
        \TSF\Db\Meta::parse(Bad3::class);
    } catch (\RuntimeException $e) {
        $msg3 = $e->getMessage();
    }
    T::ok(str_contains($msg3, "多个"), "多个 #[Id] 报错", $msg3);
}

/** 3. 基本 CRUD */
function caseCrud(): void
{
    echo "== [3] CRUD ==" . PHP_EOL;

    $db = freshDb();
    $repo = new Repo(Player::class, $db);

    $p = new Player();
    $p->uid = 1001;
    $p->hp = 88;
    $p->name = "alice";
    $p->vip = true;
    $p->rate = 0.75;
    $p->cache = "不落库";

    $repo->insert($p);
    T::ok(true, "insert 未抛异常");

    $got = $repo->find(1001);
    T::ok($got !== null, "find 拿到对象");
    if ($got !== null) {
        T::eq($got->uid, 1001, "主键回读");
        T::eq($got->hp, 88, "int 列回读");
        T::eq($got->name, "alice", "string 列回读");
        T::eq($got->vip, true, "bool 列回读（库里是 1）");
        T::eq($got->rate, 0.75, "float 列回读");
        T::eq($got->cache, "", "非映射字段保持默认值（构造函数没跑）");
    }

    // SQLite 里存的是整数 1，实体上应该是 bool true
    $raw = $db->first("SELECT vip FROM players WHERE uid = ?", [1001]);
    T::eq((int) $raw["vip"], 1, "库里 bool 存成 1");

    // update
    $got2 = $repo->find(1001);
    if ($got2 !== null) {
        $got2->hp = 30;
        $n = $repo->update($got2);
        T::eq($n, 1, "update 影响 1 行");
    }
    $got3 = $repo->find(1001);
    if ($got3 !== null) {
        T::eq($got3->hp, 30, "update 生效");
        T::eq($got3->name, "alice", "未改的列没被动");
    }

    // find 不存在
    T::ok($repo->find(9999) === null, "find 不存在的返回 null");

    // delete
    T::eq($repo->delete(1001), 1, "delete 影响 1 行");
    T::ok($repo->find(1001) === null, "delete 之后查不到");
}

/** 4. save 的插入/更新分流 */
function caseSave(): void
{
    echo "== [4] save ==" . PHP_EOL;

    $db = freshDb();
    $repo = new Repo(Player::class, $db);

    $p = new Player();
    $p->uid = 2001;
    $p->hp = 10;
    $p->name = "bob";
    T::ok($repo->save($p) === true, "首次 save 走 INSERT");
    T::eq($repo->count(), 1, "库里有 1 行");

    $p->hp = 20;
    T::ok($repo->save($p) === false, "再次 save 走 UPDATE");
    T::eq($repo->count(), 1, "仍然只有 1 行");

    $again = $repo->find(2001);
    if ($again !== null) {
        T::eq($again->hp, 20, "值已更新");
    }
}

/** 5. 自增主键回填 */
function caseAutoId(): void
{
    echo "== [5] 自增主键 ==" . PHP_EOL;

    $db = freshDb();
    $repo = new Repo(LogRow::class, $db);

    $a = new LogRow();
    $a->body = "first";
    $id1 = $repo->insert($a);
    T::eq($id1, 1, "insert 返回自增 id");
    T::eq($a->id, 1, "自增 id 回填到实体");

    $b = new LogRow();
    $b->body = "second";
    $repo->insert($b);
    T::eq($b->id, 2, "第二行 id=2");

    $got = $repo->find(2);
    T::ok($got !== null, "按自增 id 能查回来");
    if ($got !== null) {
        T::eq($got->body, "second", "内容正确");
    }
}

/** 6. 事务 */
function caseTx(): void
{
    echo "== [6] 事务 ==" . PHP_EOL;

    $db = freshDb();
    $repo = new Repo(Player::class, $db);

    // 正常提交
    $db->transaction(function (Db $d) use ($repo): void {
        $p = new Player();
        $p->uid = 3001;
        $p->hp = 1;
        $p->name = "tx-ok";
        $repo->insert($p);
    });
    T::eq($repo->count(), 1, "事务提交后可见");

    // 异常回滚
    $threw = false;
    try {
        $db->transaction(function (Db $d) use ($repo): void {
            $p = new Player();
            $p->uid = 3002;
            $p->hp = 2;
            $p->name = "tx-rollback";
            $repo->insert($p);
            throw new \RuntimeException("故意失败");
        });
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    T::ok($threw, "异常向上抛出");
    T::eq($repo->count(), 1, "回滚后仍是 1 行");
    T::ok($repo->find(3002) === null, "回滚的数据查不到");

    // 嵌套：两层只在最外层提交
    $db->transaction(function (Db $d) use ($repo): void {
        $a = new Player();
        $a->uid = 3003;
        $a->hp = 3;
        $a->name = "outer";
        $repo->insert($a);

        $d->transaction(function (Db $d2) use ($repo): void {
            $b = new Player();
            $b->uid = 3004;
            $b->hp = 4;
            $b->name = "inner";
            $repo->insert($b);
        });
    });
    T::eq($repo->count(), 3, "嵌套事务提交后 3 行");
    T::eq($db->stats()["tx_depth"], 0, "事务深度归零");

    // 嵌套的陷阱：内层失败、被外层业务**吞掉**之后继续执行。
    //
    // 嵌套用的是"深度"而不是 SAVEPOINT（业务里 A 调 B 语义上就该是一个事务），
    // 那么内层失败时整个事务已经不可能有正确结果了——但异常被吞掉后，
    // 外层并不知道，会照常 commit，把"内层之前成功的那半截"一起提交。
    //
    // 正确行为：内层失败等同于整个事务失败，外层 commit 必须变成 rollback。
    $before = $repo->count();
    try {
        $db->transaction(function (Db $d) use ($repo): void {
            $a = new Player();
            $a->uid = 3005;
            $a->hp = 5;
            $a->name = "outer-before-inner-fail";
            $repo->insert($a);

            try {
                $d->transaction(function (Db $d2) use ($repo): void {
                    $b = new Player();
                    $b->uid = 3006;
                    $b->hp = 6;
                    $b->name = "inner-fail";
                    $repo->insert($b);
                    throw new \RuntimeException("内层失败");
                });
            } catch (\RuntimeException $inner) {
                // 业务把内层的失败吞掉了，继续往下走——这一步很常见
            }
        });
    } catch (\RuntimeException $outer) {
        // 外层也可能因此报错，两种都接受
    }
    T::eq($repo->count(), $before, "内层失败被吞掉后，外层不得提交（半截数据会污染库）");
    T::ok($repo->find(3005) === null, "外层自己插的那条也不该留下");
    T::eq($db->stats()["tx_depth"], 0, "出错后事务深度仍能归零");
}

/** 7. 批量插入与分批边界 */
function caseBatch(): void
{
    echo "== [7] 批量写 ==" . PHP_EOL;

    $db = freshDb();
    $rows = [];
    for ($i = 1; $i <= 500; $i++) {
        $rows[] = [
            "uid" => $i,
            "hp" => $i % 100,
            "nick" => "p" . $i,
            "vip" => $i % 2,
            "rate" => 1.5,
        ];
    }

    // 每行 5 个参数，最多 900 参数 → 每批 180 行，500 行要 3 批
    $n = $db->insertMany("players", $rows, 900);
    T::eq($n, 500, "insertMany 影响 500 行");

    $cnt = (int) $db->value("SELECT COUNT(*) FROM players");
    T::eq($cnt, 500, "库里确实 500 行");

    // 分批确实发生了：用极小的上限逼出多次执行
    $db2 = freshDb();
    $small = [];
    for ($i = 1; $i <= 10; $i++) {
        $small[] = ["uid" => $i, "hp" => 1, "nick" => "s" . $i, "vip" => 0, "rate" => 1.0];
    }
    $db2->insertMany("players", $small, 15);   // 每批 3 行 → 4 批
    T::eq((int) $db2->value("SELECT COUNT(*) FROM players"), 10, "小批上限下仍然全插入");

    // 空数组不报错
    T::eq($db->insertMany("players", []), 0, "空数组返回 0");

    // saveMany
    $repo = new Repo(Player::class, $db);
    $list = $repo->findAll("", [], "uid ASC", 10);
    T::eq(count($list), 10, "findAll limit 生效");
    $inserted = $repo->saveMany($list);
    T::eq($inserted, 0, "已存在的全部走 UPDATE，没有新插入");
}

/** 8. 条件查询 */
function caseQuery(): void
{
    echo "== [8] 条件查询 ==" . PHP_EOL;

    $db = freshDb();
    $repo = new Repo(Player::class, $db);
    for ($i = 1; $i <= 20; $i++) {
        $p = new Player();
        $p->uid = $i;
        $p->hp = $i * 5;
        $p->name = "n" . $i;
        $p->vip = $i > 15;
        $repo->insert($p);
    }

    T::eq($repo->count(), 20, "count 全部");
    T::eq($repo->count("hp > ?", [50]), 10, "count 带条件");
    T::eq(count($repo->findAll("hp > ?", [50])), 10, "findAll 带条件");
    T::eq(count($repo->findAll("vip = ?", [1])), 5, "bool 条件查询");
    T::eq(count($repo->findAll("", [], "uid DESC", 3)), 3, "排序 + limit");

    $top = $repo->findAll("", [], "hp DESC", 1);
    if ($top !== []) {
        $one = $top[0];
        T::eq($one->uid, 20, "排第一的是 hp 最大的");
    }
}

/** 9. 断开重连：句柄缓存必须跟着换，不能拿旧连接的语句继续用 */
function caseReconnect(): void
{
    echo "== [9] 断开重连 ==" . PHP_EOL;

    // 必须用文件库：内存库一断开数据就没了，测不出"重连后还读得到"
    $path = "/tmp/tsf_db_reconnect.db";
    if (file_exists($path)) {
        unlink($path);
    }

    $db = new Db("sqlite:" . $path);
    $db->exec("CREATE TABLE players (
        uid   INTEGER PRIMARY KEY,
        hp    INTEGER NOT NULL,
        nick  TEXT    NOT NULL,
        vip   INTEGER NOT NULL,
        rate  REAL    NOT NULL
    )");
    $repo = new Repo(Player::class, $db);

    $p = new Player();
    $p->uid = 1;
    $p->hp = 77;
    $p->name = "persist";
    $repo->insert($p);

    // 先读一次，让语句进缓存
    $before = $repo->find(1);
    T::ok($before !== null, "首次读取成功");
    T::eq($db->stats()["connects"], 1, "只连过一次");

    // 断开（模拟 wait_timeout 掐连接 / 主动换连接）
    $db->close();

    $after = $repo->find(1);
    T::ok($after !== null, "重连后仍能读到");
    if ($after !== null) {
        T::eq($after->hp, 77, "重连后数据完整");
    }
    T::eq($db->stats()["connects"], 2, "确实重连了一次");

    // 再写一次：INSERT 的句柄也必须是新的
    $p2 = new Player();
    $p2->uid = 2;
    $p2->hp = 5;
    $p2->name = "after-reconnect";
    $repo->insert($p2);
    T::ok($repo->find(2) !== null, "重连后写入正常");

    $db->close();
    unlink($path);
}

/** 10. 分库：路由正确、跨片聚合、不支持的组合要早失败 */
function caseShard(): void
{
    echo "== [10] 分库 ==" . PHP_EOL;

    $n = 4;
    for ($i = 0; $i < $n; $i++) {
        $f = "/tmp/tsf_shard_" . $i . ".db";
        if (file_exists($f)) {
            unlink($f);
        }
    }

    $shards = new \TSF\Db\Shards("sqlite:/tmp/tsf_shard_{i}.db", $n);
    T::eq($shards->count(), $n, "分片数 4");

    // 建表：每片各建一次
    $shards->each(function (Db $db): void {
        $db->exec("CREATE TABLE players (
            uid   INTEGER PRIMARY KEY,
            hp    INTEGER NOT NULL,
            nick  TEXT    NOT NULL,
            vip   INTEGER NOT NULL,
            rate  REAL    NOT NULL
        )");
    });

    $repo = new Repo(Player::class, $shards);
    T::ok($repo->isSharded(), "Repo 识别为分片部署");

    // 单库时 isSharded 必须是 false，否则 findAll 的 limit 会被误禁
    $single = new Repo(Player::class, freshDb());
    T::ok(!$single->isSharded(), "单库 Repo 不是分片");

    // 插 20 个：uid 1..20，按 uid % 4 分布
    for ($i = 1; $i <= 20; $i++) {
        $p = new Player();
        $p->uid = $i;
        $p->hp = $i * 10;
        $p->name = "u" . $i;
        $repo->insert($p);
    }
    T::eq($repo->count(), 20, "跨片总数 20");
    T::eq($repo->count("hp > ?", [100]), 10, "跨片条件计数");

    // 路由：uid 7 → 7 % 4 = 3
    T::eq($shards->shardOf(7), 3, "uid=7 落第 3 片");
    T::eq($shards->shardOf(8), 0, "uid=8 落第 0 片");
    T::eq($shards->shardOf(-1), 3, "负数键归一化到 3");

    // 每片确实只放了自己那份
    $s3 = $shards->forKey(7);
    $cnt3 = (int) $s3->value("SELECT COUNT(*) FROM players");
    T::eq($cnt3, 5, "第 3 片有 5 行（7,11,15,19 加 3）");

    // 按主键读会路由到正确的片
    $p7 = $repo->find(7);
    T::ok($p7 !== null, "find(7) 命中");
    if ($p7 !== null) {
        T::eq($p7->name, "u7", "读到的是 uid=7 自己");
        T::eq($p7->hp, 70, "值正确");
    }
    T::ok($repo->find(999) === null, "find 不存在的返回 null");

    // update / delete 也要走对片
    $p7b = $repo->find(7);
    if ($p7b !== null) {
        $p7b->hp = 1;
        T::eq($repo->update($p7b), 1, "分片 update 影响 1 行");
    }
    $p7c = $repo->find(7);
    if ($p7c !== null) {
        T::eq($p7c->hp, 1, "分片 update 生效");
    }

    // 跨片查询（不带 limit）
    $rich = $repo->findAll("hp > ?", [150]);
    T::eq(count($rich), 5, "跨片 findAll 命中 5 个");

    // 分片下 limit 必须拒绝，否则会拿到"每片各取 N 条"
    $msg = "";
    try {
        $repo->findAll("", [], "", 3);
    } catch (\RuntimeException $e) {
        $msg = $e->getMessage();
    }
    T::ok(str_contains($msg, "ORDER BY"), "分片下 limit 被拒绝", $msg);

    // 自增主键 + 分片：构造时就该失败
    $msg2 = "";
    try {
        new Repo(LogRow::class, $shards);
    } catch (\RuntimeException $e) {
        $msg2 = $e->getMessage();
    }
    T::ok(str_contains($msg2, "自增"), "自增主键 + 分片被拒绝", $msg2);

    // saveMany 分片：按片分组、逐片事务
    $all = $repo->findAll();
    $ins = $repo->saveMany($all);
    T::eq($ins, 0, "saveMany 全部走 UPDATE");
    T::eq($repo->count(), 20, "saveMany 后仍是 20 行");

    $shards->closeAll();
    for ($i = 0; $i < $n; $i++) {
        $f2 = "/tmp/tsf_shard_" . $i . ".db";
        if (file_exists($f2)) {
            unlink($f2);
        }
    }
}

/** 11. 性能：单行读、批量写 */
function casePerf(): void
{
    echo "== [11] 性能 ==" . PHP_EOL;

    $db = freshDb();
    $repo = new Repo(Player::class, $db);

    $n = 2000;
    $rows = [];
    for ($i = 1; $i <= $n; $i++) {
        $rows[] = ["uid" => $i, "hp" => 50, "nick" => "b" . $i, "vip" => 0, "rate" => 1.0];
    }

    $t0 = hrtime(true);
    $db->insertMany("players", $rows);
    $t1 = hrtime(true);
    echo "  批量插入 " . number_format($n) . " 行: " . number_format(($t1 - $t0) / 1e6, 1) . " ms"
        . "（" . number_format(($t1 - $t0) / $n, 0) . " ns/行）" . PHP_EOL;

    // 单行读：含 PDO 往返 + hydrate。1,000 次对齐 T4-01 的验收口径
    // （串行模型下"并发"就是"连续"——见 README 关于连接池的说明）
    $m = 1000;
    $t2 = hrtime(true);
    $hit = 0;
    for ($i = 1; $i <= $m; $i++) {
        $p = $repo->find($i);
        if ($p !== null) {
            $hit++;
        }
    }
    $t3 = hrtime(true);
    echo "  按主键读 " . number_format($m) . " 次: " . number_format(($t3 - $t2) / 1e6, 1) . " ms"
        . "（" . number_format(($t3 - $t2) / $m, 0) . " ns/次）" . PHP_EOL;
    T::eq($hit, $m, "1,000 次全部命中，连接未断");
    T::eq($db->stats()["retries"], 0, "全程零重试");

    // 只算 hydrate 成本：先取一批行，再逐个转换
    $rawRows = $db->select("SELECT * FROM players WHERE uid <= ?", [1000]);
    $t4 = hrtime(true);
    $objs = $repo->findAll("uid <= ?", [1000]);
    $t5 = hrtime(true);
    T::eq(count($objs), 1000, "findAll 1000 行");
    T::ok(count($rawRows) === 1000, "原始行数一致");

    echo "  findAll 1000 行（含 hydrate）: " . number_format(($t5 - $t4) / 1e6, 2) . " ms"
        . "（" . number_format(($t5 - $t4) / 1000, 0) . " ns/行）" . PHP_EOL;

    // 整存 1000 个对象
    $t6 = hrtime(true);
    $ins = $repo->saveMany($objs);
    $t7 = hrtime(true);
    echo "  saveMany 1000 对象（全 UPDATE）: " . number_format(($t7 - $t6) / 1e6, 1) . " ms"
        . "（" . number_format(($t7 - $t6) / 1000, 0) . " ns/条）" . PHP_EOL;
    T::eq($ins, 0, "没有新插入");
}

function main(): void
{
    caseMeta();
    caseBadMeta();
    caseCrud();
    caseSave();
    caseAutoId();
    caseTx();
    caseBatch();
    caseQuery();
    caseReconnect();
    caseShard();
    casePerf();

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
}
