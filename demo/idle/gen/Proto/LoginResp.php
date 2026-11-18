<?php

namespace Gen\Proto;

use TSF\Proto\Message;
use TSF\Proto\Reader;
use TSF\Proto\Wire;

/**
 * LoginResp（协议号 3002）
 *
 * **自动生成，不要手改**。源是 .proto，改完跑 tsf proto build。
 */
final class LoginResp extends Message
{
    public int $uid = 0;

    public string $name = "";

    public int $coins = 0;

    public int $pending = 0;

    public int $rate_per_minute = 0;

    public int $cap_seconds = 0;


    public function msgId(): int
    {
        return 3002;
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
        if ($this->pending !== 0) {
            $b .= Wire::tag(4, 0) . Wire::varint($this->pending);
        }
        if ($this->rate_per_minute !== 0) {
            $b .= Wire::tag(5, 0) . Wire::varint($this->rate_per_minute);
        }
        if ($this->cap_seconds !== 0) {
            $b .= Wire::tag(6, 0) . Wire::varint($this->cap_seconds);
        }
        return $b;
    }

    public static function decode(string $buf): LoginResp
    {
        $m = new LoginResp();
        $r = new Reader($buf, "LoginResp");
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
            elseif ($f === 4) {
                $m->pending = $r->varint();
            }
            elseif ($f === 5) {
                $m->rate_per_minute = $r->int32();
            }
            elseif ($f === 6) {
                $m->cap_seconds = $r->int32();
            }
else {
                $r->skip();
            }
        }
        return $m;
    }
}
