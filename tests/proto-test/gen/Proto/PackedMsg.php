<?php

namespace Gen\Proto;

use TSF\Proto\Message;
use TSF\Proto\Reader;
use TSF\Proto\Wire;

/**
 * PackedMsg（协议号 1005）
 *
 * **自动生成，不要手改**。源是 .proto，改完跑 tsf proto build。
 */
final class PackedMsg extends Message
{
    /** @var int[] */
    public array $vals = [];


    public function msgId(): int
    {
        return 1005;
    }

    public function encode(): string
    {
        $b = "";
        if ($this->vals !== []) {
            $p_vals = "";
            foreach ($this->vals as $v_vals) {
                $p_vals .= Wire::varint($v_vals);
            }
            $b .= Wire::tag(6, 2) . Wire::bytes($p_vals);
        }
        return $b;
    }

    public static function decode(string $buf): PackedMsg
    {
        $m = new PackedMsg();
        $r = new Reader($buf, "PackedMsg");
        while ($r->next()) {
            $f = $r->field();
            if ($f === 6) {
                if ($r->wireType() === 2) {
                    $m->vals = array_merge($m->vals, $r->packedVarints());
                } else {
                    $m->vals[] = $r->int32();
                }
            }
else {
                $r->skip();
            }
        }
        return $m;
    }
}
