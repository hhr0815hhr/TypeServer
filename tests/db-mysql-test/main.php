<?php

/**
 * pkg/db 在真实 MySQL 上的验证。
 *
 * 为什么必须单独测：框架所有 db 测试都用 `sqlite::memory:`，MySQL 路径
 * （Migrator 的 InnoDB 建表、Repo 的 lastInsertId、严格模式下的类型检查）
 * 一次都没执行过。sqlite 和 MySQL 的差异里，最容易出事的三个：
 *
 *   1. 自增主键语法（AUTOINCREMENT vs AUTO_INCREMENT）
 *   2. 字符串长度（sqlite 的 TEXT 无限长，MySQL 的 VARCHAR 超长会报错）
 *   3. 严格模式（这台的 sql_mode 含 STRICT_TRANS_TABLES，超长/类型不符直接抛）
 *
 * 表名统一带 tsf_t_ 前缀，测完 DROP，不污染库里的其他数据。
 */

use TSF\Db\Col;
use TSF\Db\Db;
use TSF\Db\Id;
use TSF\Db\Migrator;
use TSF\Db\Repo;
use TSF\Db\Table;

#[Table("tsf_t_player")]
final class PlayerRow
{
    // auto=true 才是自增。默认 false 是有意为之：游戏服的 uid 通常由账号服/雪花号分配，
    // 不是数据库自增。漏写这个参数时，insert 会显式插 id=0，
    // MySQL 把 0 当自增占位符生成 1，而 Repo 仍然返回 0 —— 看起来像「自增失效」。
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

const DSN = "mysql:host=127.0.0.1;port=52315;dbname=tsf;charset=utf8mb4";
const USER = "root";
const PASS = 'CHANGE_ME';

/**
 * 没有可用的 MySQL 就 SKIP（退出码 77）。
 *
 * 用**端口探测**而不是 try/catch：AOT 下 `catch (\Throwable)` 接不住 PDO 构造抛的
 * `PDOException`（实测：异常直接冒成 Fatal error）。探测 socket 不涉及异常类型，
 * 而且"端口没人监听"本来就是这里要判断的事。
 *
 * 注意只探测可达性，不探测认证：服务在但密码不对属于**真配置问题**，应该红。
 */
function requireMysql(string $host, int $port, float $timeout = 0.3): void
{
    $errno = 0;
    $errstr = "";
    $s = @stream_socket_client("tcp://" . $host . ":" . $port, $errno, $errstr, $timeout);
    if ($s === false) {
        echo "SKIP 需要可用的 MySQL（" . $host . ":" . $port . "）" . PHP_EOL;
        echo "     起一个：bash scripts/dev-mysql.sh" . PHP_EOL;
        exit(77);
    }
    fclose($s);
}

function main(): void
{
    requireMysql("127.0.0.1", 52315);
    $db = new Db(DSN, USER, PASS);

    echo "== [1] 连接 ==" . PHP_EOL;
    T::eq($db->driver(), "mysql", "driver() = mysql");
    $v = $db->value("SELECT VERSION()", [], "");
    T::ok(str_starts_with((string) $v, "8."), "版本 " . (string) $v);
    $cs = $db->value("SELECT @@character_set_database", [], "");
    T::eq((string) $cs, "utf8mb4", "库字符集 utf8mb4");

    // ---- 清理旧测试表 ----
    $db->exec("DROP TABLE IF EXISTS tsf_t_player");
    // 注意：Migrator::TABLE 是 "schema_migrations"，**不带任何前缀**。
    // 之前这里 DROP 的是 tsf_schema_migrations，等于没清 —— 上一轮的 version 记录还在，
    // 于是本轮 up() 走 skipped 分支：executed=0、failed=0，看起来像「什么都没发生」。
    $db->exec("DROP TABLE IF EXISTS schema_migrations");

    // ---------------- 2a. 手动建表诊断 ----------------
    // Migrator 的 catch 会把异常吞掉，先确认 Db::exec 本身能不能执行 DDL
    echo PHP_EOL . "== [2a] 手动 DDL 诊断 ==" . PHP_EOL;
    try {
        $db->exec("DROP TABLE IF EXISTS tsf_t_manual");
        $db->exec("CREATE TABLE tsf_t_manual (
            id   INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(32) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $cnt2 = $db->value(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tsf_t_manual'",
            [],
            0
        );
        T::eq((string) (int) $cnt2, "1", "手动 exec 建表成功");
        $db->exec("DROP TABLE tsf_t_manual");
    } catch (\Throwable $e) {
        T::ok(false, "手动建表抛异常", $e->getMessage());
    }

    // ---------------- 2-pre. 目录诊断 ----------------
    // AOT 编译后 __DIR__ 可能不是源码目录（可能指向编译时路径或运行时 cwd），
    // 如果它不对，Migrator 的 scan() 会因为 is_dir 失败而返回空，
    // 表现就是 executed=0 且 failed=0。
    echo PHP_EOL . "== [2-pre] 目录诊断 ==" . PHP_EOL;
    $dirProbe = __DIR__ . "/migrations";
    T::ok(true, "__DIR__ = " . __DIR__);
    T::ok(is_dir($dirProbe), "migrations 目录存在? " . $dirProbe);
    if (is_dir($dirProbe)) {
        $fs = scandir($dirProbe);
        T::ok(is_array($fs) && count($fs) > 2, "目录里有文件", "count=" . (is_array($fs) ? count($fs) : -1));
        if (is_array($fs)) {
            foreach ($fs as $f) {
                if ($f === "." || $f === "..") {
                    continue;
                }
                T::ok(true, "  文件: " . $f);
            }
        }
    }

    // ---------------- 2. Migrator ----------------
    echo PHP_EOL . "== [2] Migrator（mysql 方言）==" . PHP_EOL;
    $dir = __DIR__ . "/migrations";
    $mig = new Migrator($db, $dir);
    // up() 返回 ["shards", "executed", "skipped", "failed"] 四个键。
    // 用 count(up()) 判断「执行了几条」永远是 4 —— 典型的测试假通过。
    $res = $mig->up();
    $executed = $res["executed"] ?? [];
    $failed = $res["failed"] ?? [];
    T::eq((string) count($executed), "1", "执行了 1 条迁移");
    T::eq((string) count($failed), "0", "没有失败");
    // skipped 必须一起断言：executed=0 且 failed=0 时，唯一的可能就是被 skipped 了
    // （上一轮的 version 记录还在）。只看 executed/failed 会误判成「什么都没发生」。
    T::eq((string) ($res["skipped"] ?? -1), "0", "跳过 0 条");
    if (count($failed) > 0) {
        T::ok(false, "失败原因", $mig->lastError);
    }

    $hasTable = $db->value(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tsf_t_player'",
        [],
        0
    );
    T::eq((string) (int) $hasTable, "1", "表 tsf_t_player 已建");

    // 幂等：再跑一次不应重复执行
    // status() 返回的是「全部迁移 + applied 标记」，**不是**「待执行列表」。
    // 用 count(status()) 判断幂等，又是拿数量当语义（这次数的是"有几个迁移文件"）。
    $mig->up();
    $pending = 0;
    foreach ($mig->status() as $row) {
        if (!(bool) ($row["applied"] ?? false)) {
            $pending++;
        }
    }
    T::eq((string) $pending, "0", "重复 up() 后没有待执行迁移");

    $status = $mig->verify();
    T::ok(true, "verify() 可调用", "返回 " . count($status) . " 项");

    // ---------------- 3. Repo CRUD ----------------
    echo PHP_EOL . "== [3] Repo CRUD ==" . PHP_EOL;
    $repo = new Repo(PlayerRow::class, $db);

    $p = new PlayerRow();
    $p->name = "甲";
    $p->coins = 100;
    $id = $repo->insert($p);

    // 诊断：先确认数据真的写进表了（分清「插入失败」还是「读不出来」）
    $cnt = (int) $db->value("SELECT COUNT(*) FROM tsf_t_player", [], 0);
    T::eq((string) $cnt, "1", "插入后表里有 1 行");
    $raw = $db->select("SELECT id, name, coins FROM tsf_t_player");
    T::ok(count($raw) === 1, "直接 select 能读到 1 行");
    if (count($raw) === 1) {
        $r0 = $raw[0];
        T::eq((string) ($r0["name"] ?? ""), "甲", "裸行里的 name");
        T::eq((string) ($r0["id"] ?? ""), (string) $id, "裸行 id 与返回值一致");
    }

    T::ok($id > 0, "insert 返回自增 id", "id=" . $id);
    T::eq((string) $p->id, (string) $id, "自增 id 回填到实体");

    $got = $repo->find($id);
    $name = "";
    $coins = -1;
    if ($got instanceof PlayerRow) {
        $name = $got->name;
        $coins = $got->coins;
    }
    T::eq($name, "甲", "find 读回中文名");
    T::eq((string) $coins, "100", "find 读回 coins");

    if ($got instanceof PlayerRow) {
        $got->coins = 250;
        $repo->update($got);
        $again = $repo->find($id);
        $c2 = -1;
        if ($again instanceof PlayerRow) {
            $c2 = $again->coins;
        }
        T::eq((string) $c2, "250", "update 生效");
    }

    // 批量插入
    $rows = [];
    $i = 0;
    while ($i < 3) {
        $r = new PlayerRow();
        $r->name = "批量" . $i;
        $r->coins = $i * 10;
        $rows[] = $r;
        $i++;
    }
    // insertMany 在 Db 上，不在 Repo 上
    $plain = [];
    foreach ($rows as $rowX) {
        $plain[] = ["name" => $rowX->name, "coins" => $rowX->coins];
    }
    $db->insertMany("tsf_t_player", $plain);
    $all = $repo->findAll("", [], "id ASC", 0);
    T::ok(count($all) >= 4, "findAll 拿到 4 条以上", "count=" . count($all));

    // 删除
    $repo->delete($id);
    T::ok($repo->find($id) === null, "delete 后 find 返回 null");

    // ---------------- 4. 事务 ----------------
    echo PHP_EOL . "== [4] 事务 ==" . PHP_EOL;
    // 闭包必须声明 1 个参数：transaction() 内部调用 `$fn($this)`，
    // AOT 下参数个数不匹配直接崩（报 stdClass::{closure}() expects exactly 0 arguments）。
    $db->transaction(function (Db $d): void {
        $d->exec("INSERT INTO tsf_t_player (name, coins) VALUES (?, ?)", ["事务内", 999]);
    });
    $cnt = $db->value("SELECT COUNT(*) FROM tsf_t_player WHERE name = ?", ["事务内"], 0);
    T::eq((string) (int) $cnt, "1", "事务提交后可见");

    // 回滚
    try {
        $db->transaction(function (Db $d): void {
            $d->exec("INSERT INTO tsf_t_player (name, coins) VALUES (?, ?)", ["要回滚", 1]);
            throw new \RuntimeException("故意抛错");
        });
        T::ok(false, "事务内的异常应该冒出来");
    } catch (\Throwable $e) {
        $cnt2 = $db->value("SELECT COUNT(*) FROM tsf_t_player WHERE name = ?", ["要回滚"], 0);
        T::eq((string) (int) $cnt2, "0", "抛异常后自动回滚");
    }

    // ---------------- 5. 严格模式（sqlite 与 mysql 的关键差异） ----------------
    echo PHP_EOL . "== [5] 严格模式 ==" . PHP_EOL;
    $strict = $db->value("SELECT @@sql_mode", [], "");
    $isStrict = strpos((string) $strict, "STRICT_TRANS_TABLES") !== false;
    T::ok(true, "sql_mode = " . substr((string) $strict, 0, 40) . "...", $isStrict ? "严格模式" : "非严格");

    // 超长字符串：VARCHAR(32)，塞 100 个字符
    $long = str_repeat("超", 100);
    $threw = false;
    try {
        $db->exec("INSERT INTO tsf_t_player (name, coins) VALUES (?, ?)", [$long, 1]);
    } catch (\Throwable $e) {
        $threw = true;
    }
    T::ok($threw, "超长字符串在严格模式下抛异常（sqlite 会静默截断）");

    // ---------------- 6. 清理 ----------------
    echo PHP_EOL . "== [6] 清理 ==" . PHP_EOL;
    $db->exec("DROP TABLE IF EXISTS tsf_t_player");
    $db->exec("DROP TABLE IF EXISTS schema_migrations");
    $left = $db->value(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'tsf_t_%'",
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
