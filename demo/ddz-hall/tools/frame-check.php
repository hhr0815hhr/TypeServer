<?php

/**
 * 一次性验证：JS 编出来的帧，PHP 能不能解。
 *
 * 用途是证明 web/index.html 里那套 buildFrame（8 字节头）和框架的 HeaderFrame
 * 是同一个格式 —— 前端连不上时，可以先用它排除"协议没对齐"这个可能。
 */

require_once __DIR__ . "/../../../tsf/src/Protocol/Contract/DecodedFrame.php";
require_once __DIR__ . "/../../../tsf/src/Protocol/Contract/FrameCodec.php";
require_once __DIR__ . "/../../../tsf/src/Protocol/Frame/HeaderFrame.php";

// node 那边 buildFrame(1, "tok-a") 的输出
$hex = "0000000d00010000746f6b2d61";

$f = TSF\Protocol\Frame\HeaderFrame::parse(hex2bin($hex));
if ($f === null) {
    echo "解析失败\n";
    exit(1);
}

echo "msgId=" . $f->msgId . " body=" . $f->body . " consumed=" . $f->consumed . "\n";
echo ($f->msgId === 1 && $f->body === "tok-a" && $f->consumed === 13)
    ? "PASS 前后端帧格式一致\n"
    : "FAIL 不一致\n";
