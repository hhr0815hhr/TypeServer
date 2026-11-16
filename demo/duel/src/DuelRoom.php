<?php

namespace Duel;

use TSF\Entity\EntitySet;
use TSF\Room\Room;

/**
 * 1v1 对战规则，架在通用 `Room` 之上。
 *
 * 分工：
 *   Room       负责「成员、状态机（WAITING → PLAYING → SETTLED）」，**不管坐标**；
 *   EntitySet  负责「位置与推进」，坐标只存在这里；
 *   本类       只加「血量、攻击、胜负」。
 *
 * 为什么坐标不在 Room 里：棋牌房间也用 Room，但它没有坐标。
 * 把坐标塞进 Room 等于让斗地主也持有一个 (x, y, vx, vy)。
 *
 * 通用房间不该认识 hp——那是玩法字段。所以组合而不是继承，也不用给 Entity 加字段。
 *
 * 坐标沿用 Entity 的场地 0..10000（1 单位 = 1 厘米），所以攻击范围写成 1500。
 */
final class DuelRoom
{
    public const MAX_HP = 100;

    public const ATTACK_DAMAGE = 20;

    /** 攻击距离（场地 0..10000 的 15%） */
    public const ATTACK_RANGE = 1500;

    /** 攻击冷却 */
    public const ATTACK_CD_MS = 800;

    /** 一局上限；超时按血量判定 */
    public const TIME_LIMIT_MS = 60000;

    /** 移动速度（单位/秒） */
    public const SPEED = 3000;

    /** 起始位置：一个在左一个在右，中间隔着超出攻击距离的 6000 */
    public const START_LEFT = 2000;

    public const START_RIGHT = 8000;

    private Room $room;

    /**
     * 坐标在这里，不在房间里。
     *
     * `Room` 只记"谁在这局里"，不管人在哪——因为那是可选能力：
     * 棋牌房间同样用 `Room`，但它没有坐标这回事。
     */
    private EntitySet $ents;

    /** @var array<int, int> uid => hp */
    private array $hp = [];

    /** @var array<int, int> uid => 上次攻击的单调毫秒 */
    private array $lastAttack = [];

    private int $elapsedMs = 0;

    /** 0 = 未结束；>0 = 赢家 uid；-1 = 平局 */
    private int $winner = 0;

    public function __construct(int $roomId, ?Room $room = null)
    {
        // 允许外部传入已恢复的 Room：崩溃恢复时不能"新建一个空房间再灌数据"，
        // 那会丢掉 Room 内部的成员顺序（哈希就不稳定了）
        $this->room = $room !== null ? $room : new Room($roomId, 2);
        $this->ents = new EntitySet();
    }

    public function room(): Room
    {
        return $this->room;
    }

    /** 加入并摆位：先来的在左，后来的在右 */
    public function join(int $uid): bool
    {
        if (!$this->room->add($uid)) {
            return false;
        }
        // 先 add 再判断人数：spawn 之前 playerCount 已经算上新来的，
        // 所以"第一个进来的人"是 playerCount() === 1
        $left = $this->room->playerCount() === 1;
        $this->ents->spawn($uid, $left ? self::START_LEFT : self::START_RIGHT, 5000);
        $this->hp[$uid] = self::MAX_HP;
        $this->lastAttack[$uid] = 0;
        return true;
    }

    /** 满员即开战 */
    public function ready(): bool
    {
        if ($this->room->playerCount() !== 2) {
            return false;
        }
        return $this->room->start();
    }

    public function isSettled(): bool
    {
        return $this->winner !== 0;
    }

    public function winner(): int
    {
        return $this->winner;
    }

    public function elapsedMs(): int
    {
        return $this->elapsedMs;
    }

    /** 每帧：推进位置 + 检查超时。已结束就不再推进 */
    public function tick(int $deltaMs): void
    {
        if ($this->isSettled()) {
            return;
        }
        $this->elapsedMs += $deltaMs;
        $this->room->tick($deltaMs);   // 只记帧号与累计时间
        $this->ents->tick($deltaMs);   // 坐标推进在 EntitySet 里
        if ($this->elapsedMs >= self::TIME_LIMIT_MS) {
            $this->settleByHp();
        }
    }

    /**
     * 设置移动方向。$dir only -1 / 0 / 1。
     *
     * 接受 ±1 而不是"目标坐标"：客户端只表达意图，位置由服务端帧推进决定，
     * 这样客户端改不了坐标，也不受延迟影响。
     */
    public function setDir(int $uid, int $dir): bool
    {
        if ($this->isSettled()) {
            return false;
        }
        $e = $this->ents->get($uid);
        if ($e === null) {
            return false;
        }
        if ($dir > 0) {
            $e->vx = self::SPEED;
        } elseif ($dir < 0) {
            $e->vx = -self::SPEED;
        } else {
            $e->vx = 0;
        }
        return true;
    }

    /**
     * 攻击。返回是否命中（冷却中 / 够不着 / 已结束都返回 false）。
     *
     * 攻击判定放在服务端：客户端只说"我要打"，命中与否由距离和冷却决定。
     */
    public function attack(int $uid, int $nowMs): bool
    {
        if ($this->isSettled()) {
            return false;
        }
        $me = $this->ents->get($uid);
        $foeUid = $this->foeOf($uid);
        if ($me === null || $foeUid === 0) {
            return false;
        }
        $foe = $this->ents->get($foeUid);
        if ($foe === null) {
            return false;
        }

        $last = isset($this->lastAttack[$uid]) ? $this->lastAttack[$uid] : 0;
        if ($nowMs - $last < self::ATTACK_CD_MS) {
            return false;
        }

        $dx = $me->x - $foe->x;
        if ($dx < 0) {
            $dx = -$dx;
        }
        if ($dx > self::ATTACK_RANGE) {
            return false;
        }

        $this->lastAttack[$uid] = $nowMs;
        $hp = (isset($this->hp[$foeUid]) ? $this->hp[$foeUid] : 0) - self::ATTACK_DAMAGE;
        if ($hp < 0) {
            $hp = 0;
        }
        $this->hp[$foeUid] = $hp;

        if ($hp <= 0) {
            $this->winner = $uid;
            $this->room->finish();
        }
        return true;
    }

    /** 对手的 uid；房间里没有第二个人时返回 0 */
    public function foeOf(int $uid): int
    {
        foreach ($this->ents->all() as $e) {
            if ($e->uid !== $uid) {
                return $e->uid;
            }
        }
        return 0;
    }

    public function hpOf(int $uid): int
    {
        return isset($this->hp[$uid]) ? $this->hp[$uid] : 0;
    }

    /**
     * 认输 / 掉线判负。
     *
     * 掉线按判负处理，而不是「等 60 秒超时」：让对手干等一分钟是最差的体验，
     * 而且这局占用的房间和定时器一直不释放。对手也不在了就记平局。
     */
    public function forfeit(int $uid): bool
    {
        if ($this->isSettled()) {
            return false;
        }
        // 用成员表判断而不是坐标表：人在不在房间里，跟有没有坐标是两回事
        if (!$this->room->has($uid)) {
            return false;
        }
        $foe = $this->foeOf($uid);
        $this->winner = $foe > 0 ? $foe : -1;
        $this->room->finish();
        return true;
    }

    private function settleByHp(): void
    {
        $bestUid = 0;
        $bestHp = -1;
        $tie = false;
        foreach ($this->ents->all() as $e) {
            $h = isset($this->hp[$e->uid]) ? $this->hp[$e->uid] : 0;
            if ($h > $bestHp) {
                $bestHp = $h;
                $bestUid = $e->uid;
                $tie = false;
            } elseif ($h === $bestHp) {
                $tie = true;
            }
        }
        $this->winner = $tie ? -1 : $bestUid;
        $this->room->finish();
    }

    /**
     * 状态快照。
     *
     * 刻意用扁平结构：AOT 下嵌套数组的类型推断更容易出问题，
     * 而 1v1 只有两个玩家，扁平反而让客户端解析更直接。
     *
     * @return array<string, int>
     */
    public function snapshot(): array
    {
        $uidA = 0;
        $uidB = 0;
        $hpA = 0;
        $hpB = 0;
        $xA = 0;
        $xB = 0;

        $i = 0;
        foreach ($this->ents->all() as $e) {
            if ($i === 0) {
                $uidA = $e->uid;
                $hpA = isset($this->hp[$e->uid]) ? $this->hp[$e->uid] : 0;
                $xA = $e->x;
            } else {
                $uidB = $e->uid;
                $hpB = isset($this->hp[$e->uid]) ? $this->hp[$e->uid] : 0;
                $xB = $e->x;
            }
            $i++;
        }

        return [
            "room" => $this->room->id(),
            "t" => $this->elapsedMs,
            "over" => $this->isSettled() ? 1 : 0,
            "uidA" => $uidA,
            "hpA" => $hpA,
            "xA" => $xA,
            "uidB" => $uidB,
            "hpB" => $hpB,
            "xB" => $xB,
        ];
    }

    /**
     * 导出整局状态。
     *
     * 框架部分（room / ents）交给它们自己的 toArray；玩法部分（hp / 冷却 / 胜负）
     * 由这一层负责——**`pkg/snapshot` 不认识 hp 是什么**，这是刻意的分界。
     */
    public function toArray(): array
    {
        return [
            "room" => $this->room->toArray(),
            "ents" => $this->ents->toArray(),
            "hp" => $this->hp,
            "lastAttack" => $this->lastAttack,
            "elapsedMs" => $this->elapsedMs,
            "winner" => $this->winner,
        ];
    }

    /** 从快照重建。id 或成员数据不合法时返回 null */
    public static function fromArray(array $d): ?DuelRoom
    {
        $roomData = isset($d["room"]) && is_array($d["room"]) ? $d["room"] : [];
        $room = Room::fromArray($roomData);
        if ($room === null) {
            return null;
        }

        $self = new DuelRoom($room->id(), $room);

        $entRows = isset($d["ents"]) && is_array($d["ents"]) ? $d["ents"] : [];
        $self->ents->loadFrom($entRows);

        // JSON 解出来的 key 是字符串，必须转回 int——否则 `hpOf()` 用 int 查不到，
        // 表现为"恢复后所有人的血量都是 0"
        $hpRaw = isset($d["hp"]) && is_array($d["hp"]) ? $d["hp"] : [];
        foreach ($hpRaw as $k => $v) {
            $self->hp[(int) $k] = (int) $v;
        }
        $laRaw = isset($d["lastAttack"]) && is_array($d["lastAttack"]) ? $d["lastAttack"] : [];
        foreach ($laRaw as $k2 => $v2) {
            $self->lastAttack[(int) $k2] = (int) $v2;
        }

        $self->elapsedMs = isset($d["elapsedMs"]) ? (int) $d["elapsedMs"] : 0;
        $self->winner = isset($d["winner"]) ? (int) $d["winner"] : 0;
        return $self;
    }
}
