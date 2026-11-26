<?php

use TSF\Kernel\Bootstrap;
use TSF\Kernel\LogFile;
use TSF\Kernel\Logger;

/**
 * 日志轮转 + 具名通道。三种模式（环境变量 LOGT）：
 *
 *   unit    LogFile 的轮转行为：到上限才转、层数平移、最老的删掉、不提前转、关掉就不转
 *   boot    经 Bootstrap 走配置：log.max_bytes / max_files 生效；gm 审计落到独立文件
 *   writer  往指定文件写一堆行（run.sh 同时起两个，验证多进程并发轮转）
 */
const LINE_BYTES = 100;

final class T
{
    public static int $pass = 0;

    public static int $fail = 0;

    public static function ok(bool $cond, string $label, string $detail = ""): void
    {
        if ($cond) {
            self::$pass++;
            echo "  OK   " . $label . PHP_EOL;
        } else {
            self::$fail++;
            echo "  FAIL " . $label . ($detail === "" ? "" : "  → " . $detail) . PHP_EOL;
        }
    }
}

/** 造一行固定 100 字节、带序号的日志（模拟 Logger 的输出） */
function line(int $n): string
{
    // 用空格而不是 0 补齐：00000001 不是合法 JSON
    $s = str_pad((string) $n, 8, " ", STR_PAD_LEFT);
    return "{\"n\":" . $s . ",\"pad\":\"" . str_repeat("x", LINE_BYTES - strlen("{\"n\":" . $s . ",\"pad\":\"") - 3) . "\"}\n";
}

/** @return array<int, int> 一个文件里每行的序号；有一行不是合法 JSON 就返回 [] */
function seqOf(string $file): array
{
    $raw = @file_get_contents($file);
    if (!is_string($raw) || $raw === "") {
        return [];
    }
    $out = [];
    foreach (explode("\n", $raw) as $l) {
        if ($l === "") {
            continue;
        }
        $j = json_decode($l, true);
        if (!is_array($j) || !isset($j["n"])) {
            return [];
        }
        $out[] = (int) $j["n"];
    }
    return $out;
}

function cleanDir(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
        return;
    }
    $list = scandir($dir);
    if (!is_array($list)) {
        return;
    }
    foreach ($list as $f) {
        if (is_string($f) && $f !== "." && $f !== "..") {
            @unlink($dir . "/" . $f);
        }
    }
}

function runUnit(): void
{
    $dir = "/tmp/tsf_logt";
    cleanDir($dir);
    $base = $dir . "/base.log";
    $max = 4096;

    echo "== [1] 写到上限就转，层数平移 ==" . PHP_EOL;
    $lf = new LogFile($base, $max, 3);
    for ($i = 0; $i < 400; $i++) {
        $lf->write(line($i));
    }
    T::ok(is_file($base), "当前文件在");
    T::ok(is_file($base . ".1"), "转出了 .1");
    T::ok(is_file($base . ".2"), "转出了 .2");
    T::ok(is_file($base . ".3"), "转出了 .3");
    T::ok(!is_file($base . ".4"), "没有 .4（最多留 3 份）");
    T::ok($lf->rotations() >= 5, "转了不止一次（40KB / 4KB）", "rotations=" . $lf->rotations());

    echo PHP_EOL . "== [2] 转走的层都是满的：没有提前转、也没有转出空层 ==" . PHP_EOL;
    // 文件最多超出上限一个检查间隔（max/16，下限 1KB）+ 一行
    $ceil = $max + 1024 + LINE_BYTES + 16;
    for ($i = 1; $i <= 3; $i++) {
        $sz = filesize($base . "." . $i);
        $sz = $sz === false ? -1 : $sz;
        T::ok($sz >= $max, "." . $i . " 是满的（≥ 上限）", "size=" . $sz);
        T::ok($sz <= $ceil, "." . $i . " 没超得离谱（≤ 上限 + 检查间隔）", "size=" . $sz);
    }
    $szBase = filesize($base);
    $szBase = $szBase === false ? -1 : $szBase;
    T::ok($szBase <= $ceil, "当前文件也没超得离谱", "size=" . $szBase);
    T::ok($szBase >= 0, "当前文件大小可读");

    echo PHP_EOL . "== [3] 内容不乱：各层按时间从旧到新，层内连续 ==" . PHP_EOL;
    // 最旧的是 .3，然后 .2、.1，最后是当前文件
    $oldest = seqOf($base . ".3");
    $mid = seqOf($base . ".2");
    $newer = seqOf($base . ".1");
    $cur = seqOf($base);
    T::ok($oldest !== [] && $mid !== [] && $newer !== [], "每个文件都是合法的 JSON 行");
    $contiguous = true;
    foreach ([$oldest, $mid, $newer, $cur] as $part) {
        $prev = -1;
        foreach ($part as $n) {
            if ($prev >= 0 && $n !== $prev + 1) {
                $contiguous = false;
            }
            $prev = $n;
        }
    }
    T::ok($contiguous, "层内序号连续（一行没丢、没重复）");
    $lastOf = function (array $a): int {
        return $a === [] ? -1 : $a[count($a) - 1];
    };
    T::ok($oldest[0] < $mid[0] && $mid[0] < $newer[0] && $newer[0] < $cur[0], "从 .3 → .2 → .1 → 当前，越靠后越新");
    T::ok($lastOf($oldest) < $mid[0] && $lastOf($mid) < $newer[0] && $lastOf($newer) < $cur[0], "层与层之间也接得上");
    eqInt($cur[count($cur) - 1], 399, "最后写的那条（399）在当前文件末尾");
    // 最老的那些层被删掉了：所以第一行不是 0
    T::ok($oldest[0] > 0, "超过保留份数的最老的层确实被删了", "最早保留的序号=" . $oldest[0]);

    echo PHP_EOL . "== [4] 关掉轮转就不转 ==" . PHP_EOL;
    $plain = $dir . "/plain.log";
    $lf2 = new LogFile($plain, 0, 3);
    for ($i = 0; $i < 1500; $i++) {
        $lf2->write(line($i));
    }
    T::ok(!is_file($plain . ".1"), "上限 0：写了 150KB 也不转");
    T::ok(count(seqOf($plain)) === 1500, "内容原样都在");

    echo PHP_EOL . "== [5] 文件名为空 = 什么都不做 ==" . PHP_EOL;
    $lf3 = new LogFile("", 1024, 3);
    $lf3->write("whatever\n");
    T::ok(true, "空文件名不报错（纯 stdout 部署）");

    echo PHP_EOL . "== [6] 从没 init() 过的 Logger 也能写 ==" . PHP_EOL;
    $threw = false;
    try {
        Logger::info("before init");
        Logger::to("nope", Logger::WARN, "no channel before init");
        Logger::rotations();
    } catch (\Throwable $e) {
        $threw = true;
        echo "     " . $e->getMessage() . PHP_EOL;
    }
    T::ok(!$threw, "init() 之前调用 Logger 不抛异常");
}

function eqInt(int $got, int $want, string $label): void
{
    T::ok($got === $want, $label, "got=" . $got . " want=" . $want);
}

function runBoot(): void
{
    Bootstrap::boot(__DIR__, "app");
    $dir = __DIR__ . "/var/log";
    $app = $dir . "/app.log";
    echo "== [B1] 配置里的 log.max_bytes / max_files 生效 ==" . PHP_EOL;
    T::ok(is_file($app), "app.log 在", $app);
    for ($i = 0; $i < 400; $i++) {
        Logger::info("filler", ["i" => $i, "pad" => str_repeat("y", 40)]);
    }
    clearstatcache();
    T::ok(is_file($app . ".1"), "转出了 .1");
    T::ok(is_file($app . ".2"), "转出了 .2");
    T::ok(!is_file($app . ".3"), "没有 .3（max_files=2）");
    T::ok(Logger::rotations() >= 3, "轮转计数", "rotations=" . Logger::rotations());

    echo PHP_EOL . "== [B2] gm 审计落到独立文件 ==" . PHP_EOL;
    T::ok(Logger::hasChannel("gm"), "gm 通道建好了");
    $gm = $dir . "/gm_audit.log";
    Logger::to("gm", Logger::INFO, "gm: player.kick", ["op" => "zhangsan", "uid" => 1001]);
    Logger::to("gm", Logger::WARN, "gm: player.add_gold", ["op" => "lisi", "err" => "nope"]);
    $gmRaw = (string) @file_get_contents($gm);
    $appRaw = (string) @file_get_contents($app);
    T::ok(strpos($gmRaw, '"msg":"gm: player.kick"') !== false, "审计记录写进了 gm_audit.log");
    T::ok(strpos($gmRaw, '"ch":"gm"') !== false, "通道名是 gm");
    T::ok(strpos($gmRaw, '"op":"zhangsan"') !== false, "操作人字段在");
    T::ok(strpos($appRaw, "gm: player.kick") === false, "审计记录**没有**混进主日志");
    T::ok(strpos($gmRaw, '"lvl":"warn"') !== false, "失败的指令是 warn 级");

    echo PHP_EOL . "== [B3] 通道没建过 → 落回主日志；通道有自己的级别 ==" . PHP_EOL;
    Logger::to("nope", Logger::INFO, "fallback-line");
    $appRaw2 = (string) @file_get_contents($app);
    T::ok(strpos($appRaw2, "fallback-line") !== false, "没建的通道回落到主日志（不丢）");
    Logger::initChannel("q", $dir . "/q.log", Logger::WARN);
    Logger::to("q", Logger::INFO, "q-info-dropped");
    Logger::to("q", Logger::WARN, "q-warn-kept");
    $qRaw = (string) @file_get_contents($dir . "/q.log");
    T::ok(strpos($qRaw, "q-info-dropped") === false, "低于通道阈值的丢弃");
    T::ok(strpos($qRaw, "q-warn-kept") !== false, "达到阈值的保留");
}

function runWriter(string $file, int $lines): void
{
    $lf = new LogFile($file, 65536, 4);
    $pid = posix_getpid();
    for ($i = 0; $i < $lines; $i++) {
        $lf->write("{\"w\":" . $pid . ",\"n\":" . $i . "}\n");
    }
}

/** 两个 writer 都跑完之后检查：并发轮转没有转出空层、没有坏行 */
function runCheck(string $file): void
{
    echo "== [C] 两个进程并发写同一个文件 ==" . PHP_EOL;
    $files = 0;
    $sizes = [];
    for ($i = 1; $i <= 6; $i++) {
        if (is_file($file . "." . $i)) {
            $files++;
            $sz = filesize($file . "." . $i);
            $sizes[] = $sz === false ? -1 : $sz;
        }
    }
    T::ok($files <= 4, "层数不超过 max_files", "files=" . $files);
    $allFull = true;
    $minSize = PHP_INT_MAX;
    foreach ($sizes as $sz) {
        if ($sz < 65536) {
            $allFull = false;
        }
        if ($sz < $minSize) {
            $minSize = $sz;
        }
    }
    T::ok($allFull, "每一层都是满的（没有因为并发而转出小文件）", "min=" . $minSize . " sizes=" . implode(",", $sizes));

    $valid = true;
    $perWorker = [];
    $paths = [];
    for ($i = 4; $i >= 1; $i--) {
        if (is_file($file . "." . $i)) {
            $paths[] = $file . "." . $i;
        }
    }
    $paths[] = $file;
    foreach ($paths as $p) {
        $raw = (string) @file_get_contents($p);
        foreach (explode("\n", $raw) as $l) {
            if ($l === "") {
                continue;
            }
            $j = json_decode($l, true);
            if (!is_array($j) || !isset($j["w"]) || !isset($j["n"])) {
                $valid = false;
                continue;
            }
            $w = (int) $j["w"];
            if (!isset($perWorker[$w])) {
                $perWorker[$w] = 0;
            }
            $perWorker[$w]++;
        }
    }
    T::ok($valid, "没有坏行（两个进程的追加写没有互相截断）");
    T::ok(count($perWorker) === 2, "两个进程的记录都在", "workers=" . count($perWorker));
}

function main(): void
{
    $mode = (string) getenv("LOGT");
    if ($mode === "boot") {
        runBoot();
    } elseif ($mode === "writer") {
        runWriter((string) getenv("LOGT_FILE"), 40000);
    } elseif ($mode === "check") {
        runCheck((string) getenv("LOGT_FILE"));
    } else {
        runUnit();
    }
    echo PHP_EOL . "通过 " . T::$pass . " / 失败 " . T::$fail . PHP_EOL;
    if (T::$fail > 0) {
        exit(1);
    }
}
