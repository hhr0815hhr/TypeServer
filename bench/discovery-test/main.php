<?php

/**
 * 服务发现测试。
 *
 * 三个容易做错的点：
 *   1. 心跳被当成"变更"——监听者每秒被刷一次，日志和路由表全在抖
 *   2. 用"显式注销"判断存活——kill -9 之后节点永远挂在表里
 *   3. 坏文件让整张表读不出来——一个节点的 JSON 写坏了，整个集群失联
 */

use TSF\Discovery\Contract\Registry;
use TSF\Discovery\FileRegistry;
use TSF\Discovery\LocalNode;
use TSF\Discovery\NodeInfo;
use TSF\Service\Scheduler;

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

function freshDir(string $name): string
{
    $dir = "/tmp/tsf_disc_" . $name;
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            $p = $dir . "/" . $f;
            if (is_file($p)) {
                unlink($p);
            }
        }
    }
    return $dir;
}

/** 1. 注册与查询 */
function caseRegister(): void
{
    echo "== [1] 注册与查询 ==" . PHP_EOL;

    $dir = freshDir("reg");
    $reg = new FileRegistry($dir, 3000);

    T::eq(count($reg->alive()), 0, "初始没有节点");

    T::ok($reg->register(new NodeInfo(1, "/tmp/n1.sock", "json", ["role" => "gw"])), "注册节点 1");
    T::ok($reg->register(new NodeInfo(2, "/tmp/n2.sock", "raw")), "注册节点 2");

    T::eq(count($reg->alive()), 2, "2 个存活节点");

    $n1 = $reg->get(1);
    T::ok($n1 !== null, "get(1) 拿得到");
    if ($n1 !== null) {
        T::eq($n1->endpoint, "/tmp/n1.sock", "endpoint 正确");
        T::eq($n1->codec, "json", "codec 正确");
        T::eq($n1->tag("role"), "gw", "tag 保留");
    }

    $n2 = $reg->get(2);
    if ($n2 !== null) {
        T::eq($n2->codec, "raw", "不同节点可以有不同 codec");
    }

    T::ok($reg->get(99) === null, "不存在的返回 null");

    // 覆盖注册
    $reg->register(new NodeInfo(1, "/tmp/n1b.sock", "json"));
    $n1b = $reg->get(1);
    if ($n1b !== null) {
        T::eq($n1b->endpoint, "/tmp/n1b.sock", "重复注册会覆盖 endpoint");
    }
    T::eq(count($reg->alive()), 2, "覆盖不增加节点数");
}

/** 2. 心跳只刷新存活，不改身份 */
function caseHeartbeat(): void
{
    echo "== [2] 心跳 ==" . PHP_EOL;

    $dir = freshDir("beat");
    $reg = new FileRegistry($dir, 3000);
    $reg->register(new NodeInfo(1, "/tmp/n1.sock", "json"));

    T::ok($reg->heartbeat(1), "心跳成功");
    T::ok(!$reg->heartbeat(99), "未注册的节点心跳返回 false");

    // 心跳不改变身份字段
    $n = $reg->get(1);
    if ($n !== null) {
        T::eq($n->endpoint, "/tmp/n1.sock", "心跳不改 endpoint");
        T::eq($n->codec, "json", "心跳不改 codec");
    }

    // 心跳不动身份文件（心跳写的是独立的 .beat）
    $path = $dir . "/1.json";
    $before = file_get_contents($path);
    usleep(20000);
    $reg->heartbeat(1);
    $after = file_get_contents($path);
    T::eq($after === $before, true, "心跳不改身份文件");
    T::ok(is_file($dir . "/1.beat"), "心跳写的是独立的 .beat 文件");
}

/** 3. TTL 过期判定 */
function caseTtl(): void
{
    echo "== [3] 过期判定 ==" . PHP_EOL;

    $dir = freshDir("ttl");
    // TTL 最小 1000（filemtime 只到秒）
    $reg = new FileRegistry($dir, 1200);
    T::eq($reg->ttlMs(), 1200, "TTL 生效");

    $reg->register(new NodeInfo(1, "/tmp/n1.sock"));
    $reg->register(new NodeInfo(2, "/tmp/n2.sock"));

    T::eq(count($reg->alive()), 2, "刚注册都活着");

    // 只给 2 号续命，1 号不管
    usleep(700000);
    $reg->heartbeat(2);

    usleep(900000);   // 距注册 1.6s，距 2 号心跳 0.9s
    $alive = $reg->alive();
    $ids = [];
    foreach ($alive as $n) {
        $ids[] = $n->nodeId;
    }
    T::ok(in_array(2, $ids, true), "有心跳的 2 号还活着");
    T::ok(!in_array(1, $ids, true), "没心跳的 1 号已过期", "alive=" . implode(",", $ids));

    // all() 仍能看到过期的
    T::eq(count($reg->all()), 2, "all() 包含过期节点");

    // TTL 兜底：太小的值没有意义（一次调度抖动就误判）
    $reg2 = new FileRegistry(freshDir("ttl2"), 50);
    T::eq($reg2->ttlMs(), 100, "TTL 小于 100 被兜底到 100");
}

/** 4. 注销（优雅退出） */
function caseUnregister(): void
{
    echo "== [4] 注销 ==" . PHP_EOL;

    $dir = freshDir("unreg");
    $reg = new FileRegistry($dir, 3000);
    $reg->register(new NodeInfo(1, "/tmp/n1.sock"));

    T::ok($reg->unregister(1), "注销成功");
    T::eq(count($reg->alive()), 0, "注销后立刻不可见");
    T::eq(count($reg->all()), 0, "文件已删除");
    T::ok(!$reg->unregister(1), "重复注销返回 false");
}

/** 5. 变更通知：心跳不能触发 */
function caseRefresh(): void
{
    echo "== [5] 变更通知 ==" . PHP_EOL;

    $dir = freshDir("refresh");
    $reg = new FileRegistry($dir, 3000);

    $hits = 0;
    $reg->onChanged(function () use (&$hits): void {
        $hits++;
    });

    $reg->refresh();
    T::eq($hits, 0, "空表 refresh 不触发（无变化）");
    T::eq($reg->known(), 0, "known=0");

    $reg->register(new NodeInfo(1, "/tmp/n1.sock"));
    T::ok($reg->refresh(), "新增节点触发变更");
    T::eq($hits, 1, "回调被调用一次");
    T::eq($reg->known(), 1, "known=1");

    // 关键：连续心跳不该算变更
    for ($i = 0; $i < 5; $i++) {
        $reg->heartbeat(1);
        usleep(2000);
        T::ok(!$reg->refresh(), "第 " . ($i + 1) . " 次心跳不算变更");
    }
    T::eq($hits, 1, "心跳没有刷爆回调");

    // 身份变了才算
    $reg->register(new NodeInfo(1, "/tmp/n1-moved.sock", "raw"));
    T::ok($reg->refresh(), "endpoint/codec 变了算变更");
    T::eq($hits, 2, "回调第二次");

    // 节点消失也算
    $reg->unregister(1);
    T::ok($reg->refresh(), "节点注销触发变更");
    T::eq($hits, 3, "回调第三次");
    T::eq($reg->known(), 0, "known 归零");
}

/** 6. 坏文件容错 */
function caseBadFile(): void
{
    echo "== [6] 坏文件容错 ==" . PHP_EOL;

    $dir = freshDir("bad");
    $reg = new FileRegistry($dir, 3000);
    $reg->register(new NodeInfo(1, "/tmp/n1.sock"));
    $reg->register(new NodeInfo(2, "/tmp/n2.sock"));

    // 写坏一个
    file_put_contents($dir . "/3.json", "{broken");
    // 空文件
    file_put_contents($dir . "/4.json", "");
    // 缺 endpoint
    file_put_contents($dir . "/5.json", '{"id":5}');
    // 临时文件不该被看到
    file_put_contents($dir . "/6.json.tmp", '{"id":6,"ep":"/tmp/n6.sock"}');

    $alive = $reg->alive();
    $ids = [];
    foreach ($alive as $n) {
        $ids[] = $n->nodeId;
    }
    T::eq(count($alive), 2, "坏文件被跳过，好的仍可读");
    T::ok(in_array(1, $ids, true) && in_array(2, $ids, true), "1/2 号都在");
    T::ok(!in_array(6, $ids, true), ".tmp 不被当成注册");
}

/** 7. LocalNode 自动心跳 */
function caseLocalNode(): void
{
    echo "== [7] LocalNode 自动心跳 ==" . PHP_EOL;

    $dir = freshDir("local");
    $reg = new FileRegistry($dir, 3000);

    Scheduler::reset();
    $s = Scheduler::instance();

    $info = new NodeInfo(7, "/tmp/n7.sock", "json", ["role" => "room"]);
    $addr = $s->spawnOf(function () use ($reg, $info): LocalNode {
        return new LocalNode($reg, $info, 50);
    });
    $node = $s->serviceOf($addr);
    if (!($node instanceof LocalNode)) {
        T::ok(false, "LocalNode 没起来");
        return;
    }

    T::ok($node->isRegistered(), "onStart 里已注册");
    T::eq(count($reg->alive()), 1, "注册表里有 1 个");

    // 跑 300ms，心跳 50ms 一次 → 约 6 次
    $deadline = Scheduler::nowMs() + 300;
    while (Scheduler::nowMs() < $deadline) {
        $s->tick();
        usleep(5000);
    }
    T::ok($node->beats() >= 3, "心跳跑了至少 3 次", "beats=" . $node->beats());
    T::eq(count($reg->alive()), 1, "心跳期间始终存活");

    // 优雅退出：onStop 会注销
    $s->kill($addr);
    $s->tick();
    T::eq(count($reg->alive()), 0, "kill 之后自动注销");
}

/** 8. codec 协商：注册信息里能看出协议不一致 */
function caseCodecNegotiation(): void
{
    echo "== [8] codec 协商 ==" . PHP_EOL;

    $dir = freshDir("codec");
    $reg = new FileRegistry($dir, 3000);
    $reg->register(new NodeInfo(1, "/tmp/n1.sock", "json"));
    $reg->register(new NodeInfo(2, "/tmp/n2.sock", "raw"));

    $myCodec = "json";

    $peer1 = $reg->get(1);
    $peer2 = $reg->get(2);

    $ok1 = $peer1 !== null && $peer1->codec === $myCodec;
    $ok2 = $peer2 !== null && $peer2->codec === $myCodec;

    T::ok($ok1, "同协议节点可连");
    T::ok(!$ok2, "异协议节点在**建连前**就能识别出来");

    // 这才是加 codec 字段的意义：不用等到调用失败报 bad-response
    if ($peer2 !== null) {
        $why = $peer2->codec !== $myCodec ? "codec-mismatch:" . $peer2->codec . "!=" . $myCodec : "";
        T::eq($why, "codec-mismatch:raw!=json", "错误信息能指出具体差异");
    }
}

function main(): void
{
    caseRegister();
    caseHeartbeat();
    caseTtl();
    caseUnregister();
    caseRefresh();
    caseBadFile();
    caseLocalNode();
    caseCodecNegotiation();

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
}
