<?php

use TSF\Cluster\Cluster;
use TSF\Cluster\Packet;
use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

const MSG_PING = 2001;

const MSG_PONG = 2002;

/** 服务端：收到什么就原样回给发送方 */
final class EchoService extends Service
{
    public function onMessage(Message $m): void
    {
        Scheduler::instance()->deliver($m->from, MSG_PONG, $m->data, $m->session);
    }
}

/** 客户端：发一条、等回包、记 RTT、再发下一条（串行，避免把自己压死） */
final class Pinger extends Service
{
    /** @var array<int, int> 每条消息的往返耗时（纳秒） */
    public array $rtt = [];

    public int $target = 0;

    public int $limit = 0;

    public int $sent = 0;

    public function onStart(): void
    {
        $this->kick();
    }

    public function onMessage(Message $m): void
    {
        if ($m->msgId !== MSG_PONG) {
            return;
        }
        $t0 = 0;
        if (is_string($m->data)) {
            $t0 = (int) $m->data;
        }
        $this->rtt[] = hrtime(true) - $t0;
        $this->kick();
    }

    private function kick(): void
    {
        if ($this->sent >= $this->limit) {
            return;
        }
        $this->sent++;
        // 用 Service::send 而不是 Scheduler::deliver：start() 是在服务之外调用的，
        // 那时调度器不知道"当前是哪个服务"，from 会变成 0（系统）。
        $this->send($this->target, MSG_PING, (string) hrtime(true), $this->sent);
    }

    /** 外部配置好 target/limit 之后手动发第一条（onStart 时还不知道这些） */
    public function start(): void
    {
        $this->kick();
    }
}

function pct(array $sorted, int $num, int $den): int
{
    $n = count($sorted);
    if ($n === 0) {
        return 0;
    }
    $i = intdiv($n * $num, $den);
    if ($i >= $n) {
        $i = $n - 1;
    }
    return $sorted[$i];
}

function runServer(int $seconds): void
{
    $c = new Cluster(1, "/tmp/tsf_node1.sock");
    $c->listen();

    $s = Scheduler::instance();
    $s->setCluster($c);
    $s->register("echo", function () {
        return new EchoService();
    });
    $s->spawn("echo");

    $deadline = Scheduler::nowMs() + $seconds * 1000;
    while (Scheduler::nowMs() < $deadline) {
        $s->tick();
        usleep(10);
    }

    $st = $s->stats();
    $cs = $c->stats();
    echo "server: handled=" . $st["handled"]
        . " out=" . $cs["out"] . " in=" . $cs["in"]
        . " drop=" . $cs["drop"] . " undelivered=" . $st["undelivered"]
        . " errors=" . $st["errors"]
        . " peers=[" . implode(",", $c->peerNodes()) . "]" . PHP_EOL;
    $c->closeAll();
}

function runClient(int $count): void
{
    $c = new Cluster(2, "/tmp/tsf_node2.sock");
    if (!$c->connect(1, "/tmp/tsf_node1.sock")) {
        echo "连接失败" . PHP_EOL;
        exit(1);
    }

    $s = Scheduler::instance();
    $s->setCluster($c);
    $s->register("pinger", function () {
        return new Pinger();
    });
    $addr = $s->spawn("pinger");
    $p = $s->serviceOf($addr);
    if (!($p instanceof Pinger)) {
        echo "spawn 失败" . PHP_EOL;
        exit(1);
    }
    // 1 号节点上的 echo 服务：节点 1 + 服务 id 1
    $p->target = (1 << 24) | 1;
    $p->limit = $count;
    $p->start();

    $deadline = Scheduler::nowMs() + 30000;
    while (count($p->rtt) < $count && Scheduler::nowMs() < $deadline) {
        $s->tick();
        usleep(10);
    }

    $rtt = $p->rtt;
    sort($rtt);
    $n = count($rtt);
    echo "== 跨进程往返（UDS，node2 → node1）==" . PHP_EOL;
    echo "  完成          " . $n . " / " . $count . PHP_EOL;
    if ($n > 0) {
        echo "  p50           " . number_format(pct($rtt, 50, 100) / 1000, 1) . " µs" . PHP_EOL;
        echo "  p99           " . number_format(pct($rtt, 99, 100) / 1000, 1) . " µs" . PHP_EOL;
        echo "  p999          " . number_format(pct($rtt, 999, 1000) / 1000, 1) . " µs" . PHP_EOL;
        echo "  max           " . number_format($rtt[$n - 1] / 1000, 1) . " µs" . PHP_EOL;
        echo "  达标(p99≤500µs) " . (pct($rtt, 99, 100) <= 500000 ? "是" : "否") . PHP_EOL;
    }
    $cs = $c->stats();
    echo "  cluster       out=" . $cs["out"] . " in=" . $cs["in"] . " drop=" . $cs["drop"] . PHP_EOL;
    $c->closeAll();
}

/** 单元级：线格式编解码 + 不可达时的行为（不需要起两个进程） */
function runUnit(): void
{
    echo "== Cluster 单元测试 ==" . PHP_EOL;

    // 1) 往返
    $raw = Packet::encode(0x01000007, 0x02000003, 12345, 2001, "payload-bytes");
    $p = Packet::tryDecode($raw);
    echo "  往返 dst      " . ($p !== null && $p->dst === 0x01000007 ? "OK" : "FAIL") . PHP_EOL;
    echo "  往返 src      " . ($p !== null && $p->src === 0x02000003 ? "OK" : "FAIL") . PHP_EOL;
    echo "  往返 session  " . ($p !== null && $p->session === 12345 ? "OK" : "FAIL") . PHP_EOL;
    echo "  往返 msgId    " . ($p !== null && $p->msgId === 2001 ? "OK" : "FAIL") . PHP_EOL;
    echo "  往返 payload  " . ($p !== null && $p->payload === "payload-bytes" ? "OK" : "FAIL") . PHP_EOL;
    echo "  消费长度      " . ($p !== null && $p->consumed === strlen($raw) ? "OK" : "FAIL") . PHP_EOL;

    // 2) 半包返回 null
    $half = substr($raw, 0, 10);
    echo "  半包返回 null " . (Packet::tryDecode($half) === null ? "OK" : "FAIL") . PHP_EOL;

    // 3) 粘包：两包拼一起，第一个能解出、消费长度正确
    $two = $raw . Packet::encode(1, 2, 3, 4, "x");
    $p2 = Packet::tryDecode($two);
    echo "  粘包首包      " . ($p2 !== null && $p2->consumed === strlen($raw) ? "OK" : "FAIL") . PHP_EOL;

    // 4) 非法长度抛异常
    $bad = chr(0) . chr(0) . chr(0) . chr(1) . str_repeat("x", 20);
    $threw = false;
    try {
        Packet::tryDecode($bad);
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    echo "  非法长度抛错  " . ($threw ? "OK" : "FAIL") . PHP_EOL;

    // 5) 不可达节点：send 返回 false 且计入 drop
    $c = new Cluster(1, "/tmp/tsf_unit.sock");
    $ok = $c->send((3 << 24) | 5, 1, 2001, "x");
    echo "  不可达返 false " . ($ok === false ? "OK" : "FAIL") . PHP_EOL;
    echo "  drop 计数     " . ($c->stats()["drop"] === 1 ? "OK" : "FAIL") . PHP_EOL;

    // 6) 节点号 0 应被拒绝
    $threw = false;
    try {
        $c2 = new Cluster(0, "/tmp/tsf_unit2.sock");
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    echo "  节点 0 被拒    " . ($threw ? "OK" : "FAIL") . PHP_EOL;

    // 7) 本地投递（节点位 = 本机）不应该走集群
    Scheduler::reset();
    $s = Scheduler::instance();
    $s->setCluster($c);
    $s->register("echo", function () {
        return new EchoService();
    });
    $addr = $s->spawn("echo");
    echo "  本机地址不误判 " . (Scheduler::instance()->has($addr) && $c->stats()["drop"] === 1 ? "OK" : "FAIL") . PHP_EOL;
}

function main(): void
{
    $argvv = $_SERVER["argv"] ?? [];
    $role = isset($argvv[1]) ? (string) $argvv[1] : "";
    if ($role === "server") {
        runServer(20);
        return;
    }
    if ($role === "client") {
        $n = isset($argvv[2]) ? (int) $argvv[2] : 2000;
        runClient($n);
        return;
    }
    if ($role === "unit") {
        runUnit();
        return;
    }
    echo "用法: cluster_test server|client|unit [count]" . PHP_EOL;
}
