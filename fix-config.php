<?php

// 把 demo 工程配置恢复成"正常服务"状态。
// 压测 / WS / 协议测试都会改配置，这里统一兜底：
//   - server.transport（曾叫 server.protocol，改名后必须一起复位）
//   - protocol.frame / protocol.payload（协议测试会改，漏复位会让后续测试莫名失败）
//   - port（WS 测试会改成 9010）
$p = __DIR__ . "/../demo/demo-echo/config/tsf.json";
$d = json_decode(file_get_contents($p), true);
if (!is_array($d)) {
    fwrite(STDERR, "bad config\n");
    exit(1);
}
$s = $d["server"];
$s["transport"] = "tcp";
unset($s["protocol"]); // 旧键，避免与 transport 混淆
$s["port"] = 9001;
$s["codec"] = "native";
$s["gateway"] = true;
$s["auth"]["require"] = true;
$s["rate_per_sec"] = 50;
$s["rate_burst"] = 100;
$s["heartbeat_sec"] = 30;
$s["send_fail_limit"] = 50;
$d["server"] = $s;

$d["protocol"] = ["frame" => "native", "payload" => "raw"];

file_put_contents($p, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo "restored: tcp:9001 frame=native payload=raw auth=true rate=50 hb=30\n";
