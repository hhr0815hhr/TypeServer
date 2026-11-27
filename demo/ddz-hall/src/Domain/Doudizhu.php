<?php

namespace Ddz\Domain;

/**
 * 一局斗地主的状态机：发牌 → 出牌 → 结算。
 *
 * 这里**没有**网络、没有 Service、没有定时器。给它一个洗好的牌堆和一个
 * 座位号，它就推进一局。这样"出牌规则对不对"可以脱离一切环境验证，
 * 而 App 层只负责把网络消息翻译成 `play()` / `pass()` 的调用。
 *
 * ## 简化
 *
 * 没有"叫地主"的过程：建房时由房主当地主，直接拿底牌。真实产品里这一步是
 * 一轮叫分（1分/2分/3分/不叫），但它只影响"谁当地主"，不影响出牌与结算的
 * 正确性——需要时在 `start()` 前面加一个叫分阶段即可。
 */
final class Doudizhu
{
    public const SEATS = 3;

    public const PHASE_IDLE = 0;

    public const PHASE_BIDDING = 1;

    public const PHASE_PLAYING = 2;

    public const PHASE_OVER = 3;

    /** @var array<int, int[]> 座位 => 手牌 */
    private array $hands = [];

    /** @var int[] 底牌（三张） */
    private array $bottom = [];

    private int $landlord = 0;

    private int $turn = 0;

    private int $phase = self::PHASE_IDLE;

    private int $winner = -1;

    /** 当前需要压过的牌型；null 表示自由出牌 */
    private ?Pattern $toBeat = null;

    /** 当前 toBeat 是谁出的（过牌两次后要轮回到他） */
    private int $toBeatSeat = -1;

    private int $passes = 0;

    /** 炸弹/王炸次数，用于翻倍 */
    private int $bombs = 0;

    /** @var array<int, int> 座位 => 叫分（0 表示不叫） */
    private array $bids = [0, 0, 0];

    /** 当前该谁叫分 */
    private int $bidTurn = 0;

    /** 已经叫过几家 */
    private int $bidCount = 0;

    /** 当前最高叫分与叫的人 */
    private int $highestScore = 0;

    private int $highestSeat = -1;

    /**
     * 开局：发 51 张，留 3 张底牌，进入叫分阶段。
     *
     * 底牌**先不发**——谁叫到分谁拿。上一版直接发给"座位 0"是简化，
     * 现在补上叫分，地主要等 `bid()` 定下来才知道。
     *
     * @param int[] $deck 洗好的 54 张
     */
    public function start(array $deck): void
    {
        if (count($deck) !== 54) {
            throw new \RuntimeException("发牌需要 54 张，收到 " . count($deck));
        }

        $hands = [[], [], []];
        for ($i = 0; $i < 51; $i++) {
            $hands[$i % self::SEATS][] = $deck[$i];
        }
        $bottom = [$deck[51], $deck[52], $deck[53]];

        for ($s = 0; $s < self::SEATS; $s++) {
            $hands[$s] = Card::sort($hands[$s]);
        }

        $this->hands = $hands;
        $this->bottom = $bottom;
        $this->landlord = -1;
        $this->turn = 0;
        $this->phase = self::PHASE_BIDDING;
        $this->winner = -1;
        $this->toBeat = null;
        $this->toBeatSeat = -1;
        $this->passes = 0;
        $this->bombs = 0;
        $this->bids = [0, 0, 0];
        $this->bidTurn = 0;
        $this->bidCount = 0;
        $this->highestScore = 0;
        $this->highestSeat = -1;
    }

    /**
     * 叫分。$score 取 0（不叫）1/2/3，必须比当前最高分高。
     *
     * 返回**叫分阶段是否结束**（不是"这次叫分是否成功"——失败返回 false 且不改状态）。
     * 结束后要么有人当地主进 PLAYING，要么三家都不叫流局进 OVER。
     */
    public function bid(int $seat, int $score): bool
    {
        if ($this->phase !== self::PHASE_BIDDING || $seat !== $this->bidTurn) {
            return false;
        }
        if ($score < 0 || $score > 3) {
            return false;
        }
        if ($score !== 0 && $score <= $this->highestScore) {
            return false;
        }

        $this->bids[$seat] = $score;
        if ($score > $this->highestScore) {
            $this->highestScore = $score;
            $this->highestSeat = $seat;
        }
        $this->bidCount++;

        // 叫到 3 分直接结束；三家轮完也结束
        if ($score === 3 || $this->bidCount >= self::SEATS) {
            $this->finishBidding();
            return true;
        }
        $this->bidTurn = ($seat + 1) % self::SEATS;
        return false;
    }

    private function finishBidding(): void
    {
        if ($this->highestSeat < 0) {
            // 三家都不叫：流局。调用方应该重新发牌
            $this->phase = self::PHASE_OVER;
            $this->winner = -1;
            return;
        }
        $this->landlord = $this->highestSeat;
        foreach ($this->bottom as $c) {
            $this->hands[$this->landlord][] = $c;
        }
        $this->hands[$this->landlord] = Card::sort($this->hands[$this->landlord]);
        $this->phase = self::PHASE_PLAYING;
        $this->turn = $this->landlord;
    }

    /**
     * 出牌。成功返回 true，失败返回 false（调用方负责把原因告诉客户端）。
     *
     * @param int[] $cards
     */
    public function play(int $seat, array $cards): bool
    {
        if ($this->phase !== self::PHASE_PLAYING || $seat !== $this->turn) {
            return false;
        }
        if ($cards === [] || !$this->hasCards($seat, $cards)) {
            return false;
        }

        $p = Pattern::analyze($cards);
        if ($p === null) {
            return false;
        }
        if ($this->toBeat !== null && !Pattern::beats($p, $this->toBeat)) {
            return false;
        }

        $this->removeCards($seat, $cards);
        $this->toBeat = $p;
        $this->toBeatSeat = $seat;
        $this->passes = 0;
        if ($p->type === Pattern::BOMB || $p->type === Pattern::ROCKET) {
            $this->bombs++;
        }

        if ($this->hands[$seat] === []) {
            $this->phase = self::PHASE_OVER;
            $this->winner = $seat;
            return true;
        }

        $this->turn = ($seat + 1) % self::SEATS;
        return true;
    }

    /** 过牌。自由出牌时不能过（那是"必须出牌"的局面） */
    public function pass(int $seat): bool
    {
        if ($this->phase !== self::PHASE_PLAYING || $seat !== $this->turn) {
            return false;
        }
        if ($this->toBeat === null) {
            return false;
        }

        $this->passes++;
        $this->turn = ($seat + 1) % self::SEATS;

        // 另外两家都过了 → 回到出牌者，他自由出牌
        if ($this->passes >= 2) {
            $this->turn = $this->toBeatSeat;
            $this->toBeat = null;
            $this->toBeatSeat = -1;
            $this->passes = 0;
        }
        return true;
    }

    /** @return int[] */
    public function handOf(int $seat): array
    {
        return $this->hands[$seat] ?? [];
    }

    /** @return int[] */
    public function bottom(): array
    {
        return $this->bottom;
    }

    public function landlord(): int
    {
        return $this->landlord;
    }

    /** 叫分阶段该谁叫 */
    public function bidTurn(): int
    {
        return $this->bidTurn;
    }

    /** @return array<int, int> 座位 => 叫分 */
    public function bids(): array
    {
        return $this->bids;
    }

    public function highestBid(): int
    {
        return $this->highestScore;
    }

    public function isBidding(): bool
    {
        return $this->phase === self::PHASE_BIDDING;
    }

    /** 三家都不叫的流局（没有赢家） */
    public function isDraw(): bool
    {
        return $this->phase === self::PHASE_OVER && $this->winner < 0;
    }

    public function currentTurn(): int
    {
        return $this->turn;
    }

    public function phase(): int
    {
        return $this->phase;
    }

    public function isOver(): bool
    {
        return $this->phase === self::PHASE_OVER;
    }

    public function winnerSeat(): int
    {
        return $this->winner;
    }

    /** 倍数 = 叫分 × 2^炸弹数。没人叫分（流局）时按 1 算，避免 0 倍 */
    public function multiplier(): int
    {
        $base = $this->highestScore > 0 ? $this->highestScore : 1;
        return $base * (1 << $this->bombs);
    }

    public function bombCount(): int
    {
        return $this->bombs;
    }

    /** 当前要压的牌型（null = 自由出牌）；给客户端显示"该出什么"用 */
    public function toBeat(): ?Pattern
    {
        return $this->toBeat;
    }

    /** @param int[] $cards */
    private function hasCards(int $seat, array $cards): bool
    {
        $count = [];
        foreach ($this->hands[$seat] as $c) {
            if (!isset($count[$c])) {
                $count[$c] = 0;
            }
            $count[$c]++;
        }
        foreach ($cards as $c) {
            if (!isset($count[$c]) || $count[$c] <= 0) {
                return false;
            }
            $count[$c]--;
        }
        return true;
    }

    /** @param int[] $cards */
    private function removeCards(int $seat, array $cards): void
    {
        $drop = [];
        foreach ($cards as $c) {
            $drop[$c] = true;
        }
        $kept = [];
        foreach ($this->hands[$seat] as $c) {
            if (!isset($drop[$c])) {
                $kept[] = $c;
            }
        }
        $this->hands[$seat] = $kept;
    }
}
