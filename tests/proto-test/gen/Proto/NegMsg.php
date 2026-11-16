<?php

namespace Gen\Proto;

use TSF\Proto\Message;
use TSF\Proto\Reader;
use TSF\Proto\Wire;

/**
 * NegMsg（协议号 1003）
 *
 * **自动生成，不要手改**。源是 .proto，改完跑 tsf proto build。
 */
final class NegMsg extends Message
{
    public int $v = 0;


    public function msgId(): int
    {
        return 1003;
    }

    public function encode(): string
    {
        $b = "";
        if ($this->v !== 0) {
            $b .= Wire::tag(3, 0) . Wire::varint($this->v);
        }
        return $b;
    }

    public static function decode(string $buf): NegMsg
    {
        $m = new NegMsg();
        $r = new Reader($buf, "NegMsg");
        while ($r->next()) {
            $f = $r->field();
            if ($f === 3) {
                $m->v = $r->int32();
            }
else {
                $r->skip();
            }
        }
        return $m;
    }
}
