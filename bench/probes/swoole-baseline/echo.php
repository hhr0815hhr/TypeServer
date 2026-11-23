<?php
/**
 * Swoole 原生 TCP echo 基线（不含 TSF）。
 *
 * ## 为什么需要这个探针
 *
 * 评审 §2.3 记的是"网络端 29 µs/条、3.4 万 msg/s @32 连接"，并据此把 TSF 的
 * 网络吞吐打成 5 分。但这个数字是 **closed-loop** 压测：吞吐 = 连接数 / RTT，
 * 而 RTT 里包含客户端 syscall、内核路径、事件循环唤醒——**跟 TSF 的代码无关**。
 *
 * 所以要先问一句：**同样的压测端打一个什么都不做的 Swoole echo，能跑多少？**
 * 如果基线也是 3 万多，那 5 分打的是 WSL2 + closed-loop 的环境上限，不是 TSF 的短处。
 *
 * ## 用法
 *
 *   php85 echo.php [port]          # 默认 9301
 *   ./load_tsf 127.0.0.1 9301 32 5
 *
 * 注意：这是**裸 PHP 脚本**（解释执行 + Swoole 扩展），不经过 tpc AOT。
 * 对"网络路径"这个命题来说这是对的对照——测的是 Swoole 收发，不是 PHP 逻辑计算。
 */
$port = isset($argv[1]) ? (int) $argv[1] : 9301;

$server = new Swoole\Server("127.0.0.1", $port);
$server->set([
    "worker_num" => 1,
    "open_tcp_nodelay" => true,
    "backlog" => 8192,
    "log_level" => 4,
]);

$server->on("receive", function (Swoole\Server $srv, int $fd, int $reactorId, string $data): void {
    // 原样回写：和 TSF 的 echo 路径在"协议层面"等价（都不解析载荷）
    $srv->send($fd, $data);
});

$server->on("workerStart", function (Swoole\Server $srv, int $workerId): void {
    fwrite(STDERR, "swoole-baseline ready on port " . $srv->port . " (worker " . $workerId . ")\n");
});

$server->start();
