<?php

namespace Gen\Proto;

use TSF\Proto\Message;
use TSF\Proto\Reader;
use TSF\Proto\Wire;

/**
 * FloatMsg（协议号 1004）
 *
 * **自动生成，不要手改**。源是 .proto，改完跑 tsf proto build。
 */
final class FloatMsg extends Message
{
    public float $d = 0.0;

    public float $f = 0.0;


    public function msgId(): int
    {
        return 1004;
    }

    public function encode(): string
    {
        $b = "";
        if ($this->d !== 0.0) {
            $b .= Wire::tag(4, 1) . Wire::double($this->d);
        }
        if ($this->f !== 0.0) {
            $b .= Wire::tag(5, 5) . Wire::float($this->f);
        }
        return $b;
    }

    public static function decode(string $buf): FloatMsg
    {
        $m = new FloatMsg();
        $r = new Reader($buf, "FloatMsg");
        while ($r->next()) {
            $f = $r->field();
            if ($f === 4) {
                $m->d = $r->double();
            }
            elseif ($f === 5) {
                $m->f = $r->float();
            }
else {
                $r->skip();
            }
        }
        return $m;
    }
}
