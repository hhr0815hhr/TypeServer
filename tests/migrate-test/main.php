<?php

/**
 * pkg/db 的 Migrator 测试。
 *
 * 迁移工具最容易出的三个错：
 *   1. 重复执行（幂等没做对，第二次把表建了两遍）
 *   2. 已执行的迁移被改了却没人发现（新环境结构对不上，几个月后才发现）
 *   3. 按 `;` 硬切语句，把 `INSERT ... VALUES ('a;b')` 切成两半
 */

use TSF\Db\Db;
use TSF\Db\Migrator;
use TSF\Db\Shards;

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

function freshDir(string $name): string
{
    $dir = "/tmp/tsf_mig_" . $name;
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            $p = $dir . "/" . $f;
            if (is_file($p)) {
                unlink($p);
            }
        }
    } else {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

function put(string $dir, string $file, string $sql): void
{
    file_put_contents($dir . "/" . $file, $sql);
}

function tableExists(Db $db, string $name): bool
{
    $rows = $db->select("SELECT name FROM sqlite_master WHERE type='table' AND name = ?", [$name]);
    return $rows !== [];
}

/** 1. 首次迁移 */
function caseFirstRun(): void
{
    echo "== [1] 首次迁移 ==" . PHP_EOL;

    $dir = freshDir("first");
    put($dir, "001_create_players.sql", "CREATE TABLE players (
        uid INTEGER PRIMARY KEY,
        hp  INTEGER NOT NULL
    );");

    $db = new Db("sqlite::memory:");
    $m = new Migrator($db, $dir);

    T::ok(!tableExists($db, "players"), "跑之前没有表");

    $r = $m->up();
    T::eq($r["shards"], 1, "1 个库");
    T::eq(count($r["executed"]), 1, "执行了 1 个");
    T::eq(count($r["failed"]), 0, "没有失败");
    T::ok(tableExists($db, "players"), "players 表已建");
    T::ok(tableExists($db, "schema_migrations"), "记录表已建");

    // 记录正确
    $rows = $db->select("SELECT version, name FROM schema_migrations");
    T::eq(count($rows), 1, "记录 1 条");
    if ($rows !== []) {
        T::eq((int) $rows[0]["version"], 1, "版本 1");
    }
}

/** 2. 重复执行是幂等的 */
function caseIdempotent(): void
{
    echo "== [2] 重复执行 ==" . PHP_EOL;

    $dir = freshDir("idem");
    put($dir, "001_a.sql", "CREATE TABLE t1 (x INTEGER);");

    $db = new Db("sqlite::memory:");
    $m = new Migrator($db, $dir);
    $m->up();

    // 再跑一遍：不报错、不重复执行
    $r2 = $m->up();
    T::eq(count($r2["executed"]), 0, "第二次没有执行任何迁移");
    T::eq($r2["skipped"], 1, "跳过了 1 个");
    T::eq(count($r2["failed"]), 0, "没有失败");

    // 记录表里还是一条
    T::eq((int) $db->value("SELECT COUNT(*) FROM schema_migrations"), 1, "记录仍然 1 条");
}

/** 3. 新增迁移只执行新的 */
function caseIncremental(): void
{
    echo "== [3] 增量迁移 ==" . PHP_EOL;

    $dir = freshDir("inc");
    put($dir, "001_a.sql", "CREATE TABLE t1 (x INTEGER);");

    $db = new Db("sqlite::memory:");
    $m = new Migrator($db, $dir);
    $m->up();

    // 加两个新的
    put($dir, "002_b.sql", "CREATE TABLE t2 (y INTEGER);");
    put($dir, "003_c.sql", "CREATE TABLE t3 (z INTEGER);");

    $r = $m->up();
    T::eq(count($r["executed"]), 2, "执行了 2 个");
    T::eq($r["skipped"], 1, "跳过已执行的 1 个");
    T::ok(tableExists($db, "t2") && tableExists($db, "t3"), "两张新表都建了");
    T::eq((int) $db->value("SELECT COUNT(*) FROM schema_migrations"), 3, "记录 3 条");

    // 执行顺序按版本号
    $versions = [];
    foreach ($db->select("SELECT version FROM schema_migrations ORDER BY version") as $row) {
        $versions[] = (int) $row["version"];
    }
    T::eq(implode(",", $versions), "1,2,3", "版本有序");
}

/** 4. 已执行的迁移被改动 → verify 必须发现 */
function caseChecksum(): void
{
    echo "== [4] 改动检测 ==" . PHP_EOL;

    $dir = freshDir("sum");
    put($dir, "001_a.sql", "CREATE TABLE t1 (x INTEGER);");

    $db = new Db("sqlite::memory:");
    $m = new Migrator($db, $dir);
    $m->up();

    // 没改动时 verify 是干净的
    T::eq(count($m->verify()), 0, "未改动时无异常");

    // 改了已经执行过的迁移
    put($dir, "001_a.sql", "CREATE TABLE t1 (x INTEGER, y TEXT);");
    $bad = $m->verify();
    T::eq(count($bad), 1, "verify 发现 1 处改动");
    if ($bad !== []) {
        T::ok(str_contains((string) $bad[0]["why"], "改动"), "原因说明清楚", (string) $bad[0]["why"]);
        T::eq((int) $bad[0]["version"], 1, "定位到版本 1");
    }

    // status 也要标出来
    $st = $m->status();
    T::eq(count($st), 1, "status 有 1 条");
    if ($st !== []) {
        T::ok($st[0]["applied"], "已执行");
        T::ok(!$st[0]["checksum_ok"], "checksum 不匹配");
    }
}

/** 5. 方言选择 */
function caseDialect(): void
{
    echo "== [5] 方言选择 ==" . PHP_EOL;

    $dir = freshDir("dialect");
    // MySQL 版和 SQLite 版同名不同内容
    put($dir, "001_t.sqlite.sql", "CREATE TABLE t_sqlite (x INTEGER);");
    put($dir, "001_t.mysql.sql", "CREATE TABLE t_mysql (x INT) ENGINE=InnoDB;");
    // 只有通用的
    put($dir, "002_common.sql", "CREATE TABLE t_common (c INTEGER);");

    $db = new Db("sqlite::memory:");
    $m = new Migrator($db, $dir);
    $m->up();

    T::ok(tableExists($db, "t_sqlite"), "SQLite 版被选中");
    T::ok(!tableExists($db, "t_mysql"), "MySQL 版没被执行");
    T::ok(tableExists($db, "t_common"), "通用版也执行");

    // 一个只有 pgsql 实现的迁移，在 sqlite 上应当跳过而不是报错
    $dir2 = freshDir("dialect2");
    put($dir2, "001_only_pg.pgsql.sql", "CREATE TABLE only_pg (x INT);");
    $db2 = new Db("sqlite::memory:");
    $m2 = new Migrator($db2, $dir2);
    $r = $m2->up();
    T::eq(count($r["failed"]), 0, "无对应方言时不报错");
    T::eq(count($r["executed"]), 0, "也没有执行");
}

/** 6. 多语句与引号内的分号 */
function caseSplit(): void
{
    echo "== [6] 语句切分 ==" . PHP_EOL;

    $dir = freshDir("split");
    put($dir, "001_multi.sql", "-- 建表并塞数据
CREATE TABLE t1 (a TEXT);
INSERT INTO t1 VALUES ('x;y');
INSERT INTO t1 VALUES (\"p;q\");
CREATE TABLE t2 (b INTEGER);
");

    $db = new Db("sqlite::memory:");
    $m = new Migrator($db, $dir);
    $r = $m->up();
    T::eq(count($r["failed"]), 0, "没有失败");
    T::ok(tableExists($db, "t1") && tableExists($db, "t2"), "两张表都建了");

    // 引号里的分号没有被切开
    T::eq((int) $db->value("SELECT COUNT(*) FROM t1"), 2, "插入了 2 行");
    $v = $db->value("SELECT a FROM t1 ORDER BY a LIMIT 1", [], 0);
    T::eq((string) $v, "p;q", "双引号里的分号保留");
}

/** 7. 分片：每个分片各自迁移、各自记录 */
function caseSharded(): void
{
    echo "== [7] 分库迁移 ==" . PHP_EOL;

    $dir = freshDir("shard");
    for ($i = 0; $i < 3; $i++) {
        $f = "/tmp/tsf_mig_shard_" . $i . ".db";
        if (file_exists($f)) {
            unlink($f);
        }
    }
    put($dir, "001_init.sql", "CREATE TABLE p (uid INTEGER PRIMARY KEY, tag TEXT);");

    $shards = new Shards("sqlite:/tmp/tsf_mig_shard_{i}.db", 3);
    $m = new Migrator($shards, $dir);

    $r = $m->up();
    T::eq($r["shards"], 3, "3 个分片");
    T::eq(count($r["executed"]), 3, "每个分片各执行 1 次（共 3 次）");

    // 每个分片都有自己的表和记录
    foreach ($shards->every() as $idx => $db) {
        if (!$db instanceof Db) {
            continue;
        }
        T::ok(tableExists($db, "p"), "分片 " . $idx . " 有 p 表");
        T::eq((int) $db->value("SELECT COUNT(*) FROM schema_migrations"), 1, "分片 " . $idx . " 记录了 1 条");
    }

    // 再跑一次：全部跳过
    $r2 = $m->up();
    T::eq(count($r2["executed"]), 0, "第二次全部跳过");
    T::eq($r2["skipped"], 3, "跳过 3 次");

    // statusAll 能看到每片的进度
    $all = $m->statusAll();
    T::eq(count($all), 3, "statusAll 3 片");
    if ($all !== []) {
        T::eq(count($all[0]["rows"]), 1, "每片 1 条状态");
    }

    $shards->closeAll();
    for ($i = 0; $i < 3; $i++) {
        $f2 = "/tmp/tsf_mig_shard_" . $i . ".db";
        if (file_exists($f2)) {
            unlink($f2);
        }
    }
}

/** 8. 只跑到指定版本 */
function caseToVersion(): void
{
    echo "== [8] 指定目标版本 ==" . PHP_EOL;

    $dir = freshDir("tover");
    put($dir, "001_a.sql", "CREATE TABLE a (x INTEGER);");
    put($dir, "002_b.sql", "CREATE TABLE b (x INTEGER);");
    put($dir, "003_c.sql", "CREATE TABLE c (x INTEGER);");

    $db = new Db("sqlite::memory:");
    $m = new Migrator($db, $dir);

    $r = $m->up(2);
    T::eq(count($r["executed"]), 2, "只执行到版本 2");
    T::ok(tableExists($db, "a") && tableExists($db, "b"), "a/b 建了");
    T::ok(!tableExists($db, "c"), "c 还没建");

    // 再跑到 3
    $r2 = $m->up();
    T::eq(count($r2["executed"]), 1, "补上版本 3");
    T::ok(tableExists($db, "c"), "c 建了");
}

function main(): void
{
    caseFirstRun();
    caseIdempotent();
    caseIncremental();
    caseChecksum();
    caseDialect();
    caseSplit();
    caseSharded();
    caseToVersion();

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
}
