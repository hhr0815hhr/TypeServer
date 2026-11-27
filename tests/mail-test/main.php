<?php

/**
 * pkg/mail 的验证（接真 MySQL）。
 *
 * 重点验证三件事：
 *   1. 过期邮件不出现在列表里
 *   2. 领取是原子的 —— 第二次领返回空（这是运营补偿最怕出错的点：重复领取）
 *   3. 未读数和标记已读一致
 */

use TSF\Db\Db;
use TSF\Db\Migrator;
use TSF\Mail\Mail;
use TSF\Mail\MailStore;

const DSN = "mysql:host=127.0.0.1;port=52315;dbname=tsf;charset=utf8mb4";
const USER = "root";
const PASS = 'CHANGE_ME';

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

    // 清理
    $db->exec("DROP TABLE IF EXISTS mail");
    $db->exec("DROP TABLE IF EXISTS schema_migrations");

    echo "== [1] 迁移 ==" . PHP_EOL;
    // 迁移目录跟着仓库走：run-svc-test.sh 导出 TSF_ROOT。此前写死 /data/TypeServer，
    // 换一个检出路径（比如 CI runner 的 /home/runner/work/...）就找不到目录。
    $tsfRoot = getenv("TSF_ROOT");
    if ($tsfRoot === false || $tsfRoot === "") {
        throw new \RuntimeException("mail-test 需要 TSF_ROOT（tsf 目录的绝对路径）；用 bash scripts/run-svc-test.sh mail-test mysql 来跑");
    }
    $mig = new Migrator($db, $tsfRoot . "/pkg/mail/migrations");
    $res = $mig->up();
    T::eq((string) count($res["executed"] ?? []), "1", "建表迁移执行了 1 条");
    if (count($res["failed"] ?? []) > 0) {
        T::ok(false, "失败原因", $mig->lastError);
    }
    $has = $db->value(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'mail'",
        [],
        0
    );
    T::eq((string) (int) $has, "1", "mail 表已建");

    $store = new MailStore($db);

    echo PHP_EOL . "== [2] 发信 ==" . PHP_EOL;
    $uid = 10001;

    // 带附件，30 天有效
    $id1 = $store->send($uid, "系统补偿", "因维护补偿", [["id" => 1001, "n" => 5], ["id" => 2003, "n" => 1]], 30);
    T::ok($id1 > 0, "带附件的邮件已发", "id=" . $id1);

    // 无附件，1 天有效
    $id2 = $store->send($uid, "活动预告", "今晚 8 点开活动", [], 1);
    T::ok($id2 > 0, "无附件邮件已发", "id=" . $id2);

    // 永不过期
    $id3 = $store->send($uid, "欢迎", "欢迎来到游戏", [], 0);
    T::ok($id3 > 0, "永久邮件已发", "id=" . $id3);

    // 别人的邮件，不该出现在 uid 的列表里
    $otherId = $store->send(99999, "别人的", "", [], 0);

    echo PHP_EOL . "== [3] 列表与未读 ==" . PHP_EOL;
    $list = $store->listOf($uid);
    T::eq((string) count($list), "3", "列表 3 封（不含别人的）");
    $titles = [];
    $i = 0;
    while ($i < count($list)) {
        $titles[] = $list[$i]->title;
        $i++;
    }
    // 按 id 倒序，最新在前
    T::eq(implode(",", $titles), "欢迎,活动预告,系统补偿", "按 id 倒序");

    T::eq((string) $store->unreadCount($uid), "3", "未读数 3");

    $unreadOnly = $store->listOf($uid, 50, true);
    T::eq((string) count($unreadOnly), "3", "只看未读也是 3 封");

    echo PHP_EOL . "== [4] 已读 ==" . PHP_EOL;
    T::ok($store->markRead($uid, $id1), "标记已读返回 true");
    T::eq((string) $store->unreadCount($uid), "2", "未读数变 2");
    T::ok(!$store->markRead($uid, $id1), "重复标记返回 false");
    T::eq((string) count($store->listOf($uid, 50, true)), "2", "只看未读变 2 封");

    echo PHP_EOL . "== [5] 领取（重点：幂等）==" . PHP_EOL;
    $got = $store->claim($uid, $id1);
    T::eq((string) count($got), "2", "领到 2 个附件");
    $first = $got[0];
    T::eq((string) ($first["id"] ?? 0), "1001", "第一个附件 id 正确");
    T::eq((string) ($first["n"] ?? 0), "5", "第一个附件数量正确");

    $again = $store->claim($uid, $id1);
    T::eq((string) count($again), "0", "第二次领返回空（不会重复发奖）");

    // 领一封没有附件的
    $none = $store->claim($uid, $id2);
    T::eq((string) count($none), "0", "无附件邮件领取返回空");

    // 别人的邮件领不到
    $stolen = $store->claim($uid, $otherId);
    T::eq((string) count($stolen), "0", "领不到别人的邮件");

    echo PHP_EOL . "== [6] 过期 ==" . PHP_EOL;
    // 直接造一封 1 秒前过期的
    $now = time();
    $expiredId = $db->insert("mail", [
        "uid" => $uid,
        "title" => "已过期",
        "body" => "",
        "items" => "",
        "created_at" => $now - 86400,
        "expire_at" => $now - 1,
        "read_at" => 0,
        "claimed_at" => 0,
    ]);
    T::ok($expiredId > 0, "造了一封已过期的邮件");

    $list2 = $store->listOf($uid);
    T::eq((string) count($list2), "3", "过期的不出现在列表里（仍是 3 封）");

    $claimed = $store->claim($uid, $expiredId);
    T::eq((string) count($claimed), "0", "过期的领不到");

    $cleaned = $store->cleanExpired($uid);
    T::eq((string) $cleaned, "1", "清理掉 1 封过期邮件");

    echo PHP_EOL . "== [7] 删除 ==" . PHP_EOL;
    T::ok($store->del($uid, $id3), "删除返回 true");
    T::eq((string) count($store->listOf($uid)), "2", "删除后列表 2 封");
    T::ok(!$store->del($uid, $id3), "重复删除返回 false");

    echo PHP_EOL . "== [8] 清理 ==" . PHP_EOL;
    $db->exec("DROP TABLE IF EXISTS mail");
    $db->exec("DROP TABLE IF EXISTS schema_migrations");
    $left = $db->value(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('mail','schema_migrations')",
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
