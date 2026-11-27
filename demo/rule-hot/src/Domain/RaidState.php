<?php

namespace Game\Domain;

/**
 * 一次结算的可见状态（Context 的 target）。
 *
 * 字段是 public 的，因为 `Context::getInt()` 走的是 `get_object_vars()`——
 * 它只能看到 public 属性。这是刻意的：脚本侧的"字段白名单"必须对应
 * 一份真实可读的数据，而不是靠魔术方法变出来。
 *
 * 但**可读不等于可写**：宿主构造 Context 时给的 writable 列表是空的，
 * 脚本 `$ctx->setInt("gold", 999)` 会抛错。这一层由 Context 保证，
 * 不靠脚本自觉。
 *
 * 注意这里只放"规则需要的输入"。钱包、库存、数据库连接一概不放——
 * 脚本要动它们，只能通过宿主授予的能力（reward / send / timer）。
 */
final class RaidState
{
    public int $uid;

    public int $level;

    /** 本次结算击杀了多少只 */
    public int $kills;

    /** 每只怪的基础金币，活动倍率按它算 */
    public int $baseGold;

    public function __construct(int $uid, int $level, int $kills, int $baseGold)
    {
        $this->uid = $uid;
        $this->level = $level;
        $this->kills = $kills;
        $this->baseGold = $baseGold;
    }
}
