<?php

namespace Ddz;

/**
 * 斗地主自己的状态。
 *
 * 这四个值是**业务定义**的，框架不认识它们——Room 只存一个 int。
 * 与 ThreeStage 的三段式相比多一段「叫地主」，这正是不能把状态写死在 Room 里的原因。
 */
final class DdzState
{
    public const WAITING = 1;   // 等人 / 等准备
    public const CALLING = 2;   // 叫地主
    public const PLAYING = 3;   // 出牌
    public const SETTLED = 4;   // 结算
}
