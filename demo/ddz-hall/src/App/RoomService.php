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
 * 一个房间 = 一个 Service。三个座位、一局斗地主。
 *
 * ## 阶段
 *
 *   WAITING  等人（座位没坐满）
 *   READY    人齐，等各自点准备、房主点开始
 *   BIDDING  叫分
 *   PLAYING  出牌
 *   OVER     结算完，等房主点"再来一局"或大家离开
 *
 * ## 断线重连
 *
 * 断线不立刻踢人：座位保留 `RECONNECT_MS`，期间用同一个 uid 重新进房就恢复。
 * 超时才真正移除。实现上不"取消定时器"，而是在超时回调里检查标记——
 * 这样重连路径不需要知道定时器 id，少一份要维护的状态。
 *
 * ## 为什么状态全放成员变量
 *
 * 静态属性在进程里是全局的：两个房间同时开，静态的手牌会互相覆盖。
 * 而 Service 的成员变量天然是"这一个房间"的——销毁时一起没了。
 */
final class RoomService extends Service
{
    private const P_WAITING = 0;

    private const P_READY = 1;

    private const P_BIDDING = 2;

    private const P_PLAYING = 3;

    private const P_OVER = 4;

    /** 断线后座位保留时长 */
    private const RECONNECT_MS = 30000;

    private Gateway $gw;

    private int $roomId;

    private int $lobbyAddr;

    /** 底分（房卡档位） */
    private int $level;

    /** @var array<int, int> 座位 => uid（0 表示空位） */
    private array $seats = [0, 0, 0];

    /** @var array<int, int> 座位 => 连接的 fd */
    private array $fds = [0, 0, 0];

    /** @var array<int, int> uid => 座位 */
    private array $seatOf = [];

    /** @var array<int, bool> 座位 => 是否已准备 */
    private array $ready = [false, false, false];

    /** @var array<int, bool> 座位 => 是否断线中 */
    private array $offline = [false, false, false];

    private int $phase = self::P_WAITING;

    private Doudizhu $game;

    /** 房主 uid（先入座的人） */
    private int $owner = 0;

    public function __construct(Gateway $gw, int $roomId, int $lobbyAddr, int $level)
    {
        $this->gw = $gw;
        $this->roomId = $roomId;
        $this->lobbyAddr = $lobbyAddr;
        $this->level = $level;
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
        } elseif ($t->op === RoomTask::OP_READY) {
            $this->onReady($t);
        } elseif ($t->op === RoomTask::OP_START) {
            $this->handleStart($t);
        } elseif ($t->op === RoomTask::OP_BID) {
            $this->onBid($t);
        } elseif ($t->op === RoomTask::OP_PLAY) {
            $this->onPlay($t);
        } elseif ($t->op === RoomTask::OP_PASS) {
            $this->onPass($t);
        } elseif ($t->op === RoomTask::OP_AGAIN) {
            $this->onAgain($t);
        } elseif ($t->op === RoomTask::OP_DISCONNECT) {
            $this->onDisconnect($t);
        } elseif ($t->op === RoomTask::OP_KICK) {
            $this->onKick($t);
        }
    }

    // ---------------- 入座 / 离开 / 断线 ----------------

    private function onJoin(RoomTask $t): void
    {
        // 同 uid 再进来 = 断线重连（或重复 join）：复用座位，恢复推送
        if (isset($this->seatOf[$t->uid])) {
            $seat = $this->seatOf[$t->uid];
            $this->fds[$seat] = $t->fd;
            $this->offline[$seat] = false;
            $this->pushResume($seat);
            return;
        }
        if ($this->phase === self::P_PLAYING || $this->phase === self::P_BIDDING) {
            $this->gw->send($t->fd, Cmd::ERR_RESP, "对局进行中，不能加入");
            return;
        }
        $seat = $this->freeSeat();
        if ($seat < 0) {
            $this->gw->send($t->fd, Cmd::ERR_RESP, "房间已满");
            return;
        }
        $this->seats[$seat] = $t->uid;
        $this->fds[$seat] = $t->fd;
        $this->seatOf[$t->uid] = $seat;
        $this->ready[$seat] = false;
        $this->offline[$seat] = false;
        if ($this->owner === 0) {
            $this->owner = $t->uid;
        }

        $this->gw->send($t->fd, Cmd::JOIN_RESP, (string) json_encode([
            "roomId" => $this->roomId,
            "seat" => $seat,
            "owner" => $this->owner,
        ]));

        // 人齐就从"等人"转"等准备"
        if (count($this->seatOf) === Doudizhu::SEATS && $this->phase === self::P_WAITING) {
            $this->phase = self::P_READY;
        }
        $this->pushState();
    }

    private function onLeave(RoomTask $t): void
    {
        $seat = $this->seatOf[$t->uid] ?? -1;
        if ($seat < 0) {
            return;
        }
        $this->removeSeat($seat);
        $this->pushState();

        // 人都走完了 → 散房
        if (count($this->seatOf) === 0) {
            $this->close();
            return;
        }
        // 房主走了 → 转给下一个还坐着的人
        if ($this->owner === $t->uid) {
            $this->owner = 0;
            for ($s = 0; $s < Doudizhu::SEATS; $s++) {
                if ($this->seats[$s] !== 0) {
                    $this->owner = $this->seats[$s];
                    break;
                }
            }
            $this->pushState();
        }
    }

    private function onDisconnect(RoomTask $t): void
    {
        $seat = $this->seatOf[$t->uid] ?? -1;
        if ($seat < 0) {
            return;
        }
        $this->offline[$seat] = true;
        $this->pushState();

        // 定时踢人。重连时不取消它，让它在超时点检查"还离线吗"
        $kick = new RoomTask();
        $kick->op = RoomTask::OP_KICK;
        $kick->uid = $t->uid;
        $this->timeout(self::RECONNECT_MS, Cmd::SVC_ROOM, $kick);

        if ($this->phase === self::P_WAITING || $this->phase === self::P_READY) {
            // 没开局时，走了也没人等他
            $this->removeSeat($seat);
            $this->pushState();
        }
    }

    private function onKick(RoomTask $t): void
    {
        $seat = $this->seatOf[$t->uid] ?? -1;
        if ($seat < 0 || !$this->offline[$seat]) {
            return;   // 已经走了，或者已经重连回来
        }
        $this->removeSeat($seat);
        $this->pushState();
        if (count($this->seatOf) === 0) {
            $this->close();
        }
    }

    private function removeSeat(int $seat): void
    {
        $uid = $this->seats[$seat];
        if ($uid !== 0) {
            unset($this->seatOf[$uid]);
        }
        $this->seats[$seat] = 0;
        $this->fds[$seat] = 0;
        $this->ready[$seat] = false;
        $this->offline[$seat] = false;
    }

    // ---------------- 准备 / 开始 / 叫分 ----------------

    private function onReady(RoomTask $t): void
    {
        $seat = $this->seatOf[$t->uid] ?? -1;
        if ($seat < 0 || $this->phase !== self::P_READY) {
            return;
        }
        $this->ready[$seat] = true;
        $this->gw->send($t->fd, Cmd::READY_RESP, "ok");
        $this->pushState();
    }

    private function handleStart(RoomTask $t): void
    {
        if ($t->uid !== $this->owner) {
            $this->gw->send($t->fd, Cmd::ERR_RESP, "只有房主能开始");
            return;
        }
        if (count($this->seatOf) !== Doudizhu::SEATS) {
            $this->gw->send($t->fd, Cmd::ERR_RESP, "人还没齐（需要 3 人）");
            return;
        }
        if ($this->phase !== self::P_READY) {
            return;
        }
        foreach ($this->ready as $seat => $ok) {
            if ($this->seats[$seat] !== 0 && !$ok) {
                $this->gw->send($t->fd, Cmd::ERR_RESP, "还有人没准备");
                return;
            }
        }
        $this->deal();
    }

    private function deal(): void
    {
        $this->game->start(Card::shuffle(Card::deck()));
        $this->phase = self::P_BIDDING;
        $this->ready = [false, false, false];

        for ($s = 0; $s < Doudizhu::SEATS; $s++) {
            if ($this->fds[$s] === 0) {
                continue;
            }
            $json = json_encode([
                "hand" => $this->game->handOf($s),
                "seat" => $s,
                "bottom_count" => 3,
            ]);
            $this->gw->send($this->fds[$s], Cmd::PUSH_DEAL, is_string($json) ? $json : "{}");
        }
        $this->pushBid();
        $this->pushState();
    }

    private function onBid(RoomTask $t): void
    {
        $seat = $this->seatOf[$t->uid] ?? -1;
        if ($seat < 0 || $this->phase !== self::P_BIDDING) {
            return;
        }

        // bid() 返回"阶段是否结束"，false 有两种含义：叫分被拒，或叫成功但还没结束。
        // 靠"轮次有没有推进"区分：被拒时状态完全不动，成功时会轮到下家。
        $before = $this->game->bidTurn();
        $ended = $this->game->bid($seat, $t->score);
        if (!$ended && $this->game->isBidding() && $this->game->bidTurn() === $before) {
            $this->gw->send($t->fd, Cmd::ERR_RESP, "叫分必须高于当前最高分（当前 " . $this->game->highestBid() . "）");
            return;
        }

        $this->gw->send($t->fd, Cmd::BID_RESP, (string) json_encode(["ok" => true, "bids" => $this->game->bids()]));

        if (!$ended) {
            $this->pushBid();
            $this->pushState();
            return;
        }

        if ($this->game->isDraw()) {
            // 三家都不叫：流局，重新发牌（真实产品这里扣不扣房卡是运营决定）
            $this->broadcast(Cmd::PUSH_BID_RESULT, (string) json_encode([
                "event" => "draw",
                "msg" => "三家都不叫，重新发牌",
            ]));
            $this->deal();
            return;
        }

        // 地主定了：把他的 20 张推给他自己，其他人只知道地主是谁
        $landlord = $this->game->landlord();
        $this->broadcast(Cmd::PUSH_BID_RESULT, (string) json_encode([
            "event" => "done",
            "landlord" => $landlord,
            "bid" => $this->game->highestBid(),
            "bottom" => $this->game->bottom(),
            "bids" => $this->game->bids(),
        ]));
        if ($this->fds[$landlord] !== 0) {
            $json = json_encode([
                "hand" => $this->game->handOf($landlord),
                "seat" => $landlord,
            ]);
            $this->gw->send($this->fds[$landlord], Cmd::PUSH_DEAL, is_string($json) ? $json : "{}");
        }
        $this->phase = self::P_PLAYING;
        $this->pushState();
    }

    private function pushBid(): void
    {
        $this->broadcast(Cmd::PUSH_BID, (string) json_encode([
            "seat" => $this->game->bidTurn(),
            "highest" => $this->game->highestBid(),
            "bids" => $this->game->bids(),
        ]));
    }

    // ---------------- 出牌 ----------------

    private function onPlay(RoomTask $t): void
    {
        $seat = $this->seatOf[$t->uid] ?? -1;
        if ($seat < 0 || $this->phase !== self::P_PLAYING) {
            return;
        }
        if (!$this->game->play($seat, $t->cards)) {
            $this->gw->send($t->fd, Cmd::ERR_RESP, "出牌不合法（牌型不对 / 压不过 / 不是你的回合）");
            return;
        }
        $this->gw->send($t->fd, Cmd::PLAY_RESP, "ok");

        $this->broadcast(Cmd::PUSH_PLAY, (string) json_encode([
            "event" => "play",
            "seat" => $seat,
            "uid" => $t->uid,
            "cards" => $t->cards,
            "left" => count($this->game->handOf($seat)),
        ]));

        if ($this->game->isOver()) {
            $this->settle();
            return;
        }
        $this->pushState();
    }

    private function onPass(RoomTask $t): void
    {
        $seat = $this->seatOf[$t->uid] ?? -1;
        if ($seat < 0 || $this->phase !== self::P_PLAYING) {
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
        $winner = $this->game->winnerSeat();
        $landlord = $this->game->landlord();
        $mult = $this->game->multiplier();
        // 地主赢：地主 +2×倍数，两个农民各 -倍数；农民赢则反过来
        $delta = [];
        for ($s = 0; $s < Doudizhu::SEATS; $s++) {
            if ($s === $landlord) {
                $delta[] = $this->game->winnerSeat() === $landlord ? 2 * $mult * $this->level : -2 * $mult * $this->level;
            } else {
                $delta[] = $this->game->winnerSeat() === $landlord ? -$mult * $this->level : $mult * $this->level;
            }
        }

        $this->broadcast(Cmd::PUSH_OVER, (string) json_encode([
            "event" => "over",
            "winner" => $winner,
            "landlord" => $landlord,
            "bombs" => $this->game->bombCount(),
            "multiplier" => $mult,
            "delta" => $delta,
            "hands" => [
                $this->game->handOf(0),
                $this->game->handOf(1),
                $this->game->handOf(2),
            ],
        ]));
        $this->phase = self::P_OVER;
        $this->pushState();
    }

    // ---------------- 续局 ----------------

    private function onAgain(RoomTask $t): void
    {
        if ($t->uid !== $this->owner || $this->phase !== self::P_OVER) {
            return;
        }
        if (count($this->seatOf) !== Doudizhu::SEATS) {
            $this->gw->send($t->fd, Cmd::ERR_RESP, "有人离开了，先补人才能再来一局");
            return;
        }
        $this->ready = [false, false, false];
        $this->phase = self::P_READY;
        $this->gw->send($t->fd, Cmd::AGAIN_RESP, "ok");
        $this->broadcast(Cmd::PUSH_STATE, (string) json_encode([
            "event" => "again",
            "roomId" => $this->roomId,
        ]));
        $this->pushState();
    }

    // ---------------- 推送 ----------------

    private function pushResume(int $seat): void
    {
        $json = json_encode([
            "hand" => $this->game->handOf($seat),
            "seat" => $seat,
            "phase" => $this->phase,
            "landlord" => $this->game->landlord(),
            "turn" => $this->game->currentTurn(),
        ]);
        $this->gw->send($this->fds[$seat], Cmd::PUSH_DEAL, is_string($json) ? $json : "{}");
        $this->pushState();
    }

    private function pushState(): void
    {
        $counts = [
            count($this->game->handOf(0)),
            count($this->game->handOf(1)),
            count($this->game->handOf(2)),
        ];
        // 叫分阶段也要给出"轮到谁"。之前这里只有 PLAYING 才给 currentTurn，
        // 其余一律 -1 —— 而 deal() 是先 pushBid() 再 pushState()，
        // 于是正确的叫分轮次被这条覆盖成 -1，前端永远不显示叫分按钮。
        $turn = -1;
        if ($this->phase === self::P_PLAYING) {
            $turn = $this->game->currentTurn();
        } elseif ($this->phase === self::P_BIDDING) {
            $turn = $this->game->bidTurn();
        }

        $json = json_encode([
            "event" => "state",
            "roomId" => $this->roomId,
            "level" => $this->level,
            "owner" => $this->owner,
            "seats" => $this->seats,
            "ready" => $this->ready,
            "offline" => $this->offline,
            "counts" => $counts,
            "phase" => $this->phase,
            "turn" => $turn,
            // 桌上没有要压的牌（自由出牌）——前端据此把桌面区域清空
            "free" => $this->game->toBeat() === null,
            "landlord" => $this->game->landlord(),
            "multiplier" => $this->game->multiplier(),
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

    private function freeSeat(): int
    {
        for ($s = 0; $s < Doudizhu::SEATS; $s++) {
            if ($this->seats[$s] === 0) {
                return $s;
            }
        }
        return -1;
    }

    /** 通知大厅摘掉列表项，然后销毁自己 */
    private function close(): void
    {
        $task = new RoomTask();
        $task->op = 0;
        $task->uid = $this->roomId;
        Scheduler::instance()->deliverAs($this->addr(), $this->lobbyAddr, Cmd::SVC_ROOM_CLOSED, $task);
        Scheduler::instance()->kill($this->addr());
    }
}
