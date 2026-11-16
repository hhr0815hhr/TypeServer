<?php

namespace Gen\Proto;

use TSF\Proto\Message;
use TSF\Proto\Reader;
use TSF\Proto\Wire;

/**
 * Bag（协议号 1011）
 *
 * **自动生成，不要手改**。源是 .proto，改完跑 tsf proto build。
 */
final class Bag extends Message
{
    public string $owner = "";

    /** @var Item[] */
    public array $items = [];

    /** 枚举 Quality，值见 Quality 类常量 */
    public int $quality = 0;


    public function msgId(): int
    {
        return 1011;
    }

    public function encode(): string
    {
        $b = "";
        if ($this->owner !== "") {
            $b .= Wire::tag(1, 2) . Wire::bytes($this->owner);
        }
        foreach ($this->items as $v_items) {
            $b .= Wire::tag(2, 2) . Wire::bytes($v_items->encode());
        }
        if ($this->quality !== 0) {
            $b .= Wire::tag(3, 0) . Wire::varint($this->quality);
        }
        return $b;
    }

    public static function decode(string $buf): Bag
    {
        $m = new Bag();
        $r = new Reader($buf, "Bag");
        while ($r->next()) {
            $f = $r->field();
            if ($f === 1) {
                $m->owner = $r->string();
            }
            elseif ($f === 2) {
                $m->items[] = Item::decode($r->string());
            }
            elseif ($f === 3) {
                $m->quality = $r->varint();
            }
else {
                $r->skip();
            }
        }
        return $m;
    }
}
