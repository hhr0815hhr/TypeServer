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

    public const PHASE_PLAYING = 1;

    public const PHASE_OVER = 2;

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

    /**
     * 开局。$deck 必须是洗好的 54 张。
     *
     * @param int[] $deck
     */
    public function start(int $landlordSeat, array $deck): void
    {
        if (count($deck) !== 54) {
            throw new \RuntimeException("发牌需要 54 张，收到 " . count($deck));
        }
        if ($landlordSeat < 0 || $landlordSeat >= self::SEATS) {
            throw new \RuntimeException("座位号越界: " . $landlordSeat);
        }

        $hands = [[], [], []];
        for ($i = 0; $i < 51; $i++) {
            $hands[$i % self::SEATS][] = $deck[$i];
        }
        $bottom = [$deck[51], $deck[52], $deck[53]];

        // 地主拿底牌
        foreach ($bottom as $c) {
            $hands[$landlordSeat][] = $c;
        }

        for ($s = 0; $s < self::SEATS; $s++) {
            $hands[$s] = Card::sort($hands[$s]);
        }

        $this->hands = $hands;
        $this->bottom = $bottom;
        $this->landlord = $landlordSeat;
        $this->turn = $landlordSeat;
        $this->phase = self::PHASE_PLAYING;
        $this->winner = -1;
        $this->toBeat = null;
        $this->toBeatSeat = -1;
        $this->passes = 0;
        $this->bombs = 0;
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

    /** 炸弹翻倍：底分 × 2^炸弹数 */
    public function multiplier(): int
    {
        return 1 << $this->bombs;
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
