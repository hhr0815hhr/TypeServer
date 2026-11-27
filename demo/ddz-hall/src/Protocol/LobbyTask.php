<?php

namespace Ddz\Protocol;

/**
 * 投给大厅的内部消息。
 *
 * 用对象而不是 array：AOT 下数组表达不了"这个键是 int、那个是 string"，
 * 取出来是混合类型，用之前处处要判断。对象成员有确定类型，编译期就查得出。
 */
final class LobbyTask
{
    /** 1=建房 2=加入 3=离开 */
    public int $op = 0;

    public int $fd = 0;

    public int $uid = 0;

    public int $roomId = 0;

    /** 房卡档位：1=初级场 2=中级场 3=高级场 */
    public int $level = 1;

    /** @var int[] 出牌请求里的牌 id（op=5 时用） */
    public array $cards = [];

    /** 叫分（op=7 时用），0 表示不叫 */
    public int $score = 0;
}
