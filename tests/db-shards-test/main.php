<?php

/**
 * pkg/db 的 Shards（多库分片）验证。
 *
 * 要证明三件事：
 *   1. 同一个 key 永远路由到同一个分片（幂等）
 *   2. 不同 key 会分散到不同分片
 *   3. Migrator / Repo 在分片模式下**调用代码不变**（这是 Source 抽象存在的理由）
 *
 * 安全：只建/删 tsf_shard_0、tsf_shard_1 两个库。开跑前先查这两个名字是否已存在，
 * 存在就退出（可能是别人的库，不能动）。
 */

use TSF\Db\Col;
use TSF\Db\Db;
use TSF\Db\Id;
use TSF\Db\Migrator;
use TSF\Db\Repo;
use TSF\Db\Shards;
use TSF\Db\Table;

#[Table("shard_player")]
final class ShardPlayer
{
    // 分片场景的主键**不能自增**：Repo 用主键算分片（forKey($id)），
    // 而各分片的 AUTO_INCREMENT 会算出相同的 id、写到不同库里 —— 看起来是
    // 「同一个玩家出现两次」。所以主键必须由业务分配，这里直接用 uid。
    #[Id]
    public int $uid = 0;

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

const HOST = "127.0.0.1";
const PORT = 52315;
const USER = "root";
const PASS = 'CHANGE_ME';

const SHARDS = 2;

function main(): void
{
    $rootDsn = "mysql:host=" . HOST . ";port=" . PORT . ";dbname=tsf;charset=utf8mb4";
    $root = new Db($rootDsn, USER, PASS);

    // ---------- 0. 安全检查 ----------
    echo "== [0] 安全检查 ==" . PHP_EOL;
    $names = [];
    $i = 0;
    while ($i < SHARDS) {
        $names[] = "tsf_shard_" . $i;
        $i++;
    }
    $existing = $root->select(
        "SELECT schema_name FROM information_schema.schemata WHERE schema_name IN (?, ?)",
        ["tsf_shard_0", "tsf_shard_1"]
    );
    if (count($existing) > 0) {
        echo "  tsf_shard_0 / tsf_shard_1 已存在，可能不是测试库，退出（不删别人的库）" . PHP_EOL;
        T::ok(false, "目标库已存在，跳过测试");
        return;
    }
    T::ok(true, "目标库不存在，可以创建");

    // ---------- 1. 建库 ----------
    echo PHP_EOL . "== [1] 建库 ==" . PHP_EOL;
    $i = 0;
    while ($i < SHARDS) {
        $root->exec("CREATE DATABASE IF NOT EXISTS `tsf_shard_" . $i . "` DEFAULT CHARSET utf8mb4");
        $i++;
    }
    $created = $root->value(
        "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name IN (?, ?)",
        ["tsf_shard_0", "tsf_shard_1"],
        0
    );
    T::eq((string) (int) $created, "2", "两个分片库已建");

    // ---------- 2. 路由计算 ----------
    echo PHP_EOL . "== [2] 路由计算 ==" . PHP_EOL;
    $tpl = "mysql:host=" . HOST . ";port=" . PORT . ";dbname=tsf_shard_{i};charset=utf8mb4";
    $shards = new Shards($tpl, SHARDS, USER, PASS);

    T::eq((string) $shards->count(), "2", "count() = 2");
    T::eq((string) $shards->shardOf(1), "1", "uid=1 → 分片 1");
    T::eq((string) $shards->shardOf(2), "0", "uid=2 → 分片 0");
    T::eq((string) $shards->shardOf(3), "1", "uid=3 → 分片 1");
    T::eq((string) $shards->shardOf(4), "0", "uid=4 → 分片 0");
    T::eq((string) $shards->shardOf(101), "1", "uid=101 → 分片 1（取模）");

    // 幂等：同一个 key 连续取，dsn 必须一样
    $d1 = $shards->forKey(7)->dsn();
    $d2 = $shards->forKey(7)->dsn();
    T::eq($d1, $d2, "同一 key 两次路由结果一致");
    T::ok(strpos($d1, "tsf_shard_1") !== false, "uid=7 的 dsn 指向分片 1", $d1);

    // every() 要能遍历所有分片（Migrator 靠它）
    T::eq((string) count($shards->every()), "2", "every() 返回 2 个 Db");

    // ---------- 3. 迁移：一次跑所有分片 ----------
    echo PHP_EOL . "== [3] 分片迁移 ==" . PHP_EOL;
    $mig = new Migrator($shards, __DIR__ . "/migrations");
    $res = $mig->up();
    T::eq((string) ($res["shards"] ?? -1), "2", "up() 遍历了 2 个分片");
    T::eq((string) count($res["executed"] ?? []), "2", "两个分片各执行 1 条");
    T::eq((string) count($res["failed"] ?? []), "0", "没有失败");
    if (count($res["failed"] ?? []) > 0) {
        T::ok(false, "失败原因", $mig->lastError);
    }

    // 两个库都要真的有表
    $i = 0;
    $hasAll = true;
    while ($i < SHARDS) {
        $one = new Db("mysql:host=" . HOST . ";port=" . PORT . ";dbname=tsf_shard_" . $i . ";charset=utf8mb4", USER, PASS);
        $c = $one->value(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = 'shard_player'",
            ["tsf_shard_" . $i],
            0
        );
        if ((int) $c !== 1) {
            $hasAll = false;
        }
        $one->close();
        $i++;
    }
    T::ok($hasAll, "每个分片库都有 shard_player 表");

    // ---------- 4. 写入：按 uid 分散 ----------
    echo PHP_EOL . "== [4] 按 uid 分散写入 ==" . PHP_EOL;
    $repo = new Repo(ShardPlayer::class, $shards);

    $i = 1;
    while ($i <= 4) {
        $p = new ShardPlayer();
        $p->uid = $i;
        $p->name = "玩家" . $i;
        $p->coins = $i * 100;
        // 非自增主键时 insert() **明确返回 0**（主键由业务持有，不需要回填/返回）。
        // 这不是失败 —— 判成功要看数据是否落到库里（下面会逐库数行数）。
        $key = $repo->insert($p);
        if ($p->uid !== $i) {
            T::ok(false, "插入 uid=" . $i, "实体主键被改了: " . $p->uid);
        }
        $i++;
    }
    T::ok(true, "4 个玩家已写入（uid 1-4）");

    // 直接查两个库，确认数据真的分开了
    $counts = [];
    $i = 0;
    while ($i < SHARDS) {
        $one = new Db("mysql:host=" . HOST . ";port=" . PORT . ";dbname=tsf_shard_" . $i . ";charset=utf8mb4", USER, PASS);
        $c = $one->value("SELECT COUNT(*) FROM shard_player", [], 0);
        $counts[$i] = (int) $c;
        $one->close();
        $i++;
    }
    T::eq((string) $counts[0], "2", "分片 0 有 2 条（uid 2、4）");
    T::eq((string) $counts[1], "2", "分片 1 有 2 条（uid 1、3）");

    // ---------- 5. 按 uid 读回来 ----------
    echo PHP_EOL . "== [5] 按 uid 读回 ==" . PHP_EOL;
    $got3 = $repo->find(3);
    $name3 = "";
    if ($got3 instanceof ShardPlayer) {
        $name3 = $got3->name;
    }
    T::eq($name3, "玩家3", "uid=3 能读回（落在分片 1）");

    $got2 = $repo->find(2);
    $name2 = "";
    if ($got2 instanceof ShardPlayer) {
        $name2 = $got2->name;
    }
    T::eq($name2, "玩家2", "uid=2 能读回（落在分片 0）");

    // ---------- 6. 清理 ----------
    echo PHP_EOL . "== [6] 清理 ==" . PHP_EOL;
    $shards->closeAll();
    $root->close();

    // 重新连一个（root 那个可能已被 closeAll 影响不到，但保险起见）
    $root2 = new Db($rootDsn, USER, PASS);
    $i = 0;
    while ($i < SHARDS) {
        $root2->exec("DROP DATABASE IF EXISTS `tsf_shard_" . $i . "`");
        $i++;
    }
    $left = $root2->value(
        "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name IN (?, ?)",
        ["tsf_shard_0", "tsf_shard_1"],
        0
    );
    T::eq((string) (int) $left, "0", "分片库已删除");
    $root2->close();

    echo PHP_EOL . "通过 " . T::$pass . " / 失败 " . T::$fail . PHP_EOL;
    if (T::$fail > 0) {
        exit(1);
    }
}
