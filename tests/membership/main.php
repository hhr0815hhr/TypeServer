<?php

use TSF\Cluster\Cluster;
use TSF\Discovery\FileRegistry;
use TSF\Discovery\LocalNode;
use TSF\Discovery\Membership;
use TSF\Discovery\NodeInfo;
use TSF\Kernel\Logger;
use TSF\Service\Scheduler;

/**
 * 成员联动：Cluster 保持连通的节点，跟着注册表里活着的节点走。
 *
 * 此前"谁该被 watchPeer"完全靠手写：注册表里有它、集群里连不上它是常态，且没有任何报错。
 * 场景全部在同一个进程里（两个节点各一条 Cluster），注册表用 FileRegistry。
 * 9423–9427。
 */
const DIR = "/tmp/tsf_membership";
const MSG_PING = 2001;
const MSG_BEAT = 60201;

/** 测试用的计数器（静态类） */
final class Counter
{
    public static int $hits = 0;
}

final class T
{
    public static int $pass = 0;

    public static int $fail = 0;

    public static function ok(bool $cond, string $label, string $detail = ""): void
    {
        if ($cond) {
            self::$pass++;
            echo "  OK   " . $label . ($detail !== "" ? "  (" . $detail . ")" : "") . PHP_EOL;
        } else {
            self::$fail++;
            echo "  FAIL " . $label . ($detail !== "" ? "  (" . $detail . ")" : "") . PHP_EOL;
        }
    }
}

function pump(Cluster $a, ?Cluster $b, int $maxMs, \Closure $until): int
{
    $t0 = hrtime(true);
    $wait = true;
    while ($wait) {
        $a->poll();
        $a->flush();
        if ($b !== null) {
            $b->poll();
            $b->flush();
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

function roundTrip(Cluster $from, Cluster $to, int $dst): bool
{
    $from->send($dst, 1, MSG_PING, "rt");
    $t0 = hrtime(true);
    while (intdiv(hrtime(true) - $t0, 1000000) < 1500) {
        foreach ($to->poll() as $p) {
            if (is_string($p->data) && $p->data === "rt") {
                return true;
            }
        }
        $from->poll();
        $to->flush();
        $from->flush();
        usleep(2000);
    }
    return false;
}

function cleanDir(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
        return;
    }
    $list = scandir($dir);
    if (!is_array($list)) {
        return;
    }
    foreach ($list as $f) {
        if (is_string($f) && $f !== "." && $f !== "..") {
            unlink($dir . "/" . $f);
        }
    }
}

function main(): void
{
    Logger::init("app", "", Logger::ERROR);
    cleanDir(DIR);
    // TTL 取 400ms：下面要真的等一个节点"过期"，默认的 3 秒太慢
    $registry = new FileRegistry(DIR, 400);

    $a = new Cluster(1, "tcp://127.0.0.1:9423");
    $a->listen();
    $a->setReconnect(50, 400, 100);
    $a->setConnectTimeout(300);
    $b = new Cluster(2, "tcp://127.0.0.1:9424");
    $b->listen();
    $b->setReconnect(50, 400, 100);
    $b->setConnectTimeout(300);
    $m = new Membership($registry, $a);

    echo "== [1] 注册表里出现了它 → 开始保持连通 ==" . PHP_EOL;
    // 自己也在注册表里，但自己不该被 watch
    $registry->register(new NodeInfo(1, "127.0.0.1:9423", "raw"));
    $registry->register(new NodeInfo(2, "127.0.0.1:9424", "raw"));
    T::ok($m->sync(), "第一次对账有变动");
    T::ok(($m->watching()[2] ?? "") === "tcp://127.0.0.1:9424", "host:port 形式的登记地址被当成 TCP 地址", implode(",", $m->watching()));
    T::ok(!isset($m->watching()[1]), "自己不在自己的连通名单里");
    T::ok(count($a->peers()) === 1, "Cluster 的 watch 名单和注册表一致");
    $ms = pump($a, $b, 3000, function () use ($a, $b): bool {
        return $a->hasPeer(2) && $b->hasPeer(1);
    });
    T::ok($a->hasPeer(2) && $b->hasPeer(1), "连上了", $ms . "ms");
    T::ok(roundTrip($a, $b, (2 << 24) | 5), "能传包");

    echo PHP_EOL . "== [2] 幂等：反复对账不会重复拨号 ==" . PHP_EOL;
    $re = $a->stats()["reconnects"];
    T::ok(!$m->sync(), "没变化时 sync() 返回 false");
    T::ok(!$m->sync(), "再对一次还是 false");
    pump($a, $b, 100, function (): bool {
        return false;
    });
    T::ok($a->stats()["reconnects"] === $re, "没有多余的拨号", "reconnects=" . $a->stats()["reconnects"]);
    T::ok($m->stats()["added"] === 1, "added 只记了 1 次", "added=" . $m->stats()["added"]);

    echo PHP_EOL . "== [3] 节点从注册表里消失 → 不再为它重连，但链路不主动断 ==" . PHP_EOL;
    usleep(600000);
    T::ok(count($registry->alive()) === 0, "前置：TTL 到了，注册表里已经没有活着的节点", "alive=" . count($registry->alive()));
    T::ok($m->sync(), "对账发现它没了");
    T::ok($m->watching() === [], "从连通名单里移除");
    T::ok(count($a->peers()) === 0, "Cluster 也不再 watch 它");
    T::ok($a->hasPeer(2), "但已经建好的链路不主动断");
    pump($a, $b, 150, function (): bool {
        return false;
    });

    echo PHP_EOL . "== [4] 它换个地址重新注册，旧链路随后断开 → 连到新地址 ==" . PHP_EOL;
    $b2 = new Cluster(2, "tcp://127.0.0.1:9425");
    $b2->listen();
    $registry->register(new NodeInfo(2, "tcp://127.0.0.1:9425", "raw"));
    T::ok($m->sync(), "对账有变动");
    T::ok(($m->watching()[2] ?? "") === "tcp://127.0.0.1:9425", "登记的是带协议的地址：原样用");
    $b->closeAll();
    $ms = pump($a, $b2, 5000, function () use ($a, $b2): bool {
        return $a->hasPeer(2) && $b2->hasPeer(1);
    });
    T::ok($a->hasPeer(2) && $b2->hasPeer(1), "旧链路断了之后，连到的是新地址", $ms . "ms");
    T::ok(roundTrip($a, $b2, (2 << 24) | 5), "新地址上能传包");

    echo PHP_EOL . "== [5] 同一个节点号换了地址（在注册表里）→ 重新登记，连到新的 ==" . PHP_EOL;
    $c1 = new Cluster(3, "tcp://127.0.0.1:9426");
    $c1->listen();
    $c1->setReconnect(50, 400, 100);
    $c1->setConnectTimeout(300);
    $registry->register(new NodeInfo(3, "127.0.0.1:9426", "raw"));
    $registry->register(new NodeInfo(2, "tcp://127.0.0.1:9425", "raw"));
    T::ok($m->sync(), "节点 3 进来了");
    $ms = pump($a, $c1, 3000, function () use ($a, $c1): bool {
        return $a->hasPeer(3) && $c1->hasPeer(1);
    });
    T::ok($a->hasPeer(3), "连上节点 3", $ms . "ms");
    $before = $a->stats()["reconnects"];
    // 节点 3 重启到另一个端口
    $c1->closeAll();
    $c2 = new Cluster(3, "tcp://127.0.0.1:9427");
    $c2->listen();
    $registry->register(new NodeInfo(3, "127.0.0.1:9427", "raw"));
    T::ok($m->sync(), "地址变了，对账有变动");
    T::ok(($m->watching()[3] ?? "") === "tcp://127.0.0.1:9427", "连通名单里是新地址");
    $ms = pump($a, $c2, 5000, function () use ($a, $c2): bool {
        return $a->hasPeer(3) && $c2->hasPeer(1);
    });
    T::ok($a->hasPeer(3) && $c2->hasPeer(1), "连到了重启之后的新地址", $ms . "ms");
    T::ok($a->stats()["reconnects"] > $before, "确实重新拨了号");
    $st = $m->stats();
    T::ok($st["changed"] === 5 && $st["removed"] === 1, "统计对得上", json_encode($st));

    echo PHP_EOL . "== [6] 登记地址的几种写法 ==" . PHP_EOL;
    T::ok($m->dialOf("tcp://10.0.0.2:9301") === "tcp://10.0.0.2:9301", "带协议：原样");
    T::ok($m->dialOf("unix:///tmp/a.sock") === "unix:///tmp/a.sock", "带 unix 协议：原样");
    T::ok($m->dialOf("10.0.0.2:9301") === "tcp://10.0.0.2:9301", "host:port：补上 tcp://");
    T::ok($m->dialOf("/tmp/a.sock") === "/tmp/a.sock", "裸路径：当 unix socket");
    T::ok($m->dialOf("") === "", "空地址：不拨");
    $custom = new Membership($registry, $a, 0, function (string $endpoint): string {
        return "unix:///override/" . $endpoint;
    });
    T::ok($custom->dialOf("x") === "unix:///override/x", "可以自己给转换规则");

    echo PHP_EOL . "== [7] 挂在心跳上：LocalNode::afterBeat ==" . PHP_EOL;
    // 计数用静态类：AOT 下闭包按引用捕获外部变量不可靠
    $sched = Scheduler::instance();
    $addr = $sched->spawnOf(function (): LocalNode {
        return new LocalNode(new FileRegistry(DIR, 400), new NodeInfo(9, "127.0.0.1:9999", "raw"), 1000);
    });
    $live = $sched->serviceOf($addr);
    if (!$live instanceof LocalNode) {
        T::ok(false, "拿到 LocalNode 实例");
    } else {
        $live->afterBeat(function (): void {
            Counter::$hits++;
        });
        $live->afterBeat(function (): void {
            throw new \RuntimeException("boom");
        });
        $live->afterBeat(function () use ($m): void {
            $m->sync();
        });
        T::ok($live->isRegistered(), "LocalNode 起来之后自动注册");
        T::ok($registry->get(9) !== null, "注册表里能看到它");
        $sched->deliverAs(0, $addr, MSG_BEAT);
        $sched->deliverAs(0, $addr, MSG_BEAT);
        $sched->tick();
        T::ok(Counter::$hits === 2, "每次心跳之后钩子都跑了", "hits=" . Counter::$hits);
        T::ok($sched->has($addr), "抛异常的钩子没有让心跳服务崩掉");
        T::ok($live->beats() === 2, "心跳本身照常记数", "beats=" . $live->beats());
        $sched->kill($addr);
        T::ok($registry->get(9) === null, "停掉之后自动注销");
    }

    echo PHP_EOL . "通过 " . T::$pass . " / 失败 " . T::$fail . PHP_EOL;
    if (T::$fail > 0) {
        exit(1);
    }
}
