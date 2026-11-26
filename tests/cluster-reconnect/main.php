<?php

use TSF\Cluster\Cluster;
use TSF\Kernel\Logger;

/**
 * 集群断线重连。两个节点跑在同一个进程里，靠 poll()/flush() 推进（同 cluster-test）。
 *
 * 修之前：`dropLink()` 之后永久失联——对端重启一次，跨节点消息从此全部进 `drop`，
 * 而本节点还活着、日志里只有一行断链警告。另外 `connect()` 是同步阻塞的，最长挡住
 * 整个 worker 2 秒（这里是 1ms 一轮的调度循环）。
 */
const ADDR1 = "tcp://127.0.0.1:9416";
const ADDR2 = "tcp://127.0.0.1:9417";
const MSG_PING = 2001;

final class R
{
    public static int $pass = 0;

    public static int $fail = 0;

    public static function ok(bool $cond, string $name, string $extra = ""): void
    {
        if ($cond) {
            self::$pass++;
            echo "  OK   " . $name . ($extra !== "" ? "  (" . $extra . ")" : "") . PHP_EOL;
        } else {
            self::$fail++;
            echo "  FAIL " . $name . ($extra !== "" ? "  (" . $extra . ")" : "") . PHP_EOL;
        }
    }
}

/** 一次 poll() 最长花了多久（微秒）：用来钉"拨号不阻塞调度" */
final class Probe
{
    public static int $maxPollUs = 0;
}

/** 推进两个节点，直到 $until() 为真或超时。返回实际用时（毫秒） */
function pump(Cluster $a, ?Cluster $b, int $maxMs, \Closure $until): int
{
    $t0 = hrtime(true);
    $wait = true;
    while ($wait) {
        $t = hrtime(true);
        $a->poll();
        $a->flush();
        if ($b !== null) {
            $b->poll();
            $b->flush();
        }
        $us = intdiv(hrtime(true) - $t, 1000);
        if ($us > Probe::$maxPollUs) {
            Probe::$maxPollUs = $us;
        }
        if (intdiv(hrtime(true) - $t0, 1000000) >= $maxMs || $until()) {
            $wait = false;
        }
        if ($wait) {
            usleep(2000);
        }
    }
    return intdiv(hrtime(true) - $t0, 1000000);
}

/** $aPeer：$a 这边应该看到的对端节点号；$bPeer 同理 */
function bothLinked(Cluster $a, Cluster $b, int $aPeer, int $bPeer): bool
{
    return $a->hasPeer($aPeer) && $b->hasPeer($bPeer);
}

/** 从 $from 发一条给 $to，返回 $to 收到的第一条载荷（没收到返回空串） */
function roundTrip(Cluster $from, Cluster $to, int $dst): string
{
    $from->send($dst, 1, MSG_PING, "rt");
    $got = "";
    $t0 = hrtime(true);
    while ($got === "" && intdiv(hrtime(true) - $t0, 1000000) < 1500) {
        $list = $to->poll();
        $from->poll();
        $to->flush();
        $from->flush();
        foreach ($list as $p) {
            $payload = is_string($p->data) ? $p->data : "";
            if ($payload === "rt") {
                $got = $payload;
            }
        }
        if ($got === "") {
            usleep(2000);
        }
    }
    return $got;
}

function main(): void
{
    // 只留 error：这里的断链/重连是测试**期望**看到的，别刷屏
    Logger::init("app", "", Logger::ERROR);

    echo "== [0] watchPeer：对端已经在监听 ==" . PHP_EOL;
    $c1 = new Cluster(1, ADDR1);
    $c1->listen();
    $c2 = new Cluster(2, ADDR2);
    $c2->listen();
    $c2->setReconnect(50, 400, 200);
    $c2->setConnectTimeout(200);

    R::ok($c2->watchPeer(1, ADDR1), "登记要连通的节点 1");
    $ms = pump($c1, $c2, 3000, function () use ($c1, $c2): bool {
        return bothLinked($c1, $c2, 2, 1);
    });
    R::ok(bothLinked($c1, $c2, 2, 1), "握手完成，双向可见", $ms . "ms");
    R::ok(roundTrip($c2, $c1, (1 << 24) | 5) === "rt", "能传包");
    R::ok(count($c2->peers()) === 1, "peers 里有 1 个");

    echo PHP_EOL . "== [1] 对端重启（短暂不可用）之后自动重连 ==" . PHP_EOL;
    // 模拟对端重启：关掉 node1，过一会儿在同一个地址重新起来
    $c1->closeAll();
    $c1->poll();
    pump($c2, null, 400, function (): bool {
        return false;
    });
    R::ok(!$c2->hasPeer(1), "node1 没了，node2 这边看到链路断开");
    $c1 = new Cluster(1, ADDR1);
    $c1->listen();
    $reconnectsBefore = $c2->stats()["reconnects"];
    $ms = pump($c1, $c2, 6000, function () use ($c1, $c2): bool {
        return bothLinked($c1, $c2, 2, 1);
    });
    R::ok(bothLinked($c1, $c2, 2, 1), "对端起来之后自动重连成功", $ms . "ms");
    R::ok($c2->stats()["reconnects"] > $reconnectsBefore, "reconnects 计数增加", "reconnects=" . $c2->stats()["reconnects"]);
    R::ok(roundTrip($c2, $c1, (1 << 24) | 5) === "rt", "重连之后又能传包");

    echo PHP_EOL . "== [2] 拨号不阻塞调度 ==" . PHP_EOL;
    // node1 关掉、地址上没人监听。对没人监听的地址 connect 可能一直不返回（取决于网络栈）：
    // 同步 connect 会在这里挡住整个 worker；非阻塞的只应让 poll() 多花几十微秒。
    Probe::$maxPollUs = 0;
    $c1->closeAll();
    $t0 = hrtime(true);
    $c2->poll();
    $firstPollUs = intdiv(hrtime(true) - $t0, 1000);
    pump($c2, null, 1500, function (): bool {
        return false;
    });
    R::ok($firstPollUs < 20000, "对端刚断时 poll() 立刻返回", $firstPollUs . "µs");
    R::ok(Probe::$maxPollUs < 100000, "之后 1.5 秒里的每一次 poll() 都不超过 100ms", "最大 " . Probe::$maxPollUs . "µs");
    R::ok(!$c2->hasPeer(1), "期间没有连上");

    echo PHP_EOL . "== [2b] 对端**不可达**（机器关机 / 网络分区）时拨号也不阻塞 ==" . PHP_EOL;
    // 上面那种"端口没人听"在本机回环上会立刻被拒，同步 connect 也返回得很快，
    // 所以 [2] 抓不到阻塞。真正会卡住的是**不可达地址**：没有 RST，只能等超时。
    // 同步 connect 在这里会挡住 worker 整整一个超时周期（原来最长 2 秒）；
    // 非阻塞的应该只让 poll() 多几十微秒，到 setConnectTimeout 的时间自己放弃。
    Probe::$maxPollUs = 0;
    $c2->forgetPeer(1);
    $c2->watchPeer(7, "tcp://10.255.255.1:9");
    pump($c2, null, 1200, function (): bool {
        return false;
    });
    R::ok(Probe::$maxPollUs < 100000, "对不可达地址的每一次 poll() 都不超过 100ms", "最大 " . Probe::$maxPollUs . "µs");
    R::ok(!$c2->hasPeer(7), "不可达的对端不会被当成连上了");
    $c2->forgetPeer(7);
    // 重新登记节点 1：后面的几节还要靠它
    $c2->watchPeer(1, ADDR1);

    echo PHP_EOL . "== [3] 对端一直起不来：退避、封顶，不空转 ==" . PHP_EOL;
    // 上面的 1.5 秒里已经拨过几次了。再观察 3 秒：
    $attBefore = $c2->stats()["reconnects"];
    pump($c2, null, 3000, function (): bool {
        return false;
    });
    $att = $c2->stats()["reconnects"] - $attBefore;
    // 退避 50ms 起步、翻倍、封顶 400ms（稳定阈值 200ms）。3 秒内的拨号次数应该落在
    // "每次都是最小值"(≈60) 和 "每次都是最大值"(≈8) 之间，且明显更靠近后者——说明退避在涨
    R::ok($att >= 4 && $att <= 25, "3 秒内拨号次数有上限（没有热循环）", "attempts=" . $att);
    R::ok(!$c2->hasPeer(1), "始终没有连上（不谎报成功）");

    echo PHP_EOL . "== [4] 这时候对端起来了，还是要能连上 ==" . PHP_EOL;
    $c1 = new Cluster(1, ADDR1);
    $c1->listen();
    $ms = pump($c1, $c2, 8000, function () use ($c1, $c2): bool {
        return bothLinked($c1, $c2, 2, 1);
    });
    R::ok(bothLinked($c1, $c2, 2, 1), "退避到封顶之后对端起来了，照样重连", $ms . "ms");

    echo PHP_EOL . "== [5] 稳定过的链路再断：退避从头算 ==" . PHP_EOL;
    // 让链路"稳定"超过 200ms 的阈值，然后断掉：下一次重连应该很快（退避回到最小值 50ms + 抖动）
    usleep(400000);
    $c1->closeAll();
    $c1->poll();
    $c2->poll();
    $c2->flush();
    $c1 = new Cluster(1, ADDR1);
    $c1->listen();
    $ms = pump($c1, $c2, 6000, function () use ($c1, $c2): bool {
        return bothLinked($c1, $c2, 2, 1);
    });
    R::ok(bothLinked($c1, $c2, 2, 1), "重连成功", $ms . "ms");
    R::ok($ms < 2000, "稳定过的链路断了之后很快回来（没有被之前的退避拖累）", $ms . "ms");

    echo PHP_EOL . "== [6] connect() 仍是一次性的 ==" . PHP_EOL;
    // 前面几节的 node2 退场：它还登记着对端 1，留着会在后面几节里继续拨号
    $c2->closeAll();
    $c3 = new Cluster(3, "tcp://127.0.0.1:9418");
    $c3->listen();
    R::ok($c3->connect(1, ADDR1), "connect() 发起连接");
    pump($c1, $c3, 3000, function () use ($c1, $c3): bool {
        return bothLinked($c1, $c3, 3, 1);
    });
    R::ok(bothLinked($c1, $c3, 3, 1), "握手完成");
    $c1->closeAll();
    $c1->poll();
    pump($c3, null, 800, function (): bool {
        return false;
    });
    $st = $c3->stats();
    R::ok(!$c3->hasPeer(1), "断了之后没连上");
    R::ok($st["reconnects"] === 0, "没有自动重连（没登记过）", "reconnects=" . $st["reconnects"]);

    echo PHP_EOL . "== [7] 两端同时互拨：只留一条，不来回抖 ==" . PHP_EOL;
    $c1->closeAll();
    $c3->closeAll();
    $a = new Cluster(1, ADDR1);
    $a->listen();
    $b = new Cluster(2, "tcp://127.0.0.1:9419");
    $b->listen();
    $a->setReconnect(50, 400, 100);
    $b->setReconnect(50, 400, 100);
    // 同时登记、同时拨号
    $a->watchPeer(2, "tcp://127.0.0.1:9419");
    $b->watchPeer(1, ADDR1);
    $ms = pump($a, $b, 4000, function () use ($a, $b): bool {
        return bothLinked($a, $b, 2, 1);
    });
    R::ok(bothLinked($a, $b, 2, 1), "双方都看到对方", $ms . "ms");
    // 再跑 2 秒：如果两条链路互相把对方挤掉，这里会看到反复断开重连
    $reA = $a->stats()["reconnects"];
    $reB = $b->stats()["reconnects"];
    pump($a, $b, 2000, function (): bool {
        return false;
    });
    $flaps = ($a->stats()["reconnects"] - $reA) + ($b->stats()["reconnects"] - $reB);
    R::ok(bothLinked($a, $b, 2, 1), "2 秒之后链路仍然在");
    R::ok($flaps === 0, "2 秒内没有再重拨（没有抖动）", "flaps=" . $flaps);
    R::ok($a->stats()["links"] === 1 && $b->stats()["links"] === 1, "两端各自只有一条到对方的链路");
    R::ok(roundTrip($a, $b, (2 << 24) | 7) === "rt" && roundTrip($b, $a, (1 << 24) | 7) === "rt", "两个方向都能传包");

    echo PHP_EOL . "== [8] forgetPeer 之后不再重连 ==" . PHP_EOL;
    $b->forgetPeer(1);
    R::ok($b->peers() === [], "peers 清空");
    $b->closeAll();
    pump($a, null, 800, function (): bool {
        return false;
    });
    pump($a, null, 800, function (): bool {
        return false;
    });
    // a 这边还登记着 b，所以它会去拨——但 b 已经 closeAll（没在监听），拨号不会成功；
    // 要验证的是 b 自己：forgetPeer + closeAll 之后 peers 为空，poll() 不会再去拨任何人
    R::ok($b->stats()["peers"] === 0, "closeAll 之后 b 不再有任何要连的对端");
    R::ok(!$a->hasPeer(2), "a 看不到 b（b 已经整个关掉了）");

    echo PHP_EOL . "通过 " . R::$pass . " / 失败 " . R::$fail . PHP_EOL;
    if (R::$fail > 0) {
        exit(1);
    }
}
