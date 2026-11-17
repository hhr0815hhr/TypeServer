<?php

namespace Mmo;

use TSF\Aoi\Grid;
use TSF\FrameLoop\FrameLoop;
use TSF\Service\Message;
use TSF\Service\Service;

/**
 * 一个房间的实时世界：帧循环 + AOI 视野 + 增量同步。
 *
 * 和斗地主的对照：
 *   斗地主  事件驱动，没有帧，状态机四段
 *   mmo     帧驱动（50ms），AOI 空间索引，状态机两段（等待/进行）
 *
 * 同步策略是「只发视野内的增量」：每帧算一次 3×3 邻居，和上一帧的可见集合做 diff，
 * 只把 add / upd / del 发给当事人。全量广播在 8 人房间里也能跑，但那是"客户端自己筛"，
 * 一旦人数上去带宽就爆——这个 demo 想演示的是服务端筛。
 */
final class MmoService extends Service
{
    /** 帧 tick 的内部消息号（不走网络） */
    public const MSG_TICK = 2900;

    private Grid $aoi;

    private ?FrameLoop $loop = null;

    /** @var array<int, array<string, int>> pid => ["x","y","fd","dir"] */
    private array $players = [];

    /** @var array<int, array<int, int>> pid => [otherPid => 1]，上一帧的可见集合 */
    private array $seen = [];

    /** @var array<int, string> pid => 名字 */
    private array $names = [];

    /** @var array<int, array<int, int>> pid => [x, y]，上一帧自己的位置（用于判断"我需要更新"） */
    private array $lastSelf = [];

    /** @var array<int, int> pid => 断线时刻；有记录说明人掉线了，但角色还站在世界里 */
    private array $offlineAt = [];

    /** 断线后角色保留的秒数 */
    private int $offlineKeepSec = 60;

    /** 房间人数上限（含断线但未清理的） */
    private int $maxPlayers = 8;

    /** @var \Closure */
    private \Closure $publish;

    private int $nextPid = 1;

    private int $mapW;
    private int $mapH;
    private int $cell;
    private int $speed;
    private int $frameMs;

    public function __construct(\Closure $publish, int $mapW = 2000, int $mapH = 2000, int $cell = 200, int $speed = 6, int $frameMs = 50, int $maxPlayers = 8)
    {
        $this->maxPlayers = $maxPlayers > 0 ? $maxPlayers : 8;
        $this->publish = $publish;
        $this->mapW = $mapW;
        $this->mapH = $mapH;
        $this->cell = $cell;
        $this->speed = $speed;
        $this->frameMs = $frameMs;

        $cols = intdiv($mapW, $cell);
        $rows = intdiv($mapH, $cell);
        $this->aoi = new Grid($cell, $cols, $rows);
    }

    public function onStart(): void
    {
        // 帧循环：每 frameMs 给自己投一条 MSG_TICK
        $this->loop = new FrameLoop($this, self::MSG_TICK, $this->frameMs, 200);
        $this->loop->start();

        // 每 10 秒扫一次：断线超过保留期就真正移除
        $this->interval(10000, Msg::SWEEP);
        \TSF\Kernel\Logger::info("mmo world started", [
            "map" => $this->mapW . "x" . $this->mapH,
            "cell" => $this->cell,
            "frame_ms" => $this->frameMs,
        ]);
    }

    public function onMessage(Message $m): void
    {
        if ($m->msgId === self::MSG_TICK) {
            $this->onTick();
            return;
        }
        if ($m->msgId === Msg::SWEEP) {
            $this->sweepOffline();
            return;
        }

        $argv = json_decode((string) $m->data, true);
        if (!is_array($argv)) {
            return;
        }
        $uid = (int) ($argv["uid"] ?? 0);
        $fd = (int) ($argv["fd"] ?? 0);
        $p = $argv["p"] ?? [];

        if ($m->msgId === Msg::JOIN) {
            $this->onJoin($uid, $fd, $p);
            return;
        }
        if ($m->msgId === Msg::MOVE) {
            $this->onMove($uid, $p);
            return;
        }
        if ($m->msgId === Msg::LEAVE) {
            $disc = false;
            if (is_array($p) && isset($p["disc"])) {
                $disc = ((int) $p["disc"]) === 1;
            }
            $this->onLeave($uid, $disc);
            return;
        }
    }

    // ---------------- 进出 ----------------

    private function onJoin(int $uid, int $fd, string|array|object $p): void
    {
        if (isset($this->players[$uid])) {
            // 重连：更新 fd、清掉断线标记，回一个 welcome。
            // 位置不重置 —— 角色一直站在原地，回来后接着走。
            $this->players[$uid]["fd"] = $fd;
            unset($this->offlineAt[$uid]);
            \TSF\Kernel\Logger::info("rejoin", ["uid" => $uid]);
            $this->welcome($uid, $fd);
            return;
        }

        // 满员拒绝。断线未清理的角色也算人数 —— 否则 60 秒内可能挤进超过上限的人
        if (count($this->players) >= $this->maxPlayers) {
            ($this->publish)($fd, Msg::ERROR, ["why" => "世界已满（上限 " . $this->maxPlayers . " 人）"]);
            return;
        }

        $name = "p" . $uid;
        if (is_array($p) && isset($p["name"])) {
            $name = (string) $p["name"];
        }

        // 随机出生点（留边距）
        $x = random_int($this->cell, $this->mapW - $this->cell);
        $y = random_int($this->cell, $this->mapH - $this->cell);

        $this->players[$uid] = ["x" => $x, "y" => $y, "fd" => $fd, "dir" => 0];
        $this->names[$uid] = $name;
        $this->seen[$uid] = [];
        $this->aoi->insert($uid, $x, $y);

        $this->welcome($uid, $fd);
        \TSF\Kernel\Logger::info("join", ["uid" => $uid, "x" => $x, "y" => $y]);
    }

    private function welcome(int $uid, int $fd): void
    {
        $row = $this->players[$uid];
        ($this->publish)($fd, Msg::WELCOME, [
            "pid" => $uid,
            "x" => $row["x"],
            "y" => $row["y"],
            "name" => $this->names[$uid] ?? "",
            "mapW" => $this->mapW,
            "mapH" => $this->mapH,
            "cell" => $this->cell,
            "frameMs" => $this->frameMs,
            "speed" => $this->speed,
        ]);
    }

    /**
     * 离开世界。
     *
     * `$disconnected = true` 表示是断线（不是主动退出）：**保留角色**，只标记离线。
     * 清掉的后果是刷新一下页面角色就没了、还得重新跑回来；保留之后 60 秒内重连
     * 能接着站在原地。
     */
    private function onLeave(int $uid, bool $disconnected = false): void
    {
        if (!isset($this->players[$uid])) {
            return;
        }

        if ($disconnected) {
            $this->offlineAt[$uid] = time();
            // fd 置 0：push 之前会判 <= 0 直接跳过，避免往死连接写
            $this->players[$uid]["fd"] = 0;
            // **必须把方向清零**：否则这个角色会一直按最后的方向往前走，
            // 变成在世界里游荡的幽灵（多人时会看到他在飘）。
            // 这个 bug 是在"断开 2 秒后重连，发现位置往前跑了 283 单位"时发现的。
            $this->players[$uid]["dir"] = 0;
            \TSF\Kernel\Logger::info("offline", ["uid" => $uid]);
            return;
        }

        $this->removeEntity($uid);
    }

    private function removeEntity(int $uid): void
    {
        $this->aoi->remove($uid);
        unset($this->players[$uid], $this->seen[$uid], $this->names[$uid],
              $this->lastSelf[$uid], $this->offlineAt[$uid]);
        $this->broadcastGone($uid);
    }

    /** 清掉超过保留期的离线角色 */
    private function sweepOffline(): void
    {
        $now = time();
        $dead = [];
        foreach ($this->offlineAt as $uid => $at) {
            if ($now - $at > $this->offlineKeepSec) {
                $dead[] = $uid;
            }
        }
        foreach ($dead as $uid) {
            \TSF\Kernel\Logger::info("offline expired", ["uid" => $uid]);
            $this->removeEntity($uid);
        }
    }

    /** 有人离开：所有还在场的人都会收到 GONE（简单做法，8 人房间够用） */
    private function broadcastGone(int $gonePid): void
    {
        foreach ($this->players as $pid => $row) {
            ($this->publish)($row["fd"], Msg::GONE, ["pid" => $gonePid]);
        }
    }

    // ---------------- 移动意图 ----------------

    private function onMove(int $uid, string|array|object $p): void
    {
        if (!isset($this->players[$uid])) {
            return;
        }
        $dx = 0;
        $dy = 0;
        if (is_array($p)) {
            $dx = (int) ($p["dx"] ?? 0);
            $dy = (int) ($p["dy"] ?? 0);
        }
        // 只接受 -1/0/1，防止客户端一帧移动很远
        if ($dx > 1) { $dx = 1; }
        if ($dx < -1) { $dx = -1; }
        if ($dy > 1) { $dy = 1; }
        if ($dy < -1) { $dy = -1; }

        // 方向用 0-7 编码存着，帧里按它移动
        $dir = 0;
        if ($dx === 1) { $dir += 1; }
        if ($dx === -1) { $dir += 2; }
        if ($dy === 1) { $dir += 4; }
        if ($dy === -1) { $dir += 8; }
        $this->players[$uid]["dir"] = $dir;
    }

    // ---------------- 帧 ----------------

    private function onTick(): void
    {
        if ($this->loop === null) {
            return;
        }
        $dt = $this->loop->beginFrame();

        // 1) 按方向移动。速度按帧长归一：50ms 一帧走 speed，帧长变化时按比例缩放
        $step = (int) round($this->speed * ($dt / (float) $this->frameMs));
        if ($step < 1) {
            $step = 1;
        }

        foreach ($this->players as $pid => $row) {
            $dir = $row["dir"];
            if ($dir === 0) {
                continue;
            }
            $dx = 0;
            $dy = 0;
            if (($dir & 1) !== 0) { $dx = 1; }
            if (($dir & 2) !== 0) { $dx = -1; }
            if (($dir & 4) !== 0) { $dy = 1; }
            if (($dir & 8) !== 0) { $dy = -1; }

            $x = $row["x"] + $dx * $step;
            $y = $row["y"] + $dy * $step;
            if ($x < 0) { $x = 0; }
            if ($x > $this->mapW) { $x = $this->mapW; }
            if ($y < 0) { $y = 0; }
            if ($y > $this->mapH) { $y = $this->mapH; }

            $this->players[$pid]["x"] = $x;
            $this->players[$pid]["y"] = $y;
            $this->aoi->move($pid, $x, $y);
        }

        // 2) 算视野 + 增量
        $this->sync();

        $this->loop->endFrame();
    }

    /**
     * 给每个人算 3×3 邻居，和上一帧比，只发差异。
     *
     * 注意 `neighbors()` 结果包含自己——自己不进 add/upd 列表，位置单独放 self 里。
     */
    private function sync(): void
    {
        foreach ($this->players as $pid => $row) {
            $near = $this->aoi->neighbors($pid);
            $now = [];
            foreach ($near as $other) {
                // 这里不能叫 $o：下面 `foreach ($now as $o => ...)` 要把 $o 当 key，
                // 而 key 在 AOT 里是 php::Var，和这里的 int 冲突。
                // 换成 $otherPid 之后 $now 的 key 也就明确了是 int。
                $otherPid = (int) $other;
                if ($otherPid !== $pid && isset($this->players[$otherPid])) {
                    $now[$otherPid] = 1;
                }
            }

            $before = $this->seen[$pid] ?? [];
            $add = [];
            $del = [];

            foreach ($now as $o => $one) {
                if (!isset($before[$o])) {
                    $add[] = $this->entityOf($o);
                }
            }
            // 注意循环变量不能复用 $o：上面 `foreach ($now as $o => ...)` 已经把 $o
            // 推断成 int（$now 是用 `$now[$o] = 1` 造的，key 是 int），而 $before 是
            // 数组，它的 key 是 php::Var —— 同名会报
            // "Cannot assign value to variable $o of type php::Int with type php::Var"。
            foreach ($before as $gone => $one) {
                if (!isset($now[$gone])) {
                    $del[] = $gone;
                }
            }

            // 已经在视野里的也要更新位置（这是每帧都变的）
            $upd = [];
            foreach ($now as $o => $one) {
                if (isset($before[$o])) {
                    $r = $this->players[$o];
                    $upd[] = ["pid" => $o, "x" => $r["x"], "y" => $r["y"]];
                }
            }

            $this->seen[$pid] = $now;

            // 「自己动了」也必须算一次变化。
            // 只判 add/del/upd 的话，单人在空旷地图上移动时没有任何邻居，
            // 三条都是空 → 一帧都不发，客户端会以为自己没动。这个 bug 只有在
            // 「一个人测」的时候才暴露，多人测试反而看似正常。
            $self = $this->players[$pid];
            $prev = $this->lastSelf[$pid] ?? [-1, -1];
            $selfMoved = ($prev[0] !== $self["x"] || $prev[1] !== $self["y"]);
            $this->lastSelf[$pid] = [$self["x"], $self["y"]];

            if (!$selfMoved && $add === [] && $del === [] && $upd === []) {
                continue;
            }
            ($this->publish)($row["fd"], Msg::SYNC, [
                "self" => ["pid" => $pid, "x" => $self["x"], "y" => $self["y"]],
                "add" => $add,
                "upd" => $upd,
                "del" => $del,
            ]);
        }
    }

    /** @return array<string, int|string> */
    private function entityOf(int $pid): array
    {
        $r = $this->players[$pid];
        return [
            "pid" => $pid,
            "x" => $r["x"],
            "y" => $r["y"],
            "name" => $this->names[$pid] ?? "",
        ];
    }
}
