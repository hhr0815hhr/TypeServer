<?php

/**
 * 严格 A/B：两个二进制只差 handler 的参数类型（mixed vs string），
 * 交替采样 + 每轮配对求比值，抵消机器漂移。
 *
 * 用法：php85 bench/ab-mixed.php [rounds] [secs]
 */

$rounds = isset($argv[1]) ? (int) $argv[1] : 5;
$secs = isset($argv[2]) ? (int) $argv[2] : 3;
$conns = 32;
$loader = "/data/TypeServer/bench/load_tsf";

$variants = [
    "mixed" => ["bin" => "/data/TypeServer/demo/ab-mixed/ab_mixed", "cwd" => "/data/TypeServer/demo/ab-mixed", "port" => 9101],
    "string" => ["bin" => "/data/TypeServer/demo/ab-string/ab_string", "cwd" => "/data/TypeServer/demo/ab-string", "port" => 9102],
];

function say(string $s): void
{
    echo $s . PHP_EOL;
}

function portFree(int $port): bool
{
    $s = @fsockopen("127.0.0.1", $port, $errno, $errstr, 0.2);
    if ($s !== false) {
        fclose($s);
        return false;
    }
    return true;
}

function waitPortFree(int $port, float $timeout = 8.0): bool
{
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        if (portFree($port)) {
            return true;
        }
        usleep(100000);
    }
    return false;
}

function waitTcp(int $port, float $timeout = 10.0): bool
{
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $s = @fsockopen("127.0.0.1", $port, $errno, $errstr, 0.2);
        if ($s !== false) {
            fclose($s);
            return true;
        }
        usleep(50000);
    }
    return false;
}

/** @param array<string, mixed> $v */
function measure(array $v, int $conns, int $secs, string $loader): float
{
    $port = (int) $v["port"];
    $name = basename((string) $v["bin"]);
    exec("pkill -9 -x " . escapeshellarg($name) . " 2>/dev/null");
    if (!waitPortFree($port)) {
        say("    ! 端口 " . $port . " 未释放");
        return 0.0;
    }

    $log = "/tmp/ab_" . $name . ".log";
    @unlink($log);
    $desc = [0 => ["file", "/dev/null", "r"], 1 => ["file", $log, "a"], 2 => ["file", $log, "a"]];
    $proc = proc_open([(string) $v["bin"]], $desc, $pipes, (string) $v["cwd"]);
    if (!is_resource($proc)) {
        return 0.0;
    }
    if (!waitTcp($port, 10.0)) {
        say("    ! 服务端起不来：" . (@file_get_contents($log) ?: "(空)"));
        proc_terminate($proc, 9);
        proc_close($proc);
        return 0.0;
    }
    usleep(300000);

    $out = shell_exec($loader . " 127.0.0.1 " . $port . " " . $conns . " " . $secs . " 2>&1");
    proc_terminate($proc, 9);
    proc_close($proc);
    exec("pkill -9 -x " . escapeshellarg($name) . " 2>/dev/null");

    $rate = 0.0;
    if (is_string($out) && preg_match('/rate=([0-9.]+)/', $out, $m)) {
        $rate = (float) $m[1];
    }
    return $rate;
}

say("== A/B：handler 参数 mixed vs string（其余代码完全一致）==");
say("   每轮 " . $secs . "s × " . $conns . " 连接，共 " . $rounds . " 轮交替");
say("");

$ratios = [];
for ($i = 1; $i <= $rounds; $i++) {
    $a = measure($variants["mixed"], $conns, $secs, $loader);
    $b = measure($variants["string"], $conns, $secs, $loader);
    $r = ($b > 0) ? $a / $b : 0.0;
    if ($r > 0) {
        $ratios[] = $r;
    }
    say(sprintf(
        "   第%d轮  mixed=%s  string=%s  比值=%.3f",
        $i,
        number_format($a, 0),
        number_format($b, 0),
        $r
    ));
}

if ($ratios === []) {
    say("没有有效样本");
    exit(1);
}

sort($ratios);
$n = count($ratios);
$median = ($n % 2 === 1) ? $ratios[intdiv($n, 2)] : ($ratios[$n / 2 - 1] + $ratios[$n / 2]) / 2.0;

say("");
say("   中位比值: " . number_format($median, 3) . "x  →  mixed 相对 string "
    . (($median - 1.0) >= 0 ? "+" : "") . number_format(($median - 1.0) * 100, 1) . "%");

$min = $ratios[0];
$max = $ratios[$n - 1];
say("   比值区间: " . number_format($min, 3) . " ~ " . number_format($max, 3));
if ($max - $min > 0.15) {
    say("   ⚠ 区间过宽（>15%），这轮机器不稳，建议复测");
} else {
    say("   ✔ 区间稳定");
}
