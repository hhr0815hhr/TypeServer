<?php

// 查 duel 的对局记录（示例里用来验证 pkg/db 真的落库了）
$path = "/data/TypeServer/demo/duel/var/duel.db";
if (!file_exists($path)) {
    echo "库不存在: " . $path . PHP_EOL;
    exit(1);
}

$pdo = new PDO("sqlite:" . $path);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$rows = $pdo->query("SELECT * FROM duel_records ORDER BY id")->fetchAll();
echo "共 " . count($rows) . " 条对局记录" . PHP_EOL;
foreach ($rows as $r) {
    printf(
        "  #%d room=%d winner=%d loser=%d winner_hp=%d frames=%d ended_at=%s\n",
        (int) $r["id"],
        (int) $r["room"],
        (int) $r["winner"],
        (int) $r["loser"],
        (int) $r["winner_hp"],
        (int) $r["frames"],
        date("H:i:s", (int) $r["ended_at"])
    );
}
