<?php

// 阶段 1.5：验证 Room 的可扩展性改造真的生效。
//
// 改造前 Room 把状态语义焊死在 1..4：stateName() 对第五个状态返回 "closed"（错的，
// 不是"未知"），loadFrom() 把第五个状态改成 WAITING（恢复出来是错的）。
// 这个文件就是证明这两个洞补上了。

use Ddz\Card;
use Ddz\Pattern;
use TSF\Room\Member;
use TSF\Room\Room;
use TSF\Room\RoomManager;
use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

/** 斗地主的「叫地主」状态。业务自定义，框架不认识 */
const DDZ_CALLING = 5;

function buildRoom(int $id): Room
{
    $room = new Room($id, 3);

    // 业务声明：叫地主阶段允许进人（还能观战）、但不推帧
    $room->setStateName(DDZ_CALLING, "calling");
    $room->setJoinableStates([Room::WAITING, DDZ_CALLING]);
    $room->setRunningStates([Room::PLAYING]);

    // 转移：等待 --call--> 叫地主 --start--> 进行
    $room->fsm()->allow(Room::WAITING, "call", DDZ_CALLING);
    $room->fsm()->allow(DDZ_CALLING, "start", Room::PLAYING);
    return $room;
}

function probeFlexibleStates(): void
{
    echo "=== 自定义状态能否工作 ===" . PHP_EOL;

    $room = buildRoom(1);
    echo "  初始: " . $room->stateName() . PHP_EOL;

    echo "  WAITING 进人: " . ($room->add(1001) ? "ok" : "no") . PHP_EOL;

    $fired = $room->fsm()->fire("call");
    echo "  fire(call): " . ($fired ? "ok" : "fail")
        . " → state=" . $room->state() . " name=" . $room->stateName() . PHP_EOL;

    // 关键 1：第五个状态的名字不是 "closed" 了
    echo "  名字正确: " . ($room->stateName() === "calling" ? "OK" : "FAIL(" . $room->stateName() . ")") . PHP_EOL;

    // 关键 2：CALLING 允许进人（业务声明过）
    echo "  CALLING 进人: " . ($room->add(1002) ? "ok" : "no") . PHP_EOL;

    // 关键 3：CALLING 不推帧
    echo "  CALLING 推帧: " . ($room->tick(50) ? "advanced" : "blocked") . "（应为 blocked）" . PHP_EOL;

    $room->fsm()->fire("start");
    echo "  fire(start) → " . $room->stateName()
        . "，推帧: " . ($room->tick(50) ? "advanced" : "blocked") . "（应为 advanced）" . PHP_EOL;

    // 关键 4：loadFrom 能恢复自定义状态，不被改成 WAITING
    $fresh = buildRoom(2);
    $ok = $fresh->loadFrom(["state" => DDZ_CALLING, "members" => []]);
    echo "  loadFrom(CALLING): " . ($ok ? "ok" : "fail")
        . " → state=" . $fresh->stateName()
        . ($fresh->state() === DDZ_CALLING ? " OK" : " FAIL(被改写)") . PHP_EOL;
}

/** 只为了验证「业务 Service 能注入 RoomManager」 */
final class DdzProbeService extends Service
{
    public int $roomId = 0;
    public int $max = 0;

    public function __construct(int $roomId, int $max)
    {
        $this->roomId = $roomId;
        $this->max = $max;
    }

    public function onMessage(Message $m): void
    {
        // 斗地主的出牌、叫地主都从这里进 —— 默认的 RoomService 承载不了
    }
}

function probeInjectedService(): void
{
    echo PHP_EOL . "=== 业务 Service 注入 ===" . PHP_EOL;

    $mgr = new RoomManager(RoomManager::MODE_DEDICATED, 50, 200);
    $roomId = $mgr->create(3, false, function (int $id, int $max): Service {
        return new DdzProbeService($id, $max);
    });
    echo "  create 返回 roomId=" . $roomId . PHP_EOL;

    $addr = $mgr->addrOf($roomId);
    echo "  房间服务地址=" . $addr . PHP_EOL;

    $svc = Scheduler::instance()->serviceOf($addr);
    $isOurs = $svc instanceof DdzProbeService;
    echo "  注入生效: " . ($isOurs ? "OK（拿到的是 DdzProbeService）" : "FAIL（仍是默认 RoomService）") . PHP_EOL;
}

function main(): void
{
    probeFlexibleStates();
    probeInjectedService();

    echo PHP_EOL . "=== 牌型判定回归（确认没被改坏）===" . PHP_EOL;
    $p = Pattern::parse([0, 4, 8, 12, 16]);
    echo "  顺子: " . ($p !== null ? $p->name() : "非法") . PHP_EOL;
    $pl = Pattern::parse([0, 1, 2, 4, 5, 6]);
    echo "  飞机: " . ($pl !== null ? $pl->name() : "非法") . PHP_EOL;
}
