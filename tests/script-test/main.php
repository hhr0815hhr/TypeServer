<?php

use TSF\Script\Broadcast;
use TSF\Script\Context;
use TSF\Script\Manager;
use TSF\Script\Script;

/**
 * pkg/script 的回归测试。
 *
 * 输出 PASS/FAIL 行，由 run.sh 统计。不用 assert：
 * AOT 下断言失败的行为不确定，显式比较更可靠。
 */

final class Player
{
    public int $uid = 1001;
    public int $level = 10;
    public int $gold = 500;
    public int $hp = 400;
    public int $hpMax = 1000;
    /** @var array<int, int> */
    public array $buffs = [];
}

/**
 * 类型化只读视图（L1 侧，AOT 编译）。
 *
 * 这是脚本表达力的关键：白名单只能"按字段"给，视图能"按语义"给——
 * 脚本拿到的是一个能读全部状态、能调能力方法、但**没有写入口**的对象。
 *
 * 实测依据（bench/script-cost）：方法调用 45 ns/次，白名单字段访问 410 ns/次。
 * 视图不只是表达力更好，它快 9 倍。
 */
final class PlayerView
{
    private Player $p;

    public function __construct(Player $p)
    {
        $this->p = $p;
    }

    public function level(): int
    {
        return $this->p->level;
    }

    public function gold(): int
    {
        return $this->p->gold;
    }

    public function hpRatioPct(): int
    {
        return intdiv($this->p->hp * 100, $this->p->hpMax);
    }

    public function hasBuff(int $id): bool
    {
        return isset($this->p->buffs[$id]);
    }

    /** 能力方法：判断留在 AOT，规则（用不用、什么时候用）可以热更 */
    public function canJoin(int $needLevel): bool
    {
        return $this->p->level >= $needLevel;
    }

    /**
     * 显式拒绝一切写属性。
     *
     * 不加这个的话，脚本写 `$v->level = 99` 在 PHP 8.2+ 只是 Deprecated、
     * 会**静默创建动态属性**——脚本以为写成功了，宿主状态其实没变。
     * "写了但没用"比报错难查得多，必须在视图层就掐掉。
     */
    public function __set(string $name, string|array|object|int|float|bool|null $value): void
    {
        throw new \RuntimeException("PlayerView 是只读视图，不能写 " . $name);
    }
}

function check(bool $cond, string $name, string $detail = ""): void
{
    if ($cond) {
        echo "PASS " . $name . "\n";
    } else {
        echo "FAIL " . $name . ($detail !== "" ? "  (" . $detail . ")" : "") . "\n";
    }
}


/**
 * 副作用能力：脚本能不能"做事"，而不只是"改数字"。
 *
 * 这是热更能覆盖"活动玩法"还是只能覆盖"调数值"的分界线。
 */
final class RewardProbe
{
    public static int $amount = 0;

    public static string $reason = "";

    public static int $rewarded = 0;

    public static array $logs = [];
}

function caseSideEffects(): void
{
    echo PHP_EOL . "[副作用能力]" . PHP_EOL;
    $dir = __DIR__;
    $mgr = new Manager();
    $mgr->register("quest", $dir . "/quest.php");
    $mgr->loadAll();
    $s = $mgr->get("quest");
    if ($s === null) {
        check(false, "取到脚本");
        return;
    }

    $p = new Player();

    // 1) 未授权时脚本发奖 → 抛错（不能静默成功，也不能静默失败）
    $ctx = new Context($p, ["uid", "gold"], ["gold"]);
    $threw = false;
    try {
        $ctx->reward(100, "probe");
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    check($threw, "未 bindReward 时发奖抛错");
    check(!$ctx->canReward(), "canReward() 为假");

    // 2) 授权后脚本能发奖，宿主拿到金额与理由
    RewardProbe::$amount = 0;
    RewardProbe::$reason = "";
    RewardProbe::$logs = [];
    $p2 = new Player();
    $p2->gold = 100;                       // 低于 500 → 脚本会发奖
    $ctx2 = new Context($p2, ["uid", "gold"], ["gold"]);
    $ctx2->bindReward(function (int $amount, string $reason): bool {
        RewardProbe::$amount = $amount;
        RewardProbe::$reason = $reason;
        RewardProbe::$rewarded++;
        return true;
    });
    $ctx2->bindLog(function (string $msg): void {
        RewardProbe::$logs[] = $msg;
    });
    $s->callWithContext("daily", $ctx2);
    check(RewardProbe::$rewarded === 1, "脚本触发了一次发奖");
    check(RewardProbe::$amount === 350, "金额正确（350）", "实际 " . RewardProbe::$amount);
    check(RewardProbe::$reason === "daily_first_win", "理由正确", RewardProbe::$reason);
    check(count(RewardProbe::$logs) === 1, "脚本写了一条日志");

    // 3) 条件不满足时不发奖（脚本里的 if 是真的在跑）
    RewardProbe::$rewarded = 0;
    $p3 = new Player();
    $p3->gold = 900;
    $ctx3 = new Context($p3, ["uid", "gold"], ["gold"]);
    $ctx3->bindReward(function (int $amount, string $reason): bool {
        RewardProbe::$rewarded++;
        return true;
    });
    $s->callWithContext("daily", $ctx3);
    check(RewardProbe::$rewarded === 0, "gold>=500 时不发奖");

    // 4) 日志未绑定时静默：不能因为"没人收日志"把活动打挂
    $ctx4 = new Context(new Player(), ["uid", "gold"], ["gold"]);
    $noThrow = true;
    try {
        $ctx4->log("nobody listens");
    } catch (\Throwable $e) {
        $noThrow = false;
    }
    check($noThrow, "未 bindLog 时 log 静默不抛错");
}


/**
 * 玩法能力：定时器 / 发消息 / 读配置。
 *
 * 对标 Lua 宿主注册的 C 函数面。没有这三样，脚本只能"改数字"，
 * 一旦要"30 秒后结算""通知玩家""读活动参数"就得改 AOT 代码——那不叫热更。
 */
function caseCaps(): void
{
    echo PHP_EOL . "[玩法能力]" . PHP_EOL;

    $mgr = new Manager();
    $mgr->register("quest", __DIR__ . "/quest.php");
    $mgr->loadAll();
    $s = $mgr->get("quest");
    if ($s === null) {
        check(false, "取到脚本");
        return;
    }

    // ---- 1) 未授权：调用即抛错 ----
    $ctx = new Context(new Player(), ["uid"], []);
    $threw = 0;
    try {
        $ctx->timer(1000, "x");
    } catch (\RuntimeException $e) {
        $threw++;
    }
    try {
        $ctx->send("a", 1, "d");
    } catch (\RuntimeException $e) {
        $threw++;
    }
    check($threw === 2, "未绑定 timer/send 时抛错", "n=" . $threw);
    check(!$ctx->canTimer() && !$ctx->canSend(), "can() 如实反映");
    check($ctx->configInt("nope", 7) === 7, "未绑定 config 时返回默认值");

    // ---- 2) 授权后：脚本能挂号、能发消息、能读配置 ----
    $got = [];
    $ctx2 = new Context(new Player(), ["uid"], []);
    $ctx2->bind("timer", function (int $ms, string $entry) use (&$got): void {
        $got[] = "timer:" . $ms . ":" . $entry;
    });
    $ctx2->bind("send", function (string $target, int $msgId, string $data) use (&$got): bool {
        $got[] = "send:" . $target . ":" . $msgId . ":" . $data;
        return true;
    });
    $ctx2->bind("config", function (string $key): string {
        if ($key === "activity.enabled") {
            return "1";
        }
        if ($key === "activity.reward") {
            return "500";
        }
        return "";
    });
    $ctx2->bind("log", function (string $msg) use (&$got): void {
        $got[] = "log:" . $msg;
    });

    $r = $s->callWithContext("activity", $ctx2);
    check($r === 1, "activity 返回 1（开关打开）", "got=" . var_export($r, true));
    check(in_array("timer:30000:onActivitySettle", $got, true), "脚本挂上了 30 秒后的回调", implode(" | ", $got));
    check(in_array("send:player:1001:9001:join", $got, true), "脚本发出了消息");
    check($ctx2->configInt("activity.reward") === 500, "脚本读到配置数值");

    // ---- 3) 开关关掉时什么都不做 ----
    $got2 = [];
    $ctx3 = new Context(new Player(), ["uid"], []);
    $ctx3->bind("timer", function (int $ms, string $entry) use (&$got2): void {
        $got2[] = "timer";
    });
    $ctx3->bind("config", function (string $key): string {
        return "0";     // activity.enabled = 0
    });
    $r2 = $s->callWithContext("activity", $ctx3);
    check($r2 === 0, "开关关闭时返回 0");
    check($got2 === [], "关闭后没有挂号任何回调");
}


/**
 * 沙箱：死循环必须能被打断。
 *
 * 这是"敢不敢把脚本交给运营改"的分水岭。没有执行预算时，一个 `while (true)`
 * 就足以让整个 worker 停止处理消息——所有玩家一起掉，而且日志里什么都没有
 * （因为它没有崩，只是不再往后跑）。
 */
function caseSandbox(): void
{
    echo PHP_EOL . "[沙箱：执行预算]" . PHP_EOL;

    $mgr = new Manager();
    $mgr->register("loop", __DIR__ . "/loop.php");
    $mgr->loadAll();
    $s = $mgr->get("loop");
    if ($s === null) {
        check(false, "取到死循环脚本");
        return;
    }

    $ctx = new Context(new Player(), ["uid"], []);
    $t0 = microtime(true);
    // 预算 1 秒（zend_set_timeout 只支持秒级）
    $r = $s->callWithContext("main", $ctx, 1000);
    $elapsed = microtime(true) - $t0;

    $isErr = is_array($r) && isset($r["ok"]) && $r["ok"] === false;
    check($isErr, "死循环被中断（返回结构化错误）", var_export($r, true));
    check($elapsed < 3.0, "在预算附近返回，没有永久卡死", "elapsed=" . round($elapsed, 2) . "s");
    if (is_array($r)) {
        check(isset($r["timeout"]) && $r["timeout"] === true, "标记为 timeout（可与其它 fatal 区分）");
    }

    // 中断之后脚本管理器仍然可用：同一个死循环脚本再调一次，仍然是被预算打断，
    // 而不是把进程带崩（预算参数必须带，否则这一行会真的卡住测试进程）
    $ok = $s->callWithContext("main", new Context(new Player(), ["uid"], []), 1000);
    check(is_array($ok) && isset($ok["ok"]) && $ok["ok"] === false, "再次调用仍返回错误而不是崩溃进程");
}


/**
 * 脚本私有状态：跨热更不丢。
 *
 * 热更 = 重新 require 一遍文件，脚本里的 `static` 必然重置（Lua 换 chunk 也一样）。
 * 但"已发奖名单""本轮累计"必须活下来，所以状态要外置到宿主。
 */
function caseState(): void
{
    echo PHP_EOL . "[脚本私有状态]" . PHP_EOL;

    $mgr = new Manager();
    $mgr->register("quest", __DIR__ . "/quest.php");
    $mgr->loadAll();
    $s = $mgr->get("quest");
    if ($s === null) {
        check(false, "取到脚本");
        return;
    }

    $mk = function (): Context {
        return new Context(new Player(), ["uid"], []);
    };

    check($s->callWithContext("counter", $mk()) === 1, "第一次调用计数 1");
    check($s->callWithContext("counter", $mk()) === 2, "第二次 2（跨调用累计）");

    // ---- 关键：热更之后状态还在 ----
    $before = $s->versions();
    check($s->reload(), "reload 成功");
    check($s->versions() === $before + 1, "版本号递增", "v=" . $s->versions());
    check($s->callWithContext("counter", $mk()) === 3, "reload 后计数继续（3），没有清零");

    // 宿主可以直接拿到状态做事（落盘 / 落库）
    check($s->state()->getInt("hits") === 3, "state() 能导出当前值");
    $s->state()->setString("lastWinner", "u1001");
    check($s->state()->getString("lastWinner") === "u1001", "宿主写入的也能被脚本读到");

    // ---- Context 侧的四种类型都要能用（string/bool 之前漏了测试）----
    $ctxT = $mk();
    $s->callWithContext("counter", $ctxT);          // 让 Script 把 state 挂上
    $ctxT->setStateString("phase", "final");
    check($ctxT->stateString("phase") === "final", "Context 能写读 string 状态");
    check($ctxT->stateString("missing", "dft") === "dft", "未设置的 string 返回默认值");

    $ctxT->setStateBool("claimed", true);
    check($ctxT->stateBool("claimed") === true, "Context 能写读 bool 状态");
    check($ctxT->stateBool("neverSet") === false, "未设置的 bool 默认为 false");

    $ctxT->setStateInt("capped", 7);
    check($ctxT->stateInt("capped") === 7, "Context 能写读 int 状态");
    check($ctxT->stateInt("absent", 42) === 42, "未设置的 int 返回默认值");

    // 状态是同一个对象：Context 写的，宿主 state() 立刻能读到（不能是副本）
    check($s->state()->getString("phase") === "final", "Context 写入与宿主 state() 共享同一份");
    check($s->state()->getBool("claimed") === true, "bool 也共享");

    // ---- 另一个 Script 是独立状态（不共享）----
    $mgr2 = new Manager();
    $mgr2->register("quest", __DIR__ . "/quest.php");
    $mgr2->loadAll();
    $s2 = $mgr2->get("quest");
    if ($s2 !== null) {
        check($s2->callWithContext("counter", $mk()) === 1, "新实例从 1 开始（状态不跨进程）");
    }

    // ---- import/export：进程重启后恢复 ----
    // 用"当前值"而不是写死的 3：前面新增的调用会让 hits 继续增长，
    // 断死数字会在下次有人加一句调用时莫名其妙挂掉
    $hitsNow = $s->state()->getInt("hits");
    $snapshot = $s->state()->export();
    $s3state = new \TSF\Script\State();
    $s3state->import($snapshot);
    check($s3state->getInt("hits") === $hitsNow, "导出/导入可以恢复状态", "want=" . $hitsNow);
    check($s3state->getString("lastWinner") === "u1001", "字符串字段也恢复");
}


/**
 * 脚本间调用：活动 A 能调活动 B 的入口，但**不是随便调**。
 *
 * 三件事必须成立：
 *   1. 能传参、能拿返回值
 *   2. 逻辑名由宿主映射到文件，脚本不知道路径
 *   3. 循环调用（A→A）不会把进程拖死，而是第 8 层抛错
 */
function caseScriptCall(): void
{
    echo PHP_EOL . "[脚本间调用]" . PHP_EOL;

    $mgr = new Manager();
    $mgr->register("quest", __DIR__ . "/quest.php");
    $mgr->register("peer", __DIR__ . "/quest.php");
    $mgr->loadAll();

    $s = $mgr->get("quest");
    if ($s === null) {
        check(false, "取到脚本");
        return;
    }

    // ---- 1) 未授权：调用即抛错 ----
    $ctx0 = new Context(new Player(), ["uid"], []);
    $threw = false;
    try {
        $ctx0->callScript("peer", "ping", "x");
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    check($threw, "未 bind callScript 时抛错");

    // ---- 2) 授权后：能调、能传参、能拿返回值 ----
    $ctx = new Context(new Player(), ["uid"], []);
    $ctx->bind("callScript", function (string $t, string $e, string $a) use ($mgr): string|int|float|bool|null {
        return $mgr->invoke($t, $e, $a);
    });
    $ctx->setArg("hello");
    $r = $s->callWithContext("callPeer", $ctx, 1000);
    check($r === "pong:hello", "A 调到 B、传参、拿到返回值", "r=" . var_export($r, true));

    // 入参走 ctx->arg()，不占用 target 的业务字段
    check($ctx->arg() === "hello", "入参存在 Context 上，不碰业务字段");

    // ---- 3) 循环调用：第 8 层抛错，不会无限递归 ----
    $ctx2 = new Context(new Player(), ["uid"], []);
    $ctx2->bind("callScript", function (string $t, string $e, string $a) use ($mgr): string|int|float|bool|null {
        return $mgr->invoke($t, $e, $a);
    });
    // self 指向自己，callPeer 调 callPeer 就是 A→A
    $mgr->register("self", __DIR__ . "/quest.php");
    $mgr->loadAll();
    // 递归到第 8 层抛 RuntimeException，逐层包装后抛到最外层的 AOT 调用方
    $blocked = false;
    try {
        $mgr->invoke("self", "loopCall", "x");
    } catch (\RuntimeException $e) {
        $blocked = strpos($e->getMessage(), "调用层级") !== false;
    }
    check($blocked, "循环调用在第 " . Manager::MAX_DEPTH . " 层被拦下（不拖死进程）");

    // 深度计数要复位：一次失败的递归不能污染后续正常调用
    $r2 = $mgr->invoke("peer", "ping", "after");
    check($r2 === "pong:after", "递归失败后深度已复位，正常调用不受影响", "r=" . var_export($r2, true));
}

/**
 * 灰度：同一逻辑两个版本，按 uid 分流。
 *
 * 四条必须钉住：
 *   1 注册了 alt 不等于启用（默认 0%，setGray 是显式动作）
 *   2 同一 uid 结果恒定（分流必须是纯函数，否则同一个人每次调用走不同版本）
 *   3 连续 uid 不能按段划分（`uid % 100` 会让 1001–1100 全进新逻辑）
 *   4 reload 要连 alt 一起换（只换 base 会出现"灰度中的人继续跑旧代码"）
 */
function caseGray(): void
{
    $dir = __DIR__;
    $mgr = new Manager();
    $mgr->register("gray", $dir . "/gray_a.php");
    $mgr->registerAlt("gray", 2, $dir . "/gray_b.php");
    $mgr->register("plain", $dir . "/gray_a.php");
    $failed = $mgr->loadAll();
    check(count($failed) === 0, "灰度：base 与 alt 都能加载");

    $p = new Player();
    $ctx = new Context($p, ["uid"], []);

    // 注册了 alt 但没设比例：必须走 base
    $rDefault = $mgr->callGray("gray", 1001, "val", $ctx);
    check($rDefault === 1, "灰度默认 0%：走 base", "实际 " . (string) $rDefault);

    $mgr->setGray("gray", 100);
    $rAll = $mgr->callGray("gray", 1001, "val", $ctx);
    check($rAll === 2, "灰度 100%：走 alt", "实际 " . (string) $rAll);

    $mgr->setGray("gray", 50);
    $hit = 0;
    $stable = true;
    for ($i = 0; $i < 1000; $i++) {
        $uid = 10000 + $i;
        $a = $mgr->callGray("gray", $uid, "val", $ctx);
        $b = $mgr->callGray("gray", $uid, "val", $ctx);
        if ($a !== $b) {
            $stable = false;
        }
        if ($a === 2) {
            $hit++;
        }
    }
    check($stable, "灰度：同一 uid 结果恒定");
    check($hit > 400 && $hit < 600, "灰度 50%：1000 个 uid 里 400-600 命中", "命中 " . $hit);

    // 连续 50 个 uid 必须两版都出现。
    // 为什么是 50 不是 100：`uid % 100` 在 100 个连续 uid 上会覆盖全部 100 个桶，
    // 两版都出现——那样这条断言就测不出"按段划分"。50 个才能暴露它
    // （0–49 全落进 <50，seenBase 会是 false）。
    $seenBase = false;
    $seenAlt = false;
    for ($i = 0; $i < 50; $i++) {
        $v = $mgr->callGray("gray", 20000 + $i, "val", $ctx);
        if ($v === 1) {
            $seenBase = true;
        }
        if ($v === 2) {
            $seenAlt = true;
        }
    }
    check($seenBase && $seenAlt, "灰度：连续 50 个 uid 两版都有（不按段划分）");

    // 没有 alt 的脚本：设了比例也不该出错
    $rPlain = $mgr->callGray("plain", 1001, "val", $ctx);
    check($rPlain === 1, "没有 alt 的脚本：灰度设置被忽略", "实际 " . (string) $rPlain);

    // reload 要连 alt 一起换
    $mgr->setGray("gray", 100);
    check($mgr->reload("gray"), "reload 同时覆盖 base 与 alt");
    $afterReload = $mgr->callGray("gray", 1001, "val", $ctx);
    check($afterReload === 2, "reload 后灰度仍指向 alt", "实际 " . (string) $afterReload);
}

/**
 * 模拟网络：把 send 闭包收到的消息存下来，测试里手工分发。
 *
 * 为什么不启真集群：广播的协议层（reqId / ack 汇总 / 缺失判定）与传输无关，
 * 起两个进程只会让测试变慢、变脆。真集群那条路由 `cluster-guard` 覆盖。
 */
final class Wire
{
    /** @var array<int, int> */
    public array $nodes = [];

    /** @var array<int, int> */
    public array $ids = [];

    /** @var array<int, string> */
    public array $payloads = [];

    public function put(int $node, int $msgId, string $payload): void
    {
        $this->nodes[] = $node;
        $this->ids[] = $msgId;
        $this->payloads[] = $payload;
    }
}

/**
 * 重载广播：发出去 / 对方重载 / ack 汇总 / 缺失与失败要分开。
 */
function caseBroadcast(): void
{
    $dir = __DIR__;

    // 两个"节点"各一份 Manager：模拟两台机器各自的脚本表
    $mgrA = new Manager();
    $mgrA->register("bcast", $dir . "/gray_a.php");
    $mgrA->loadAll();

    $mgrB = new Manager();
    $mgrB->register("bcast", $dir . "/gray_a.php");
    $mgrB->loadAll();

    $wire = new Wire();
    $bcastA = new Broadcast($mgrA, 1, function (int $node, int $msgId, string $payload) use ($wire): void {
        $wire->put($node, $msgId, $payload);
    });
    $bcastB = new Broadcast($mgrB, 2, function (int $node, int $msgId, string $payload): void {
    });

    $before = 0;
    $sB0 = $mgrB->get("bcast");
    if ($sB0 instanceof Script) {
        $before = $sB0->versions();
    }

    $reqId = $bcastA->request("bcast", [2], "r1");
    check($reqId === "r1", "request 返回调用方给的 reqId");
    check(count($wire->ids) === 1, "广播发出 1 条");
    check($wire->ids[0] === Broadcast::MSG_RELOAD, "消息号是 RELOAD");
    check($wire->nodes[0] === 2, "目标是节点 2");

    // 节点 2 收到并重载
    $ack = $bcastB->onMessage(Broadcast::MSG_RELOAD, $wire->payloads[0]);
    check($ack !== "", "节点 2 回了 ack");

    $after = 0;
    $sB1 = $mgrB->get("bcast");
    if ($sB1 instanceof Script) {
        $after = $sB1->versions();
    }
    check($after === $before + 1, "节点 2 真的重载了", "versions " . $before . " -> " . $after);

    // 节点 1 收 ack
    $bcastA->onMessage(Broadcast::MSG_ACK, $ack);
    $rep = $bcastA->report("r1");
    check($rep["done"] === true, "全部 ack 后 done=true");
    check($rep["missing"] === 0, "没有缺失节点");
    check(count($rep["acked"]) === 1, "acked 里有 1 个节点");

    // 两个目标只回一个：missing 必须是 1，done 必须是 false
    $wire2 = new Wire();
    $bcastA2 = new Broadcast($mgrA, 1, function (int $node, int $msgId, string $payload) use ($wire2): void {
        $wire2->put($node, $msgId, $payload);
    });
    $bcastA2->request("bcast", [2, 3], "r2");
    check(count($wire2->ids) === 2, "两个目标各发 1 条");

    $ack2 = $bcastB->onMessage(Broadcast::MSG_RELOAD, $wire2->payloads[0]);
    $bcastA2->onMessage(Broadcast::MSG_ACK, $ack2);
    $rep2 = $bcastA2->report("r2");
    check($rep2["missing"] === 1, "3 号没回，missing=1");
    check($rep2["done"] === false, "未全部 ack，done=false");

    // 3 号回了个失败 ack：要从 missing 挪到 failedNodes，不能混成一类
    $failAck = "r2" . chr(31) . "3" . chr(31) . "0" . chr(31) . "0";
    $bcastA2->onMessage(Broadcast::MSG_ACK, $failAck);
    $rep3 = $bcastA2->report("r2");
    check($rep3["missing"] === 0, "失败也计入 settled，missing=0");
    check(count($rep3["failedNodes"]) === 1, "failedNodes 里有 1 个节点");
    check($rep3["done"] === true, "全部有回音，done=true（失败也是结果）");

    // 坏包不能崩、不能改状态
    $bad = $bcastA2->onMessage(Broadcast::MSG_RELOAD, "no-separator");
    check($bad === "", "坏 RELOAD 包返回空串");
}

/**
 * 类型化视图：脚本能读全部状态、能调能力方法，但写不进去。
 *
 * 这条路要成立，必须同时满足三条：
 *   1 方法能跨边界调到（interp 里 $v->level() 走的是 AOT 编译的方法）
 *   2 值是对的（含 intdiv 这类 AOT 语义和 PHP 不同的地方）
 *   3 没有写入口（属性 private + 没有 setter）
 */
function caseView(): void
{
    $dir = __DIR__;
    $mgr = new Manager();
    $mgr->register("view", $dir . "/view.php");
    $failed = $mgr->loadAll();
    check(count($failed) === 0, "View：脚本加载");

    $s = $mgr->get("view");
    if ($s === null) {
        check(false, "View：按名取到脚本");
        return;
    }

    $p = new Player();
    $p->level = 50;
    $p->gold = 500;
    $p->hp = 400;
    $p->hpMax = 1000;
    $p->buffs = [7 => 1];

    $v = new PlayerView($p);

    // 50 * 1000 + 500 + 40 + 1
    $r = $s->call("describe", $v);
    check($r === 50541, "View：脚本读到全部状态且值正确", "实际 " . (string) $r);

    $r2 = $s->call("canJoin", $v);
    check($r2 === "yes", "View：能力方法在 AOT 侧判定", "实际 " . (string) $r2);

    $r3 = $s->call("tryWrite", $v);
    check($r3 === "blocked", "View：脚本写属性被拦下", "实际 " . (string) $r3);
    check($p->level === 50, "View：被拦后宿主状态没变", "level=" . (string) $p->level);
}

function main(): void
{
    $dir = __DIR__;

    // ---- 1. 加载 ----
    $mgr = new Manager();
    $mgr->register("quest", $dir . "/quest.php");
    $failed = $mgr->loadAll();
    check(count($failed) === 0, "加载脚本");

    $s = $mgr->get("quest");
    check($s !== null, "按名取到脚本");
    if ($s === null) {
        return;
    }
    check($s->loaded(), "loaded() 为真");
    check($s->versions() === 1, "首次加载 versions=1");

    // ---- 2. 对象共享 ----
    $p = new Player();
    $ctx = new Context($p, ["uid", "level", "gold"], ["gold"]);
    $r = $s->callWithContext("main", $ctx);
    check($p->gold === 600, "脚本改了宿主对象（500 -> 600）", "实际 " . (string) $p->gold);
    check($r === 600, "返回值是脚本算出的 600");

    // ---- 3. 只读字段 ----
    $ctx2 = new Context($p, ["uid", "level", "gold"], ["gold"]);
    check(!$ctx2->canWrite("level"), "level 不可写");
    check($ctx2->canWrite("gold"), "gold 可写");
    check($ctx2->getInt("level") === 10, "只读字段能读");

    // ---- 4. 越权写入 ----
    $threw = false;
    try {
        $ctx2->setInt("level", 99);
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    check($threw, "越权写入抛 RuntimeException");
    check($p->level === 10, "越权后 level 没变");

    // ---- 5. 热更 ----
    $path = $dir . "/quest.php";
    $orig = (string) file_get_contents($path);
    // quest.php 里是 $ctx->addInt("gold", 100)，不是 "+ 100"
    $mod = str_replace('"gold", 100', '"gold", 999', $orig);
    if ($mod === $orig) {
        check(false, "测试自身：改脚本内容失败（字符串没匹配上）");
        return;
    }
    file_put_contents($path, $mod);

    // filemtime 精度是秒，测试里直接 reload 而不是等 changed()
    $s->reload();
    $p2 = new Player();
    $ctx3 = new Context($p2, ["gold"], ["gold"]);
    $s->callWithContext("main", $ctx3);
    check($p2->gold === 1499, "热更后走新逻辑（500 -> 1499）", "实际 " . (string) $p2->gold);
    check($s->versions() === 2, "reload 后 versions=2");

    // 还原脚本
    file_put_contents($path, $orig);

    // ---- 6. 异常隔离 ----
    $mgr->register("boom", $dir . "/boom.php");
    $mgr2failed = $mgr->loadAll();
    check(count($mgr2failed) === 0, "加载会抛异常的脚本");

    $boom = $mgr->get("boom");
    if ($boom !== null) {
        $ctx4 = new Context(new Player(), ["gold"], ["gold"]);
        $r2 = $boom->callWithContext("main", $ctx4);
        $isErr = is_array($r2) && isset($r2["ok"]) && $r2["ok"] === false;
        check($isErr, "异常被隔离成结构化错误");
        if ($isErr) {
            check(isset($r2["class"]) && $r2["class"] === "RuntimeException", "错误里带异常类名");
        }
    }

    caseSideEffects();
    caseCaps();
    caseSandbox();
    caseState();
    caseScriptCall();
    caseGray();
    caseBroadcast();
    caseView();

    echo "DONE\n";
}
