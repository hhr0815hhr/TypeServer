<?php

namespace Gen\Proto;

use TSF\Proto\Message;
use TSF\Proto\Reader;
use TSF\Proto\Wire;

/**
 * MailInfo（协议号 3102）
 *
 * **自动生成，不要手改**。源是 .proto，改完跑 tsf proto build。
 */
final class MailInfo extends Message
{
    public int $id = 0;

    public string $title = "";

    public bool $read = false;

    public bool $claimed = false;

    public bool $has_items = false;


    public function msgId(): int
    {
        return 3102;
    }

    public function encode(): string
    {
        $b = "";
        if ($this->id !== 0) {
            $b .= Wire::tag(1, 0) . Wire::varint($this->id);
        }
        if ($this->title !== "") {
            $b .= Wire::tag(2, 2) . Wire::bytes($this->title);
        }
        if ($this->read) {
            $b .= Wire::tag(3, 0) . Wire::varint($this->read ? 1 : 0);
        }
        if ($this->claimed) {
            $b .= Wire::tag(4, 0) . Wire::varint($this->claimed ? 1 : 0);
        }
        if ($this->has_items) {
            $b .= Wire::tag(5, 0) . Wire::varint($this->has_items ? 1 : 0);
        }
        return $b;
    }

    public static function decode(string $buf): MailInfo
    {
        $m = new MailInfo();
        $r = new Reader($buf, "MailInfo");
        while ($r->next()) {
            $f = $r->field();
            if ($f === 1) {
                $m->id = $r->int32();
            }
            elseif ($f === 2) {
                $m->title = $r->string();
            }
            elseif ($f === 3) {
                $m->read = $r->boolean();
            }
            elseif ($f === 4) {
                $m->claimed = $r->boolean();
            }
            elseif ($f === 5) {
                $m->has_items = $r->boolean();
            }
else {
                $r->skip();
            }
        }
        return $m;
    }
}
