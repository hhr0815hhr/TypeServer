<?php

namespace Gen\Proto;

use TSF\Proto\Message;
use TSF\Proto\Reader;
use TSF\Proto\Wire;

/**
 * LoginReq（协议号 3001）
 *
 * **自动生成，不要手改**。源是 .proto，改完跑 tsf proto build。
 */
final class LoginReq extends Message
{
    public string $name = "";


    public function msgId(): int
    {
        return 3001;
    }

    public function encode(): string
    {
        $b = "";
        if ($this->name !== "") {
            $b .= Wire::tag(1, 2) . Wire::bytes($this->name);
        }
        return $b;
    }

    public static function decode(string $buf): LoginReq
    {
        $m = new LoginReq();
        $r = new Reader($buf, "LoginReq");
        while ($r->next()) {
            $f = $r->field();
            if ($f === 1) {
                $m->name = $r->string();
            }
else {
                $r->skip();
            }
        }
        return $m;
    }
}
