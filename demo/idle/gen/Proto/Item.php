<?php

namespace Gen\Proto;

use TSF\Proto\Message;
use TSF\Proto\Reader;
use TSF\Proto\Wire;

/**
 * Item（协议号 3103）
 *
 * **自动生成，不要手改**。源是 .proto，改完跑 tsf proto build。
 */
final class Item extends Message
{
    public int $id = 0;

    public int $n = 0;


    public function msgId(): int
    {
        return 3103;
    }

    public function encode(): string
    {
        $b = "";
        if ($this->id !== 0) {
            $b .= Wire::tag(1, 0) . Wire::varint($this->id);
        }
        if ($this->n !== 0) {
            $b .= Wire::tag(2, 0) . Wire::varint($this->n);
        }
        return $b;
    }

    public static function decode(string $buf): Item
    {
        $m = new Item();
        $r = new Reader($buf, "Item");
        while ($r->next()) {
            $f = $r->field();
            if ($f === 1) {
                $m->id = $r->int32();
            }
            elseif ($f === 2) {
                $m->n = $r->int32();
            }
else {
                $r->skip();
            }
        }
        return $m;
    }
}
