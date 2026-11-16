<?php

/**
 * 一次性：把两个对照工程的 handler 注册分别固定成 mixed / string 两种签名，
 * 其余代码完全一致（同一框架、同一配置、同一构建参数）。
 */

$base = "/data/TypeServer/demo";

$startMarker = "    if (\$proto->payloadName() !== \"raw\") {";
$endMarker = "    \$gw->boot();";

$mixedBlock = <<<'PHP'
    // A/B 对照：强制注册 mixed 签名的 handler（其余代码与 string 版完全一致）
    $gw->on(Msg::ECHO_REQ, function (int $fd, int $uid, mixed $data) use ($gw): void {
        $gw->send($fd, Msg::ECHO_RESP, $data);
    });
    $gw->on(1003, function (int $fd, int $uid, mixed $data) use ($gw): void {
        $gw->send($fd, 1004, "uid=" . $uid);
    });

PHP;

$stringBlock = <<<'PHP'
    // A/B 对照：强制注册 string 签名的 handler（其余代码与 mixed 版完全一致）
    $gw->on(Msg::ECHO_REQ, function (int $fd, int $uid, string $body) use ($gw): void {
        $gw->send($fd, Msg::ECHO_RESP, $body);
    });
    $gw->on(1003, function (int $fd, int $uid, string $body) use ($gw): void {
        $gw->send($fd, 1004, "uid=" . $uid);
    });

PHP;

/** @param array<string, mixed> $cfg */
function patchMain(string $path, string $block): void
{
    $code = file_get_contents($path);
    // 锚点必须唯一：用"业务处理器"注释段起点，不能用 if 条件（装配段也有一次同名条件）
    $a = strpos($code, "    // ---- 业务处理器 ----");
    $b = strpos($code, "    \$gw->boot();");
    if ($a === false || $b === false || $b <= $a) {
        fwrite(STDERR, "marker not found in " . $path . "\n");
        exit(1);
    }
    $code = substr($code, 0, $a) . $block . substr($code, $b);
    file_put_contents($path, $code);
    echo "patched " . $path . "\n";
}

patchMain($base . "/ab-mixed/main.php", $mixedBlock);
patchMain($base . "/ab-string/main.php", $stringBlock);

/** @param array<string, mixed> $over */
function patchConfig(string $path, int $port): void
{
    $d = json_decode(file_get_contents($path), true);
    $d["server"]["port"] = $port;
    $d["server"]["auth"]["require"] = false;
    $d["server"]["rate_per_sec"] = 0;
    $d["server"]["gateway"] = true;
    $d["protocol"]["frame"] = "native";
    $d["protocol"]["payload"] = "raw";
    file_put_contents($path, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    echo "config " . $path . " port=" . $port . "\n";
}

patchConfig($base . "/ab-mixed/config/tsf.json", 9101);
patchConfig($base . "/ab-string/config/tsf.json", 9102);
