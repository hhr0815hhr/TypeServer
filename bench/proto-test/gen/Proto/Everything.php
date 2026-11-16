<?php

namespace Gen\Proto;

use TSF\Proto\Message;
use TSF\Proto\Reader;
use TSF\Proto\Wire;

/**
 * Everything（协议号 1012）
 *
 * **自动生成，不要手改**。源是 .proto，改完跑 tsf proto build。
 */
final class Everything extends Message
{
    public int $i32 = 0;

    public int $i64 = 0;

    public int $u32 = 0;

    public int $s32 = 0;

    public int $s64 = 0;

    public bool $flag = false;

    public string $name = "";

    public string $raw = "";

    public float $f32 = 0.0;

    public float $f64 = 0.0;

    public int $fx32 = 0;

    public int $fx64 = 0;

    /** @var int[] */
    public array $zig = [];

    /** @var float[] */
    public array $farr = [];

    public ?Item $one = null;


    public function msgId(): int
    {
        return 1012;
    }

    public function encode(): string
    {
        $b = "";
        if ($this->i32 !== 0) {
            $b .= Wire::tag(1, 0) . Wire::varint($this->i32);
        }
        if ($this->i64 !== 0) {
            $b .= Wire::tag(2, 0) . Wire::varint($this->i64);
        }
        if ($this->u32 !== 0) {
            $b .= Wire::tag(3, 0) . Wire::varint($this->u32);
        }
        if ($this->s32 !== 0) {
            $b .= Wire::tag(4, 0) . Wire::varint(Wire::zigzag($this->s32));
        }
        if ($this->s64 !== 0) {
            $b .= Wire::tag(5, 0) . Wire::varint(Wire::zigzag($this->s64));
        }
        if ($this->flag) {
            $b .= Wire::tag(6, 0) . Wire::varint($this->flag ? 1 : 0);
        }
        if ($this->name !== "") {
            $b .= Wire::tag(7, 2) . Wire::bytes($this->name);
        }
        if ($this->raw !== "") {
            $b .= Wire::tag(8, 2) . Wire::bytes($this->raw);
        }
        if ($this->f32 !== 0.0) {
            $b .= Wire::tag(9, 5) . Wire::float($this->f32);
        }
        if ($this->f64 !== 0.0) {
            $b .= Wire::tag(10, 1) . Wire::double($this->f64);
        }
        if ($this->fx32 !== 0) {
            $b .= Wire::tag(11, 5) . Wire::fixed32($this->fx32);
        }
        if ($this->fx64 !== 0) {
            $b .= Wire::tag(12, 1) . Wire::fixed64($this->fx64);
        }
        if ($this->zig !== []) {
            $p_zig = "";
            foreach ($this->zig as $v_zig) {
                $p_zig .= Wire::varint(Wire::zigzag($v_zig));
            }
            $b .= Wire::tag(13, 2) . Wire::bytes($p_zig);
        }
        if ($this->farr !== []) {
            $p_farr = "";
            foreach ($this->farr as $v_farr) {
                $p_farr .= Wire::float($v_farr);
            }
            $b .= Wire::tag(14, 2) . Wire::bytes($p_farr);
        }
        if ($this->one !== null) {
            $b .= Wire::tag(15, 2) . Wire::bytes($this->one->encode());
        }
        return $b;
    }

    public static function decode(string $buf): Everything
    {
        $m = new Everything();
        $r = new Reader($buf, "Everything");
        while ($r->next()) {
            $f = $r->field();
            if ($f === 1) {
                $m->i32 = $r->int32();
            }
            elseif ($f === 2) {
                $m->i64 = $r->varint();
            }
            elseif ($f === 3) {
                $m->u32 = $r->varint();
            }
            elseif ($f === 4) {
                $m->s32 = $r->sint();
            }
            elseif ($f === 5) {
                $m->s64 = $r->sint();
            }
            elseif ($f === 6) {
                $m->flag = $r->boolean();
            }
            elseif ($f === 7) {
                $m->name = $r->string();
            }
            elseif ($f === 8) {
                $m->raw = $r->string();
            }
            elseif ($f === 9) {
                $m->f32 = $r->float();
            }
            elseif ($f === 10) {
                $m->f64 = $r->double();
            }
            elseif ($f === 11) {
                $m->fx32 = $r->fixed32();
            }
            elseif ($f === 12) {
                $m->fx64 = $r->fixed64();
            }
            elseif ($f === 13) {
                if ($r->wireType() === 2) {
                    $m->zig = array_merge($m->zig, $r->packedSints());
                } else {
                    $m->zig[] = $r->sint();
                }
            }
            elseif ($f === 14) {
                if ($r->wireType() === 2) {
                    $m->farr = array_merge($m->farr, $r->packedFloats());
                } else {
                    $m->farr[] = $r->float();
                }
            }
            elseif ($f === 15) {
                $m->one = Item::decode($r->string());
            }
else {
                $r->skip();
            }
        }
        return $m;
    }
}
