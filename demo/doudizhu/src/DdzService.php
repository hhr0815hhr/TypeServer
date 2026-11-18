<?php

namespace Ddz;

use TSF\Db\Repo;
use TSF\EventLog\EventLog;
use TSF\Room\Member;
use TSF\Fsm\Machine;
use TSF\Service\Message;
use TSF\Service\Service;

/**
 * 一桌斗地主。
 *
 * 单桌 demo：3 个座位，不做多桌管理（那部分由 RoomManager 负责，见 duel 示例）。
 *
 * 消息从 Scheduler 投进来（`$m->data` 是 JSON 字符串，含 uid/fd/载荷），
 * 推送通过构造函数注入的 `$publish(fd, msgId, data)` 发出——Service 不直接持有 Gateway，
 * 这样换传输层（tcp/ws）时这一层不用改。
 */
final class DdzService extends Service
{
    private DdzRoom $room;

    /** @var array<int, int> uid => seat */
    private array $seatOf = [];

    /** @var array<int, int> seat => uid */
    private array $uidOf = [];

    /** @var array<int, int> seat => fd */
    private array $fdOf = [];

    /** @var array<int, int> seat => 是否已准备 */
    private array $ready = [];

    /** 本轮从哪个座位开始叫地主 */
    private int $callSeat = 0;

    /** @var array<int, int> seat => 断线时刻；有记录表示该座位的人掉线了，座位还留着 */
    private array $offlineAt = [];

    /** @var array<int, int> seat => 1，该座位是机器人 */
    private array $bots = [];

    /** @var array<string, int> 机器人名字池 */
    private array $botNames = ["小明", "小红", "老王"];

    /** 断线后保留座位的秒数 */
    private int $offlineKeepSec = 60;

    /** @var array<int, int> uid => fd，观战者（局中进来的，不占座位、看不到手牌） */
    private array $spectators = [];

    /** @var \Closure */
    private \Closure $publish;

    /** 出牌超时秒数；0 表示不超时（测试用） */
    private int $turnSec;

    /** 当前回合的超时定时器 id，0 表示没有 */
    private int $turnTimer = 0;

    /** 玩家余额仓储；为 null 时不落库（纯内存跑法） */
    private ?Repo $players = null;

    /** 对局日志；与 $players 一起决定是否开启持久化 */
    private ?EventLog $log = null;

    /** 局号：每结算一局 +1，用作日志的业务 id（幂等键的一部分） */
    private int $roundSeq = 0;

    public function __construct(
        \Closure $publish,
        int $turnSec = 30,
        ?Repo $players = null,
        ?EventLog $log = null
    ) {
        $this->publish = $publish;
        $this->turnSec = $turnSec;
        $this->players = $players;
        $this->log = $log;

        // 状态机由业务构造并配置 —— Room 不认识这四个状态
        $fsm = new Machine(DdzState::WAITING);
        $fsm->allow(DdzState::WAITING, "deal", DdzState::CALLING)
            ->allow(DdzState::CALLING, "start", DdzState::PLAYING)
            ->allow(DdzState::PLAYING, "settle", DdzState::SETTLED)
            ->allow(DdzState::WAITING, "close", DdzState::SETTLED);

        $this->room = new DdzRoom(1, 3, $fsm);
        $this->ready = [0 => 0, 1 => 0, 2 => 0];
    }

    public function onStart(): void
    {
        // 每 10 秒扫一次断线座位，超过保留期就真正清掉
        $this->interval(10000, Msg::SWEEP);
    }

    public function onMessage(Message $m): void
    {
        if ($m->msgId === Msg::SWEEP) {
            $this->sweepOffline();
            return;
        }
        $raw = (string) $m->data;
        $argv = json_decode($raw, true);
        if (!is_array($argv)) {
            return;
        }
        $uid = (int) ($argv["uid"] ?? 0);
        $fd = (int) ($argv["fd"] ?? 0);
        $p = $argv["p"] ?? [];

        if ($m->msgId === Msg::SEAT) {
            $this->onSeat($uid, $fd, $p);
            return;
        }
        if ($m->msgId === Msg::READY) {
            $this->onReady($uid);
            return;
        }
        if ($m->msgId === Msg::CALL) {
            $this->onCall($uid, $p);
            return;
        }
        if ($m->msgId === Msg::PLAY) {
            $this->onPlay($uid, $p);
            return;
        }
        if ($m->msgId === Msg::PASS) {
            $this->onPass($uid);
            return;
        }
        if ($m->msgId === Msg::LEAVE) {
            $this->onLeave($uid, $fd);
            return;
        }
        if ($m->msgId === Msg::AUTO) {
            $this->onAuto($p);
            return;
        }
        if ($m->msgId === Msg::ADD_BOT) {
            $this->onAddBot();
            return;
        }
    }

    // ---------------- 推送 ----------------

    private function push(int $seat, int $msgId, string|array|object $data): void
    {
        $fd = $this->fdOf[$seat] ?? 0;
        if ($fd > 0) {
            ($this->publish)($fd, $msgId, $data);
        }
    }

    /** 按 fd 直接推（观战者没有座位号） */
    private function pushTo(int $fd, int $msgId, string|array|object $data): void
    {
        if ($fd > 0) {
            ($this->publish)($fd, $msgId, $data);
        }
    }

    private function broadcast(int $msgId, string|array|object $data): void
    {
        $s = 0;
        while ($s < 3) {
            if (isset($this->uidOf[$s])) {
                $this->push($s, $msgId, $data);
            }
            $s++;
        }
        // 观战者也要看得到桌面变化和出牌。手牌是 push() 单发的，不会漏给他们。
        foreach ($this->spectators as $suid => $fd) {
            $this->pushTo($fd, $msgId, $data);
        }
    }

    /** 桌面快照：谁在座、状态、轮到谁、地主是谁 */
    private function tableInfo(): array
    {
        $seats = [];
        $s = 0;
        while ($s < 3) {
            $seats[] = [
                "seat" => $s,
                "uid" => $this->uidOf[$s] ?? 0,
                "ready" => $this->ready[$s] ?? 0,
                "offline" => isset($this->offlineAt[$s]) ? 1 : 0,
                "bot" => isset($this->bots[$s]) ? 1 : 0,
                "count" => count($this->room->hands[$s] ?? []),
            ];
            $s++;
        }
        return [
            "state" => $this->room->stateName(),
            "seats" => $seats,
            "landlord" => $this->room->landlord,
            "current" => $this->room->current,
            "bottom" => $this->room->bottom,
        ];
    }

    private function error(int $seat, string $why): void
    {
        $this->push($seat, Msg::ERROR, ["why" => $why]);
    }

    /**
     * 轮到某人：推 TURN + 起超时定时器。
     *
     * 所有"该谁动了"的地方都走这里，避免有的地方起定时器、有的地方不起——
     * 漏一处就会出现"卡在某个人身上永远不动"。
     */
    private function beginTurn(int $seat, bool $mustLead): void
    {
        if ($this->turnTimer > 0) {
            $this->cancelTimer($this->turnTimer);
            $this->turnTimer = 0;
        }
        $this->push($seat, Msg::TURN, ["mustLead" => $mustLead]);

        // 机器人（或已掉线的人）：用 1 秒短超时代打，别让真人干等 30 秒
        $quick = isset($this->bots[$seat]) || isset($this->offlineAt[$seat]);
        if ($quick) {
            $this->turnTimer = $this->timeout(1000, Msg::AUTO, json_encode([
                "uid" => 0, "fd" => 0, "p" => ["seat" => $seat],
            ]));
            return;
        }

        if ($this->turnSec > 0) {
            // 载荷必须和其他业务消息**同构**，两层都不能少：
            //   1) 必须是 JSON 字符串（传数组会被 (string) 转成 "Array"，json_decode 失败）
            //   2) 必须有 p 这一层（onMessage 统一从 $argv["p"] 取载荷）
            // 少任何一层，onAuto 都拿不到座位号，然后静默 return —— 表现就是"超时没反应"。
            $this->turnTimer = $this->timeout($this->turnSec * 1000, Msg::AUTO, json_encode([
                "uid" => 0,
                "fd" => 0,
                "p" => ["seat" => $seat],
            ]));
        }
    }

    /** 超时自动处理：必须出牌就出最小的一张，否则过 */
    private function onAuto(string|array|object $p): void
    {
        if ($this->room->state() !== DdzState::PLAYING) {
            return;
        }
        $seat = -1;
        if (is_array($p) && isset($p["seat"])) {
            $seat = (int) $p["seat"];
        }
        // 已经换了回合：这条超时消息作废
        if ($seat < 0 || $seat !== $this->room->current) {
            return;
        }
        $hand = $this->room->hands[$seat] ?? [];
        if ($hand === []) {
            return;
        }

        // 机器人的 uid 是负数，也要能代打 —— 所以这里不能要求 uid > 0
        $uid = $this->uidOf[$seat] ?? 0;
        if ($uid === 0) {
            return;
        }

        $mustLead = ($this->room->lastPlaySeat === $seat || $this->room->lastPlaySeat < 0);
        if ($mustLead) {
            // 出最小的一张：单张永远合法，不用判断牌型
            $this->broadcast(Msg::ERROR, ["why" => "座位" . $seat . " 超时，自动出牌"]);
            $this->onPlay($uid, ["cards" => [$hand[0]]]);
            return;
        }
        $this->broadcast(Msg::ERROR, ["why" => "座位" . $seat . " 超时，自动过牌"]);
        $this->onPass($uid);
    }

    // ---------------- 坐桌 ----------------

    private function onSeat(int $uid, int $fd, string|array|object $p): void
    {
        // 已经在座 = 重连：恢复 fd、清掉断线标记、补发手牌和当前回合
        if (isset($this->seatOf[$uid])) {
            $seat = $this->seatOf[$uid];
            $this->fdOf[$seat] = $fd;
            unset($this->offlineAt[$seat]);
            \TSF\Kernel\Logger::info("rejoin", ["uid" => $uid, "seat" => $seat]);

            $this->push($seat, Msg::HAND, ["cards" => $this->room->hands[$seat] ?? []]);
            $this->broadcast(Msg::TABLE, $this->tableInfo());
            if ($this->room->current === $seat) {
                $mustLead = ($this->room->lastPlaySeat === $seat || $this->room->lastPlaySeat < 0);
                $this->push($seat, Msg::TURN, ["mustLead" => $mustLead]);
            }
            return;
        }

        if ($this->room->state() !== DdzState::WAITING) {
            // 局中进来当观战者：进成员表（SPECTATOR），收桌面和出牌广播，但收不到手牌
            $this->room->addAs($uid, Member::SPECTATOR, "sp" . $uid);
            $this->spectators[$uid] = $fd;
            \TSF\Kernel\Logger::info("spectate", ["uid" => $uid]);
            $this->pushTo($fd, Msg::TABLE, $this->tableInfo());
            $this->pushTo($fd, Msg::ERROR, ["why" => "已作为观战者加入（看不到手牌，不能操作）"]);
            return;
        }

        $want = -1;
        if (is_array($p) && isset($p["seat"])) {
            $want = (int) $p["seat"];
        }

        $seat = -1;
        if ($want >= 0 && $want < 3 && !isset($this->uidOf[$want])) {
            $seat = $want;
        } else {
            $s = 0;
            while ($s < 3) {
                if (!isset($this->uidOf[$s])) {
                    $seat = $s;
                    break;
                }
                $s++;
            }
        }

        if ($seat < 0) {
            ($this->publish)($fd, Msg::ERROR, ["why" => "桌子满了"]);
            return;
        }

        $this->seatOf[$uid] = $seat;
        $this->uidOf[$seat] = $uid;
        $this->fdOf[$seat] = $fd;
        $this->room->addAs($uid, Member::PLAYER, "p" . $uid);   // Member::PLAYER = 1
        $this->ready[$seat] = 0;

        $this->push($seat, Msg::HAND, ["cards" => []]);
        $this->broadcast(Msg::TABLE, $this->tableInfo());
    }

    /**
     * 离桌。`$uid > 0` 是客户端主动离开；`$fd > 0` 是连接断开（断线清理）。
     *
     * 断线清理必须做：否则关掉一个标签页，那个座位永远被占着，再开就「桌子满了」。
     * 这里不区分"断线"和"离桌"——demo 阶段断线即离桌；要做断线重连的话，
     * 应该改成保留座位 + 超时回收（那需要在线状态字段和定时器）。
     */
    private function onLeave(int $uid, int $fd = 0): void
    {
        // 观战者掉线：从观战表里删掉就行，不动座位
        if (isset($this->spectators[$uid])) {
            unset($this->spectators[$uid]);
            $this->room->remove($uid);
            return;
        }

        $seat = -1;
        if ($uid > 0) {
            $seat = $this->seatOf[$uid] ?? -1;
        } else {
            $s = 0;
            while ($s < 3) {
                if (($this->fdOf[$s] ?? 0) === $fd && $fd > 0) {
                    $seat = $s;
                    break;
                }
                $s++;
            }
        }
        \TSF\Kernel\Logger::info("leave", ["uid" => $uid, "fd" => $fd, "seat" => $seat]);
        if ($seat < 0) {
            return;
        }

        $gone = $this->uidOf[$seat] ?? 0;

        // 局中掉线：保留座位，只标记离线。
        //
        // 直接清掉的后果是：刷新一下页面座位就没了、手牌也丢了，而且如果正好轮到他，
        // 整桌就卡在等人上。保留座位后，掉线的人由现有的超时机制自动代打（托管），
        // 60 秒内回来还能接着打。
        $playing = ($this->room->state() === DdzState::PLAYING
            || $this->room->state() === DdzState::CALLING);
        if ($playing && $gone > 0 && !isset($this->bots[$seat])) {
            $this->offlineAt[$seat] = time();
            unset($this->fdOf[$seat]);
            \TSF\Kernel\Logger::info("player offline", ["uid" => $gone, "seat" => $seat]);
            $this->broadcast(Msg::TABLE, $this->tableInfo());
            return;
        }

        if ($gone > 0) {
            unset($this->seatOf[$gone]);
        }
        unset($this->uidOf[$seat], $this->fdOf[$seat], $this->offlineAt[$seat], $this->bots[$seat]);
        $this->ready[$seat] = 0;
        if ($gone > 0) {
            $this->room->remove($gone);
        }
        $this->room->hands[$seat] = [];

        // 人都走光了就把桌子复位。
        //
        // 不复位的后果很具体：上一局停在 calling/playing，新玩家坐桌时被
        // `state !== WAITING` 挡住，报「本局已开始，只能观战」——表现是
        // 「重启服务才能再开一局」。空桌复位比"进人时再检查"更可靠。
        if (count($this->uidOf) === 0) {
            $this->room->fsm()?->force(DdzState::WAITING);
            $this->room->hands = [[], [], []];
            $this->room->bottom = [];
            $this->room->landlord = -1;
            $this->room->current = 0;
            $this->room->lastPlaySeat = -1;
            $this->room->lastPlay = [];
            $this->room->passCount = 0;
            $this->room->bombs = 0;
            $this->callSeat = 0;
        }

        $this->broadcast(Msg::TABLE, $this->tableInfo());
    }

    // ---------------- 准备 / 发牌 ----------------

    private function onReady(int $uid): void
    {
        $seat = $this->seatOf[$uid] ?? -1;
        if ($seat < 0) {
            return;
        }
        if ($this->room->state() !== DdzState::WAITING) {
            $this->error($seat, "当前不是等待阶段");
            return;
        }
        $this->ready[$seat] = 1;
        $this->broadcast(Msg::TABLE, $this->tableInfo());
        $this->maybeStart();
    }

    private function deal(): void
    {
        $deck = Card::shuffled(function (int $n): int {
            return random_int(0, $n - 1);
        });

        $this->room->hands = [[], [], []];
        $i = 0;
        while ($i < 51) {
            $seat = $i % 3;
            $this->room->hands[$seat][] = $deck[$i];
            $i++;
        }
        $this->room->bottom = [$deck[51], $deck[52], $deck[53]];
        $s = 0;
        while ($s < 3) {
            $this->room->hands[$s] = DdzRoom::sortHand($this->room->hands[$s]);
            $s++;
        }

        $this->room->landlord = -1;
        $this->room->current = 0;
        $this->room->lastPlaySeat = -1;
        $this->room->lastPlay = [];
        $this->room->passCount = 0;
        $this->room->bombs = 0;
        $this->room->fsm()?->fire("deal");

        $this->broadcast(Msg::TABLE, $this->tableInfo());
        $s = 0;
        while ($s < 3) {
            if (isset($this->uidOf[$s])) {
                $this->push($s, Msg::HAND, ["cards" => $this->room->hands[$s]]);
            }
            $s++;
        }

        $this->callSeat = 0;
        $this->room->current = 0;
        $this->push(0, Msg::TURN, ["mustCall" => true]);
    }

    // ---------------- 叫地主 ----------------

    private function onCall(int $uid, string|array|object $p): void
    {
        $seat = $this->seatOf[$uid] ?? -1;
        if ($seat < 0 || $this->room->state() !== DdzState::CALLING) {
            $this->error($seat < 0 ? 0 : $seat, "现在不能叫地主");
            return;
        }
        if ($seat !== $this->room->current) {
            $this->error($seat, "还没轮到你");
            return;
        }

        $call = true;
        if (is_array($p) && isset($p["call"])) {
            $call = (bool) $p["call"];
        }

        if ($call) {
            $this->room->landlord = $seat;
            // 地主拿三张底牌
            $hand = $this->room->hands[$seat];
            foreach ($this->room->bottom as $c) {
                $hand[] = $c;
            }
            $this->room->hands[$seat] = DdzRoom::sortHand($hand);
            $this->room->fsm()?->fire("start");
            $this->room->current = $seat;
            $this->room->lastPlaySeat = -1;

            $this->broadcast(Msg::TABLE, $this->tableInfo());
            $this->push($seat, Msg::HAND, ["cards" => $this->room->hands[$seat]]);
            $this->beginTurn($seat, true);
            return;
        }

        // 不叫：轮到下一家
        $next = ($seat + 1) % 3;
        if ($next === $this->callSeat) {
            // 三家都不叫 → 重新发牌
            $this->broadcast(Msg::TABLE, $this->tableInfo());
            $s = 0;
            while ($s < 3) {
                $this->push($s, Msg::ERROR, ["why" => "三家都不叫，重新发牌"]);
                $s++;
            }
            $this->room->fsm()?->force(DdzState::WAITING);
            $this->ready = [0 => 0, 1 => 0, 2 => 0];
            $this->broadcast(Msg::TABLE, $this->tableInfo());
            return;
        }

        $this->room->current = $next;
        $this->push($next, Msg::TURN, ["mustCall" => true]);
    }

    // ---------------- 出牌 ----------------

    private function onPlay(int $uid, string|array|object $p): void
    {
        $seat = $this->seatOf[$uid] ?? -1;
        if ($seat < 0) {
            return;
        }
        if ($this->room->state() !== DdzState::PLAYING) {
            $this->error($seat, "现在不是出牌阶段");
            return;
        }
        if ($seat !== $this->room->current) {
            $this->error($seat, "还没轮到你");
            return;
        }

        $cards = [];
        if (is_array($p) && isset($p["cards"]) && is_array($p["cards"])) {
            foreach ($p["cards"] as $c) {
                $cards[] = (int) $c;
            }
        }
        if ($cards === []) {
            $this->error($seat, "没选牌");
            return;
        }
        if (!$this->room->hasCards($seat, $cards)) {
            $this->error($seat, "你没有这些牌");
            return;
        }

        $pat = Pattern::parse($cards);
        if ($pat === null) {
            $this->error($seat, "不是合法牌型");
            return;
        }

        // 不是自由出牌时，必须压过上一手
        $mustBeat = $this->room->lastPlaySeat >= 0 && $this->room->lastPlaySeat !== $seat;
        if ($mustBeat && $this->room->lastPlay !== []) {
            $prev = Pattern::parse($this->room->lastPlay);
            if ($prev !== null && !$pat->beats($prev)) {
                $this->error($seat, "管不上（" . $pat->name() . "）");
                return;
            }
        }

        // 记炸弹
        if ($pat->type === Pattern::BOMB || $pat->type === Pattern::ROCKET) {
            $this->room->bombs++;
        }

        $this->room->hands[$seat] = $this->room->takeCards($seat, $cards);
        $this->room->lastPlaySeat = $seat;
        $this->room->lastPlay = $cards;
        $this->room->passCount = 0;

        $this->broadcast(Msg::PLAYED, [
            "seat" => $seat,
            "cards" => $cards,
            "type" => $pat->name(),
            "left" => count($this->room->hands[$seat]),
        ]);
        $this->push($seat, Msg::HAND, ["cards" => $this->room->hands[$seat]]);

        // 出完牌 → 结算
        if (count($this->room->hands[$seat]) === 0) {
            $this->settle($seat);
            return;
        }

        $this->room->current = ($seat + 1) % 3;
        $this->beginTurn($this->room->current, false);
    }

    private function onPass(int $uid): void
    {
        $seat = $this->seatOf[$uid] ?? -1;
        if ($seat < 0 || $this->room->state() !== DdzState::PLAYING) {
            return;
        }
        if ($seat !== $this->room->current) {
            $this->error($seat, "还没轮到你");
            return;
        }
        // 自由出牌的人不能过
        if ($this->room->lastPlaySeat === $seat || $this->room->lastPlaySeat < 0) {
            $this->error($seat, "你是本轮先出，必须出牌");
            return;
        }

        $this->room->passCount++;
        $this->broadcast(Msg::PASSED, ["seat" => $seat]);

        if ($this->room->passCount >= 2) {
            // 另外两家都不要：最后出牌的人重新自由出牌
            $this->room->current = $this->room->lastPlaySeat;
            $this->room->lastPlay = [];
            $this->room->passCount = 0;
            $this->beginTurn($this->room->current, true);
            return;
        }

        $this->room->current = ($seat + 1) % 3;
        $this->beginTurn($this->room->current, false);
    }

    // ---------------- 结算 ----------------

    /**
     * 写日志 + 改余额。
     *
     * 幂等：日志的业务 id 用 `ddz:<局号>`，同一局不会写两条（局号只在结算时自增一次）。
     * 真正要防的是"结算函数被重复调用"——那条路径目前不存在（出完牌即结算并复位），
     * 所以这里不做查重，只保证 id 稳定，将来要重放核对时用它当键。
     *
     * @param array<int, array<string, int>> $rows
     */
    /** @return array<int, array<string, int>> 每个座位结算后的余额 */
    private function persist(int $winnerSeat, int $landlord, int $mult, string $spring, array $rows): array
    {
        if ($this->players === null || $this->log === null) {
            return [];
        }

        $this->roundSeq++;
        $key = "ddz:" . $this->roundSeq;

        $this->log->append($key, "settle", [
            "winner" => $winnerSeat,
            "landlord" => $landlord,
            "mult" => $mult,
            "spring" => $spring,
            "rows" => $rows,
        ]);
        $this->log->commit();

        $bal = [];
        foreach ($rows as $row) {
            $seat = (int) $row["seat"];
            $uid = $this->uidOf[$seat] ?? 0;
            if ($uid <= 0) {
                continue;
            }
            $p = $this->players->find($uid);
            if ($p === null || !($p instanceof PlayerRow)) {
                // 首次见到这个玩家：给初始筹码
                $p = new PlayerRow();
                $p->uid = $uid;
                $p->coins = 1000;
                $p->name = "p" . $uid;
                $this->players->insert($p);
            }
            $p->coins = $p->coins + (int) $row["delta"];
            $this->players->update($p);
            $bal[] = ["seat" => $seat, "coins" => $p->coins];
        }
        return $bal;
    }

    /** 清掉超过保留期的断线座位 */
    private function sweepOffline(): void
    {
        $now = time();
        $dead = [];
        foreach ($this->offlineAt as $seat => $at) {
            if ($now - $at > $this->offlineKeepSec) {
                $dead[] = $seat;
            }
        }
        foreach ($dead as $seat) {
            \TSF\Kernel\Logger::info("offline expired", ["seat" => $seat]);
            $this->forceLeave($seat);
        }
    }

    /** 真正清掉一个座位（断线超时，或未开局时主动离开） */
    private function forceLeave(int $seat): void
    {
        $uid = $this->uidOf[$seat] ?? 0;
        if ($uid > 0) {
            unset($this->seatOf[$uid]);
            $this->room->remove($uid);
        }
        unset($this->uidOf[$seat], $this->fdOf[$seat], $this->offlineAt[$seat], $this->bots[$seat]);
        $this->ready[$seat] = 0;
        $this->room->hands[$seat] = [];

        if (count($this->uidOf) === 0) {
            $this->resetTable();
        }
        $this->broadcast(Msg::TABLE, $this->tableInfo());
    }

    /** 空桌复位（原来写在 onLeave 里，现在两处都要用） */
    private function resetTable(): void
    {
        $this->room->fsm()?->force(DdzState::WAITING);
        $this->room->hands = [[], [], []];
        $this->room->bottom = [];
        $this->room->landlord = -1;
        $this->room->current = 0;
        $this->room->lastPlaySeat = -1;
        $this->room->lastPlay = [];
        $this->room->passCount = 0;
        $this->room->bombs = 0;
        $this->callSeat = 0;
        $this->ready = [0 => 0, 1 => 0, 2 => 0];
    }

    /**
     * 补一个机器人到空位。
     *
     * 机器人不是 AI，只是"不会掉线的玩家"：轮到它时由短超时自动出牌（出最小的单张）。
     * 目的是让一个人也能开局测试，而不是做一个能打赢的对手。
     */
    private function onAddBot(): void
    {
        if ($this->room->state() !== DdzState::WAITING) {
            $this->broadcast(Msg::ERROR, ["why" => "开局后才能加"]);
            return;
        }
        $seat = -1;
        $s = 0;
        while ($s < 3) {
            if (!isset($this->uidOf[$s])) {
                $seat = $s;
                break;
            }
            $s++;
        }
        if ($seat < 0) {
            $this->broadcast(Msg::ERROR, ["why" => "没有空位"]);
            return;
        }

        // 机器人用负数 uid，和真人（正数）区分开
        $botUid = -1 - $seat;
        $this->uidOf[$seat] = $botUid;
        // **必须同时登记 seatOf**：代打时走的是 onPlay($uid, ...)，
        // 那里第一件事就是 `$seat = $this->seatOf[$uid]` —— 漏了这一步，
        // 机器人能"轮到它"但永远不出牌（其实是静默 return，没有任何报错）。
        $this->seatOf[$botUid] = $seat;
        $this->bots[$seat] = 1;
        $this->room->addAs($botUid, 1, "bot" . $seat);
        $this->ready[$seat] = 1;
        \TSF\Kernel\Logger::info("add bot", ["seat" => $seat]);

        $this->broadcast(Msg::TABLE, $this->tableInfo());
        $this->maybeStart();
    }

    /** 三人齐且都准备 → 发牌（原逻辑写在 onReady 里，加机器人后两处都要调） */
    private function maybeStart(): void
    {
        if ($this->room->state() !== DdzState::WAITING) {
            return;
        }
        if (count($this->uidOf) < 3) {
            return;
        }
        if (($this->ready[0] ?? 0) !== 1 || ($this->ready[1] ?? 0) !== 1 || ($this->ready[2] ?? 0) !== 1) {
            return;
        }
        $this->deal();
    }

    private function settle(int $winnerSeat): void
    {
        $this->room->fsm()?->fire("settle");

        $landlord = $this->room->landlord;
        $landlordWin = ($winnerSeat === $landlord);

        // 倍数：炸弹/王炸每次翻倍
        $mult = 1;
        $b = 0;
        while ($b < $this->room->bombs) {
            $mult = $mult * 2;
            $b++;
        }

        // 春天：地主赢且两个农民一张没出 / 农民赢且地主只出过一手
        $spring = "";
        if ($landlordWin) {
            $all = true;
            $s = 0;
            while ($s < 3) {
                if ($s !== $landlord && count($this->room->hands[$s]) < 17) {
                    $all = false;
                }
                $s++;
            }
            if ($all) {
                $spring = "spring";
                $mult = $mult * 2;
            }
        }

        $base = 100;
        $score = $base * $mult;

        $rows = [];
        $s = 0;
        while ($s < 3) {
            $isLandlord = ($s === $landlord);
            $win = ($isLandlord === $landlordWin);
            $delta = 0;
            if ($isLandlord) {
                // 地主一打二
                $delta = $win ? $score * 2 : -$score * 2;
            } else {
                $delta = $win ? $score : -$score;
            }
            $rows[] = [
                "seat" => $s,
                "win" => $win ? 1 : 0,
                "delta" => $delta,
                "left" => count($this->room->hands[$s]),
            ];
            $s++;
        }

        // ---- 落库：先写事件日志，再改余额 ----
        //
        // 顺序有讲究：日志在前、余额在后。崩在两者之间时，日志里有一条结算记录、
        // 余额没动 —— 重启后可以按日志补账（重放），反过来就查不出来了。
        // 这也是 eventlog 存在的意义：它是"事实"，余额是"事实的推导结果"。
        $balances = $this->persist($winnerSeat, $landlord, $mult, $spring, $rows);

        $this->broadcast(Msg::SETTLED, [
            "lander" => $winnerSeat,
            "landlordWin" => $landlordWin ? 1 : 0,
            "mult" => $mult,
            "spring" => $spring,
            "rows" => $rows,
            "coins" => $balances,   // 结算后的余额（来自 db），客户端直接显示
        ]);

        // 复位成等待，下一局重新准备
        $this->ready = [0 => 0, 1 => 0, 2 => 0];
        $this->room->fsm()?->force(DdzState::WAITING);
        $this->broadcast(Msg::TABLE, $this->tableInfo());
    }
}
