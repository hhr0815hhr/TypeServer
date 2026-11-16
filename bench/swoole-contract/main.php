<?php

/**
 * Swoole 契约测试：验证框架对扩展的三条**关键假设**。
 *
 * 用法（run.sh 驱动，端口由它分配且**不重复使用**）：
 *   php85 main.php srv-big <port> <bytes>
 *   php85 main.php cli-big <port> <expect>
 *   php85 main.php srv-slow <port> <rounds>
 *   php85 main.php cli-slow <port> <holdSec>
 *   php85 main.php srv-shutdown <port>
 *   php85 main.php cli-shutdown <port>
 *
 * 只跑 php85（验的是扩展/内核行为：缓冲、flush、shutdown），和 AOT 无关。
 */

const HDR = 8;

function frame(int $msgId, string $body): string
{
    return pack("N", strlen($body) + HDR) . pack("N", $msgId) . $body;
}

function modeSrvBig(array $argv): void
{
    $port = (int) $argv[2];
    $bytes = (int) $argv[3];

    $srv = new Swoole\Server("127.0.0.1", $port);
    $srv->set(["worker_num" => 1, "log_level" => 1, "buffer_output_size" => 65536]);
    $srv->on("receive", function ($s, int $fd, int $rid, string $data) use ($bytes): void {
        try {
            $ok = $s->send($fd, frame(9001, str_repeat("x", $bytes)));
            echo "SEND_RET " . ($ok ? "true" : "false") . " bytes=" . $bytes . PHP_EOL;
        } catch (\Throwable $e) {
            echo "SEND_THREW " . get_class($e) . ": " . $e->getMessage() . PHP_EOL;
        }
    });
    $srv->start();
}

function modeCliBig(array $argv): void
{
    $port = (int) $argv[2];
    $expect = (int) $argv[3];

    $sock = @stream_socket_client("tcp://127.0.0.1:" . $port, $e1, $e2, 3);
    if ($sock === false) {
        echo "CLI_CONNECT_FAILED" . PHP_EOL;
        return;
    }
    stream_set_blocking($sock, false);
    fwrite($sock, frame(9000, "go"));

    $buf = "";
    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline) {
        $chunk = fread($sock, 1 << 20);
        if ($chunk !== false && $chunk !== "") {
            $buf .= $chunk;
            if (strlen($buf) >= $expect + HDR) {
                break;
            }
        }
        usleep(3000);
    }
    $got = strlen($buf);
    $declared = $got >= 4 ? unpack("N", substr($buf, 0, 4))[1] : 0;
    echo "CLI_GOT total=" . $got . " declared=" . $declared
        . " want=" . ($expect + HDR)
        . " intact=" . (($got === $expect + HDR && $declared === $expect + HDR) ? "yes" : "no")
        . PHP_EOL;
}

function modeSrvSlow(array $argv): void
{
    $port = (int) $argv[2];
    $rounds = (int) $argv[3];

    $srv = new Swoole\Server("127.0.0.1", $port);
    $srv->set(["worker_num" => 1, "log_level" => 1, "buffer_output_size" => 65536]);
    $srv->on("receive", function ($s, int $fd, int $rid, string $data) use ($rounds): void {
        $fails = 0;
        $firstFail = -1;
        $threw = 0;
        for ($i = 0; $i < $rounds; $i++) {
            try {
                $ok = $s->send($fd, frame(9002, str_repeat("y", 1024)));
                if (!$ok) {
                    $fails++;
                    if ($firstFail < 0) {
                        $firstFail = $i;
                    }
                }
            } catch (\Throwable $e) {
                $threw++;
                if ($firstFail < 0) {
                    $firstFail = $i;
                }
            }
        }
        echo "SLOW_RESULT rounds=" . $rounds . " fails=" . $fails
            . " threw=" . $threw . " first_bad=" . $firstFail . PHP_EOL;
    });
    $srv->start();
}

function modeCliSlow(array $argv): void
{
    $port = (int) $argv[2];
    $hold = isset($argv[3]) ? (float) $argv[3] : 6.0;

    $sock = @stream_socket_client("tcp://127.0.0.1:" . $port, $e1, $e2, 3);
    if ($sock === false) {
        echo "CLI_CONNECT_FAILED" . PHP_EOL;
        return;
    }
    fwrite($sock, frame(9000, "go"));
    usleep((int) ($hold * 1000000));   // 故意不读
    echo "CLI_HELD" . PHP_EOL;
}

function modeSrvShutdown(array $argv): void
{
    $port = (int) $argv[2];

    $srv = new Swoole\Server("127.0.0.1", $port);
    $srv->set(["worker_num" => 1, "log_level" => 1]);
    $srv->on("receive", function ($s, int $fd, int $rid, string $data): void {
        $ok = $s->send($fd, frame(9003, "final"));
        echo "SENT_FINAL " . ($ok ? "true" : "false") . PHP_EOL;
        $s->shutdown();
    });
    $srv->start();
}

function modeCliShutdown(array $argv): void
{
    $port = (int) $argv[2];

    $sock = @stream_socket_client("tcp://127.0.0.1:" . $port, $e1, $e2, 3);
    if ($sock === false) {
        echo "CLI_CONNECT_FAILED" . PHP_EOL;
        return;
    }
    stream_set_blocking($sock, false);
    fwrite($sock, frame(9000, "go"));

    $buf = "";
    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline) {
        $chunk = fread($sock, 4096);
        if ($chunk !== false && $chunk !== "") {
            $buf .= $chunk;
        } elseif (feof($sock)) {
            break;
        }
        usleep(3000);
    }
    echo "CLI_SHUTDOWN got=" . strlen($buf)
        . " final=" . (strpos($buf, "final") !== false ? "yes" : "no") . PHP_EOL;
}

function main(): void
{
    $argv = $_SERVER["argv"];
    $mode = isset($argv[1]) ? (string) $argv[1] : "help";
    if ($mode === "srv-big") { modeSrvBig($argv); return; }
    if ($mode === "cli-big") { modeCliBig($argv); return; }
    if ($mode === "srv-slow") { modeSrvSlow($argv); return; }
    if ($mode === "cli-slow") { modeCliSlow($argv); return; }
    if ($mode === "srv-shutdown") { modeSrvShutdown($argv); return; }
    if ($mode === "cli-shutdown") { modeCliShutdown($argv); return; }
    echo "usage: main.php <mode> ..." . PHP_EOL;
}

// 这个 bench 只跑解释器，不需要 AOT 的"入口由 tpc 调 main()"约定
main();
