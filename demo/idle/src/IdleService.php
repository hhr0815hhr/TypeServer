<?php

namespace Idle;

use Gen\Proto\CollectReq;
use Gen\Proto\CollectResp;
use Gen\Proto\ErrorMsg;
use Gen\Proto\LoginReq;
use Gen\Proto\LoginResp;
use Gen\Proto\MailClaimReq;
use Gen\Proto\MailClaimResp;
use Gen\Proto\MailInfo;
use Gen\Proto\MailListReq;
use Gen\Proto\MailListResp;
use Gen\Proto\Msg;
use Gen\Proto\RankItem;
use Gen\Proto\RankReq;
use Gen\Proto\RankResp;
use TSF\Db\Db;
use TSF\Db\Repo;
use TSF\Mail\MailStore;
use TSF\Service\Message;
use TSF\Service\Service;

/**
 * 挂机收菜。
 *
 * ## 三个核心设计
 *
 * **1. 不存"待领取"，用 lastTick 现算。** 存 pending 就要维护"什么时候加、什么时候清"，
 *    漏一次永久错位。现算则状态只有一个 lastTick，离线在线同一套公式。
 *
 * **2. 收取时 lastTick 推进 collected * secPerCoin，不是 = now。** 直接设 now 会把
 *    "不足一个结算周期的零头"吃掉，玩家挂一天少拿几百金币。
 *
 * **3. 断线不清内存。** 重连时复用内存里的 lastTick；即使进程重启内存丢了，
 *    从 db 读回 lastTick 也能算出同样的结果 —— 这就是"状态在 db、内存只是缓存"。
 */
final class IdleService extends Service
{
    /**
     * 回包通道。main.php 注入 `$gw->send(...)`。
     *
     * 为什么不用 `$this->send()`：`Service::send(int $to, ...)` 的 $to 是
     * **服务地址**（Scheduler 的寻址空间），而我们要发的是 fd（连接号）。
     * 两者数值上可能撞，语义完全不同 —— 直接传 fd 进去会投到错误的服务。
     * 注入闭包让 Service 不依赖 Gateway，测试时也能换成假的。
     */
    private \Closure $publish;

    public function __construct(\Closure $publish)
    {
        $this->publish = $publish;
    }

    /** @var array<int, IdlePlayer> uid => 玩家（内存状态） */
    private array $players = [];

    /** @var array<int, int> fd => uid */
    private array $uidOf = [];

    /** @var array<int, int> uid => fd（0 表示离线） */
    private array $fdOf = [];

    private ?Db $db = null;

    private ?MailStore $mail = null;

    /** @var \Redis|null */
    private $redis = null;

    private int $rate_per_minute = 10;

    private int $cap_seconds = 28800;

    private int $welcomeCoins = 100;

    /**
     * 注意：**不在这里建 db/redis 连接**。
     *
     * onStart 在 spawnOf 时执行，那时事件循环还没跑起来 —— 建立的连接从建立起
     * 就是阻塞 socket，之后在哪个上下文调用都一样卡住整个 worker（实测尖峰
     * 14/267 次）。所以连接延迟到第一次 onMessage（那时已在 Timer 回调里）。
     */
    public function onStart(): void
    {
        $this->rate_per_minute = (int) \TSF\Kernel\Config::getInt("idle.rate_per_minute", 10);
        $this->cap_seconds = (int) \TSF\Kernel\Config::getInt("idle.cap_seconds", 28800);
        $this->welcomeCoins = (int) \TSF\Kernel\Config::getInt("idle.welcome_coins", 100);
    }

    private function db(): ?Db
    {
        if ($this->db instanceof Db) {
            return $this->db;
        }
        $dsn = \TSF\Kernel\Config::getString("db.dsn", "");
        if ($dsn === "") {
            return null;
        }
        $this->db = new Db(
            $dsn,
            \TSF\Kernel\Config::getString("db.user", ""),
            \TSF\Kernel\Config::getString("db.pass", "")
        );
        return $this->db;
    }

    private function repo(): ?Repo
    {
        $db = $this->db();
        if ($db === null) {
            return null;
        }
        return new Repo(IdlePlayer::class, $db);
    }

    private function mailStore(): ?MailStore
    {
        $db = $this->db();
        if ($db === null) {
            return null;
        }
        if ($this->mail instanceof MailStore) {
            return $this->mail;
        }
        $this->mail = new MailStore($db, "mail");
        return $this->mail;
    }

    private function redis(): ?\Redis
    {
        if ($this->redis instanceof \Redis) {
            return $this->redis;
        }
        $r = new \Redis();
        try {
            $ok = $r->connect(
                \TSF\Kernel\Config::getString("redis.host", "127.0.0.1"),
                (int) \TSF\Kernel\Config::getInt("redis.port", 6379),
                (float) \TSF\Kernel\Config::getInt("redis.timeout", 3)
            );
            if (!$ok) {
                return null;
            }
            $auth = \TSF\Kernel\Config::getString("redis.auth", "");
            if ($auth !== "") {
                $r->auth($auth);
            }
        } catch (\Throwable $e) {
            return null;
        }
        $this->redis = $r;
        return $r;
    }

    /** 结算周期：多少秒产出 1 金币 */
    private function secPerCoin(): int
    {
        if ($this->rate_per_minute <= 0) {
            return 60;
        }
        $s = intdiv(60, $this->rate_per_minute);
        return $s > 0 ? $s : 1;
    }

    /**
     * 待收取金币。上限 capSeconds，超出部分不计（防止长期不登录一次领爆）。
     *
     * @return int
     */
    private function pendingOf(IdlePlayer $p, int $now): int
    {
        $elapsed = $now - $p->lastTick;
        if ($elapsed < 0) {
            $elapsed = 0;
        }
        if ($elapsed > $this->cap_seconds) {
            $elapsed = $this->cap_seconds;
        }
        return intdiv($elapsed, $this->secPerCoin());
    }

    // ---------------- 消息入口 ----------------

    public function onMessage(Message $m): void
    {
        // msgId=0 是 Gateway 转发过来的"断线"通知（用 session 带 fd）
        if ($m->msgId === 0) {
            $this->onDisconnect($m->session);
            return;
        }
        if ($m->msgId === Msg::LOGIN_REQ) {
            $this->onLogin($m);
            return;
        }
        if ($m->msgId === Msg::COLLECT_REQ) {
            $this->onCollect($m);
            return;
        }
        if ($m->msgId === Msg::RANK_REQ) {
            $this->onRank($m);
            return;
        }
        if ($m->msgId === Msg::MAIL_LIST_REQ) {
            $this->onMailList($m);
            return;
        }
        if ($m->msgId === Msg::MAIL_CLAIM_REQ) {
            $this->onMailClaim($m);
            return;
        }
        // 未知协议号：不崩，回错误
        $this->fail($m, 404, "unknown msgId " . $m->msgId);
    }

    /**
     * 回包。
     *
     * 用 `$in->session` 而不是 `$in->from`：from 是**服务地址**（Gateway 转发时
     * 填的是它自己的地址），session 才是我们用来传 fd 的字段 —— Gateway 侧
     * 是 `deliver($addr, $msgId, $data, $fd)` 这样投进来的。
     */
    private function reply(Message $in, int $msgId, object $body): void
    {
        $fd = $in->session;
        if ($fd <= 0) {
            return;
        }
        // 走注入的 publish（内部是 $gw->send），不是 $this->send —— 后者收的是服务地址
        ($this->publish)($fd, $msgId, $body);
    }

    private function fail(Message $in, int $code, string $text): void
    {
        $e = new ErrorMsg();
        $e->code = $code;
        $e->msg = $text;
        $this->reply($in, Msg::ERROR_MSG, $e);
    }

    // ---------------- 登录 ----------------

    private function onLogin(Message $m): void
    {
        $fd = $m->session;
        $data = $m->data;
        $name = "";
        if ($data instanceof LoginReq) {
            $name = $data->name;
        }
        if ($name === "") {
            $this->fail($m, 400, "name required");
            return;
        }

        $repo = $this->repo();
        if ($repo === null) {
            $this->fail($m, 500, "db not available");
            return;
        }

        // uid 由 name 派生的稳定值（demo 简化：真实项目应该走账号系统）
        $uid = $this->uidOfName($name);
        $now = time();

        $p = null;
        $created = false;
        if (isset($this->players[$uid])) {
            // 内存里有：断线重连，直接复用（lastTick 还是上次的 → 离线收益自然补上）
            $p = $this->players[$uid];
        } else {
            $loaded = $repo->find($uid);
            if ($loaded instanceof IdlePlayer) {
                $p = $loaded;
            }
        }

        if (!($p instanceof IdlePlayer)) {
            $p = new IdlePlayer();
            $p->uid = $uid;
            $p->name = $name;
            $p->coins = 0;
            $p->lastTick = $now;
            $p->createdAt = $now;
            $repo->insert($p);
            $created = true;
        }

        // 绑定连接
        $this->players[$uid] = $p;
        $this->uidOf[$fd] = $uid;
        $this->fdOf[$uid] = $fd;

        // 新角色送一封欢迎邮件（带附件），只发一次
        if ($created) {
            $ms = $this->mailStore();
            if ($ms !== null) {
                $ms->send(
                    $uid,
                    "欢迎",
                    "欢迎来到挂机农场，收下这份见面礼。",
                    [["id" => 1, "n" => $this->welcomeCoins]],
                    30
                );
            }
        }

        $resp = new LoginResp();
        $resp->uid = $uid;
        $resp->name = $p->name;
        $resp->coins = $p->coins;
        $resp->pending = $this->pendingOf($p, $now);
        $resp->rate_per_minute = $this->rate_per_minute;
        $resp->cap_seconds = $this->cap_seconds;
        $this->reply($m, Msg::LOGIN_RESP, $resp);

        // 顺便把当前金币同步到榜（新号也能上榜）
        $this->rankUpdate($p);
    }

    /** demo 用 name 派生的稳定 uid，省掉账号系统 */
    private function uidOfName(string $name): int
    {
        $n = crc32($name);
        if ($n < 0) {
            $n += 4294967296;
        }
        return (int) ($n % 900000000) + 100000000;
    }

    // ---------------- 收取 ----------------

    private function onCollect(Message $m): void
    {
        $uid = $this->uidOf[$m->session] ?? 0;
        if ($uid <= 0) {
            $this->fail($m, 401, "login first");
            return;
        }
        $p = $this->players[$uid] ?? null;
        if (!($p instanceof IdlePlayer)) {
            $this->fail($m, 401, "no player");
            return;
        }
        $repo = $this->repo();
        if ($repo === null) {
            $this->fail($m, 500, "db not available");
            return;
        }

        $now = time();
        $got = $this->pendingOf($p, $now);

        $resp = new CollectResp();
        if ($got <= 0) {
            $resp->collected = 0;
            $resp->coins = $p->coins;
            $resp->pending = 0;
            $this->reply($m, Msg::COLLECT_RESP, $resp);
            return;
        }

        $p->coins += $got;
        // 只推进已结算的部分，零头留到下次
        $p->lastTick += $got * $this->secPerCoin();

        try {
            $repo->update($p);
        } catch (\Throwable $e) {
            // 写失败就回滚内存（保持内存和 db 一致）
            $p->coins -= $got;
            $p->lastTick -= $got * $this->secPerCoin();
            $this->fail($m, 500, "persist failed");
            return;
        }

        $this->rankUpdate($p);

        $resp->collected = $got;
        $resp->coins = $p->coins;
        $resp->pending = $this->pendingOf($p, time());
        $this->reply($m, Msg::COLLECT_RESP, $resp);
    }

    // ---------------- 排行榜 ----------------

    private function rankKey(): string
    {
        return "idle:rank";
    }

    private function rankUpdate(IdlePlayer $p): void
    {
        $r = $this->redis();
        if ($r === null) {
            return;
        }
        try {
            $r->zAdd($this->rankKey(), (float) $p->coins, (string) $p->uid);
        } catch (\Throwable $e) {
            // 榜挂了不影响主流程
        }
    }

    private function onRank(Message $m): void
    {
        $top = 10;
        $data = $m->data;
        if ($data instanceof RankReq && $data->top > 0) {
            $top = $data->top;
        }

        $resp = new RankResp();
        $r = $this->redis();
        if ($r === null) {
            $this->reply($m, Msg::RANK_RESP, $resp);
            return;
        }

        try {
            // zRevRange 返回 [member, score, member, score, ...]
            $flat = $r->zRevRange($this->rankKey(), 0, $top - 1, true);
        } catch (\Throwable $e) {
            $this->reply($m, Msg::RANK_RESP, $resp);
            return;
        }
        if (!is_array($flat)) {
            $this->reply($m, Msg::RANK_RESP, $resp);
            return;
        }

        $items = [];
        foreach ($flat as $member => $score) {
            $item = new RankItem();
            $item->uid = (int) $member;
            // 名字从内存取；不在线的显示 uid
            $pp = $this->players[(int) $member] ?? null;
            $item->name = $pp instanceof IdlePlayer ? $pp->name : ("玩家" . (string) $member);
            $item->coins = (int) $score;
            $items[] = $item;
        }
        $resp->items = $items;
        $this->reply($m, Msg::RANK_RESP, $resp);
    }

    // ---------------- 邮件 ----------------

    private function onMailList(Message $m): void
    {
        $uid = $this->uidOf[$m->session] ?? 0;
        $resp = new MailListResp();
        if ($uid <= 0) {
            $this->reply($m, Msg::MAIL_LIST_RESP, $resp);
            return;
        }
        $ms = $this->mailStore();
        if ($ms === null) {
            $this->reply($m, Msg::MAIL_LIST_RESP, $resp);
            return;
        }

        $list = $ms->listOf($uid, 20);
        $out = [];
        foreach ($list as $one) {
            $info = new MailInfo();
            $info->id = $one->id;
            $info->title = $one->title;
            $info->read = $one->isRead();
            $info->claimed = $one->isClaimed();
            $info->has_items = $one->hasItems();
            $out[] = $info;
        }
        $resp->mails = $out;
        $this->reply($m, Msg::MAIL_LIST_RESP, $resp);
    }

    private function onMailClaim(Message $m): void
    {
        $uid = $this->uidOf[$m->session] ?? 0;
        $resp = new MailClaimResp();
        $resp->ok = false;

        $data = $m->data;
        $mailId = $data instanceof MailClaimReq ? $data->mail_id : 0;
        if ($uid <= 0 || $mailId <= 0) {
            $this->reply($m, Msg::MAIL_CLAIM_RESP, $resp);
            return;
        }

        $ms = $this->mailStore();
        if ($ms === null) {
            $this->reply($m, Msg::MAIL_CLAIM_RESP, $resp);
            return;
        }

        $got = $ms->claim($uid, $mailId);
        $resp->ok = true;

        // demo 的约定：附件 id=1 表示金币，直接加到角色上
        $p = $this->players[$uid] ?? null;
        foreach ($got as $one) {
            $itemId = (int) ($one["id"] ?? 0);
            $n = (int) ($one["n"] ?? 0);
            if ($itemId === 1 && $n > 0 && $p instanceof IdlePlayer) {
                $p->coins += $n;
                $repo = $this->repo();
                if ($repo !== null) {
                    try {
                        $repo->update($p);
                        $this->rankUpdate($p);
                    } catch (\Throwable $e) {
                        $p->coins -= $n;
                    }
                }
            }
        }

        $items = [];
        foreach ($got as $one) {
            $it = new \Gen\Proto\Item();
            $it->id = (int) ($one["id"] ?? 0);
            $it->n = (int) ($one["n"] ?? 0);
            $items[] = $it;
        }
        $resp->items = $items;
        $resp->coins = $p instanceof IdlePlayer ? $p->coins : 0;
        $this->reply($m, Msg::MAIL_CLAIM_RESP, $resp);
    }

    // ---------------- 连接生命周期 ----------------

    /** 断线：只解绑 fd，**内存状态保留**（重连时复用，离线收益才补得对） */
    public function onDisconnect(int $fd): void
    {
        $uid = $this->uidOf[$fd] ?? 0;
        if ($uid <= 0) {
            return;
        }
        unset($this->uidOf[$fd]);
        if (($this->fdOf[$uid] ?? 0) === $fd) {
            $this->fdOf[$uid] = 0;
        }
    }

    /** 服务退出：把内存里的状态写回 db */
    public function onStop(): void
    {
        $repo = $this->repo();
        if ($repo === null) {
            return;
        }
        foreach ($this->players as $p) {
            try {
                $repo->update($p);
            } catch (\Throwable $e) {
                // 退出时尽力而为
            }
        }
    }
}
