<?php

/**
 * RPC 单进程测试：验证语义，不测跨进程延迟（那在 rpc-crash/cluster 里）。
 *
 * 这里要钉死的几件事：
 *   1. 回调**一定会被调一次**（成功、远端报错、超时，三选一）
 *   2. 处理函数抛异常不能打穿服务，必须变成错误响应
 *   3. 超时之后才到的响应**不能再回调**（否则调用方会处理两遍）
 *   4. 多个在飞请求的响应不能串
 */

use TSF\Rpc\Client;
use TSF\Rpc\Codec\JsonCodec;
use TSF\Rpc\Codec\RawCodec;
use TSF\Rpc\Contract\RpcCodec;
use TSF\Rpc\Request;
use TSF\Rpc\Response;
use TSF\Rpc\Router;
use TSF\Rpc\Rpc;
use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

final class T
{
    public static int $pass = 0;

    public static int $fail = 0;

    public static function ok(bool $cond, string $label, string $detail = ""): void
    {
        if ($cond) {
            self::$pass++;
            echo "  OK   " . $label . PHP_EOL;
        } else {
            self::$fail++;
            echo "  FAIL " . $label . ($detail === "" ? "" : "  → " . $detail) . PHP_EOL;
        }
    }

    public static function eq(int|string|float|bool $got, int|string|float|bool $want, string $label): void
    {
        self::ok($got === $want, $label, "got=" . var_export($got, true) . " want=" . var_export($want, true));
    }
}

/**
 * 模拟"旧二进制"的对端：它不认识版本化载荷，看到 `V1\x1f` 就回 bad-request。
 *
 * 这正是降级协商要处理的情形——新节点先按新格式发，收到 bad-request 后
 * 标记并改用旧格式重发，一次往返就自愈。
 */
final class LegacyService extends Service
{
    private Router $rpc;

    private JsonCodec $codec;

    public int $calls = 0;

    public function __construct()
    {
        $this->codec = new JsonCodec();
        $this->rpc = new Router($this->codec);
        $this->rpc->on("echo", function (string $b, Message $m): string {
            $this->calls++;
            return "legacy:" . $b;
        });
    }

    public function onMessage(Message $m): void
    {
        $raw = is_string($m->data) ? $m->data : "";
        if (strpos($raw, "V1" . "\x1f") === 0) {
            Scheduler::instance()->deliver(
                $m->from,
                Rpc::MSG_RESP,
                $this->codec->packResponse(false, "bad-request"),
                $m->session
            );
            return;
        }
        $this->rpc->handle($m);
    }
}

/**
 * 模拟"升级完成"的对端：第一次收到版本化请求时还读不懂（回 bad-request），
 * 之后就能正常处理。用来验证客户端的 `legacy` 标记会过期并自动恢复。
 */
final class UpgradeService extends Service
{
    private Router $rpc;

    private JsonCodec $codec;

    private bool $upgraded = false;

    public function __construct()
    {
        $this->codec = new JsonCodec();
        $this->rpc = new Router($this->codec, 8);
        $this->rpc->on("echo", function (string $b, Message $m): string {
            return "new:" . $b;
        });
    }

    public function onMessage(Message $m): void
    {
        $raw = is_string($m->data) ? $m->data : "";
        if (!$this->upgraded && strpos($raw, "V1" . "\x1f") === 0) {
            $this->upgraded = true;
            Scheduler::instance()->deliver(
                $m->from,
                Rpc::MSG_RESP,
                $this->codec->packResponse(false, "bad-request"),
                $m->session
            );
            return;
        }
        $this->rpc->handle($m);
    }
}

/** 提供几个方法，覆盖正常/异常/空返回 */
final class EchoService extends Service
{
    private Router $rpc;

    public int $calls = 0;

    public function __construct(?RpcCodec $codec = null, int $protoVersion = 1)
    {
        $this->rpc = new Router($codec, $protoVersion);
        $this->rpc->on("echo", function (string $b, Message $m): string {
            $this->calls++;
            return "echo:" . $b;
        });
        $this->rpc->on("boom", function (string $b, Message $m): string {
            throw new \RuntimeException("handler blew up");
        });
        $this->rpc->on("empty", function (string $b, Message $m): string {
            return "";
        });
        $this->rpc->on("num", function (string $b, Message $m): string {
            return "42";
        });
    }

    public function router(): Router
    {
        return $this->rpc;
    }

    public function onMessage(Message $m): void
    {
        $this->rpc->handle($m);
    }
}

/** 收消息但永不回复：用来造超时 */
final class Blackhole extends Service
{
    public int $got = 0;

    public function onMessage(Message $m): void
    {
        $this->got++;
    }
}

/**
 * 收下请求但**不当场回复**，60ms 后才回：用来造"超时之后才到的响应"。
 *
 * 刻意不走 Router——Router 处理完就立刻回包，那样调用方 20ms 内就拿到成功了，
 * 根本造不出"迟到"的场景。
 */
final class SlowService extends Service
{
    public int $lastFrom = 0;

    public int $lastSession = 0;

    public function onMessage(Message $m): void
    {
        if ($m->msgId === 1) {
            // 定时器到点：补发那条迟到的响应。
            // 注意要用 codec 编，不能用已经删掉的两个响应协议号——
            // 成功/失败现在是载荷里的事
            if ($this->lastFrom !== 0) {
                $codec = new JsonCodec();
                Scheduler::instance()->deliver(
                    $this->lastFrom,
                    Rpc::MSG_RESP,
                    $codec->packResponse(true, "late"),
                    $this->lastSession
                );
            }
            return;
        }
        if ($m->msgId !== Rpc::MSG_REQ) {
            return;
        }
        // 只记下回程地址，不回复
        $this->lastFrom = $m->from;
        $this->lastSession = $m->session;
        $this->timeout(60, 1);
    }
}

function boot(?RpcCodec $codec = null): array
{
    Scheduler::reset();
    $s = Scheduler::instance();
    $echo = $s->spawnOf(function () use ($codec): EchoService {
        return new EchoService($codec);
    });
    $rpcAddr = $s->spawnOf(function () use ($codec): Client {
        return new Client(100, $codec);
    });
    $client = $s->serviceOf($rpcAddr);
    if (!($client instanceof Client)) {
        throw new \RuntimeException("client 没起来");
    }
    return [$s, $echo, $client];
}

function drain(Scheduler $s, int $times = 3): void
{
    for ($i = 0; $i < $times; $i++) {
        $s->tick();
    }
}

/** 1. 正常调用 */
function caseOk(): void
{
    echo "== [1] 正常调用 ==" . PHP_EOL;

    [$s, $echo, $client] = boot();

    $got = "";
    $err = "";
    $client->call($echo, "echo", "hi", function (?string $r, string $e) use (&$got, &$err): void {
        $got = $r === null ? "" : $r;
        $err = $e;
    });
    drain($s);

    T::eq($err, "", "没有错误");
    T::eq($got, "echo:hi", "拿到返回体");

    $svc = $s->serviceOf($echo);
    if ($svc instanceof EchoService) {
        T::eq($svc->calls, 1, "服务端确实执行了一次");
    }

    // 空返回也是成功
    $got2 = "x";
    $err2 = "x";
    $client->call($echo, "empty", "", function (?string $r, string $e) use (&$got2, &$err2): void {
        $got2 = $r === null ? "<null>" : $r;
        $err2 = $e;
    });
    drain($s);
    T::eq($err2, "", "空返回算成功");
    T::eq($got2, "", "返回体为空串");
}

/** 2. 未知方法 / 处理函数抛异常 */
function caseFailures(): void
{
    echo "== [2] 失败路径 ==" . PHP_EOL;

    [$s, $echo, $client] = boot();

    $err1 = "";
    $client->call($echo, "no-such-method", "", function (?string $r, string $e) use (&$err1): void {
        $err1 = $e;
    });
    drain($s);
    T::ok(str_contains($err1, "no-method"), "未知方法回错误", $err1);

    $err2 = "";
    $resp2 = "x";
    $client->call($echo, "boom", "", function (?string $r, string $e) use (&$err2, &$resp2): void {
        $err2 = $e;
        $resp2 = $r === null ? "<null>" : $r;
    });
    drain($s);
    T::ok(str_contains($err2, "handler-error"), "处理函数抛异常变成错误响应", $err2);
    T::eq($resp2, "<null>", "失败时 resp 为 null");

    // 服务本身还活着，能继续服务
    $ok = "";
    $client->call($echo, "echo", "after", function (?string $r, string $e) use (&$ok): void {
        $ok = $e === "" && $r !== null ? $r : "ERR";
    });
    drain($s);
    T::eq($ok, "echo:after", "异常之后服务仍可用");
}

/** 3. 超时 */
function caseTimeout(): void
{
    echo "== [3] 超时 ==" . PHP_EOL;

    Scheduler::reset();
    $s = Scheduler::instance();
    $hole = $s->spawnOf(function (): Blackhole {
        return new Blackhole();
    });
    $rpcAddr = $s->spawnOf(function (): Client {
        return new Client(50);
    });
    $client = $s->serviceOf($rpcAddr);
    if (!($client instanceof Client)) {
        return;
    }

    $err = "";
    $called = 0;
    $client->call($hole, "anything", "", function (?string $r, string $e) use (&$err, &$called): void {
        $err = $e;
        $called++;
    }, 20);

    drain($s);
    T::eq($called, 0, "还不到 20ms，回调没被调");

    usleep(30000);   // 30ms，超过 20ms 超时
    drain($s, 2);

    T::eq($called, 1, "超时后回调被调用一次");
    T::eq($err, "timeout", "错误原因是 timeout");
    T::eq($client->pendingCount(), 0, "待发表已清空");

    $st = $client->stats();
    T::eq((int) $st["timeout"], 1, "超时计数 1");
}

/** 4. 超时之后才到的响应必须被丢弃 */
function caseLateResponse(): void
{
    echo "== [4] 迟到的响应 ==" . PHP_EOL;

    Scheduler::reset();
    $s = Scheduler::instance();
    $slow = $s->spawnOf(function (): SlowService {
        return new SlowService();
    });
    $rpcAddr = $s->spawnOf(function (): Client {
        return new Client(50);
    });
    $client = $s->serviceOf($rpcAddr);
    if (!($client instanceof Client)) {
        return;
    }

    $called = 0;
    $err = "";
    $client->call($slow, "slow", "", function (?string $r, string $e) use (&$called, &$err): void {
        $called++;
        $err = $e;
    }, 20);

    drain($s);
    usleep(35000);
    drain($s, 2);
    T::eq($called, 1, "先收到超时");
    T::eq($err, "timeout", "原因是 timeout");

    // 服务端的 60ms 延迟回复此时还没到；等到它到，也不能再回调
    usleep(40000);
    drain($s, 3);
    T::eq($called, 1, "迟到响应没有触发第二次回调");

    $st = $client->stats();
    T::eq((int) $st["late"], 1, "late 计数为 1");
}

/** 5. 多个在飞请求不串 */
function caseConcurrent(): void
{
    echo "== [5] 多个在飞请求 ==" . PHP_EOL;

    [$s, $echo, $client] = boot();

    $got = [];
    for ($i = 1; $i <= 20; $i++) {
        $client->call($echo, "echo", "n" . $i, function (?string $r, string $e) use (&$got, $i): void {
            $got[$i] = $e === "" && $r !== null ? $r : "ERR:" . $e;
        });
    }
    drain($s, 4);

    T::eq(count($got), 20, "20 个回调都被调用");
    $wrong = 0;
    for ($i = 1; $i <= 20; $i++) {
        if (!isset($got[$i]) || $got[$i] !== "echo:n" . $i) {
            $wrong++;
        }
    }
    T::eq($wrong, 0, "每个回调拿到的是自己的响应（没有串号）");
    T::eq($client->pendingCount(), 0, "待发表清空");
}

/** 6. 参数校验 */
function caseGuards(): void
{
    echo "== [6] 参数校验 ==" . PHP_EOL;

    [$s, $echo, $client] = boot();

    $err = "";
    $id = $client->call(0, "echo", "", function (?string $r, string $e) use (&$err): void {
        $err = $e;
    });
    T::eq($id, 0, "地址为 0 时返回 0");
    T::eq($err, "bad-addr", "错误原因是 bad-addr");

    T::eq($client->pendingCount(), 0, "非法调用不进待发表");

    // 服务端能看到方法列表
    $svc = $s->serviceOf($echo);
    if ($svc instanceof EchoService) {
        $methods = $svc->router()->methods();
        T::eq(count($methods), 4, "注册了 4 个方法");
        T::ok(in_array("echo", $methods, true), "包含 echo");
        T::ok($svc->router()->has("boom"), "has() 可用");
        T::ok(!$svc->router()->has("nope"), "不存在的返回 false");
    }
}

/**
 * 一个故意奇怪的协议：方法名倒着写、用 `|` 定界、响应首字节 Y/N。
 *
 * 存在的唯一目的是证明 `RpcCodec` 真的可插——如果框架里还残留着
 * "响应一定是 JSON" 的假设，这个 case 会立刻挂。
 */
final class ReversedCodec implements RpcCodec
{
    public function packRequest(string $method, string $body): string
    {
        return strrev($method) . "|" . $body;
    }

    public function unpackRequest(string $raw): ?Request
    {
        $pos = strpos($raw, "|");
        if ($pos === false) {
            return null;
        }
        $m = strrev(substr($raw, 0, $pos));
        if ($m === "") {
            return null;
        }
        return new Request($m, substr($raw, $pos + 1));
    }

    public function packResponse(bool $ok, string $body): string
    {
        return ($ok ? "Y" : "N") . $body;
    }

    public function unpackResponse(string $raw): ?Response
    {
        if ($raw === "") {
            return null;
        }
        $f = $raw[0];
        if ($f !== "Y" && $f !== "N") {
            return null;
        }
        return new Response($f === "Y", substr($raw, 1));
    }

    public function name(): string
    {
        return "reversed";
    }
}

/** 7. 换成 RawCodec：不解析 body，只贴方法名 */
function caseRawCodec(): void
{
    echo "== [7] 换协议：RawCodec ==" . PHP_EOL;

    $raw = new RawCodec();
    [$s, $echo, $client] = boot($raw);

    T::eq($client->codec()->name(), "raw", "客户端用 raw");

    $svc = $s->serviceOf($echo);
    if ($svc instanceof EchoService) {
        T::eq($svc->router()->codec()->name(), "raw", "服务端也用 raw");
    }

    $got = "";
    $err = "";
    $client->call($echo, "echo", "hello", function (?string $r, string $e) use (&$got, &$err): void {
        $got = $r === null ? "<null>" : $r;
        $err = $e;
    });
    drain($s);
    T::eq($err, "", "raw 协议调用无错误");
    T::eq($got, "echo:hello", "raw 协议返回正确");

    // 错误路径也走 codec
    $err2 = "";
    $client->call($echo, "boom", "", function (?string $r, string $e) use (&$err2): void {
        $err2 = $e;
    });
    drain($s);
    T::ok(str_contains($err2, "handler-error"), "raw 协议下错误也能传回", $err2);

    // body 里带换行和特殊字符也不该出问题（raw 只切第一个换行）
    $got3 = "";
    $client->call($echo, "echo", "a\nb|c", function (?string $r, string $e) use (&$got3): void {
        $got3 = $e === "" && $r !== null ? $r : "ERR:" . $e;
    });
    drain($s);
    T::eq($got3, "echo:a\nb|c", "body 含换行与竖线仍正确");
}

/** 8. 一个完全自定义的协议 */
function caseCustomCodec(): void
{
    echo "== [8] 自定义协议 ==" . PHP_EOL;

    $weird = new ReversedCodec();
    [$s, $echo, $client] = boot($weird);

    T::eq($client->codec()->name(), "reversed", "客户端用自定义协议");

    $got = "";
    $err = "";
    $client->call($echo, "echo", "x", function (?string $r, string $e) use (&$got, &$err): void {
        $got = $r === null ? "<null>" : $r;
        $err = $e;
    });
    drain($s);
    T::eq($err, "", "自定义协议无错误");
    T::eq($got, "echo:x", "自定义协议返回正确");

    $err2 = "";
    $client->call($echo, "boom", "", function (?string $r, string $e) use (&$err2): void {
        $err2 = $e;
    });
    drain($s);
    T::ok(str_contains($err2, "handler-error"), "自定义协议的错误语义也能自定义", $err2);
}

/** 9. 两端协议不一致：必须快速失败，不能等超时 */
function caseMismatch(): void
{
    echo "== [9] 协议不匹配 ==" . PHP_EOL;

    Scheduler::reset();
    $s = Scheduler::instance();
    // 服务端说 JSON，客户端说 RAW
    $echo = $s->spawnOf(function (): EchoService {
        return new EchoService(new JsonCodec());
    });
    $rpcAddr = $s->spawnOf(function (): Client {
        return new Client(500, new RawCodec());
    });
    $client = $s->serviceOf($rpcAddr);
    if (!($client instanceof Client)) {
        return;
    }

    $err = "";
    $t0 = hrtime(true);
    $client->call($echo, "echo", "x", function (?string $r, string $e) use (&$err): void {
        $err = $e;
    });
    drain($s, 2);
    $ms = (hrtime(true) - $t0) / 1e6;

    T::ok($err !== "", "收到错误而不是一直等", $err);
    // 客户端报的是 bad-response 而不是 bad-request：它发出去的是 raw 格式，
    // 服务端解不出来回了 bad-request，但那条响应又是 JSON 格式——
    // 客户端按 raw 解同样解不出来。**两端协议不一致时，任何一端的诊断都只能是
    // "我解不出来"**，指望它指出"对方协议不对"是不可能的。
    // 关键是没有挂在那里等超时，而是立刻结束了这次调用。
    T::ok(str_contains($err, "bad-response") || str_contains($err, "bad-request"), "错误与格式有关", $err);
    T::ok($ms < 100, "快速失败（< 100ms，没有等到超时）", number_format($ms, 1) . " ms");
}


function caseVersion(): void
{
    echo "== [10] 载荷版本协商 ==" . PHP_EOL;

    // 新客户端 → 新服务端（服务端版本更高）：正常调用，并学到对端版本
    Scheduler::reset();
    $s = Scheduler::instance();
    $echo = $s->spawnOf(function (): EchoService {
        return new EchoService(null, 8);
    });
    $caddr = $s->spawnOf(function (): Client {
        return new Client(100, null, 1);
    });
    $client = $s->serviceOf($caddr);
    if (!($client instanceof Client)) {
        throw new \RuntimeException("client 没起来");
    }

    $got = "";
    $err = "x";
    $client->call($echo, "echo", "v", function (?string $r, string $e) use (&$got, &$err): void {
        $got = $r === null ? "" : $r;
        $err = $e;
    });
    drain($s);
    T::eq($err, "", "版本化请求正常返回");
    T::eq($got, "echo:v", "返回体正确");
    T::eq($client->peerVersion(($echo >> 24) & 0xFF), 8, "学到了对端载荷版本");

    // 客户端比服务端新：服务端应回明确的 version-mismatch，而不是 bad-request 或超时
    Scheduler::reset();
    $s2 = Scheduler::instance();
    $old = $s2->spawnOf(function (): EchoService {
        return new EchoService(null, 1);
    });
    $c2addr = $s2->spawnOf(function (): Client {
        return new Client(100, null, 9);
    });
    $c2 = $s2->serviceOf($c2addr);
    if (!($c2 instanceof Client)) {
        throw new \RuntimeException("client 没起来");
    }
    $err2 = "";
    $c2->call($old, "echo", "x", function (?string $r, string $e) use (&$err2): void {
        $err2 = $e;
    });
    drain($s2);
    T::eq($err2, Rpc::ERR_VERSION, "服务端版本更旧时回 version-mismatch");
    $osvc = $s2->serviceOf($old);
    if ($osvc instanceof EchoService) {
        T::eq($osvc->router()->stats()["version_rejected"], 1, "服务端记到一次版本拒绝");
        T::eq($osvc->calls, 0, "handler 没有被执行");
    }

    // 新客户端 → 旧二进制：先吃一个 bad-request，自动降级重发并成功
    Scheduler::reset();
    $s3 = Scheduler::instance();
    $legacy = $s3->spawnOf(function (): LegacyService {
        return new LegacyService();
    });
    $c3addr = $s3->spawnOf(function (): Client {
        return new Client(200, null, 3);
    });
    $c3 = $s3->serviceOf($c3addr);
    if (!($c3 instanceof Client)) {
        throw new \RuntimeException("client 没起来");
    }
    $got3 = "";
    $err3 = "x";
    $c3->call($legacy, "echo", "y", function (?string $r, string $e) use (&$got3, &$err3): void {
        $got3 = $r === null ? "" : $r;
        $err3 = $e;
    });
    drain($s3);
    T::eq($err3, "", "降级重发后调用成功");
    T::eq($got3, "legacy:y", "拿到旧对端的返回");
    T::ok($c3->isLegacy(($legacy >> 24) & 0xFF), "对端被标记为旧二进制");

    // 降级不能是永久的：对方升级之后要能自己恢复
    Scheduler::reset();
    $s4 = Scheduler::instance();
    $up = $s4->spawnOf(function (): UpgradeService {
        return new UpgradeService();
    });
    $c4addr = $s4->spawnOf(function (): Client {
        return new Client(200, null, 3);
    });
    $c4 = $s4->serviceOf($c4addr);
    if (!($c4 instanceof Client)) {
        throw new \RuntimeException("client 没起来");
    }
    // 标记的有效期给 200ms，而不是 1ms：要在标记生效期间断言 `isLegacy`，1ms 的预算在
    // 一次调度抖动（drain 多跑几圈、机器被别的进程抢走 1ms）下就已经过期。
    // 2026-11-27 全量回归里这条红过一次（单独重跑总是绿）。
    $c4->setLegacyTtl(200);

    $got4 = "";
    $c4->call($up, "echo", "z", function (?string $r, string $e) use (&$got4): void {
        $got4 = $r === null ? "" : $r;
    });
    drain($s4);
    $node = ($up >> 24) & 0xFF;
    T::eq($got4, "new:z", "首次靠降级成功");
    T::ok($c4->isLegacy($node), "此时被标记为旧二进制");

    // 等它过期：轮询到标记消失为止，上限 2 秒。行为是「标记会自动失效」，不是「正好 200ms 后失效」。
    $wait0 = hrtime(true);
    while ($c4->isLegacy($node) && (hrtime(true) - $wait0) / 1e6 < 2000) {
        usleep(20000);
    }
    T::ok(!$c4->isLegacy($node), "标记过期后自动失效");

    $got5 = "";
    $c4->call($up, "echo", "w", function (?string $r, string $e) use (&$got5): void {
        $got5 = $r === null ? "" : $r;
    });
    drain($s4);
    T::eq($got5, "new:w", "重新试探成功");
    T::eq($c4->peerVersion($node), 8, "学到了升级后的版本");
    T::ok(!$c4->isLegacy($node), "不再标记为旧二进制");
}

function main(): void
{
    caseOk();
    caseFailures();
    caseTimeout();
    caseLateResponse();
    caseConcurrent();
    caseGuards();
    caseRawCodec();
    caseCustomCodec();
    caseMismatch();
    caseVersion();

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
}
