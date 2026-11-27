<?php

namespace Game\App;

use Game\Domain\LootAot;
use Game\Domain\RaidState;
use Game\Domain\RaidView;
use Game\Protocol\Cmd;
use TSF\Cluster\Addr;
use TSF\Cluster\Cluster;
use TSF\Gateway\Gateway;
use TSF\Kernel\Config;
use TSF\Kernel\Logger;
use TSF\Script\Broadcast;
use TSF\Script\Context;
use TSF\Script\Manager;
use TSF\Script\Script;
use TSF\Service\Message;
use TSF\Service\Service;

/**
 * 结算服：AOT 负责状态与执行，脚本负责规则。
 *
 * ## 一条请求的完整链路
 *
 *   客户端 raid 请求
 *     → Gateway 鉴权/限流
 *     → handler 投 SVC_RAID（只带 fd|uid|kills，不带规则）
 *     → 这里：算基础收益（AOT 规则）→ 问 loot 脚本掉什么 → 钱包执行
 *            → 问 activity 脚本要不要加奖 → 钱包执行 → 回包
 *
 * ## 三个刻意分开的东西
 *
 *   1. **规则（脚本）**：掉率、活动倍率、条件。运营每周改。
 *   2. **执行（AOT）**：加钱、加物品、审计。一次写错就是资损，不放脚本。
 *   3. **不确定性（AOT）**：随机源在 RaidView 里。放脚本就没法回放、没法对账。
 *
 * ## 能力闭包为什么在 onStart 建一次
 *
 * 每次请求都 `new` 一批闭包（bind reward/log/timer/config）会多分配 4 个对象。
 * 单次几十纳秒，但结算路径上一天几百万次就没必要了。能力只依赖 `$this`，
 * 与"哪个玩家"无关，所以共享一份；当前玩家由 `$this->cur` 指过去
 * （单线程串行处理，不存在交叉）。
 */
final class RaidService extends Service
{
    private Gateway $gw;

    private Manager $mgr;

    private string $scriptDir;

    private Wallet $wallet;

    /** 当前正在结算的状态，能力闭包通过它取上下文 */
    private ?RaidState $cur = null;

    /** @var array<string, \Closure> 能力名 => 实现，onStart 建一次 */
    private array $caps = [];

    /** @var array<int, RaidState> 定时器 token => 到点时要用的状态 */
    private array $pending = [];

    private int $timerSeq = 0;

    /**
     * 全服累计判定序号（AOT 持有）。
     *
     * 随机源必须是"跨请求延续"的序列，否则同一玩家的每次结算都走同一段
     * 散列值（第一版就踩了：200 次请求掉 200 件，像 100% 掉率）。
     */
    private int $rollSeq = 0;

    /** @var array<string, int> GM 设过的灰度比例（Manager 没有 getter，这里留一份） */
    private array $grayPct = [];

    /** 集群（可选）：没配 RULE_HOT_NODE 时保持 null，单机行为一字不变 */
    private ?Cluster $cluster = null;

    private ?Broadcast $bc = null;

    // ---- 统计（GM status / 日志用）----
    private int $requests = 0;

    private int $dropTotal = 0;

    private int $goldTotal = 0;

    private int $scriptErrors = 0;

    private int $rewardsRejected = 0;

    /** 最近一次脚本错误的定位信息（file:line + message），GM 直接能看 */
    private string $lastScriptError = "";

    public function __construct(Gateway $gw, string $scriptDir)
    {
        $this->gw = $gw;
        $this->scriptDir = $scriptDir;
        $this->wallet = new Wallet();
        $this->mgr = new Manager();
    }

    public function onStart(): void
    {
        // 注册脚本：loot 有 base + 灰度两版，activity 只有一版。
        // registerAlt 只是"登记"，不设比例不生效（setGray 默认 0）。
        $this->mgr->register("loot", $this->scriptDir . "/loot.php");
        $this->mgr->registerAlt("loot", 2, $this->scriptDir . "/loot.hotfix.php");
        $this->mgr->register("activity", $this->scriptDir . "/activity.php");

        $failed = $this->mgr->loadAll();
        if ($failed !== []) {
            // 不崩：脚本坏了服务还得活着，客户端至少还能拿到基础收益（AOT 路径）
            Logger::error("脚本加载失败，将回退 AOT 规则", ["names" => $failed]);
        }

        $self = $this;
        $this->caps["reward"] = function (int $amount, string $reason) use ($self): bool {
            return $self->award($amount, $reason);
        };
        $this->caps["log"] = function (string $msg) use ($self): void {
            $self->audit($msg);
        };
        $this->caps["timer"] = function (int $ms, string $entry) use ($self): void {
            $self->scheduleScriptEntry($ms, $entry);
        };
        $this->caps["config"] = function (string $key): string {
            return Config::getString("game." . $key, "");
        };

        Logger::info("rule service ready", [
            "scripts" => implode(",", $this->mgr->names()),
            "dir" => $this->scriptDir,
        ]);
    }

    public function onMessage(Message $m): void
    {
        // 先取局部：直接 switch($m->msgId) 在 AOT 下会触发 Variant→Int 转换错误
        $msgId = $m->msgId;
        $d = $m->data;

        if ($msgId === Cmd::SVC_SCRIPT_TIMER) {
            if (is_string($d)) {
                $this->onScriptTimer($d);
            }
            return;
        }

        if ($msgId !== Cmd::SVC_RAID) {
            return;
        }
        if (!is_string($d) || $d === "") {
            return;
        }
        $this->onRaid($d);
    }

    /**
     * 一次结算。payload 格式 `fd|uid|kills`。
     *
     * 为什么用扁平串而不是数组：Service 的 data 是 string|array|object，
     * 取字段要先收窄；结算路径上每帧多条，多一层判断不值得（与 battle 一致）。
     */
    private function onRaid(string $payload): void
    {
        $parts = explode("|", $payload);
        if (count($parts) !== 3) {
            return;
        }
        $fd = (int) $parts[0];
        $uid = (int) $parts[1];
        $kills = (int) $parts[2];
        if ($uid <= 0 || $kills <= 0) {
            return;
        }

        $level = 1 + intdiv($uid % 100, 10);
        $luck = 0;
        $baseGold = 10;

        // ---- 1. 判定：问 loot 脚本该掉什么 ----
        // 判定基线先取出来：见 RaidView::seqBase 的注释（不延续就会"永远同一结果"）
        $seqBase = $this->rollSeq;
        $this->rollSeq = $this->rollSeq + $kills * 3;
        $view = new RaidView($uid, $kills, $level, $luck, $seqBase);

        // 用 pick 而不是 get：pick 会按 uid 的灰度比例在 base / hotfix 之间选
        $spec = "";
        $script = $this->mgr->pick("loot", $uid);
        if ($script instanceof Script) {
            $r = $script->call("roll", $view);
            if (is_string($r)) {
                $spec = $r;
            } elseif (is_array($r) && isset($r["ok"]) && $r["ok"] === false) {
                // 脚本抛异常时 interp 返回结构化错误。不能静默当空——
                // 否则线上表现是"掉落突然全空"，日志里什么都没有
                $this->scriptErrors = $this->scriptErrors + 1;
                $why = isset($r["message"]) ? (string) $r["message"] : "script error";
                // interp 现在会带 file / line：没有它，2000 行的脚本报一句
                // "business failed"，运维只能人肉翻
                $at = "";
                if (isset($r["file"]) && isset($r["line"])) {
                    $at = (string) $r["file"] . ":" . (string) $r["line"];
                }
                $this->lastScriptError = $at === "" ? $why : ($at . " " . $why);
                Logger::error("loot 脚本失败，回退 AOT 规则", ["why" => $why, "at" => $at]);
                // 用同一个基线重建视图：脚本已经消耗掉了 seq，复用会错位
                $spec = LootAot::roll(new RaidView($uid, $kills, $level, $luck, $seqBase));
            }
        } else {
            // 没有脚本（加载失败/被删）：走 AOT 兜底，服务不中断
            $spec = LootAot::roll($view);
        }

        // ---- 2. 执行：物品入库（脚本只说"掉什么"，进不进由这里定）----
        $before = $this->wallet->itemCount();
        $this->wallet->addSpec($spec);
        $dropped = $this->wallet->itemCount() - $before;

        $this->wallet->addGold($baseGold * $kills, "raid_base");

        // ---- 3. 副作用：问 activity 脚本要不要加奖 ----
        $state = new RaidState($uid, $level, $kills, $baseGold);
        $this->cur = $state;
        $act = $this->mgr->get("activity");
        if ($act instanceof Script) {
            $ctx = $this->makeCtx($state);
            $act->callWithContext("onKill", $ctx, 500);
        }
        $this->cur = null;

        $this->requests = $this->requests + 1;
        $this->dropTotal = $this->dropTotal + $dropped;
        $this->goldTotal = $this->goldTotal + $baseGold * $kills;

        // ---- 4. 回包：把"这次掉了什么 + 现在总共多少"给客户端，便于统计 ----
        $body = "kills=" . $kills
            . " drops=" . $dropped
            . " spec=" . ($spec === "" ? "-" : $spec)
            . " gold=" . $this->wallet->gold()
            . " items=" . $this->wallet->itemCount();
        $this->gw->send($fd, Cmd::RAID_RESP, $body);
    }

    /**
     * 脚本挂了延时：登记一个一次性定时器，到点回投自己。
     *
     * 状态要跟着 token 存：一次结算结束后 `$this->cur` 会被清空，
     * 到点时已经知道不"是谁"了——这是脚本层最容易漏的一件事。
     */
    public function scheduleScriptEntry(int $ms, string $entry): void
    {
        $state = $this->cur;
        if ($state === null) {
            Logger::warn("脚本申请定时器时没有当前状态，已忽略", ["entry" => $entry]);
            return;
        }
        $this->timerSeq = $this->timerSeq + 1;
        $tok = $this->timerSeq;
        $this->pending[$tok] = $state;
        $this->timeout($ms, Cmd::SVC_SCRIPT_TIMER, (string) $tok . "|" . $entry);
    }

    private function onScriptTimer(string $payload): void
    {
        $parts = explode("|", $payload);
        if (count($parts) !== 2) {
            return;
        }
        $tok = (int) $parts[0];
        $entry = $parts[1];
        $state = $this->pending[$tok] ?? null;
        if ($state === null) {
            return;
        }
        unset($this->pending[$tok]);

        $act = $this->mgr->get("activity");
        if (!$act instanceof Script) {
            return;
        }
        $this->cur = $state;
        $ctx = $this->makeCtx($state);
        $act->callWithContext($entry, $ctx, 500);
        $this->cur = null;
    }

    /** 每次调用都 new Context（便宜），能力闭包共享（onStart 已建） */
    private function makeCtx(RaidState $state): Context
    {
        $ctx = new Context($state, ["uid", "level", "kills", "baseGold"], []);
        foreach ($this->caps as $name => $fn) {
            $ctx->bind((string) $name, $fn);
        }
        return $ctx;
    }

    /**
     * reward 能力的落地：脚本"申请"发钱，这里决定给不给。
     *
     * 上限 10000 是示例，真实项目还要加：日额度、单玩家每活动总额、
     * 幂等键（同一业务单号只发一次）。**这些都必须在这里，
     * 不能写进脚本**——脚本会被改，风控不能跟着改。
     */
    public function award(int $amount, string $reason): bool
    {
        if ($amount <= 0) {
            return false;
        }
        if ($amount > 10000) {
            $this->rewardsRejected = $this->rewardsRejected + 1;
            Logger::warn("脚本申请额度超限，已拒绝", ["amount" => $amount, "reason" => $reason]);
            return false;
        }
        $this->wallet->addGold($amount, "script:" . $reason);
        return true;
    }

    public function audit(string $msg): void
    {
        Logger::info("script audit", ["msg" => $msg]);
    }

    // ---- GM 命令的落地（main.php 里的 Registry 转发到这里）----

    /** @return array<string, mixed> */
    public function gmReload(string $name): array
    {
        if ($name === "") {
            $done = $this->mgr->reloadAll();
            return ["ok" => true, "names" => $done];
        }
        if (!$this->mgr->get($name) instanceof Script) {
            return ["ok" => false, "msg" => "没有这个脚本: " . $name];
        }
        if (!$this->mgr->reload($name)) {
            return ["ok" => false, "msg" => "重载失败（语法错？看 var/log/app.log）: " . $name];
        }
        return ["ok" => true, "names" => [$name]];
    }

    /** @return array<string, mixed> */
    public function gmGray(string $name, int $pct): array
    {
        if (!$this->mgr->get($name) instanceof Script) {
            return ["ok" => false, "msg" => "没有这个脚本: " . $name];
        }
        $this->mgr->setGray($name, $pct);
        $this->grayPct[$name] = $pct;
        return ["ok" => true, "gray" => $pct];
    }

    public function gmStatus(): string
    {
        $gray = "";
        foreach ($this->grayPct as $k => $v) {
            if ($gray !== "") {
                $gray = $gray . " ";
            }
            $gray = $gray . $k . "=" . $v . "%";
        }
        if ($gray === "") {
            $gray = "-";
        }
        return "scripts=" . implode(",", $this->mgr->names())
            . " gray=" . $gray
            . " requests=" . $this->requests
            . " drops=" . $this->dropTotal
            . " gold=" . $this->goldTotal
            . " base_gold=" . $this->wallet->gold()
            . " script_errors=" . $this->scriptErrors
            . " last_error=" . ($this->lastScriptError === "" ? "-" : $this->lastScriptError)
            . " reward_rejected=" . $this->rewardsRejected
            . " reasons=" . $this->wallet->reasons();
    }

    /**
     * 接上集群：把 Broadcast 的发送端接到 Cluster。
     *
     * 为什么发送端是闭包而不是让 Broadcast 直接依赖 Cluster：
     * `pkg/script` 是零依赖包（单机部署也会装它），一旦 import Cluster 就绑死了。
     * 这里由装配层注入，测试里可以换成内存队列。
     */
    public function enableCluster(Cluster $c): void
    {
        $this->cluster = $c;
        $nodeId = $c->nodeId();
        $this->bc = new Broadcast($this->mgr, $nodeId, function (int $node, int $msgId, string $payload) use ($c): void {
            // Addr::pack 而不是裸节点号：Cluster::send 的 dst 是完整服务地址，
            // 高位取节点、低位是服务 id（这里用 0 = 系统）
            $c->send(Addr::pack($node, 0), 0, $msgId, $payload);
        });
        Logger::info("script broadcast enabled", ["node" => $nodeId]);
    }

    /** 收到集群包：交给 Broadcast 解析。返回非空 = 要回 ACK */
    public function onClusterMessage(int $msgId, string $payload): string
    {
        $bc = $this->bc;
        if (!$bc instanceof Broadcast) {
            return "";
        }
        return $bc->onMessage($msgId, $payload);
    }

    /**
     * 广播重载：本机先重载，再把 RELOAD 发给所有已连上的节点。
     *
     * 返回 reqId 而不是"结果"：广播不等待 ack，单线程里同步等会卡住整个 worker。
     * 结果用 `gmClusterStatus($reqId)` 查。
     *
     * @return array<string, mixed>
     */
    public function gmReloadCluster(string $name): array
    {
        $bc = $this->bc;
        if (!$bc instanceof Broadcast) {
            return ["ok" => false, "msg" => "未启用集群（启动时没设 RULE_HOT_NODE）"];
        }
        if (!$this->mgr->get($name) instanceof Script) {
            return ["ok" => false, "msg" => "没有这个脚本: " . $name];
        }

        // 本机先做：哪怕广播全失败，leader 自己也已经是新规则
        $localOk = $this->mgr->reload($name);

        $c = $this->cluster;
        $peers = [];
        if ($c instanceof Cluster) {
            $peers = $c->peerNodes();
        }
        if ($peers === []) {
            return ["ok" => true, "reqId" => "", "local" => $localOk ? 1 : 0, "peers" => 0];
        }
        $reqId = $bc->request($name, $peers);
        return [
            "ok" => true,
            "reqId" => $reqId,
            "local" => $localOk ? 1 : 0,
            "peers" => count($peers),
        ];
    }

    /**
     * 查广播结果。`done=0` 且 `missing>0` 表示"还有节点没回"（网络慢或掉线），
     * `failed>0` 表示"回了但对方重载失败"——这两种要分开，运维处置完全不同。
     */
    public function gmClusterStatus(string $reqId): string
    {
        $bc = $this->bc;
        if (!$bc instanceof Broadcast) {
            return "cluster=off";
        }
        if ($reqId === "") {
            return "reqId=required";
        }
        $r = $bc->report($reqId);

        $targets = 0;
        $t = $r["targets"];
        if (is_int($t)) {
            $targets = $t;
        }
        $missing = 0;
        $mi = $r["missing"];
        if (is_int($mi)) {
            $missing = $mi;
        }
        $done = false;
        $d = $r["done"];
        if (is_bool($d)) {
            $done = $d;
        }
        $acked = 0;
        $ak = $r["acked"];
        if (is_array($ak)) {
            $acked = count($ak);
        }
        $failed = 0;
        $fa = $r["failedNodes"];
        if (is_array($fa)) {
            $failed = count($fa);
        }

        return "req=" . $reqId
            . " targets=" . $targets
            . " acked=" . $acked
            . " failed=" . $failed
            . " missing=" . $missing
            . " done=" . ($done ? "1" : "0");
    }

    /**
     * 进程内对照：同一份输入，AOT 规则 vs 脚本规则各跑 $n 次。
     *
     * 为什么不做成"两种模式各压一遍 TCP"：端到端 QPS 被网络和限流主导
     * （单 worker 约 4 万 msg/s），3 µs 的规则差异淹没在噪声里。
     * 要判断"这条规则放脚本值不值"，必须把规则单独拎出来计时。
     *
     * 两边都 new RaidView（每次重置判定序列），保证测量的是编码差异而不是缓存。
     */
    public function gmBench(int $n, int $kills = 10): string
    {
        if ($n <= 0) {
            $n = 1000;
        }
        if ($n > 200000) {
            $n = 200000;
        }
        if ($kills <= 0) {
            $kills = 1;
        }

        // 预热：第一次调用有 opcache/分支预测的冷启动成本
        for ($i = 0; $i < 100; $i++) {
            LootAot::roll(new RaidView(1001, $kills, 5, 0));
        }

        // 三轮取最优：WSL2 上后台负载会让单轮结果波动 30%，
        // 最小值最接近"没有干扰时的成本"，也是判断容量的保守基准
        $aotNs = 0;
        for ($round = 0; $round < 3; $round++) {
            $t0 = hrtime(true);
            for ($i = 0; $i < $n; $i++) {
                LootAot::roll(new RaidView(1001, $kills, 5, 0));
            }
            $one = intdiv(hrtime(true) - $t0, $n);
            if ($aotNs === 0 || $one < $aotNs) {
                $aotNs = $one;
            }
        }

        $script = $this->mgr->pick("loot", 1001);
        if (!$script instanceof Script) {
            return "aot_ns=" . $aotNs . " script=- ratio=- （脚本未加载）";
        }
        for ($i = 0; $i < 100; $i++) {
            $script->call("roll", new RaidView(1001, $kills, 5, 0));
        }

        $scriptNs = 0;
        for ($round2 = 0; $round2 < 3; $round2++) {
            $t1 = hrtime(true);
            for ($i = 0; $i < $n; $i++) {
                $script->call("roll", new RaidView(1001, $kills, 5, 0));
            }
            $one2 = intdiv(hrtime(true) - $t1, $n);
            if ($scriptNs === 0 || $one2 < $scriptNs) {
                $scriptNs = $one2;
            }
        }

        // 用整数百分比表示倍数，避免 float → string 在 AOT 下的格式差异
        $x100 = 0;
        if ($aotNs > 0) {
            $x100 = intdiv($scriptNs * 100, $aotNs);
        }
        return "n=" . $n
            . " aot_ns=" . $aotNs
            . " script_ns=" . $scriptNs
            . " ratio=" . intdiv($x100, 100) . "." . ($x100 % 100) . "x"
            . " （3 轮取最优；每次 " . $kills . " 只怪的掉落判定）";
    }

    /** 供 GM 直接把"当前规则"和 AOT 版对齐验证（同 uid 同 kills 必须同结果） */
    public function gmCompare(int $uid, int $kills): string
    {
        $level = 1 + intdiv($uid % 100, 10);
        $a = LootAot::roll(new RaidView($uid, $kills, $level, 0));
        $script = $this->mgr->pick("loot", $uid);
        $b = "";
        if ($script instanceof Script) {
            $r = $script->call("roll", new RaidView($uid, $kills, $level, 0));
            if (is_string($r)) {
                $b = $r;
            }
        }
        return "aot=" . ($a === "" ? "-" : $a)
            . " script=" . ($b === "" ? "-" : $b)
            . " same=" . ($a === $b ? "yes" : "no");
    }
}
