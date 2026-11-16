<?php

namespace Probe;

/** 参数：单一具体类型 */
function take_string(string $v): int
{
    return strlen($v);
}

function take_array(array $v): int
{
    return count($v);
}

/** 参数：联合类型 */
function take_union(string|array $v): int
{
    return is_string($v) ? strlen($v) : count($v);
}

/** 参数：mixed */
function take_mixed(mixed $v): int
{
    return is_string($v) ? strlen($v) : count($v);
}

/** 返回值：三种写法 */
function ret_string(): string
{
    return "hello-tsf-payload";
}

function ret_union(): string|array
{
    return "hello-tsf-payload";
}

function ret_mixed(): mixed
{
    return "hello-tsf-payload";
}

/** 解码结果：数组 vs 小对象 */
final class Frame
{
    public int $msgId = 0;

    public int $flags = 0;

    public string $body = "";

    public int $consumed = 0;
}

function ret_array(): array
{
    return [1002, 0, "hello-tsf-payload", 24];
}

function ret_object(): Frame
{
    $f = new Frame();
    $f->msgId = 1002;
    $f->flags = 0;
    $f->body = "hello-tsf-payload";
    $f->consumed = 24;
    return $f;
}
