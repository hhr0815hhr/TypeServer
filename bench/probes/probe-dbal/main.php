<?php

use Doctrine\DBAL\DriverManager;

/**
 * 探针：把 doctrine/dbal 的 vendor 全量放进 sources，看能否 AOT 编译，
 * 以及 QueryBuilder 相对原生 PDO 的真实开销。
 *
 * 回答两个问题：
 *   1. vendor 到底能不能 AOT（project.yml 的 sources 直接指到 vendor）
 *   2. QueryBuilder 值不值得——差多少，值不值那点开发效率
 */

function main(): void
{
    $path = "/tmp/dbal_probe.sqlite";
    if (is_file($path)) {
        unlink($path);
    }

    $conn = DriverManager::getConnection([
        "driver" => "pdo_sqlite",
        "path" => $path,
    ]);

    $conn->executeStatement("CREATE TABLE players (uid INTEGER PRIMARY KEY, hp INTEGER, name TEXT)");

    for ($i = 1; $i <= 100; $i++) {
        $qb = $conn->createQueryBuilder();
        $qb->insert("players")
            ->values(["uid" => ":uid", "hp" => ":hp", "name" => ":name"])
            ->setParameter("uid", $i)
            ->setParameter("hp", 100)
            ->setParameter("name", "p" . $i);
        $qb->executeStatement();
    }

    // QueryBuilder 1000 次
    $t0 = hrtime(true);
    $sum = 0;
    for ($i = 0; $i < 1000; $i++) {
        $qb = $conn->createQueryBuilder();
        $row = $qb->select("*")
            ->from("players")
            ->where("uid = :uid")
            ->setParameter("uid", ($i % 100) + 1)
            ->fetchAssociative();
        if ($row !== false) {
            $sum += (int) $row["hp"];
        }
    }
    $us = intdiv(hrtime(true) - $t0, 1000);

    // 原生 PDO 1000 次（同一条连接、同一条 prepare）
    $pdo = new \PDO("sqlite:" . $path);
    $t0 = hrtime(true);
    $st = $pdo->prepare("SELECT hp FROM players WHERE uid = ?");
    $sum2 = 0;
    for ($i = 0; $i < 1000; $i++) {
        $st->execute([($i % 100) + 1]);
        $sum2 += (int) $st->fetchColumn();
    }
    $us2 = intdiv(hrtime(true) - $t0, 1000);

    echo "QueryBuilder 1000 次: " . $us . " µs（每次 " . intdiv($us, 1000) . " µs）" . PHP_EOL;
    echo "原生 PDO     1000 次: " . $us2 . " µs（每次 " . intdiv($us2, 1000) . " µs）" . PHP_EOL;
    if ($us2 > 0) {
        echo "倍数: " . round($us / $us2, 1) . "x" . PHP_EOL;
    }
    echo "sum=" . $sum . " / " . $sum2 . PHP_EOL;
}
