<?php

namespace Gen\Proto;

use TSF\Proto\Message;
use TSF\Proto\Reader;
use TSF\Proto\Wire;

/**
 * MailClaimResp（协议号 3010）
 *
 * **自动生成，不要手改**。源是 .proto，改完跑 tsf proto build。
 */
final class MailClaimResp extends Message
{
    public bool $ok = false;

    /** @var Item[] */
    public array $items = [];

    public int $coins = 0;


    public function msgId(): int
    {
        return 3010;
    }

    public function encode(): string
    {
        $b = "";
        if ($this->ok) {
            $b .= Wire::tag(1, 0) . Wire::varint($this->ok ? 1 : 0);
        }
        foreach ($this->items as $v_items) {
            $b .= Wire::tag(2, 2) . Wire::bytes($v_items->encode());
        }
        if ($this->coins !== 0) {
            $b .= Wire::tag(3, 0) . Wire::varint($this->coins);
        }
        return $b;
    }

    public static function decode(string $buf): MailClaimResp
    {
        $m = new MailClaimResp();
        $r = new Reader($buf, "MailClaimResp");
        while ($r->next()) {
            $f = $r->field();
            if ($f === 1) {
                $m->ok = $r->boolean();
            }
            elseif ($f === 2) {
                $m->items[] = Item::decode($r->string());
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
