<?php

namespace Ddz;

use TSF\Fsm\Machine;
use TSF\Room\Room;

/**
 * 斗地主的房间：在 Room（成员表 + 状态值）之上加牌局自己的状态。
 *
 * 这些东西没有一处能塞进 Service——它们和座位、和房间生命周期绑定，
 * 且要参与快照。这正是 Room 必须允许派生的理由。
 */
final class DdzRoom extends Room
{
    /** @var array<int, array<int, int>> 座位 => 手牌（升序） */
    public array $hands = [];

    /** @var array<int, int> 三张底牌 */
    public array $bottom = [];

    /** 地主座位，-1 表示还没定 */
    public int $landlord = -1;

    /** 当前该谁出牌（座位号） */
    public int $current = 0;

    /** 最后出牌的座位，-1 表示本轮自由出牌 */
    public int $lastPlaySeat = -1;

    /** @var array<int, int> 最后出的牌 */
    public array $lastPlay = [];

    /** 连续过牌数：到 2 说明另外两家都不要，最后出牌的人重新自由出牌 */
    public int $passCount = 0;

    /** 炸弹/王炸次数，用于倍数 */
    public int $bombs = 0;

    public function __construct(int $id, int $maxPlayers, Machine $fsm)
    {
        parent::__construct($id, $maxPlayers, $fsm);
        // 三家的手牌槽先占好，避免后面 empty() 判断要考虑"键不存在"
        $this->hands = [[], [], []];
        $this->setStateName(DdzState::WAITING, "waiting");
        $this->setStateName(DdzState::CALLING, "calling");
        $this->setStateName(DdzState::PLAYING, "playing");
        $this->setStateName(DdzState::SETTLED, "settled");
    }

    /** 起手牌排序：按 rank 升序，同 rank 按花色 */
    public static function sortHand(array $cards): array
    {
        sort($cards);
        return $cards;
    }

    /** 手牌里有没有这些牌（出牌合法性检查的第一关） */
    public function hasCards(int $seat, array $cards): bool
    {
        $hand = $this->hands[$seat] ?? [];
        $pool = [];
        foreach ($hand as $c) {
            $pool[$c] = ($pool[$c] ?? 0) + 1;
        }
        foreach ($cards as $c) {
            if (!isset($pool[$c]) || $pool[$c] <= 0) {
                return false;
            }
            $pool[$c] = $pool[$c] - 1;
        }
        return true;
    }

    /** 从手牌里移出这些牌，返回新的手牌 */
    public function takeCards(int $seat, array $cards): array
    {
        $hand = $this->hands[$seat] ?? [];
        $drop = [];
        foreach ($cards as $c) {
            $drop[$c] = ($drop[$c] ?? 0) + 1;
        }
        $keep = [];
        foreach ($hand as $c) {
            if (isset($drop[$c]) && $drop[$c] > 0) {
                $drop[$c] = $drop[$c] - 1;
                continue;
            }
            $keep[] = $c;
        }
        return $keep;
    }
}
