<?php

/**
 * 裸 Swoole 对照实验：不经过 TSF，只做 echo。
 *
 * 目的：把「8,500 连接 OOM」拆成两个可能——Swoole/环境，还是 TSF 的会话层。
 * 配置刻意与 TcpServer.php 保持一致（max_conn / backlog / buffer / 协程全关）。
 */

$port = (int) ($argv[1] ?? 9002);
$maxConn = (int) ($argv[2] ?? 20000);
$bufSize = (int) ($argv[3] ?? 65536);
$sockBuf = (int) ($argv[4] ?? 0);     // 0 = 不显式设置，用 Swoole 默认
$inBuf = (int) ($argv[5] ?? 0);
$workers = (int) ($argv[6] ?? 1);

$server = new Swoole\Server("127.0.0.1", $port);

$setting = [
    "worker_num" => $workers,
    "reactor_num" => $workers,
    "max_conn" => $maxConn,
    "backlog" => 8192,
    "buffer_output_size" => $bufSize,
    "enable_coroutine" => false,
    "open_cpu_affinity" => false,
    "log_level" => 4,
];

if ($sockBuf > 0) {
    $setting["socket_buffer_size"] = $sockBuf;
}
if ($inBuf > 0) {
    $setting["input_buffer_size"] = $inBuf;
    $setting["buffer_input_size"] = $inBuf;
}
$server->set($setting);

$cnt = 0;
$server->on("connect", function ($s, int $fd, int $rid) use (&$cnt): void {
    $cnt++;
    if ($cnt % 1000 === 0) {
        $st = @file_get_contents("/proc/self/status");
        $rss = 0;
        if ($st !== false) {
            if (preg_match('/VmRSS:\s+(\d+)/', $st, $m)) {
                $rss = (int) $m[1];
            }
        }
        $em = memory_get_usage(false);
        fwrite(STDERR, sprintf(
            "[bare] w=%d conn=%-5d emalloc=%7.1f MB  rss=%7.1f MB\n",
            $s->worker_id,
            $cnt,
            $em / 1048576,
            $rss / 1024
        ));
    }
});

$first = true;
$server->on("receive", function ($s, int $fd, int $rid, string $data) use (&$first): void {
    if ($first) {
        $first = false;
        // cid = -1 说明回调没在协程里跑（enable_coroutine=false 生效）
        fwrite(STDERR, "[bare] first receive coroutine cid=" . Swoole\Coroutine::getCid() . PHP_EOL);
    }
    $s->send($fd, $data);
});

$server->on("workerStart", function ($s, int $wid): void {
    $st = $s->stats();
    fwrite(STDERR, "[bare] worker=" . $wid
        . " mem_limit=" . ini_get("memory_limit")
        . " mem=" . (string) memory_get_usage(true)
        . " conns=" . (string) $st["connection_num"] . PHP_EOL);
});

$server->start();
