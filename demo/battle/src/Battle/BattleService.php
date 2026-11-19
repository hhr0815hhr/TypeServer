<?php

namespace Game\Battle;

use Game\Msg;
use TSF\FrameLoop\FrameLoop;
use TSF\Gm\Task;
use TSF\Kernel\FileLoader;
use TSF\Kernel\Logger;
use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

/**
 * 战斗服驱动：帧循环 + 输入分发 + 推送节流。
 *
 * 三个频率被刻意分开，因为它们解决的是三件不同的事：
 *
 *   判定 30 Hz（33ms）  决定了手感与命中精度。再高对非射击玩法没有收益，
 *                       但每帧要遍历全部实体 + 结算，是主要 CPU 成本。
 *   推送 5 Hz（每 6 帧）决定了带宽。把判定频率和推送频率绑死是新手常见错误：
 *                       判定必须 30Hz 才跟手，但把 30Hz 的位置广播给视野内
 *                       所有人，带宽会先爆掉，客户端也只是在重复插值。
 *   统计 0.2 Hz        只打日志，不进热路径。
 *
 * 消息载荷统一是字符串，不用数组：
 *   `Scheduler` 的 data 是 `string|array|object` 联合类型，取字段必须先收窄；
 *   而热路径上每帧几十条输入，多一层 is_array 判断不值得。
 *   格式固定为 `"uid"` / `"uid,dx,dy"` / `"uid,skillId"`，用 explode 拆。
 */
final class BattleService extends Service
{
    public const MSG_TICK = 1;

    public const MSG_ENTER = 2;

    public const MSG_MOVE = 3;

    public const MSG_CAST = 4;

    public const MSG_LEAVE = 5;

    /**
     * 应用一份外部校验过的配置（GM 命令发来的）。
     *
     * 载荷是 JSON：`{"h":"<crc32>","t":{...扁平表...}}`。
     * 为什么走字符串而不是直接传数组：消息 data 虽然是 `string|array|object`，
     * 但 AOT 下对 mixed 嵌套数组下钻有历史坑（README 5.1），
     * 而 JSON 编解码一次对 18 个 key 是微秒级，不值得冒那个险。
     */
    public const MSG_APPLY_CONFIG = 6;

    /** 33ms ≈ 30Hz */
    public const FRAME_MS = 33;

    public const MAX_DELTA_MS = 200;

    /** 每几帧推送一次（6 → 约 5Hz） */
    public const PUSH_EVERY = 6;

    private BattleRoom $room;

    private ?FrameLoop $loop = null;

    private int $outputAddr = 0;

    /** 每几帧推送一次（6 → 约 5Hz）。可从配置覆盖，便于测推送在帧预算里的占比 */
    private int $pushEvery = self::PUSH_EVERY;

    private int $pushTick = 0;

    private int $reportAtMs = 0;

    private int $reportEveryMs = 5000;

    /**
     * 帧耗时直方图：桶号 = 微秒 / 100，最多 401 桶（0~40ms）。
     *
     * 为什么不存样本数组再排序：`sort()` 传引用参数，AOT 下引用语义容易出岔子；
     * 而固定桶的统计不需要排序，遍历 401 次就能拿到任意分位，成本固定且可预期。
     * 401 次遍历每 5 秒才做一次，完全不影响帧预算。
     *
     * @var array<int, int> 桶号 => 次数
     */
    private array $hist = [];

    private int $histMax = 400;

    /**
     * 进行中的异步 GM 任务：taskId => {uids, i, hp, total}。
     *
     * 为什么执行游标放在 Service 而不是 Task 里：Task 是"可查询的记录"，
     * 游标只有执行者自己需要。混在一起会让任务表变成可变的大对象。
     *
     * @var array<string, array<string, mixed>>
     */
    private array $jobs = [];

    public function __construct(BattleRoom $room)
    {
        $this->room = $room;
    }

    public function setOutput(int $addr): void
    {
        $this->outputAddr = $addr;
    }

    public function room(): BattleRoom
    {
        return $this->room;
    }

    public function onStart(): void
    {
        $pushes = $this->room->skills()->getInt("net.push_every", self::PUSH_EVERY);
        $this->pushEvery = $pushes > 0 ? $pushes : self::PUSH_EVERY;

        $this->loop = new FrameLoop($this, self::MSG_TICK, self::FRAME_MS, self::MAX_DELTA_MS);
        $this->loop->start();
        $this->reportAtMs = Scheduler::nowMs() + $this->reportEveryMs;
        Logger::info("battle start", [
            "frame_ms" => self::FRAME_MS,
            "push_every" => $this->pushEvery,
            "map_w" => $this->room->width(),
            "map_h" => $this->room->height(),
            "max_players" => $this->room->maxPlayers(),
        ]);
    }

    public function onStop(): void
    {
        // 定时器由 Scheduler 在 kill 时统一撤销，这里只做业务收尾
        Logger::info("battle stop", $this->room->stats());
    }

    public function onMessage(Message $m): void
    {
        // 必须先取局部变量：直接 switch($m->msgId) 会生成 Variant 到 Int 的
        // 隐式转换，AOT 编译期报 "cannot convert php::Variant to php::Int"。
        $msgId = $m->msgId;
        if ($msgId === self::MSG_TICK) {
            $this->onFrame();
            return;
        }

        $d = $m->data;
        if (!is_string($d) || $d === "") {
            return;
        }

        if ($msgId === self::MSG_APPLY_CONFIG) {
            $this->onApplyConfig($d);
            return;
        }

        if ($msgId === self::MSG_ENTER) {
            $this->onEnter((int) $d);
            return;
        }
        if ($msgId === self::MSG_LEAVE) {
            $uid = (int) $d;
            $this->room->remove($uid);
            return;
        }

        // 以下是游戏输入，载荷都是逗号分隔的扁平串
        $parts = explode(",", $d);
        if ($msgId === self::MSG_MOVE) {
            if (count($parts) !== 3) {
                return;
            }
            $this->room->move((int) $parts[0], (int) $parts[1], (int) $parts[2]);
            return;
        }
        if ($msgId === self::MSG_CAST) {
            if (count($parts) !== 2) {
                return;
            }
            $code = $this->room->cast((int) $parts[0], (int) $parts[1]);
            if ($code !== 0) {
                // 失败原因回给本人：冷却中/已死亡/无此技能都要能自测出来
                $this->pushOne((int) $parts[0], Msg::REJECT, Msg::body([
                    "code" => $code,
                    "msg" => "cast rejected",
                ]));
            }
        }
    }

    /**
     * 【管理面】重载配置表，**同步返回结果**。
     *
     * 和消息路径的区别：这条由 GM 直接调用，不经过信箱。
     * 为什么可以这样：GM 和本服务在同一个 Scheduler 线程里，不会并发；
     * 而 GM 必须知道"到底成没成功"——返回"处理中"对运维没有意义。
     *
     * 约定：`gm*` 前缀的方法只允许 GM 调用，内部不得 spawn、不得投递消息等待回复。
     *
     * @return array<string, mixed>
     */
    public function gmReloadConfig(string $base): array
    {
        $path = $base . "/config/skill.php";
        $hash = FileLoader::hash($path);
        if ($hash === "") {
            return ["ok" => false, "msg" => "读不到文件: " . $path];
        }
        $data = FileLoader::array($path);
        if ($data === null) {
            return ["ok" => false, "msg" => "文件没有返回数组: " . $path];
        }
        $this->room->skills()->applyExternal($data, $hash);
        return ["ok" => true, "msg" => "reloaded", "keys" => count($data), "hash" => $hash];
    }

    /**
     * 【管理面】改玩家血量，同步返回改前值。
     *
     * 返回 before 不是装饰：GM 操作必须可审计——"原来多少、改成多少"要能对上，
     * 万一改错了还能立刻改回去。
     *
     * @return array<string, mixed>
     */
    public function gmSetHp(int $uid, int $hp): array
    {
        $p = $this->room->player($uid);
        if ($p === null) {
            return ["ok" => false, "msg" => "玩家 " . $uid . " 不在图中"];
        }
        $before = $p->hp;
        $p->hp = $hp;
        Logger::info("gm set hp", ["uid" => $uid, "before" => $before, "after" => $hp]);
        return ["ok" => true, "msg" => "ok", "uid" => $uid, "before" => $before, "hp" => $hp];
    }

    /**
     * 应用 GM 手动重载的配置。
     *
     * 数据在 GM 那边已经校验过（能读、是数组），这里只做最后的形状检查：
     * 载荷被截断或版本不匹配时，宁可不动现表——半个配置表比旧表更危险。
     */
    private function onApplyConfig(string $json): void
    {
        $p = json_decode($json, true);
        if (!is_array($p)) {
            return;
        }
        $t = $p["t"] ?? null;
        $h = $p["h"] ?? "";
        if (!is_array($t)) {
            return;
        }
        $this->room->skills()->applyExternal($t, is_string($h) ? $h : "");
    }

    /**
     * 【管理面】给所有在线玩家批量设血量 —— **同步版**。
     *
     * 只适合小规模（几十人）。上千人会在一帧里跑完，直接卡住全场，
     * 那种规模应该用异步版（gmStartBatchHp）。
     *
     * @return array<string, mixed>
     */
    public function gmBatchSetHp(int $hp): array
    {
        $n = 0;
        $uids = $this->room->uids();
        foreach ($uids as $uid) {
            $p = $this->room->player($uid);
            if ($p !== null) {
                $p->hp = $hp;
                $n++;
            }
        }
        return ["ok" => true, "affected" => $n, "hp" => $hp];
    }

    /**
     * 【管理面】启动一个异步批量任务，立刻返回，由帧循环每帧推进 200 个。
     *
     * 为什么分片：1 万人一口气改完是几十毫秒起步，一帧就废了。
     * 每帧 200 个，1 万人 = 50 帧 ≈ 1.7 秒，分散在 50 帧里几乎无感。
     *
     * @return int 任务总量
     */
    public function gmStartBatchHp(string $taskId, int $hp): int
    {
        $uids = $this->room->uids();
        $this->jobs[$taskId] = [
            "uids" => $uids,
            "i" => 0,
            "hp" => $hp,
            "total" => count($uids),
        ];
        Task::progress($taskId, 0);
        return count($uids);
    }

    /** 每帧推进所有进行中的异步任务，每个任务最多 200 个 */
    private function stepJobs(): void
    {
        if ($this->jobs === []) {
            return;
        }
        foreach ($this->jobs as $id => $job) {
            $uids = $job["uids"];
            $total = (int) $job["total"];
            $i = (int) $job["i"];
            $hp = (int) $job["hp"];
            $n = 0;
            while ($i < $total && $n < 200) {
                $uid = (int) $uids[$i];
                $p = $this->room->player($uid);
                if ($p !== null) {
                    $p->hp = $hp;
                }
                $i++;
                $n++;
            }
            $job["i"] = $i;
            $this->jobs[$id] = $job;
            Task::progress($id, $i);
            if ($i >= $total) {
                Task::finish($id, json_encode(["affected" => $total, "hp" => $hp]));
                unset($this->jobs[$id]);
            }
        }
    }

    public function online(): int
    {
        return count($this->room->uids());
    }

    private function onEnter(int $uid): void
    {
        if ($uid <= 0) {
            return;
        }
        $code = $this->room->add($uid);
        $p = $this->room->player($uid);
        if ($p === null) {
            $this->pushOne($uid, Msg::REJECT, Msg::body(["code" => $code, "msg" => "enter failed"]));
            return;
        }
        $this->pushOne($uid, Msg::ENTER_OK, Msg::body([
            "uid" => $uid,
            "x" => $p->x,
            "y" => $p->y,
            "hp" => $p->hp,
            "frame_ms" => self::FRAME_MS,
        ]));
    }

    /**
     * 一帧：算 delta → 推进房间 → 推事件 → 按节流推快照 → 记耗时。
     *
     * 推送放在 endFrame 之前：网络发送是这一帧的真实成本，
     * 把它排除在统计外会让"帧耗时"永远漂亮，而实际卡顿发生在发送上。
     */
    private function onFrame(): void
    {
        $loop = $this->loop;
        if ($loop === null) {
            return;
        }
        $delta = $loop->beginFrame();

        $events = $this->room->tick($delta);
        $this->flushEvents($events);

        // 异步 GM 任务推进：每帧最多处理一批，不能一口气跑完——
        // 1 万人的批量操作在一帧里做完会直接吃掉几百毫秒，全场卡住。
        $this->stepJobs();

        $this->pushTick++;
        if ($this->pushTick >= $this->pushEvery) {
            $this->pushTick = 0;
            $this->pushSnapshots();
        }

        $us = $loop->endFrame();
        $this->recordTick($us);
        $this->maybeReport();
    }

    /** 帧耗时入桶。只记 0~40ms，超出的并进最后一桶——那一档已经说明帧超了预算 */
    private function recordTick(int $us): void
    {
        $b = intdiv($us, 100);
        if ($b > $this->histMax) {
            $b = $this->histMax;
        }
        $this->hist[$b] = ($this->hist[$b] ?? 0) + 1;
    }

    /**
     * 从直方图算分位数（微秒）。
     *
     * @return array<int, int> [p50, p99, max, total]
     */
    private function percentiles(): array
    {
        $total = 0;
        foreach ($this->hist as $c) {
            $total += $c;
        }
        if ($total === 0) {
            return [0, 0, 0, 0];
        }
        $acc = 0;
        $p50 = 0;
        $p99 = 0;
        $max = 0;
        for ($i = 0; $i <= $this->histMax; $i++) {
            $c = $this->hist[$i] ?? 0;
            if ($c === 0) {
                continue;
            }
            $acc += $c;
            $max = $i * 100;
            if ($p50 === 0 && $acc * 2 >= $total) {
                $p50 = $i * 100;
            }
            if ($acc * 100 >= $total * 99) {
                $p99 = $i * 100;
                break;
            }
        }
        return [$p50, $p99, $max, $total];
    }

    /**
     * 事件推送：**一帧打包成一条 Scheduler 消息**，每个事件一行。
     *
     * 批量化是踩坑换来的：第一版每个事件发一条消息，AOE 打中 54 人就是 54 条，
     * Scheduler 被占满、输入排队、12 秒只结算 161 次施法且没有任何报错。
     *
     * 聚合是第二层优化：一次施法现在只产出一个事件包（含全部命中目标），
     * 所以行数从"1 + 命中数"降到 1。1000 人时这是 13 倍的差距。
     *
     * 观众范围：技能包发给施法者九宫格视野内的人；复活只发本人。
     * 受击者必然在施法者视野内（否则打不中），所以不需要再并受击者视野。
     *
     * 载荷：`协议号;uid1,uid2;body`，body 是元组数组
     *   [1, from, x, y, [[uid,dmg,hp], ...]]   技能结算
     *   [2, uid, x, y, hp]                      复活
     *
     * @param array<int, array<int, int|array>> $events
     */
    private function flushEvents(array $events): void
    {
        if ($this->outputAddr === 0 || $events === []) {
            return;
        }
        // 观众范围开关：1=施法者视野（真实做法），0=只发当事人（对照）
        $wide = $this->room->skills()->getInt("net.event_audience", 1) === 1;
        $lines = [];
        foreach ($events as $e) {
            $evt = $e[0];
            $from = $e[1];
            if ($from <= 0) {
                continue;
            }
            if ($wide && $evt === BattleRoom::EVT_SKILL) {
                $audience = $this->audienceOf($from);
                if ($audience === "") {
                    continue;
                }
            } else {
                $audience = (string) $from;
            }
            $lines[] = Msg::DAMAGE . ";" . $audience . ";" . Msg::body($e);
        }
        if ($lines === []) {
            return;
        }
        $this->send($this->outputAddr, Output::MSG_BATCH, implode("\n", $lines));
    }

    /**
     * 观众列表：施法者九宫格视野 + 自己，拼成逗号串。
     *
     * 用字符串拼接而不是 `implode(",", array_keys($v))`：
     * 后者要额外分配一个键数组，而这条路径每次施法都要走。
     */
    private function audienceOf(int $uid): string
    {
        $scene = $this->room->scene();
        $view = $scene->viewOf($uid);
        $s = (string) $uid;
        foreach ($view as $k => $_) {
            $s .= "," . $k;
        }
        return $s;
    }

    /**
     * 快照推送：同样合并成一条消息。
     *
     * 200 个玩家就是 200 行，但只占 1 条 Scheduler 消息。
     * 如果每人一条，5Hz 下每秒就是 1000 条消息——和事件推送叠加后，
     * 输入消息会被挤到队尾，表现为"操作延迟"而不是"推送太慢"。
     */
    private function pushSnapshots(): void
    {
        if ($this->outputAddr === 0) {
            return;
        }
        $uids = $this->room->uids();
        $lines = [];
        foreach ($uids as $uid) {
            // 房间直接给拼好的 JSON 文本，这里不再构造数组、不再 encode
            $body = $this->room->snapshotJson($uid);
            if ($body === "") {
                continue;
            }
            $lines[] = Msg::SNAPSHOT . ";" . $uid . ";" . $body;
        }
        if ($lines === []) {
            return;
        }
        $this->send($this->outputAddr, Output::MSG_BATCH, implode("\n", $lines));
    }

    private function pushOne(int $uid, int $proto, string $body): void
    {
        if ($this->outputAddr === 0 || $uid <= 0) {
            return;
        }
        $this->send($this->outputAddr, Output::MSG_PUSH, $proto . ";" . $uid . ";" . $body);
    }

    private function maybeReport(): void
    {
        $now = Scheduler::nowMs();
        if ($now < $this->reportAtMs) {
            return;
        }
        $this->reportAtMs = $now + $this->reportEveryMs;
        $loop = $this->loop;
        $s = $this->room->stats();
        if ($loop !== null) {
            $s["tick_last_us"] = $loop->lastUs();
            $s["ticks"] = $loop->ticks();
        }
        $p = $this->percentiles();
        $s["tick_p50_us"] = $p[0];
        $s["tick_p99_us"] = $p[1];
        $s["tick_max_us"] = $p[2];
        $s["tick_samples"] = $p[3];
        $this->hist = [];
        Logger::info("battle stats", $s);
    }
}
