<?php

use TSF\Discovery\NodeInfo;
use TSF\Registry\RedisLease;
use TSF\Registry\RedisRegistry;
use TSF\Registry\Resp;

/**
 * Redis 注册表回归。
 *
 * 判据是"契约"，不是"实现"：同一个 `Registry` 接口下，FileRegistry 和
 * RedisRegistry 的行为必须一致（注册可见、心跳续命、TTL 过期、注销收敛、
 * 变更回调只响应身份变化）。
 *
 * 需要 127.0.0.1:6379 的 Redis；连不上就打 SKIP 并以 0 退出——环境没装
 * 不该把套件判红，但也别假装测过。
 */

final class T
{
    public static int $pass = 0;

    public static int $fail = 0;

    public static function ok(bool $cond, string $name, string $extra = ""): void
    {
        if ($cond) {
            self::$pass++;
            echo "  PASS  " . $name . ($extra !== "" ? "  (" . $extra . ")" : "") . PHP_EOL;
        } else {
            self::$fail++;
            echo "  FAIL  " . $name . ($extra !== "" ? "  (" . $extra . ")" : "") . PHP_EOL;
        }
    }
}

const PREFIX = "tsftest";

function mk(int $ttlMs = 3000): RedisRegistry
{
    return new RedisRegistry("127.0.0.1", 6379, "", 0, $ttlMs, PREFIX);
}

function node(int $id, string $ep = "", string $codec = "raw"): NodeInfo
{
    $addr = $ep === "" ? "tcp://127.0.0.1:" . (9100 + $id) : $ep;
    return new NodeInfo($id, $addr, $codec, ["role" => "game"], 1);
}

/** 列表里有没有这个节点。用成员判断而不是数总数：其它节点还在有效期内会影响计数 */
function hasNode(array $list, int $id): bool
{
    foreach ($list as $n) {
        if ($n instanceof NodeInfo && $n->nodeId === $id) {
            return true;
        }
    }
    return false;
}

/** 只删本套件命名空间下的键，不碰别人的数据 */
function cleanup(Resp $r): void
{
    try {
        $keys = $r->cmd("KEYS", PREFIX . ":*");
        if (is_array($keys)) {
            foreach ($keys as $k) {
                if (is_string($k) && $k !== "") {
                    $r->cmd("DEL", $k);
                }
            }
        }
    } catch (\Throwable $e) {
        // 清场失败不影响判定：后面的断言会暴露残留
    }
}

function main(): void
{
    echo "== Redis 注册表 ==" . PHP_EOL;

    $probe = new Resp("127.0.0.1", 6379);
    if (!$probe->connect()) {
        echo "SKIP 连不上 Redis（127.0.0.1:6379）—— 跳过而不是判失败" . PHP_EOL;
        return;
    }
    cleanup($probe);

    $r = mk();
    if (!$r->connect()) {
        echo "SKIP 注册表建连失败" . PHP_EOL;
        return;
    }

    // 1) 注册即可见
    T::ok($r->register(node(1)), "注册 node1");
    T::ok($r->register(node(2, "tcp://127.0.0.1:9102", "json")), "注册 node2");
    $a = $r->alive();
    T::ok(count($a) === 2, "两个节点都活着", "n=" . count($a));

    // 2) 字段往返
    $n1 = $r->get(1);
    T::ok($n1 !== null, "get(1) 非空");
    if ($n1 !== null) {
        T::ok($n1->endpoint === "tcp://127.0.0.1:9101", "endpoint 正确", $n1->endpoint);
        T::ok($n1->codec === "raw", "codec 正确", $n1->codec);
        T::ok($n1->tag("role") === "game", "tags 正确");
        T::ok($n1->entry === 1, "entry 正确");
        T::ok($n1->nodeId === 1, "nodeId 正确");
    }
    T::ok($r->get(99) === null, "不存在的节点返回 null");

    // 3) 另一个连接看得到同一份状态（跨进程的最低仿真）
    $r2 = mk();
    T::ok($r2->connect(), "第二个连接建连");
    T::ok(count($r2->alive()) === 2, "另一连接看到同样的两个节点");

    // 4) refresh 只在身份变化时返回 true（心跳不算变化）
    T::ok($r2->refresh() === true, "首次 refresh 有变化");
    T::ok($r2->refresh() === false, "紧接着 refresh 无变化");
    $r2->heartbeat(2);
    T::ok($r2->refresh() === false, "心跳不触发变更回调");
    $r2->register(node(3));
    T::ok($r2->refresh() === true, "新节点触发变更回调");
    T::ok($r2->known() === 3, "known 计数正确", "known=" . $r2->known());
    $r2->unregister(3);

    // 5) 注销立刻收敛
    T::ok($r->unregister(2), "注销 node2");
    T::ok($r->get(2) === null, "注销后 get 返回 null");
    T::ok(count($r->alive()) === 1, "注销后只剩 1 个");

    // 6) TTL：不心跳就过期（模拟 kill -9）
    //
    // 窗口给到 800ms 而不是 300ms：这个套件要和别的套件一起跑，机器一忙
    // "注册完立刻可见"就会被 300ms 的窗口压线，变成一条随机失败——
    // 时序断言宁可慢一点，也不要变成"偶尔红"。
    $short = mk(800);
    $short->connect();
    $short->register(node(7));
    T::ok(hasNode($short->alive(), 7), "短 TTL 注册后可见");
    usleep(1200000);
    T::ok(!hasNode($short->alive(), 7), "1.2s 不心跳后过期");

    // 7) 心跳能续命：总时长超过 TTL，但仍活着（每段间隔都小于 TTL）
    $short->register(node(8));
    usleep(500000);
    $short->heartbeat(8);
    usleep(500000);
    T::ok(hasNode($short->alive(), 8), "心跳续命后仍活着（累计 1s > TTL 800ms）");
    $short->unregister(8);

    // 8) 调用方传更小的 ttl 时，按 lastSeen 精判
    $mid = mk(5000);
    $mid->connect();
    $mid->register(node(9));
    usleep(150000);
    T::ok(hasNode($mid->alive(3000), 9), "ttl 3000 时仍算活");
    T::ok(!hasNode($mid->alive(100), 9), "ttl 100 时被判过期（lastSeen 精判）");
    $mid->unregister(9);

    // 9) 归属租约：跨节点互斥（这是防"两个节点同时搬同一份状态"的唯一手段）
    $l1 = new RedisLease("127.0.0.1", 6379, "", 0, PREFIX, 1);
    $l2 = new RedisLease("127.0.0.1", 6379, "", 0, PREFIX, 2);
    T::ok($l1->connect() && $l2->connect(), "租约建连");
    T::ok($l1->token() !== $l2->token(), "两个实例的 token 不同");
    T::ok($l1->tryAcquire("room:1", 5000), "节点1 拿到租约");
    T::ok(!$l2->tryAcquire("room:1", 5000), "节点2 拿不到（互斥）");
    T::ok($l1->holds("room:1"), "节点1 确认持有");
    T::ok(!$l2->holds("room:1"), "节点2 确认不持有");
    T::ok(!$l2->renew("room:1", 5000), "别人的租约续不了");
    T::ok($l1->renew("room:1", 5000), "自己的能续");
    T::ok($l1->release("room:1"), "释放成功");
    T::ok($l2->tryAcquire("room:1", 5000), "释放后节点2 能拿到");
    $l2->release("room:1");

    // 过期即失效：持有者崩了不能把 key 永久锁死
    T::ok($l1->tryAcquire("room:2", 150), "短 TTL 拿到");
    usleep(300000);
    T::ok($l2->tryAcquire("room:2", 5000), "过期后别人能拿");
    T::ok(!$l1->renew("room:2", 5000), "过期后原持有者续不上");
    $l2->release("room:2");
    $l1->close();
    $l2->close();

    cleanup($probe);
    $probe->close();
    $r->close();
    $r2->close();
    $short->close();
    $mid->close();

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
}
