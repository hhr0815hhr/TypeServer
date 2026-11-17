<?php
// 建 demo 用的表。跑一次就行（IF NOT EXISTS 幂等）。
// 用法: php85 init_db.php

$cfg = json_decode((string) file_get_contents(__DIR__ . "/config/tsf.json"), true);
$db = $cfg["db"] ?? [];
$pdo = new PDO(
    (string) ($db["dsn"] ?? ""),
    (string) ($db["user"] ?? ""),
    (string) ($db["pass"] ?? ""),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pdo->exec("CREATE TABLE IF NOT EXISTS idle_player (
    uid        INT PRIMARY KEY,
    name       VARCHAR(32) NOT NULL,
    coins      BIGINT NOT NULL DEFAULT 0,
    last_tick  BIGINT NOT NULL,
    created_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "idle_player 已建" . PHP_EOL;

// pkg/mail 的表（第一次跑也需要）
$pdo->exec("CREATE TABLE IF NOT EXISTS mail (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    uid        INT NOT NULL,
    title      VARCHAR(64) NOT NULL,
    body       VARCHAR(1024) NOT NULL DEFAULT '',
    items      VARCHAR(1024) NOT NULL DEFAULT '',
    created_at BIGINT NOT NULL,
    expire_at  BIGINT NOT NULL DEFAULT 0,
    read_at    BIGINT NOT NULL DEFAULT 0,
    claimed_at BIGINT NOT NULL DEFAULT 0,
    INDEX idx_mail_uid (uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "mail 已建" . PHP_EOL;
