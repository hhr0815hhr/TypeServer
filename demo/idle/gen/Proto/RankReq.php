<?php

namespace Gen\Proto;

use TSF\Proto\Message;
use TSF\Proto\Reader;
use TSF\Proto\Wire;

/**
 * RankReq（协议号 3005）
 *
 * **自动生成，不要手改**。源是 .proto，改完跑 tsf proto build。
 */
final class RankReq extends Message
{
    public int $top = 0;


    public function msgId(): int
    {
        return 3005;
    }

    public function encode(): string
    {
        $b = "";
        if ($this->top !== 0) {
            $b .= Wire::tag(1, 0) . Wire::varint($this->top);
        }
        return $b;
    }

    public static function decode(string $buf): RankReq
    {
        $m = new RankReq();
        $r = new Reader($buf, "RankReq");
        while ($r->next()) {
            $f = $r->field();
            if ($f === 1) {
                $m->top = $r->int32();
            }
else {
                $r->skip();
            }
        }
        return $m;
    }
}
