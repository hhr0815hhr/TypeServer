<?php

namespace Ddz\App;

use Ddz\Protocol\Cmd;
use Ddz\Protocol\LobbyTask;
use Ddz\Protocol\RoomInfo;
use Ddz\Protocol\RoomTask;
use TSF\Gateway\Gateway;
use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

/**
 * 大厅：房间列表 + 房卡 + 建房。
 *
 * 它自己**不跑对局**，只负责三件事：
 *   1. 扣房卡、开房间（spawn 一个 RoomService）
 *   2. 维护房间列表，响应"列表"请求
 *   3. 把"加入/离开"转发给对应的 RoomService
 *
 * ## 房卡放在这里是个简化
 *
 * 真实项目里房卡属于玩家资产，应该在独立的 `PlayerService` + `Infra/Repo`
 * 里（带事务、带流水）。这里为了不让示例膨胀，用进程内的 map 代替，
 * 并在 `Main.php` 里注释了这个取舍。
 */
final class LobbyService extends Service
{
    /** 房卡档位 → 消耗张数 */
    private const PRICES = [1 => 1, 2 => 2, 3 => 5];

    /** 新玩家初始房卡 */
    private const INIT_CARDS = 10;

    private Gateway $gw;

    /** @var array<int, int> uid => 剩余房卡 */
    private array $cards = [];

    /** @var array<int, RoomInfo> roomId => 列表展示信息 */
    private array $rooms = [];

    /** @var array<int, int> roomId => RoomService 的地址 */
    private array $roomAddr = [];

    /** @var array<int, int> uid => 所在 roomId（0 表示不在房间） */
    private array $uidRoom = [];

    private int $nextRoomId = 1;

    public function __construct(Gateway $gw)
    {
        $this->gw = $gw;
    }

    public function onMessage(Message $m): void
    {
        if ($m->msgId === Cmd::SVC_ROOM_CLOSED) {
            // 房间打完了，从列表里摘掉
            if ($m->data instanceof RoomTask) {
                $this->closeRoom($m->data->uid);
            }
            return;
        }
        if ($m->msgId !== Cmd::SVC_LOBBY || !($m->data instanceof LobbyTask)) {
            return;
        }
        $t = $m->data;
        if ($t->op === 1) {
            $this->createRoom($t);
        } elseif ($t->op === 2) {
            $this->joinRoom($t);
        } elseif ($t->op === 3) {
            $this->leaveRoom($t);
        } elseif ($t->op === 4) {
            $this->sendList($t->fd);
        } elseif ($t->op === 5) {
            $this->relay($t, RoomTask::OP_PLAY);
        } elseif ($t->op === 6) {
            $this->relay($t, RoomTask::OP_PASS);
        } elseif ($t->op === 7) {
            $this->relay($t, RoomTask::OP_READY);
        } elseif ($t->op === 8) {
            $this->relay($t, RoomTask::OP_START);
        } elseif ($t->op === 9) {
            $this->relay($t, RoomTask::OP_BID);
        } elseif ($t->op === 10) {
            $this->relay($t, RoomTask::OP_AGAIN);
        } elseif ($t->op === 11) {
            // 断线：只转给房间，不清 uidRoom —— 座位要留着重连用
            $this->relay($t, RoomTask::OP_DISCONNECT);
        }
    }

    /**
     * 把"出牌/过牌"转给玩家所在的房间。
     *
     * 为什么不让 main.php 直接发给房间：那里不知道 uid 在哪个房间。
     * 让它自己维护一份 uid→房间 的映射，就是同一个事实存两处，
     * 迟早不一致（一边改了另一边没改）。统一从大厅查。
     */
    private function relay(LobbyTask $t, int $roomOp): void
    {
        $roomId = $this->uidRoom[$t->uid] ?? 0;
        if ($roomId === 0 || !isset($this->roomAddr[$roomId])) {
            $this->err($t->fd, "你不在房间里");
            return;
        }
        $task = new RoomTask();
        $task->op = $roomOp;
        $task->uid = $t->uid;
        $task->fd = $t->fd;
        $task->cards = $t->cards;
        $task->score = $t->score;
        Scheduler::instance()->deliverAs($this->addr(), $this->roomAddr[$roomId], Cmd::SVC_ROOM, $task);
    }

    private function createRoom(LobbyTask $t): void
    {
        $price = self::PRICES[$t->level] ?? 0;
        if ($price === 0) {
            $this->err($t->fd, "房卡档位不存在");
            return;
        }
        // 已经在别的房间：自动退掉再建。
        //
        // 早先是直接报"你已经在房间里了"，但断线重连的场景里，服务端还留着
        // 30 秒的座位，用户这时点建房会撞上一条他已经看不见的旧房间，
        // 而且没有任何操作能自己解开（前端没有"离开"按钮可用，因为页面重开过）。
        $cur = $this->uidRoom[$t->uid] ?? 0;
        if ($cur > 0 && isset($this->roomAddr[$cur])) {
            $leave = new RoomTask();
            $leave->op = RoomTask::OP_LEAVE;
            $leave->uid = $t->uid;
            $leave->fd = $t->fd;
            Scheduler::instance()->deliverAs($this->addr(), $this->roomAddr[$cur], Cmd::SVC_ROOM, $leave);
            if (isset($this->rooms[$cur]) && $this->rooms[$cur]->seated > 0) {
                $this->rooms[$cur]->seated--;
            }
        }
        unset($this->uidRoom[$t->uid]);

        $left = $this->cards[$t->uid] ?? self::INIT_CARDS;
        if ($left < $price) {
            $this->err($t->fd, "房卡不足（需要 " . $price . " 张，你有 " . $left . " 张）");
            return;
        }
        $this->cards[$t->uid] = $left - $price;

        $roomId = $this->nextRoomId++;
        $gw = $this->gw;
        $lobbyAddr = $this->addr();
        $level = $t->level;
        $addr = Scheduler::instance()->spawnOf(function () use ($gw, $roomId, $lobbyAddr, $level): Service {
            return new RoomService($gw, $roomId, $lobbyAddr, $level);
        });

        $info = new RoomInfo();
        $info->roomId = $roomId;
        $info->level = $t->level;
        $info->seated = 1;   // 房主马上入座
        $info->phase = 0;
        $info->owner = $t->uid;

        $this->rooms[$roomId] = $info;
        $this->roomAddr[$roomId] = $addr;
        $this->uidRoom[$t->uid] = $roomId;

        $this->gw->send($t->fd, Cmd::CREATE_RESP, (string) json_encode([
            "roomId" => $roomId,
            "cards" => $this->cards[$t->uid],
        ]));

        // 房主自动入座
        $task = new RoomTask();
        $task->op = RoomTask::OP_JOIN;
        $task->uid = $t->uid;
        $task->fd = $t->fd;
        Scheduler::instance()->deliverAs($this->addr(), $addr, Cmd::SVC_ROOM, $task);
    }

    private function joinRoom(LobbyTask $t): void
    {
        $roomId = $t->roomId;
        if (!isset($this->roomAddr[$roomId])) {
            $this->err($t->fd, "房间不存在或已结束");
            return;
        }
        // 已在房间里：如果就是这一间，当重连处理（转发即可，不重复计数）
        $cur = $this->uidRoom[$t->uid] ?? 0;
        if ($cur > 0) {
            if ($cur === $roomId) {
                $task = new RoomTask();
                $task->op = RoomTask::OP_JOIN;
                $task->uid = $t->uid;
                $task->fd = $t->fd;
                Scheduler::instance()->deliverAs($this->addr(), $this->roomAddr[$roomId], Cmd::SVC_ROOM, $task);
                return;
            }
            $this->err($t->fd, "你已经在房间 " . $cur . " 里了");
            return;
        }
        $task = new RoomTask();
        $task->op = RoomTask::OP_JOIN;
        $task->uid = $t->uid;
        $task->fd = $t->fd;
        Scheduler::instance()->deliverAs($this->addr(), $this->roomAddr[$roomId], Cmd::SVC_ROOM, $task);

        $this->uidRoom[$t->uid] = $roomId;
        $this->rooms[$roomId]->seated++;
        $this->gw->send($t->fd, Cmd::JOIN_RESP, (string) json_encode(["roomId" => $roomId]));
    }

    private function leaveRoom(LobbyTask $t): void
    {
        $roomId = $this->uidRoom[$t->uid] ?? 0;
        if ($roomId === 0 || !isset($this->roomAddr[$roomId])) {
            unset($this->uidRoom[$t->uid]);
            return;
        }
        $task = new RoomTask();
        $task->op = RoomTask::OP_LEAVE;
        $task->uid = $t->uid;
        $task->fd = $t->fd;
        Scheduler::instance()->deliverAs($this->addr(), $this->roomAddr[$roomId], Cmd::SVC_ROOM, $task);
        unset($this->uidRoom[$t->uid]);
        if (isset($this->rooms[$roomId]) && $this->rooms[$roomId]->seated > 0) {
            $this->rooms[$roomId]->seated--;
        }
    }

    /**
     * 房间结束：摘掉列表项、踢掉还在里面的人。
     *
     * 参数是 roomId（RoomTask 的 uid 字段在这里复用为 roomId——
     * 因为"房间结束"这件事没有别的信息要带）。
     */
    private function closeRoom(int $roomId): void
    {
        unset($this->rooms[$roomId], $this->roomAddr[$roomId]);
        foreach ($this->uidRoom as $uid => $rid) {
            if ($rid === $roomId) {
                unset($this->uidRoom[$uid]);
            }
        }
    }

    /**
     * 回房间列表。
     *
     * 这个方法是 private：大厅的状态只有自己改，外部想看就发消息进来。
     * 早先写成 public 的 `roomList()` 让房间直接调，等于把两个 Service
     * 焊在一个进程里——拆进程那天会发现调不通（actor 之间只能发消息）。
     */
    private function sendList(int $fd): void
    {
        $rows = [];
        foreach ($this->rooms as $info) {
            $rows[] = [
                "roomId" => $info->roomId,
                "level" => $info->level,
                "seated" => $info->seated,
                "phase" => $info->phase,
                "owner" => $info->owner,
            ];
        }
        $json = json_encode(["rooms" => $rows]);
        $this->gw->send($fd, Cmd::LIST_RESP, is_string($json) ? $json : "{\"rooms\":[]}");
    }

    private function err(int $fd, string $msg): void
    {
        $this->gw->send($fd, Cmd::ERR_RESP, $msg);
    }
}
