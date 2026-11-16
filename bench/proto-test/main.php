<?php

/**
 * T6-03 验证：proto → PHP 类 + 编解码，且**字节和标准 protobuf 完全一致**。
 *
 * 编码对不对不能只靠"自己编自己解"——那样两边一起错也测不出来。
 * 所以第 [1] 组用的是 protobuf 官方文档里的规范样例字节：
 *
 *   field 1, varint 150        → 08 96 01
 *   field 2, string "testing"  → 12 07 74 65 73 74 69 6e 67
 *   field 6, packed [3,270,86942] → 32 06 03 8E 02 9E A7 05
 *
 * 这三条对上了，前端拿 protobuf.js 解我们的包才不会出问题。
 */

use Gen\Proto\Bag;
use Gen\Proto\Codec;
use Gen\Proto\Everything;
use Gen\Proto\FloatMsg;
use Gen\Proto\Item;
use Gen\Proto\Msg;
use Gen\Proto\NegMsg;
use Gen\Proto\PackedMsg;
use Gen\Proto\Quality;
use Gen\Proto\StrMsg;
use Gen\Proto\Vector;

final class T
{
    public static int $pass = 0;

    public static int $fail = 0;

    public static function ok(bool $cond, string $label, string $detail = ""): void
    {
        if ($cond) {
            self::$pass++;
            echo "  OK   " . $label . PHP_EOL;
        } else {
            self::$fail++;
            echo "  FAIL " . $label . ($detail === "" ? "" : "  → " . $detail) . PHP_EOL;
        }
    }

    public static function eq(int|string|float|bool $got, int|string|float|bool $want, string $label): void
    {
        self::ok($got === $want, $label, "got=" . var_export($got, true) . " want=" . var_export($want, true));
    }

    public static function hex(string $s): string
    {
        $out = "";
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $out .= sprintf("%02X", ord($s[$i]));
        }
        return $out;
    }

    public static function bytes(string $got, string $wantHex, string $label): void
    {
        $h = self::hex($got);
        self::ok($h === $wantHex, $label, "got=" . $h . " want=" . $wantHex);
    }
}

/** 造一段"未来版本才有的字段"：field 99, varint 7 */
function extraField(): string
{
    return "\x98\x06\x07";   // tag = 99<<3|0 = 792 → varint 98 06
}

function caseGolden(): void
{
    echo "== [1] 规范样例字节（和标准 protobuf 对齐）==" . PHP_EOL;

    $v = new Vector();
    $v->a = 150;
    T::bytes($v->encode(), "089601", "field1 varint 150 → 08 96 01");

    $s = new StrMsg();
    $s->s = "testing";
    T::bytes($s->encode(), "120774657374696E67", "field2 string \"testing\"");

    $p = new PackedMsg();
    $p->vals = [3, 270, 86942];
    T::bytes($p->encode(), "3206038E029EA705", "field6 packed [3,270,86942]");

    // 负数：proto3 规定 int32 按 64 位符号扩展编。0xFFFF_FFFF_FFFF_FFFF 的
    // LEB128 是 9 个 0xFF（前 63 位）+ 0x01（最高位），共 10 字节
    $n = new NegMsg();
    $n->v = -1;
    T::bytes($n->encode(), "18FFFFFFFFFFFFFFFFFF01", "int32 -1 编成 10 字节（不是 1 字节）");

    // double 1.0 / float 1.5
    $f = new FloatMsg();
    $f->d = 1.0;
    $f->f = 1.5;
    T::bytes($f->encode(), "21000000000000F03F2D0000C03F", "double 1.0 + float 1.5");
}

function caseDefaults(): void
{
    echo "== [2] proto3 默认值不序列化 ==" . PHP_EOL;

    $v = new Vector();
    T::eq($v->encode(), "", "int32 为 0 时不写任何字节");

    $s = new StrMsg();
    T::eq($s->encode(), "", "string 为空时不写");

    $p = new PackedMsg();
    T::eq($p->encode(), "", "repeated 为空时不写");

    $e = new Everything();
    T::eq($e->encode(), "", "全默认值的消息编出空串");
    T::eq($e->one === null, true, "消息字段默认是 null");
}

function caseRoundTrip(): void
{
    echo "== [3] 全类型往返 ==" . PHP_EOL;

    $e = new Everything();
    $e->i32 = -123456;
    $e->i64 = 9007199254740993;
    $e->u32 = 4000000000;
    $e->s32 = -7;
    $e->s64 = -1234567890123;
    $e->flag = true;
    $e->name = "中文名字";
    $e->raw = "\x00\x01\xFF";
    $e->f32 = 1.5;
    $e->f64 = 3.141592653589793;
    $e->fx32 = 42;
    $e->fx64 = 123456789;
    $e->zig = [-1, 0, 1, -2000000000];
    $e->farr = [0.5, -2.25];
    $sub = new Item();
    $sub->id = 7;
    $sub->count = 3;
    $e->one = $sub;

    $bin = $e->encode();
    $back = Everything::decode($bin);

    T::eq($back->i32, -123456, "int32 负数");
    T::eq($back->i64, 9007199254740993, "int64 大数");
    T::eq($back->u32, 4000000000, "uint32 超过 2^31");
    T::eq($back->s32, -7, "sint32");
    T::eq($back->s64, -1234567890123, "sint64");
    T::eq($back->flag, true, "bool");
    T::eq($back->name, "中文名字", "string（UTF-8）");
    T::eq($back->raw, "\x00\x01\xFF", "bytes（含 0x00）");
    T::eq($back->f32, 1.5, "float");
    T::eq($back->f64, 3.141592653589793, "double 精确往返");
    T::eq($back->fx32, 42, "fixed32");
    T::eq($back->fx64, 123456789, "fixed64");
    T::eq(count($back->zig), 4, "repeated sint32 个数");
    T::eq($back->zig[0] ?? 0, -1, "repeated sint32 首项");
    T::eq($back->zig[3] ?? 0, -2000000000, "repeated sint32 末项");
    T::eq(count($back->farr), 2, "repeated float 个数");
    T::eq($back->farr[1] ?? 0.0, -2.25, "repeated float 值");
    T::ok($back->one !== null, "嵌套消息不是 null");
    if ($back->one !== null) {
        T::eq($back->one->id, 7, "嵌套消息的字段");
    }

    // 再编一次应当得到同样的字节（稳定）
    T::eq(T::hex($back->encode()), T::hex($bin), "解完再编，字节完全一致");
}

function caseUnknownField(): void
{
    echo "== [4] 不认识的字段必须跳过 ==" . PHP_EOL;

    $v = new Vector();
    $v->a = 150;
    // 老服务端收到新客户端塞进来的 field 99：不能报错，也不能把游标读歪
    $back = Vector::decode($v->encode() . extraField());
    T::eq($back->a, 150, "多一个未知字段，已知字段仍然正确");

    // 未知字段夹在两个已知字段之间（更容易暴露游标问题）
    $e = new Item();
    $e->id = 5;
    $e->count = 9;
    $bytes = "\x08\x05" . extraField() . "\x10\x09";
    $b2 = Item::decode($bytes);
    T::eq($b2->id, 5, "未知字段在前，第一个已知字段正确");
    T::eq($b2->count, 9, "未知字段在后，第二个已知字段正确（游标没歪）");

    // 未知的 length-delimited 也要能跳过
    $long = "\xA2\x06\x05hello";   // field 100, wt=2, len=5
    $b3 = Item::decode($bytes . $long);
    T::eq($b3->count, 9, "未知的 length-delimited 字段也能跳过");
}

function caseWireTypeMismatch(): void
{
    echo "== [5] 已知字段的 wire type 不对要报错 ==" . PHP_EOL;

    // field 1 声明是 int32，却按 length-delimited 发过来
    $bad = "\x0A\x03abc";
    $caught = "";
    try {
        Vector::decode($bad);
    } catch (\RuntimeException $e) {
        $caught = $e->getMessage();
    }
    T::ok($caught !== "", "wire type 不匹配时抛异常");
    T::ok(str_contains($caught, "字段 1"), "错误里带字段号", $caught);

    $caught2 = "";
    try {
        Vector::decode("\x08");
    } catch (\RuntimeException $e) {
        $caught2 = $e->getMessage();
    }
    T::ok(str_contains($caught2, "截断"), "数据截断时给出明确原因", $caught2);

    // 字段号 0 是非法（全是 0 的垃圾数据）
    $caught3 = "";
    try {
        Vector::decode("\x00\x00");
    } catch (\RuntimeException $e) {
        $caught3 = $e->getMessage();
    }
    T::ok($caught3 !== "", "字段号 0 的垃圾数据被拒绝");
}

function casePackedAndUnpacked(): void
{
    echo "== [6] repeated 两种编码都要收 ==" . PHP_EOL;

    // packed：一个 tag 里塞多个
    $packed = "\x32\x04\x01\x02\x03\x04";
    $a = PackedMsg::decode($packed);
    T::eq(count($a->vals), 4, "packed 解出 4 个");
    T::eq($a->vals[3] ?? 0, 4, "packed 末项");

    // 非 packed：每个值一个 tag（老实现会这么发）
    $one = "\x30\x01\x30\x02\x30\x03";
    $b = PackedMsg::decode($one);
    T::eq(count($b->vals), 3, "非 packed 也能解");
    T::eq($b->vals[2] ?? 0, 3, "非 packed 末项");

    // 混着来（部分 packed + 部分单个）：追加而不是覆盖
    $mixed = "\x32\x02\x01\x02" . "\x30\x03";
    $c = PackedMsg::decode($mixed);
    T::eq(count($c->vals), 3, "混着发不会覆盖前面解出来的");
    T::eq($c->vals[0] ?? 0, 1, "混着发的首项");
    T::eq($c->vals[2] ?? 0, 3, "混着发的末项");
}

function caseNested(): void
{
    echo "== [7] 嵌套消息与枚举 ==" . PHP_EOL;

    $bag = new Bag();
    $bag->owner = "玩家A";
    $bag->quality = Quality::RARE;
    $i1 = new Item();
    $i1->id = 1;
    $i1->count = 10;
    $i2 = new Item();
    $i2->id = 2;
    $i2->count = 20;
    $bag->items = [$i1, $i2];

    $back = Bag::decode($bag->encode());
    T::eq($back->owner, "玩家A", "外层字段");
    T::eq($back->quality, Quality::RARE, "枚举值");
    T::eq(count($back->items), 2, "嵌套 repeated 个数");
    if (count($back->items) === 2) {
        T::eq($back->items[0]->id, 1, "第一个子消息");
        T::eq($back->items[1]->count, 20, "第二个子消息");
    }

    T::eq(Quality::EPIC, 2, "枚举常量值");
    $all = Quality::all();
    T::eq($all[1] ?? "", "RARE", "枚举反查");
}

function caseDispatch(): void
{
    echo "== [8] 按协议号派发 ==" . PHP_EOL;

    $v = new Vector();
    $v->a = 42;

    $m = Codec::decode(Msg::VECTOR, $v->encode());
    T::ok($m instanceof Vector, "按 msgId 解出正确的类");
    if ($m instanceof Vector) {
        T::eq($m->a, 42, "解出的值正确");
    }

    T::eq(Codec::decode(9999, "junk") === null, true, "不认识的协议号返回 null（不抛异常）");
    T::eq(Msg::VECTOR, 1001, "协议号常量");
    T::eq(Msg::name(1005), "PackedMsg", "数字反查名字");
    T::eq(Msg::name(9999), "#9999", "不认识的号显示成 #N");

    T::eq($v->msgId(), 1001, "消息自己知道协议号");
}

function main(): void
{
    caseGolden();
    caseDefaults();
    caseRoundTrip();
    caseUnknownField();
    caseWireTypeMismatch();
    casePackedAndUnpacked();
    caseNested();
    caseDispatch();

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
    exit(T::$fail === 0 ? 0 : 1);
}
