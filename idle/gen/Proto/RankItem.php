<?php

namespace Gen\Proto;

use TSF\Proto\Message;
use TSF\Proto\Reader;
use TSF\Proto\Wire;

/**
 * RankItem（协议号 3101）
 *
 * **自动生成，不要手改**。源是 .proto，改完跑 tsf proto build。
 */
final class RankItem extends Message
{
    public int $uid = 0;

    public string $name = "";

    public int $coins = 0;


    public function msgId(): int
    {
        return 3101;
    }

    public function encode(): string
    {
        $b = "";
        if ($this->uid !== 0) {
            $b .= Wire::tag(1, 0) . Wire::varint($this->uid);
        }
        if ($this->name !== "") {
            $b .= Wire::tag(2, 2) . Wire::bytes($this->name);
        }
        if ($this->coins !== 0) {
            $b .= Wire::tag(3, 0) . Wire::varint($this->coins);
        }
        return $b;
    }

    public static function decode(string $buf): RankItem
    {
        $m = new RankItem();
        $r = new Reader($buf, "RankItem");
        while ($r->next()) {
            $f = $r->field();
            if ($f === 1) {
                $m->uid = $r->int32();
            }
            elseif ($f === 2) {
                $m->name = $r->string();
            }
            elseif ($f === 3) {
                $m->coins = $r->varint();
            }
else {
                $r->skip();
            }
        }
        return $m;
    }
}
