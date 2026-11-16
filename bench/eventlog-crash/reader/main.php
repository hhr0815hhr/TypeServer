<?php

/**
 * 验证崩溃后的日志：条数、连续性、有没有坏行。
 *
 * 这是 T4-04 验收线"1,000 笔交易 kill 后零丢失"的执行方。
 */

use TSF\EventLog\EventLog;

function main(): void
{
    $path = "/tmp/tsf_eventlog_crash.log";

    if (!file_exists($path)) {
        echo "FAIL 日志文件不存在" . PHP_EOL;
        return;
    }

    $size = filesize($path);
    $log = new EventLog($path);

    $rows = $log->replay();
    $n = count($rows);
    $bad = $log->badLines();

    echo "文件大小: " . number_format(is_int($size) ? $size : 0) . " 字节" . PHP_EOL;
    echo "重放条数: " . $n . PHP_EOL;
    echo "坏行数: " . $bad . PHP_EOL;
    echo "last_seq: " . $log->lastSeq() . PHP_EOL;

    // 序号必须从 1 连续到 n，中间不能有洞
    $gaps = 0;
    $prev = 0;
    foreach ($rows as $r) {
        $s = $r["s"];
        if ($s !== $prev + 1) {
            $gaps++;
        }
        $prev = $s;
    }
    echo "序号断点: " . $gaps . PHP_EOL;

    // 校验内容：每一笔的 id 和数据都对得上
    $wrong = 0;
    foreach ($rows as $r) {
        $s = $r["s"];
        if ($r["id"] !== "trade-" . $s) {
            $wrong++;
        }
        $d = $r["d"];
        if (!isset($d["from"]) || (int) $d["from"] !== $s) {
            $wrong++;
        }
    }
    echo "内容不符: " . $wrong . PHP_EOL;

    $ok = ($n === 1000) && ($bad === 0) && ($gaps === 0) && ($wrong === 0);
    if ($ok) {
        echo "PASS 1000 笔交易零丢失" . PHP_EOL;
    } else {
        echo "FAIL 验收不通过" . PHP_EOL;
    }

    $log->close();
}
