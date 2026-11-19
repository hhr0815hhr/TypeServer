<?php

namespace Game\Battle;

/**
 * L2 玩家战斗状态：纯数据，不含任何规则。
 *
 * 为什么是 public 属性而不是 getter/setter：
 * 这一层每帧要被 AOI 与结算遍历几十次，一次方法调用在 AOT 下仍是几十纳秒；
 * 而这里没有"赋值需要校验"的需求——所有写入都发生在 BattleRoom 内部，
 * 外部拿不到实例。用 getter 只会让调用点更长，不会更安全。
 *
 * 规则一律留在 BattleRoom：玩家对象不知道自己如何被伤害，
 * 这样换一套数值体系不用动这个类。
 */
final class BattlePlayer
{
    public int $uid;

    public int $x;

    public int $y;

    public int $hp;

    public int $maxHp;

    public int $atk;

    /** 客户端意图速度（单位/秒），服务端换算后写入实体 */
    public int $speed;

    /** 下一次可施法的房间时刻（毫秒）。房间时间轴，不是墙上时间 */
    public int $nextCastMs = 0;

    /** 复活时刻（房间时间轴毫秒）；0 表示活着或未排期 */
    public int $respawnAtMs = 0;

    public int $kills = 0;

    public int $deaths = 0;

    /** 进图时刻（房间时间轴毫秒），用于统计在线时长 */
    public int $joinedMs = 0;

    public function __construct(int $uid, int $x, int $y, int $hp, int $atk, int $speed)
    {
        $this->uid = $uid;
        $this->x = $x;
        $this->y = $y;
        $this->hp = $hp;
        $this->maxHp = $hp;
        $this->atk = $atk;
        $this->speed = $speed;
    }

    public function alive(): bool
    {
        return $this->hp > 0;
    }

    /** 复活：满血、清冷却。死亡惩罚与复活位置属于业务策略，由房间决定 */
    public function revive(int $x, int $y, int $nowMs): void
    {
        $this->hp = $this->maxHp;
        $this->x = $x;
        $this->y = $y;
        $this->nextCastMs = $nowMs;
    }
}
