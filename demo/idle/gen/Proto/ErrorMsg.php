<?php

namespace Gen\Proto;

use TSF\Proto\Message;
use TSF\Proto\Reader;
use TSF\Proto\Wire;

/**
 * ErrorMsg（协议号 3011）
 *
 * **自动生成，不要手改**。源是 .proto，改完跑 tsf proto build。
 */
final class ErrorMsg extends Message
{
    public int $code = 0;

    public string $msg = "";


    public function msgId(): int
    {
        return 3011;
    }

    public function encode(): string
    {
        $b = "";
        if ($this->code !== 0) {
            $b .= Wire::tag(1, 0) . Wire::varint($this->code);
        }
        if ($this->msg !== "") {
            $b .= Wire::tag(2, 2) . Wire::bytes($this->msg);
        }
        return $b;
    }

    public static function decode(string $buf): ErrorMsg
    {
        $m = new ErrorMsg();
        $r = new Reader($buf, "ErrorMsg");
        while ($r->next()) {
            $f = $r->field();
            if ($f === 1) {
                $m->code = $r->int32();
            }
            elseif ($f === 2) {
                $m->msg = $r->string();
            }
else {
                $r->skip();
            }
        }
        return $m;
    }
}
