<?php

/**
 * 单元测试入口：不编译、不起服务、不需要网络。
 *
 *     php85 tests/run.php
 *
 * 每个测试文件用 `return true/false` 报告结果，这里只做汇总。
 * 要测 Service 调度、协议、网络，用框架的 `tsf verify`（它起真进程）。
 */

$files = glob(__DIR__ . "/unit/*Test.php");
if ($files === false) {
    echo "读不到 tests/unit/\n";
    exit(1);
}
if ($files === []) {
    echo "tests/unit/ 下没有 *Test.php\n";
    exit(0);
}

sort($files);
$failedFiles = 0;
$ran = 0;

foreach ($files as $f) {
    echo "== " . basename($f) . " ==" . PHP_EOL;
    $ok = require $f;
    $ran++;
    if ($ok !== true) {
        $failedFiles++;
    }
}

echo PHP_EOL . "运行 " . $ran . " 个文件，失败 " . $failedFiles . PHP_EOL;
exit($failedFiles === 0 ? 0 : 1);
