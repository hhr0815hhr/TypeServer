<?php

namespace Gen\Proto;

use TSF\Proto\Message;
use TSF\Proto\Reader;
use TSF\Proto\Wire;

/**
 * RankResp（协议号 3006）
 *
 * **自动生成，不要手改**。源是 .proto，改完跑 tsf proto build。
 */
final class RankResp extends Message
{
    /** @var RankItem[] */
    public array $items = [];


    public function msgId(): int
    {
        return 3006;
    }

    public function encode(): string
    {
        $b = "";
        foreach ($this->items as $v_items) {
            $b .= Wire::tag(1, 2) . Wire::bytes($v_items->encode());
        }
        return $b;
    }

    public static function decode(string $buf): RankResp
    {
        $m = new RankResp();
        $r = new Reader($buf, "RankResp");
        while ($r->next()) {
            $f = $r->field();
            if ($f === 1) {
                $m->items[] = RankItem::decode($r->string());
            }
else {
                $r->skip();
            }
        }
        return $m;
    }
}
