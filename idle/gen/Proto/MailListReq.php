<?php

namespace Gen\Proto;

use TSF\Proto\Message;
use TSF\Proto\Reader;
use TSF\Proto\Wire;

/**
 * MailListReq（协议号 3007）
 *
 * **自动生成，不要手改**。源是 .proto，改完跑 tsf proto build。
 */
final class MailListReq extends Message
{
    public int $seq = 0;


    public function msgId(): int
    {
        return 3007;
    }

    public function encode(): string
    {
        $b = "";
        if ($this->seq !== 0) {
            $b .= Wire::tag(1, 0) . Wire::varint($this->seq);
        }
        return $b;
    }

    public static function decode(string $buf): MailListReq
    {
        $m = new MailListReq();
        $r = new Reader($buf, "MailListReq");
        while ($r->next()) {
            $f = $r->field();
            if ($f === 1) {
                $m->seq = $r->int32();
            }
else {
                $r->skip();
            }
        }
        return $m;
    }
}
