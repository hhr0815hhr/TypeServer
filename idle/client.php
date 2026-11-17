<?php
// protobuf 客户端。用法: php85 client.php [host] [port]
//
// 手写帧（| len 4B 大端 | msgId 2B | flags 1B | pad 1B | body |），
// 载荷用 gen/Proto 里生成的类编解码 —— 和跑在服务端的那套是同一份代码。

spl_autoload_register(function (string $cls): void {
    $map = [
        "Gen\\Proto\\" => __DIR__ . "/gen/Proto/",
        "TSF\\Proto\\" => "/data/TypeServer/tsf/src/Proto/",
    ];
    foreach ($map as $prefix => $dir) {
        if (strncmp($cls, $prefix, strlen($prefix)) === 0) {
            $rel = substr($cls, strlen($prefix));
            $file = $dir . str_replace("\\", "/", $rel) . ".php";
            if (is_file($file)) {
                require_once $file;
            }
            return;
        }
    }
});

$host = $argv[1] ?? "127.0.0.1";
$port = (int) ($argv[2] ?? 9501);

$sock = @stream_socket_client("tcp://" . $host . ":" . $port, $errno, $errstr, 5.0);
if (!is_resource($sock)) {
    fwrite(STDERR, "连接失败: " . $errstr . " (" . $errno . ")" . PHP_EOL);
    exit(1);
}
stream_set_timeout($sock, 5);

function sendMsg($sock, \TSF\Proto\Message $m): void
{
    $body = $m->encode();
    $len = 8 + strlen($body);
    $frame = pack("N", $len) . pack("n", $m->msgId()) . chr(0) . chr(0) . $body;
    fwrite($sock, $frame);
}

/** @return array{0:int,1:string} [msgId, body] */
function recvMsg($sock): array
{
    $head = "";
    while (strlen($head) < 8) {
        $chunk = fread($sock, 8 - strlen($head));
        if ($chunk === false || $chunk === "") {
            throw new \RuntimeException("读帧头失败");
        }
        $head .= $chunk;
    }
    $len = unpack("N", substr($head, 0, 4))[1];
    $msgId = unpack("n", substr($head, 4, 2))[1];
    $need = $len - 8;
    $body = "";
    while (strlen($body) < $need) {
        $chunk = fread($sock, $need - strlen($body));
        if ($chunk === false || $chunk === "") {
            throw new \RuntimeException("读帧体失败");
        }
        $body .= $chunk;
    }
    return [$msgId, $body];
}

use Gen\Proto\CollectReq;
use Gen\Proto\LoginReq;
use Gen\Proto\MailClaimReq;
use Gen\Proto\MailListReq;
use Gen\Proto\Msg;
use Gen\Proto\RankReq;

function call($sock, \TSF\Proto\Message $req, int $expectMsgId, string $label): \TSF\Proto\Message
{
    sendMsg($sock, $req);
    [$msgId, $body] = recvMsg($sock);
    $resp = \Gen\Proto\Codec::decode($msgId, $body);
    if ($resp === null) {
        fwrite(STDERR, $label . ": 收到无法解码的 msgId=" . $msgId . PHP_EOL);
        exit(1);
    }
    echo "  " . $label . " → " . Msg::name($msgId) . PHP_EOL;
    return $resp;
}

$name = "tester-" . ($argv[3] ?? "a");

echo "== 登录（" . $name . "）== " . PHP_EOL;
$req = new LoginReq();
$req->name = $name;
$login = call($sock, $req, Msg::LOGIN_RESP, "LOGIN");
if ($login instanceof \Gen\Proto\LoginResp) {
    echo "    uid=" . $login->uid . " coins=" . $login->coins
        . " pending=" . $login->pending . " rate=" . $login->rate_per_minute . "/min"
        . " cap=" . $login->cap_seconds . "s" . PHP_EOL;
}

echo PHP_EOL . "== 收取 ==" . PHP_EOL;
$collect = call($sock, new CollectReq(), Msg::COLLECT_RESP, "COLLECT");
if ($collect instanceof \Gen\Proto\CollectResp) {
    echo "    collected=" . $collect->collected . " coins=" . $collect->coins
        . " pending=" . $collect->pending . PHP_EOL;
}

echo PHP_EOL . "== 排行榜 ==" . PHP_EOL;
$rankReq = new RankReq();
$rankReq->top = 5;
$rank = call($sock, $rankReq, Msg::RANK_RESP, "RANK");
if ($rank instanceof \Gen\Proto\RankResp) {
    $n = count($rank->items);
    echo "    前 " . $n . " 名：" . PHP_EOL;
    foreach ($rank->items as $it) {
        echo "      #" . $it->uid . " " . $it->name . " " . $it->coins . PHP_EOL;
    }
}

echo PHP_EOL . "== 邮件列表 ==" . PHP_EOL;
$ml = call($sock, new MailListReq(), Msg::MAIL_LIST_RESP, "MAIL_LIST");
$firstMailId = 0;
if ($ml instanceof \Gen\Proto\MailListResp) {
    echo "    共 " . count($ml->mails) . " 封" . PHP_EOL;
    foreach ($ml->mails as $info) {
        echo "      #" . $info->id . " " . $info->title
            . ($info->claimed ? " [已领]" : " [可领]") . PHP_EOL;
        if (!$info->claimed && $firstMailId === 0) {
            $firstMailId = $info->id;
        }
    }
}

if ($firstMailId > 0) {
    echo PHP_EOL . "== 领取邮件附件 ==" . PHP_EOL;
    $cr = new MailClaimReq();
    $cr->mail_id = $firstMailId;
    $claim = call($sock, $cr, Msg::MAIL_CLAIM_RESP, "MAIL_CLAIM");
    if ($claim instanceof \Gen\Proto\MailClaimResp) {
        echo "    ok=" . ($claim->ok ? "true" : "false")
            . " items=" . count($claim->items)
            . " coins=" . $claim->coins . PHP_EOL;
    }

    // 再领一次：应该 ok 但 items 为空（幂等）
    echo PHP_EOL . "== 重复领取（应拿不到东西）==" . PHP_EOL;
    $cr2 = new MailClaimReq();
    $cr2->mail_id = $firstMailId;
    $again = call($sock, $cr2, Msg::MAIL_CLAIM_RESP, "MAIL_CLAIM_AGAIN");
    if ($again instanceof \Gen\Proto\MailClaimResp) {
        echo "    ok=" . ($again->ok ? "true" : "false")
            . " items=" . count($again->items)
            . " coins=" . $again->coins . PHP_EOL;
    }
}

echo PHP_EOL . "完成" . PHP_EOL;
fclose($sock);
