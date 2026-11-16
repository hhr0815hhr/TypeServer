#!/usr/bin/env php85
<?php

/**
 * 性能测量 harness。
 *
 * 存在的理由：我在 codec 那次测量里犯过错——每个变体只跑一次、顺序跑，
 * 结果把机器漂移记成了"提升 55%"，实际只有 14%。
 *
 * 这个工具把正确做法固化下来：
 *   1. 交替采样（A → B → A → B …），抵消机器状态漂移
 *   2. 多轮默认 5 次
 *   3. MAD 剔除离群值
 *   4. 离散度过大时**直接标红警告**，不给出结论
 *   5. 原始采样落 JSON，便于事后复核
 *
 * 用法：
 *   php85 bench.php list
 *   php85 bench.php run codec [--reps=5] [--secs=5] [--conns=32] [--json=out.json]
 */

require_once __DIR__ . "/stats.php";

const CV_WARN = 0.15;

function arg(array $argv, string $name, string $def): string
{
    foreach ($argv as $a) {
        if (strpos($a, "--" . $name . "=") === 0) {
            return substr($a, strlen($name) + 3);
        }
    }
    return $def;
}

function say(string $s): void
{
    echo $s . PHP_EOL;
}

function cpu_ticks(int $pid): int
{
    $stat = @file_get_contents("/proc/" . $pid . "/stat");
    if ($stat === false) {
        return 0;
    }
    // 字段 14/15 是 utime/stime（跳过 comm 里的空格：从最后一个 ')' 之后数）
    $pos = strrpos($stat, ")");
    if ($pos === false) {
        return 0;
    }
    $rest = trim(substr($stat, $pos + 2));
    $f = explode(" ", $rest);
    if (count($f) < 15) {
        return 0;
    }
    return (int) $f[11] + (int) $f[12];
}

function wait_tcp(string $host, int $port, float $timeoutSec = 10.0): bool
{
    $deadline = microtime(true) + $timeoutSec;
    while (microtime(true) < $deadline) {
        $s = @fsockopen($host, $port, $errno, $errstr, 0.1);
        if ($s !== false) {
            fclose($s);
            return true;
        }
        usleep(50000);
    }
    return false;
}

/**
 * 等端口真正空出来。
 *
 * 踩过的坑：上一轮的服务进程被 kill 后，端口未释放时新进程 bind 失败直接退出，
 * 而 wait_tcp 仍然能连上（连到了正在退出的旧进程），于是压测打在一个将死的服务上，
 * 那一轮吞吐异常低——看起来像"机器抖动"，实际是测量脚本的问题。
 */
function wait_port_free(string $host, int $port, float $timeoutSec = 8.0): bool
{
    $deadline = microtime(true) + $timeoutSec;
    while (microtime(true) < $deadline) {
        $s = @fsockopen($host, $port, $errno, $errstr, 0.05);
        if ($s === false) {
            return true;
        }
        fclose($s);
        usleep(50000);
    }
    return false;
}

/** 把嵌套键写进 JSON 配置（config.patch: {"protocol.frame": "native"}） */
function patch_config(string $file, array $patch): void
{
    $raw = @file_get_contents($file);
    $cfg = ($raw === false || $raw === "") ? [] : json_decode($raw, true);
    if (!is_array($cfg)) {
        $cfg = [];
    }
    foreach ($patch as $path => $val) {
        $parts = explode(".", $path);
        $cur = &$cfg;
        $last = count($parts) - 1;
        for ($i = 0; $i <= $last; $i++) {
            if ($i === $last) {
                $cur[$parts[$i]] = $val;
            } else {
                if (!isset($cur[$parts[$i]]) || !is_array($cur[$parts[$i]])) {
                    $cur[$parts[$i]] = [];
                }
                $cur = &$cur[$parts[$i]];
            }
        }
        unset($cur);
    }
    file_put_contents($file, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

function stop_server(array $srv): void
{
    if (isset($GLOBALS["__proc"]) && is_resource($GLOBALS["__proc"])) {
        @proc_terminate($GLOBALS["__proc"], 15);
        usleep(200000);
        @proc_terminate($GLOBALS["__proc"], 9);
        @proc_close($GLOBALS["__proc"]);
        $GLOBALS["__proc"] = null;
    }
    exec("pkill -9 -x " . escapeshellarg($srv["proc"]) . " 2>/dev/null");
    usleep(200000);
}

/** 起服务 + 跑一次压测，返回 ['rate'=>, 'us'=>, 'p50'=>, 'p99'=>] */
function run_once(array $srv, array $loader, int $conns, int $secs): array
{
    stop_server($srv);
    // 必须先确认端口空出来，否则新进程 bind 失败，压测会打到正在退出的旧进程上
    if (!wait_port_free("127.0.0.1", (int) $srv["port"], 8.0)) {
        say("    ! 端口 " . $srv["port"] . " 一直被占用，跳过本轮");
        return ["rate" => 0.0, "us" => 0.0, "p50" => 0.0, "p99" => 0.0];
    }

    $log = "/tmp/bench_srv.log";
    @unlink($log);
    $desc = [0 => ["file", "/dev/null", "r"], 1 => ["file", $log, "a"], 2 => ["file", $log, "a"]];
    $proc = proc_open([$srv["bin"]], $desc, $pipes, $srv["cwd"]);
    if (!is_resource($proc)) {
        return ["rate" => 0.0, "us" => 0.0, "p50" => 0.0, "p99" => 0.0];
    }
    $GLOBALS["__proc"] = $proc;
    $st = proc_get_status($proc);
    $pid = (int) $st["pid"];

    if (!wait_tcp("127.0.0.1", (int) $srv["port"], 10.0)) {
        say("    ! 服务端起不来，日志：" . (@file_get_contents($log) ?: "(空)"));
        stop_server($srv);
        return ["rate" => 0.0, "us" => 0.0, "p50" => 0.0, "p99" => 0.0];
    }
    // 再确认一次：进程还活着（排除"连上的是别的进程"这种假就绪）
    $st2 = proc_get_status($proc);
    if (!$st2["running"]) {
        say("    ! 服务进程已退出（bind 失败？），日志：" . (substr((string) @file_get_contents($log), 0, 200)));
        stop_server($srv);
        return ["rate" => 0.0, "us" => 0.0, "p50" => 0.0, "p99" => 0.0];
    }
    usleep(300000);

    $c0 = cpu_ticks($pid);
    $cmd = $loader["bin"] . " 127.0.0.1 " . (int) $srv["port"] . " " . $conns . " " . $secs;
    $out = shell_exec($cmd . " 2>&1");
    $c1 = cpu_ticks($pid);
    $aliveAfter = proc_get_status($proc)["running"];
    stop_server($srv);

    $rate = 0.0;
    $p50 = 0.0;
    $p99 = 0.0;
    $recv = 0.0;
    if (is_string($out) && preg_match('/recv=(\d+).*rate=([0-9.]+).*p50=([0-9.]+)ms.*p99=([0-9.]+)ms/', $out, $m)) {
        $recv = (float) $m[1];
        $rate = (float) $m[2];
        $p50 = (float) $m[3];
        $p99 = (float) $m[4];
    }
    $us = 0.0;
    if ($recv > 0) {
        $us = ($c1 - $c0) * 10000.0 / $recv;
    }
    if (!$aliveAfter) {
        say("    ! 压测期间服务进程退出，本轮数据作废");
        return ["rate" => 0.0, "us" => 0.0, "p50" => 0.0, "p99" => 0.0];
    }
    return ["rate" => $rate, "us" => $us, "p50" => $p50, "p99" => $p99];
}

function fmt(float $v, int $dec = 0): string
{
    return number_format($v, $dec);
}

function delta(float $a, float $b, string $better = "higher"): string
{
    if ($a <= 0.0) {
        return "-";
    }
    $p = ($b - $a) * 100.0 / $a;
    $sign = $p >= 0 ? "+" : "";
    return $sign . number_format($p, 1) . "%";
}

// ---------------- 主流程 ----------------

$argvv = $_SERVER["argv"] ?? [];
$cmd = $argvv[1] ?? "help";
$scenarios = require __DIR__ . "/scenarios.php";

if ($cmd === "list") {
    say("可用场景：");
    foreach ($scenarios as $name => $sc) {
        $vs = implode(" / ", array_keys($sc["variants"]));
        say("  " . $name . "  — " . $sc["desc"] . "  [" . $vs . "]");
    }
    exit(0);
}

if ($cmd !== "run") {
    say("用法:");
    say("  php85 bench.php list");
    say("  php85 bench.php run <scenario> [--reps=5] [--secs=5] [--conns=32] [--json=out.json]");
    exit(0);
}

$name = $argvv[2] ?? "";
if (!isset($scenarios[$name])) {
    say("未知场景: " . $name . "（用 list 查看）");
    exit(1);
}
$sc = $scenarios[$name];
$reps = (int) arg($argvv, "reps", "5");
$secs = (int) arg($argvv, "secs", "5");
$conns = (int) arg($argvv, "conns", (string) ($sc["loader"]["conns"] ?? 32));
$jsonOut = arg($argvv, "json", "");

$variants = array_keys($sc["variants"]);

// 备份原始配置：结束时必须逐字节还原。
// 早期版本"恢复成第一个变体的值"，结果压测后工程停留在压测配置里
// （关掉了鉴权、限流），下次手工起服务会以为功能坏了。
$cfgPath = $sc["server"]["config"];
$cfgBackup = @file_get_contents($cfgPath);

say("== 场景: " . $name . " — " . $sc["desc"] . " ==");
say("   轮次 " . $reps . " · 每轮 " . $secs . "s · 连接 " . $conns . " · 变体 " . implode(" / ", $variants));
say("");

$samples = [];
foreach ($variants as $v) {
    $samples[$v] = ["rate" => [], "us" => [], "p50" => [], "p99" => []];
}

// 交替采样：每轮把每个变体都跑一次，顺序固定（A,B,A,B...），
// 这样机器漂移会均摊到所有变体上，而不是集中在某个变体
say("   轮次 | " . implode(" | ", array_map(fn ($v) => sprintf("%-22s", $v), $variants)));
for ($r = 1; $r <= $reps; $r++) {
    $line = sprintf("   %4d", $r);
    foreach ($variants as $v) {
        $patch = $sc["variants"][$v]["set"] ?? [];
        if ($patch !== []) {
            patch_config($sc["server"]["config"], $patch);
        }
        $loader = $sc["loader"];
        if (isset($sc["variants"][$v]["loader"])) {
            $loader["bin"] = $sc["variants"][$v]["loader"];
        }
        $m = run_once($sc["server"], $loader, $conns, $secs);
        $samples[$v]["rate"][] = $m["rate"];
        $samples[$v]["us"][] = $m["us"];
        $samples[$v]["p50"][] = $m["p50"];
        $samples[$v]["p99"][] = $m["p99"];
        $line .= " | " . sprintf("%-8s %5.1f us/msg  ", fmt($m["rate"]), $m["us"]);
    }
    say($line);
}

say("");
say("   指标              " . implode("   ", array_map(fn ($v) => sprintf("%-14s", $v), $variants)) . "   差异(最后-首个)");

$summary = [];
$metricNames = ["rate" => ["吞吐 msg/s", 0], "us" => ["CPU/消息 us", 2], "p50" => ["p50 ms", 2], "p99" => ["p99 ms", 2]];
$maxCv = 0.0;

foreach ($metricNames as $mk => $info) {
    $line = "   " . sprintf("%-16s", $info[0]);
    $med = [];
    foreach ($variants as $v) {
        $rej = Stats::rejectOutliers($samples[$v][$mk]);
        $m = Stats::median($rej["kept"]);
        $cv = Stats::cv($rej["kept"]);
        if ($cv > $maxCv) {
            $maxCv = $cv;
        }
        $med[$v] = $m;
        $line .= sprintf("%-16s", fmt($m, $info[1]));
        $summary[$v][$mk] = $m;
        $summary[$v][$mk . "_cv"] = $cv;
        $summary[$v][$mk . "_dropped"] = count($rej["dropped"]);
    }
    $first = $med[$variants[0]] ?? 0.0;
    $last = $med[$variants[count($variants) - 1]] ?? 0.0;
    $line .= "   " . delta($first, $last);
    say($line);
}

// ---- 配对比较：每轮的比值，抵消全机共同漂移 ----
// 单看绝对中位数会被"某一轮机器整体变慢"带偏（两个变体一起掉），
// 而同一轮内 A/B 是紧邻执行的，做比值能把共同漂移约掉。
$paired = [];
if (count($variants) === 2) {
    $a = $variants[0];
    $b = $variants[1];
    say("");
    say("   配对比较（每轮 " . $b . " / " . $a . "）：");
    $ratios = [];
    for ($i = 0; $i < $reps; $i++) {
        $ra = $samples[$a]["rate"][$i] ?? 0.0;
        $rb = $samples[$b]["rate"][$i] ?? 0.0;
        if ($ra <= 0.0 || $rb <= 0.0) {
            continue;
        }
        $ratio = $rb / $ra;
        $ratios[] = $ratio;
        say("     第" . ($i + 1) . "轮: " . fmt($ra) . " → " . fmt($rb) . "  = " . number_format($ratio, 3) . "x");
    }
    if ($ratios !== []) {
        $rej = Stats::rejectOutliers($ratios, 2.5);
        $mr = Stats::median($rej["kept"]);
        $mrAll = Stats::median($ratios);
        $cv = Stats::cv($rej["kept"]);
        $dropRatio = count($rej["dropped"]) / count($ratios);
        say("     中位比值: " . number_format($mr, 3) . "x  →  " . $b . " 相对 " . $a . " "
            . (($mr - 1.0) >= 0 ? "+" : "") . number_format(($mr - 1.0) * 100, 1) . "%"
            . "   （未剔离群值: " . number_format($mrAll, 3) . "x = "
            . (($mrAll - 1.0) >= 0 ? "+" : "") . number_format(($mrAll - 1.0) * 100, 1) . "%）");
        say("     剔除 " . count($rej["dropped"]) . "/" . count($ratios) . " 轮   离散度(剔除后): "
            . number_format($cv * 100, 1) . "%");
        if ($dropRatio >= 0.3) {
            say("     ⚠ 剔除比例过高（≥30%），剔除后的离散度会偏乐观；请以\"未剔离群值\"那行为准，并复测。");
        }
        $paired = ["a" => $a, "b" => $b, "ratio" => $mr, "ratio_all" => $mrAll, "cv" => $cv,
            "dropped" => count($rej["dropped"]), "n" => count($ratios)];
    }
}

say("");
say("   稳定性: 最大绝对离散度 " . number_format($maxCv * 100, 1) . "%（阈值 " . (CV_WARN * 100) . "%）");
if ($maxCv > CV_WARN) {
    say("   ⚠ 离散度过大，绝对值不可用；请只参考上面的配对比值，并考虑复测。");
} else {
    say("   ✔ 离散度合格，结果可用。");
}

if ($jsonOut !== "") {
    $dir = dirname($jsonOut);
    if ($dir !== "" && !is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $payload = [
        "scenario" => $name,
        "desc" => $sc["desc"],
        "ts" => date("c"),
        "params" => ["reps" => $reps, "secs" => $secs, "conns" => $conns, "variants" => $variants],
        "samples" => $samples,
        "summary" => $summary,
        "paired" => $paired,
        "max_cv" => $maxCv,
        "reliable" => $maxCv <= CV_WARN,
    ];
    $ok = @file_put_contents($jsonOut, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    say("");
    say("   " . ($ok === false ? "! 保存失败: " . $jsonOut : "原始采样已保存: " . $jsonOut));
}

// 还原原始配置（逐字节）
if (is_string($cfgBackup)) {
    @file_put_contents($cfgPath, $cfgBackup);
    say("");
    say("   配置已还原: " . $cfgPath);
}
