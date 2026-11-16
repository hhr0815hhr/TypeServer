<?php

/**
 * 写 1000 笔交易，然后**挂着不退出**，等外面 kill -9。
 *
 * 刻意不 close()：一旦 close 就会 flush + 关句柄，那就测不出
 * "进程被强杀时数据还在不在"了。真正的保证来自每条 append 之后的 fflush。
 */

use TSF\EventLog\EventLog;

function main(): void
{
    $path = "/tmp/tsf_eventlog_crash.log";
    $done = "/tmp/tsf_eventlog_crash.done";

    if (file_exists($path)) {
        unlink($path);
    }
    if (file_exists($done)) {
        unlink($done);
    }

    $log = new EventLog($path, EventLog::MODE_FLUSH);

    for ($i = 1; $i <= 1000; $i++) {
        $log->append("trade-" . $i, "transfer", [
            "from" => $i,
            "to" => $i + 1,
            "amount" => 10,
        ]);
    }

    // 通知外面"写完了，可以杀了"
    file_put_contents($done, (string) $log->lastSeq());

    // 挂住等死（60 秒兜底，避免脚本出错时留下僵尸）
    $spin = 0;
    while ($spin < 600) {
        usleep(100000);
        $spin++;
    }

    // 走不到这里，除非没人杀
    $log->close();
    echo "writer: 没有被杀掉，正常退出" . PHP_EOL;
}
