<?php

/**
 * 探针：max_conn 与 PHP 内存记账的关系。
 *
 * 假设：Swoole 按 max_conn 预分配连接表，且这笔内存在 PHP 的
 * emalloc 记账里（因此吃 memory_limit），但实际页未被触碰（RSS 很低）。
 *
 * 用法: php85 probe-maxconn.php <max_conn>
 */

$maxConn = (int) ($argv[1] ?? 20000);

$server = new Swoole\Server("127.0.0.1", 9003);

$server->set([
    "worker_num" => 1,
    "reactor_num" => 1,
    "max_conn" => $maxConn,
    "backlog" => 8192,
    "buffer_output_size" => 65536,
    "enable_coroutine" => false,
    "log_level" => 4,
]);

$server->on("receive", function ($s, int $fd, int $rid, string $data): void {
    $s->send($fd, $data);
});

$server->on("workerStart", function ($s, int $wid) use ($maxConn): void {
    $emalloc = memory_get_usage(false);   // memory_limit 判定的口径
    $real = memory_get_usage(true);       // 实际向系统申请的页
    $rss = 0;
    $st = @file_get_contents("/proc/self/status");
    if ($st !== false) {
        if (preg_match('/VmRSS:\s+(\d+)/', $st, $m)) {
            $rss = (int) $m[1];
        }
    }
    fwrite(STDERR, sprintf(
        "max_conn=%-6d emalloc=%8.1f MB  real=%8.1f MB  rss=%8.1f MB  per_conn=%6.1f KB\n",
        $maxConn,
        $emalloc / 1048576,
        $real / 1048576,
        $rss / 1024,
        $maxConn > 0 ? ($emalloc / $maxConn / 1024) : 0
    ));
    $s->shutdown();
});

$server->start();
