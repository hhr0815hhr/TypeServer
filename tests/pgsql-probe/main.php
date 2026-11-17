<?php

/**
 * 只看两件事：AOT 产物里有没有 pgsql 驱动，以及 DSN 能不能被解析。
 *
 * 判据：
 *   "could not find driver"      → 驱动没加载（扩展没装上）
 *   "connection refused" / 超时   → 驱动加载了，只是服务器不可达
 *
 * 这个区分很重要 —— 两种失败原因完全不同，报错长得却很像。
 */

function main(): void
{
    $drivers = \PDO::getAvailableDrivers();
    echo "PDO 驱动: " . implode(", ", $drivers) . PHP_EOL;
    echo "有 pgsql: " . (in_array("pgsql", $drivers, true) ? "是" : "否") . PHP_EOL;

    $host = getenv("PGHOST") ?: "127.0.0.1";
    $port = (int) (getenv("PGPORT") ?: "1");    // 故意用 1 端口，正常连不上
    $db = getenv("PGDATABASE") ?: "postgres";
    $user = getenv("PGUSER") ?: "postgres";
    $pass = getenv("PGPASSWORD") ?: "x";

    $dsn = "pgsql:host=" . $host . ";port=" . $port . ";dbname=" . $db;

    try {
        new \PDO($dsn, $user, $pass, [\PDO::ATTR_TIMEOUT => 2, \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        echo "连接成功（有服务器在跑）" . PHP_EOL;
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        echo "连接失败: " . substr($msg, 0, 100) . PHP_EOL;
        if (strpos($msg, "could not find driver") !== false) {
            echo "→ 驱动没加载（扩展问题）" . PHP_EOL;
        } else {
            echo "→ 驱动已加载，只是服务器不可达（符合预期）" . PHP_EOL;
        }
    }
}
