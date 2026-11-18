<?php

namespace Gen\Proto;

use TSF\Proto\Message;
use TSF\Proto\Reader;
use TSF\Proto\Wire;

/**
 * CollectResp（协议号 3004）
 *
 * **自动生成，不要手改**。源是 .proto，改完跑 tsf proto build。
 */
final class CollectResp extends Message
{
    public int $collected = 0;

    public int $coins = 0;

    public int $pending = 0;


    public function msgId(): int
    {
        return 3004;
    }

    public function encode(): string
    {
        $b = "";
        if ($this->collected !== 0) {
            $b .= Wire::tag(1, 0) . Wire::varint($this->collected);
        }
        if ($this->coins !== 0) {
            $b .= Wire::tag(2, 0) . Wire::varint($this->coins);
        }
        if ($this->pending !== 0) {
            $b .= Wire::tag(3, 0) . Wire::varint($this->pending);
        }
        return $b;
    }

    public static function decode(string $buf): CollectResp
    {
        $m = new CollectResp();
        $r = new Reader($buf, "CollectResp");
        while ($r->next()) {
            $f = $r->field();
            if ($f === 1) {
                $m->collected = $r->varint();
            }
            elseif ($f === 2) {
                $m->coins = $r->varint();
            }
            elseif ($f === 3) {
                $m->pending = $r->varint();
            }
else {
                $r->skip();
            }
        }
        return $m;
    }
}
