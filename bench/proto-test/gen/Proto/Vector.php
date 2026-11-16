<?php

namespace Gen\Proto;

use TSF\Proto\Message;
use TSF\Proto\Reader;
use TSF\Proto\Wire;

/**
 * Vector（协议号 1001）
 *
 * **自动生成，不要手改**。源是 .proto，改完跑 tsf proto build。
 */
final class Vector extends Message
{
    public int $a = 0;


    public function msgId(): int
    {
        return 1001;
    }

    public function encode(): string
    {
        $b = "";
        if ($this->a !== 0) {
            $b .= Wire::tag(1, 0) . Wire::varint($this->a);
        }
        return $b;
    }

    public static function decode(string $buf): Vector
    {
        $m = new Vector();
        $r = new Reader($buf, "Vector");
        while ($r->next()) {
            $f = $r->field();
            if ($f === 1) {
                $m->a = $r->int32();
            }
else {
                $r->skip();
            }
        }
        return $m;
    }
}
