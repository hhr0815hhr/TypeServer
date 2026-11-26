<?php

/**
 * pkg/db 在真实 PostgreSQL 上的验证。
 *
 * 和 MySQL 那份的差异点（探测阶段已经确认）：
 *   1. lastInsertId() 在 pg 上**可用**（返回 '1'），不需要 RETURNING
 *   2. pg 的 DDL **可以**在事务里回滚（MySQL 会隐式提交）
 *   3. pg 的类型检查是严格的（超长字符串抛 22001）
 *
 * 表名统一 pg_t_ 前缀，测完 DROP。
 */

use TSF\Db\Col;
use TSF\Db\Db;
use TSF\Db\Id;
use TSF\Db\Migrator;
use TSF\Db\Repo;
use TSF\Db\Table;

#[Table("pg_t_player")]
final class PgPlayer
{
    #[Id(true)]
    public int $id = 0;

    #[Col]
    public string $name = "";

    #[Col]
    public int $coins = 0;
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

    public static function eq(string|int $got, string|int $want, string $label): void
    {
        self::ok($got === $want, $label, "got=" . var_export($got, true) . " want=" . var_export($want, true));
    }
}

// 只连本机的容器（bash scripts/dev-pg.sh）。
// 这里曾经硬编码过一个公网地址的库和账号密码——提交进了 git 历史。
// 测试不该依赖"某个人的数据库"：它会让套件在别人机器上必红（或者更糟，连上一个真库），
// 也让凭据进仓库。要测 PG 方言，一个空的本地容器就够。
const DSN = "pgsql:host=127.0.0.1;port=5432;dbname=tsf";
const USER = "tsf";
const PASS = 'CHANGE_ME';

/**
 * 没有可用的 PostgreSQL 就 SKIP（退出码 77）。
 * 用端口探测而不是 try/catch：AOT 下 `catch (\Throwable)` 接不住 PDO 构造抛的异常（同 mail-test）。
 */
function requirePg(string $host, int $port, float $timeout = 0.3): void
{
    $errno = 0;
    $errstr = "";
    $s = @stream_socket_client("tcp://" . $host . ":" . $port, $errno, $errstr, $timeout);
    if ($s === false) {
        echo "SKIP 需要可用的 PostgreSQL（" . $host . ":" . $port . "）" . PHP_EOL;
        echo "     起一个：bash scripts/dev-pg.sh" . PHP_EOL;
        exit(77);
    }
    if (is_resource($s)) {
        fclose($s);
    }
}

function main(): void
{
    requirePg("127.0.0.1", 5432);
    $db = new Db(DSN, USER, PASS);

    echo "== [1] 连接 ==" . PHP_EOL;
    T::eq($db->driver(), "pgsql", "driver() = pgsql");
    $v = (string) $db->value("SELECT version()", [], "");
    T::ok(strpos($v, "PostgreSQL") !== false, "版本 " . substr($v, 0, 32));

    $db->exec("DROP TABLE IF EXISTS pg_t_player");
    $db->exec("DROP TABLE IF EXISTS schema_migrations");

    echo PHP_EOL . "== [2] Migrator（pgsql 方言）==" . PHP_EOL;
    $mig = new Migrator($db, __DIR__ . "/migrations");
    $res = $mig->up();
    T::eq((string) count($res["executed"] ?? []), "1", "执行了 1 条迁移");
    T::eq((string) count($res["failed"] ?? []), "0", "没有失败");
    T::eq((string) ($res["skipped"] ?? -1), "0", "跳过 0 条");
    if (count($res["failed"] ?? []) > 0) {
        T::ok(false, "失败原因", $mig->lastError);
    }

    $has = $db->value(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'pg_t_player'",
        [],
        0
    );
    T::eq((string) (int) $has, "1", "表 pg_t_player 已建");

    $mig->up();
    $pending = 0;
    foreach ($mig->status() as $row) {
        if (!(bool) ($row["applied"] ?? false)) {
            $pending++;
        }
    }
    T::eq((string) $pending, "0", "重复 up() 后没有待执行迁移");

    echo PHP_EOL . "== [3] Repo CRUD ==" . PHP_EOL;
    $repo = new Repo(PgPlayer::class, $db);

    $p = new PgPlayer();
    $p->name = "甲";
    $p->coins = 100;
    $id = $repo->insert($p);
    T::ok($id > 0, "insert 返回自增 id", "id=" . $id);
    T::eq((string) $p->id, (string) $id, "自增 id 回填到实体");

    $got = $repo->find($id);
    $name = "";
    $coins = -1;
    if ($got instanceof PgPlayer) {
        $name = $got->name;
        $coins = $got->coins;
    }
    T::eq($name, "甲", "find 读回中文");
    T::eq((string) $coins, "100", "find 读回 coins");

    if ($got instanceof PgPlayer) {
        $got->coins = 250;
        $repo->update($got);
        $again = $repo->find($id);
        $c2 = -1;
        if ($again instanceof PgPlayer) {
            $c2 = $again->coins;
        }
        T::eq((string) $c2, "250", "update 生效");
    }

    // 批量插入
    $plain = [];
    $i = 0;
    while ($i < 3) {
        $plain[] = ["name" => "批量" . $i, "coins" => $i * 10];
        $i++;
    }
    $db->insertMany("pg_t_player", $plain);
    $all = $repo->findAll("", [], "id ASC", 0);
    T::ok(count($all) >= 4, "findAll 拿到 4 条以上", "count=" . count($all));

    $repo->delete($id);
    T::ok($repo->find($id) === null, "delete 后 find 返回 null");

    echo PHP_EOL . "== [4] 事务 ==" . PHP_EOL;
    $db->transaction(function (Db $d): void {
        $d->exec("INSERT INTO pg_t_player (name, coins) VALUES (?, ?)", ["事务内", 999]);
    });
    $cnt = $db->value("SELECT COUNT(*) FROM pg_t_player WHERE name = ?", ["事务内"], 0);
    T::eq((string) (int) $cnt, "1", "事务提交后可见");

    try {
        $db->transaction(function (Db $d): void {
            $d->exec("INSERT INTO pg_t_player (name, coins) VALUES (?, ?)", ["要回滚", 1]);
            throw new \RuntimeException("故意抛错");
        });
        T::ok(false, "事务内的异常应该冒出来");
    } catch (\Throwable $e) {
        $cnt2 = $db->value("SELECT COUNT(*) FROM pg_t_player WHERE name = ?", ["要回滚"], 0);
        T::eq((string) (int) $cnt2, "0", "抛异常后自动回滚");
    }

    echo PHP_EOL . "== [5] 严格性 ==" . PHP_EOL;
    $threw = false;
    try {
        $db->exec("INSERT INTO pg_t_player (name) VALUES (?)", [str_repeat("超", 100)]);
    } catch (\Throwable $e) {
        $threw = true;
    }
    T::ok($threw, "超长字符串抛异常（VARCHAR(32) 塞 100 字符）");

    echo PHP_EOL . "== [6] 清理 ==" . PHP_EOL;
    $db->exec("DROP TABLE IF EXISTS pg_t_player");
    $db->exec("DROP TABLE IF EXISTS schema_migrations");
    $left = $db->value(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name LIKE 'pg_t_%'",
        [],
        0
    );
    T::eq((string) (int) $left, "0", "测试表已清理");
    $db->close();

    echo PHP_EOL . "通过 " . T::$pass . " / 失败 " . T::$fail . PHP_EOL;
    if (T::$fail > 0) {
        exit(1);
    }
}
