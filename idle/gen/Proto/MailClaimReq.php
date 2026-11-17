<?php

namespace Gen\Proto;

use TSF\Proto\Message;
use TSF\Proto\Reader;
use TSF\Proto\Wire;

/**
 * MailClaimReq（协议号 3009）
 *
 * **自动生成，不要手改**。源是 .proto，改完跑 tsf proto build。
 */
final class MailClaimReq extends Message
{
    public int $mail_id = 0;


    public function msgId(): int
    {
        return 3009;
    }

    public function encode(): string
    {
        $b = "";
        if ($this->mail_id !== 0) {
            $b .= Wire::tag(1, 0) . Wire::varint($this->mail_id);
        }
        return $b;
    }

    public static function decode(string $buf): MailClaimReq
    {
        $m = new MailClaimReq();
        $r = new Reader($buf, "MailClaimReq");
        while ($r->next()) {
            $f = $r->field();
            if ($f === 1) {
                $m->mail_id = $r->int32();
            }
else {
                $r->skip();
            }
        }
        return $m;
    }
}
