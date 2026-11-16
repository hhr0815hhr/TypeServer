<?php

namespace Probe2;

/** 载荷允许的具体形态：字符串 / 数组 / 对象。不含 int/float/bool——消息体没有这些。 */
interface Payload
{
    public function pack(int $msgId, string|array|object $data): string;

    public function unpack(int $msgId, string $body): string|array|object;
}

final class JsonLike implements Payload
{
    public function pack(int $msgId, string|array|object $data): string
    {
        return json_encode($data);
    }

    public function unpack(int $msgId, string $body): string|array|object
    {
        $v = json_decode($body, true);
        return is_array($v) ? $v : $body;
    }
}

final class Frame
{
    public int $msgId = 0;

    public int $flags = 0;

    public string $body = "";

    public int $consumed = 0;
}

/** 联合类型 + 接口分派 + 对象返回值 */
function roundtrip(Payload $p, string|array|object $data): int
{
    $body = $p->pack(1001, $data);
    $back = $p->unpack(1001, $body);
    if (is_array($back)) {
        return count($back);
    }
    if (is_object($back)) {
        return 1;
    }
    return strlen($back);
}

function makeFrame(): ?Frame
{
    $f = new Frame();
    $f->msgId = 1002;
    $f->body = "hello";
    $f->consumed = 13;
    return $f;
}

function takeNullable(?Frame $f): int
{
    return $f === null ? -1 : $f->consumed;
}
