<?php

namespace Ddz\App;

use Ddz\Domain\Card;
use Ddz\Domain\Doudizhu;
use Ddz\Protocol\Cmd;
use Ddz\Protocol\RoomTask;
use TSF\Gateway\Gateway;
use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

/**
 * 一个房间 = 一个 Service。
 *
 * 三个座位、一局斗地主。人满自动开局，打完广播结算然后自我销毁
 * （并把房间号告诉大厅，让列表把它摘掉）。
 *
 * ## 为什么状态全放成员变量
 *
 * 静态属性在进程里是全局的：两个房间同时开，静态的手牌会互相覆盖。
 * 而 Service 的成员变量天然是"这一个房间"的——销毁时一起没了。
 */
final class RoomService extends Service
{
    private Gateway $gw;

    private int $roomId;

    private int $lobbyAddr;

    /** @var array<int, int> 座位 => uid（0 表示空位） */
    private array $seats = [0, 0, 0];

    /** @var array<int, int> 座位 => 连接的 fd */
    private array $fds = [0, 0, 0];

    /** @var array<int, int> uid => 座位 */
    private array $seatOf = [];

    private Doudizhu $game;

    private bool $started = false;

    public function __construct(Gateway $gw, int $roomId, int $lobbyAddr)
    {
        $this->gw = $gw;
        $this->roomId = $roomId;
        $this->lobbyAddr = $lobbyAddr;
        $this->game = new Doudizhu();
    }

    public function onMessage(Message $m): void
    {
        if ($m->msgId !== Cmd::SVC_ROOM || !($m->data instanceof RoomTask)) {
            return;
        }
        $t = $m->data;
        if ($t->op === RoomTask::OP_JOIN) {
            $this->onJoin($t);
        } elseif ($t->op === RoomTask::OP_LEAVE) {
            $this->onLeave($t);
        } elseif ($t->op === RoomTask::OP_PLAY) {
            $this->onPlay($t);
        } elseif ($t->op === RoomTask::OP_PASS) {
            $this->onPass($t);
        }
    }

    private function onJoin(RoomTask $t): void
    {
        if (isset($this->seatOf[$t->uid])) {
            return;   // 已经在里面了
        }
        $seat = $this->freeSeat();
        if ($seat < 0) {
            $this->gw->send($t->fd, Cmd::ERR_RESP, "房间已满");
            return;
        }
        $this->seats[$seat] = $t->uid;
        $this->fds[$seat] = $t->fd;
        $this->seatOf[$t->uid] = $seat;

        $this->broadcast(Cmd::PUSH_STATE, (string) json_encode([
            "event" => "join",
            "seat" => $seat,
            "uid" => $t->uid,
            "seated" => count($this->seatOf),
            "roomId" => $this->roomId,
        ]));

        // 三人齐了自动开局（真实产品这里是"所有人点准备"，这里简化）
        if (count($this->seatOf) === Doudizhu::SEATS && !$this->started) {
            $this->startGame();
        }
    }

    private function onLeave(RoomTask $t): void
    {
        $seat = $this->seatOf[$t->uid] ?? -1;
        if ($seat < 0) {
            return;
        }
        unset($this->seatOf[$t->uid]);
        $this->seats[$seat] = 0;
        $this->fds[$seat] = 0;

        $this->broadcast(Cmd::PUSH_STATE, (string) json_encode([
            "event" => "leave",
            "seat" => $seat,
            "uid" => $t->uid,
        ]));

        // 没开局就没人了 → 直接散房
        if (!$this->started && count($this->seatOf) === 0) {
            $this->close();
        }
    }

    private function startGame(): void
    {
        $this->game->start(0, Card::shuffle(Card::deck()));
        $this->started = true;

        for ($s = 0; $s < Doudizhu::SEATS; $s++) {
            if ($this->fds[$s] === 0) {
                continue;
            }
            $json = json_encode([
                "hand" => $this->game->handOf($s),
                "landlord" => $this->game->landlord(),
                "seat" => $s,
                "turn" => $this->game->currentTurn(),
                "bottom" => $this->game->bottom(),
            ]);
            $this->gw->send($this->fds[$s], Cmd::PUSH_DEAL, is_string($json) ? $json : "{}");
        }
        $this->pushState();
    }

    private function onPlay(RoomTask $t): void
    {
        $seat = $this->seatOf[$t->uid] ?? -1;
        if ($seat < 0 || !$this->started) {
            return;
        }
        if (!$this->game->play($seat, $t->cards)) {
            $this->gw->send($t->fd, Cmd::ERR_RESP, "出牌不合法（牌型不对 / 压不过 / 不是你的回合）");
            return;
        }
        $this->gw->send($t->fd, Cmd::PLAY_RESP, "ok");

        $json = json_encode([
            "event" => "play",
            "seat" => $seat,
            "uid" => $t->uid,
            "cards" => $t->cards,
            "left" => count($this->game->handOf($seat)),
        ]);
        $this->broadcast(Cmd::PUSH_PLAY, is_string($json) ? $json : "{}");

        if ($this->game->isOver()) {
            $this->settle();
            return;
        }
        $this->pushState();
    }

    private function onPass(RoomTask $t): void
    {
        $seat = $this->seatOf[$t->uid] ?? -1;
        if ($seat < 0 || !$this->started) {
            return;
        }
        if (!$this->game->pass($seat)) {
            $this->gw->send($t->fd, Cmd::ERR_RESP, "现在不能过牌（你是自由出牌）");
            return;
        }
        $this->broadcast(Cmd::PUSH_PLAY, (string) json_encode([
            "event" => "pass",
            "seat" => $seat,
            "uid" => $t->uid,
        ]));
        $this->pushState();
    }

    private function settle(): void
    {
        $json = json_encode([
            "event" => "over",
            "winner" => $this->game->winnerSeat(),
            "landlord" => $this->game->landlord(),
            "bombs" => $this->game->bombCount(),
            "multiplier" => $this->game->multiplier(),
            "hands" => [
                $this->game->handOf(0),
                $this->game->handOf(1),
                $this->game->handOf(2),
            ],
        ]);
        $this->broadcast(Cmd::PUSH_OVER, is_string($json) ? $json : "{}");
        $this->close();
    }

    /** 通知大厅摘掉列表项，然后销毁自己 */
    private function close(): void
    {
        $task = new RoomTask();
        $task->op = 0;
        $task->uid = $this->roomId;   // 复用 uid 字段传 roomId
        Scheduler::instance()->deliverAs($this->addr(), $this->lobbyAddr, Cmd::SVC_ROOM_CLOSED, $task);

        // 调用点都在 onMessage 的末尾（settle/散房），之后不再碰 $this，
        // 所以可以直接 kill。框架在 dispatch 的 finally 里会清 busy 标记。
        Scheduler::instance()->kill($this->addr());
    }

    private function freeSeat(): int
    {
        for ($s = 0; $s < Doudizhu::SEATS; $s++) {
            if ($this->seats[$s] === 0) {
                return $s;
            }
        }
        return -1;
    }

    private function pushState(): void
    {
        $counts = [
            count($this->game->handOf(0)),
            count($this->game->handOf(1)),
            count($this->game->handOf(2)),
        ];
        $turn = $this->started ? $this->game->currentTurn() : -1;
        $json = json_encode([
            "event" => "state",
            "roomId" => $this->roomId,
            "seats" => $this->seats,
            "counts" => $counts,
            "turn" => $turn,
            "landlord" => $this->game->landlord(),
        ]);
        $this->broadcast(Cmd::PUSH_STATE, is_string($json) ? $json : "{}");
    }

    private function broadcast(int $msgId, string $body): void
    {
        for ($s = 0; $s < Doudizhu::SEATS; $s++) {
            if ($this->fds[$s] !== 0) {
                $this->gw->send($this->fds[$s], $msgId, $body);
            }
        }
    }
}
