<?php

/**
 * 背压测试：客户端只发不读，服务端发送缓冲区堆满后应断开它。
 *
 * 判定依据：服务端日志出现 "slow consumer, closing"。
 * 这比"客户端侧看起来断了"更可靠——FIN 可能滞后。
 *
 * 用法：php85 bp-test.php <port>
 */

require_once __DIR__ . "/../tsf/src/Protocol/Contract/FrameCodec.php";
require_once __DIR__ . "/../tsf/src/Protocol/Frame/HeaderFrame.php";
require_once __DIR__ . "/../tsf/src/Protocol/Msg.php";

use TSF\Protocol\Frame\HeaderFrame;
use TSF\Protocol\Msg;

$port = (int) ($argv[1] ?? 9001);
$rounds = (int) ($argv[2] ?? 3000);
$bodySize = (int) ($argv[3] ?? 4096);

$s = stream_socket_client("tcp://127.0.0.1:" . $port, $errno, $errstr, 5);
if ($s === false) {
    fwrite(STDERR, "connect failed: " . $errstr . PHP_EOL);
    exit(1);
}
stream_set_timeout($s, 5);

// 登录并读掉响应（后面就不读了）
fwrite($s, HeaderFrame::build(Msg::LOGIN, "tok-1001"));
$hdr = fread($s, 8);
if (strlen($hdr) < 8) {
    fwrite(STDERR, "login failed" . PHP_EOL);
    exit(1);
}
$len = HeaderFrame::u32($hdr, 0);
fread($s, $len - 8);
echo "登录完成，开始灌数据（" . $rounds . " × " . $bodySize . " B，期间不读回包）" . PHP_EOL;

$payload = str_repeat("x", $bodySize);
$frame = HeaderFrame::build(Msg::ECHO_REQ, $payload);
$sent = 0;
for ($i = 0; $i < $rounds; $i++) {
    $n = @fwrite($s, $frame);
    if ($n === false || $n < strlen($frame)) {
        break;
    }
    $sent++;
}

echo "已发送 " . $sent . " 帧，等待服务端触发背压..." . PHP_EOL;
sleep(3);

// 现在尝试读：若服务端已断开，会读到 EOF
stream_set_blocking($s, false);
$d = @fread($s, 65536);
$eof = feof($s);
echo "读取结果: " . ($d === false ? "false" : strlen((string) $d) . " 字节") . ", eof=" . ($eof ? "true" : "false") . PHP_EOL;

fclose($s);
echo "done" . PHP_EOL;
