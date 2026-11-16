<?php

/**
 * 探针：AOT 下 PDO 能不能用，以及连接复用的开销。
 *
 * 这是 M4 的地基。如果 PDO 在 AOT 下不可用、或者慢得离谱，
 * 数据层就得换方案（纯文件 / 外部进程 / 自研存储），越早发现越好。
 */

function main(): void
{
    echo "== PDO 可用性（SQLite）==" . PHP_EOL;

    $path = "/tmp/probe_db.sqlite";
    if (is_file($path)) {
        unlink($path);
    }

    $db = new \PDO("sqlite:" . $path);
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL");
    $db->exec("CREATE TABLE t (id INTEGER PRIMARY KEY, v INTEGER)");

    // 1) 基本写入
    $st = $db->prepare("INSERT INTO t (v) VALUES (?)");
    for ($i = 0; $i < 100; $i++) {
        $st->execute([$i]);
    }
    $n = (int) $db->query("SELECT COUNT(*) FROM t")->fetchColumn();
    echo "插入 100 行 -> 实际 " . $n . PHP_EOL;

    // 2) 1000 次查询，复用同一条连接与 prepare
    $t0 = hrtime(true);
    $sel = $db->prepare("SELECT v FROM t WHERE id = ?");
    $sum = 0;
    for ($i = 1; $i <= 1000; $i++) {
        $sel->execute([($i % 100) + 1]);
        $sum += (int) $sel->fetchColumn();
    }
    $us = intdiv(hrtime(true) - $t0, 1000);
    echo "1000 次查询: " . $us . " µs（每次 " . intdiv($us, 1000) . " µs）sum=" . $sum . PHP_EOL;

    // 3) 事务批量插入
    $t0 = hrtime(true);
    $db->beginTransaction();
    $ins = $db->prepare("INSERT INTO t (v) VALUES (?)");
    for ($i = 0; $i < 1000; $i++) {
        $ins->execute([$i]);
    }
    $db->commit();
    $us2 = intdiv(hrtime(true) - $t0, 1000);
    echo "事务内 1000 次插入: " . $us2 . " µs" . PHP_EOL;

    // 4) 断开重连（模拟连接失效后的恢复）
    $db = null;
    $db2 = new \PDO("sqlite:" . $path);
    $n2 = (int) $db2->query("SELECT COUNT(*) FROM t")->fetchColumn();
    echo "重连后行数 " . $n2 . PHP_EOL;

    echo "结论: " . ($n === 100 && $n2 === 1100 ? "PDO 可用" : "PDO 行为异常") . PHP_EOL;
}
