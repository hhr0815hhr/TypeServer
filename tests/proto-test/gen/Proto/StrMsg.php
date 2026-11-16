<?php

namespace Gen\Proto;

use TSF\Proto\Message;
use TSF\Proto\Reader;
use TSF\Proto\Wire;

/**
 * StrMsg（协议号 1002）
 *
 * **自动生成，不要手改**。源是 .proto，改完跑 tsf proto build。
 */
final class StrMsg extends Message
{
    public string $s = "";


    public function msgId(): int
    {
        return 1002;
    }

    public function encode(): string
    {
        $b = "";
        if ($this->s !== "") {
            $b .= Wire::tag(2, 2) . Wire::bytes($this->s);
        }
        return $b;
    }

    public static function decode(string $buf): StrMsg
    {
        $m = new StrMsg();
        $r = new Reader($buf, "StrMsg");
        while ($r->next()) {
            $f = $r->field();
            if ($f === 2) {
                $m->s = $r->string();
            }
else {
                $r->skip();
            }
        }
        return $m;
    }
}
