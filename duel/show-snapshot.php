<?php

/**
 * 打印 duel 的快照文件内容（用解释器跑即可，纯 JSON 解析）。
 *
 * 用途：崩溃恢复验证时肉眼确认"崩之前的状态确实被存下来了"。
 */

$dir = "/data/TypeServer/demo/duel/var/snap";
if (!is_dir($dir)) {
    echo "快照目录不存在: " . $dir . PHP_EOL;
    exit(1);
}

$files = [];
foreach (scandir($dir) as $f) {
    if (str_ends_with((string) $f, ".snap")) {
        $files[] = $dir . "/" . $f;
    }
}

if ($files === []) {
    echo "没有快照文件（说明房间已正常收摊，或从未存过）" . PHP_EOL;
    exit(0);
}

foreach ($files as $p) {
    $json = file_get_contents($p);
    if (!is_string($json)) {
        continue;
    }
    $d = json_decode($json, true);
    if (!is_array($d)) {
        echo basename($p) . "  ← 不是合法 JSON" . PHP_EOL;
        continue;
    }

    $body = $d["b"]["duel"] ?? [];
    $room = $body["room"] ?? [];
    $ents = $body["ents"] ?? [];
    $hp = $body["hp"] ?? [];

    printf(
        "%s\n  kind=%s key=%d 版本=v%d 采集=%s\n",
        basename($p),
        (string) ($d["k"] ?? "?"),
        (int) ($d["id"] ?? 0),
        (int) ($d["v"] ?? 0),
        date("H:i:s", (int) ((int) ($d["t"] ?? 0) / 1000))
    );
    printf(
        "  房间: id=%d state=%d frame=%d elapsed=%dms 容量=%d\n",
        (int) ($room["id"] ?? 0),
        (int) ($room["state"] ?? 0),
        (int) ($room["frame"] ?? 0),
        (int) ($room["elapsedMs"] ?? 0),
        (int) ($room["maxPlayers"] ?? 0)
    );

    $members = $room["members"] ?? [];
    $lines = [];
    foreach ($members as $m) {
        $lines[] = sprintf(
            "uid=%d(role=%d seat=%d)",
            (int) ($m["uid"] ?? 0),
            (int) ($m["role"] ?? 0),
            (int) ($m["seat"] ?? -1)
        );
    }
    echo "  成员(" . count($members) . "): " . implode(" ", $lines) . PHP_EOL;

    $elines = [];
    foreach ($ents as $e) {
        $elines[] = sprintf(
            "%d@(%d,%d)v(%d,%d)",
            (int) ($e["uid"] ?? 0),
            (int) ($e["x"] ?? 0),
            (int) ($e["y"] ?? 0),
            (int) ($e["vx"] ?? 0),
            (int) ($e["vy"] ?? 0)
        );
    }
    echo "  坐标(" . count($ents) . "): " . implode(" ", $elines) . PHP_EOL;

    $hlines = [];
    foreach ($hp as $uid => $v) {
        $hlines[] = $uid . "=>" . $v;
    }
    echo "  血量: " . implode(" ", $hlines) . PHP_EOL;
    echo "  winner=" . (int) ($body["winner"] ?? 0) . PHP_EOL;
}
