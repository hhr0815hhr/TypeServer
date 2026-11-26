<?php

use TSF\Cluster\Cluster;
use TSF\Http\App;
use TSF\Http\IpFilter;
use TSF\Http\MetricsRoute;
use TSF\Http\Request;
use TSF\Metrics\Metrics;
use TSF\Metrics\Prometheus;
use TSF\Rpc\Rpc;
use TSF\Rpc\Router;
use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

/**
 * Prometheus 导出回归。
 *
 * 判据是"抓取端能不能吃"：TYPE/HELP 不能重复、名字必须合法、同名指标不能出现两次声明。
 * 格式错了不是"少一个指标"——Prometheus 会**整批 400**，整个进程的指标全丢。
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

function countOf(string $hay, string $needle): int
{
    $n = 0;
    $off = 0;
    while (true) {
        $p = strpos($hay, $needle, $off);
        if ($p === false) {
            return $n;
        }
        $n++;
        $off = $p + strlen($needle);
    }
}

function has(string $hay, string $line): bool
{
    return strpos($hay, $line) !== false;
}

function main(): void
{
    echo "== Prometheus 导出 ==" . PHP_EOL;

    Metrics::reset();
    Metrics::inc("room.created", 3);
    Metrics::gauge("room.online", 7.5);
    for ($i = 1; $i <= 10; $i++) {
        Metrics::observe("net.rtt.us", (float) ($i * 100));
    }

    $txt = Prometheus::render(false);

    // 1) 计数器
    T::ok(has($txt, "# TYPE room_created counter"), "计数器 TYPE 正确");
    T::ok(has($txt, "\nroom_created 3\n"), "计数器值正确");

    // 2) 仪表
    T::ok(has($txt, "# TYPE room_online gauge"), "仪表 TYPE 正确");
    T::ok(has($txt, "\nroom_online 7.5\n"), "仪表值保留小数");

    // 3) 直方图 → summary
    T::ok(has($txt, "# TYPE net_rtt_us summary"), "直方图映射成 summary");
    T::ok(has($txt, 'net_rtt_us{quantile="0.5"}'), "有 p50 分位");
    T::ok(has($txt, 'net_rtt_us{quantile="0.99"}'), "有 p99 分位");
    T::ok(has($txt, "net_rtt_us_sum "), "有 _sum");
    T::ok(has($txt, "\nnet_rtt_us_count 10\n"), "_count 等于观测次数");

    // 4) 点号名字必须被换掉：抓取端只认 [a-zA-Z0-9_:]
    // HELP 里保留原始名（便于和日志对照），所以只要求"样本行"里没有点
    T::ok(!has($txt, "\nroom.created "), "样本行里的点被替换");
    T::ok(Prometheus::sanitize("a.b-c") === "a_b_c", "sanitize 点与横线", Prometheus::sanitize("a.b-c"));
    T::ok(Prometheus::sanitize("9lives") === "_9lives", "首字符是数字时补下划线", Prometheus::sanitize("9lives"));

    // 5) 同名指标只能有一份 TYPE/HELP：重复声明会让抓取端整批失败
    T::ok(countOf($txt, "# TYPE room_created ") === 1, "TYPE 只声明一次");
    T::ok(countOf($txt, "# HELP room_created ") === 1, "HELP 只声明一次");

    // 6) 进程指标可选
    T::ok(!has($txt, "tsf_mem_bytes"), "关闭时不带进程指标");
    $txt2 = Prometheus::render(true);
    T::ok(has($txt2, "tsf_mem_bytes "), "打开时带进程内存");
    T::ok(has($txt2, "# TYPE tsf_mem_bytes gauge"), "进程指标声明为 gauge");

    // 7) Content-Type 必须带版本号
    T::ok(strpos(Prometheus::CONTENT_TYPE, "version=0.0.4") !== false, "Content-Type 带版本号");

    // 8) 路由：不启服务，直接走 Router::dispatch
    $app = new App("127.0.0.1", 18080);
    MetricsRoute::register($app, "/metrics", false);
    $req = new Request("GET", "/metrics", [], [], [], "", [], "127.0.0.1");
    $res = $app->router()->dispatch($req);
    T::ok($res->status === 200, "路由返回 200", "status=" . $res->status);
    T::ok(has($res->body, "room_created 3"), "响应体是指标文本");
    $ct = $res->headers["content-type"] ?? "";
    T::ok(strpos($ct, "version=0.0.4") !== false, "响应头是 Prometheus 格式", $ct);

    // 9) 来源限制：默认只放行回环与私有网段
    T::ok(IpFilter::allows(IpFilter::PRIVATE_ONLY, "127.0.0.1"), "放行回环");
    T::ok(IpFilter::allows(IpFilter::PRIVATE_ONLY, "192.168.1.20"), "放行 192.168/16");
    T::ok(IpFilter::allows(IpFilter::PRIVATE_ONLY, "10.2.3.4"), "放行 10/8");
    T::ok(IpFilter::allows(IpFilter::PRIVATE_ONLY, "172.20.1.1"), "放行 172.16/12");
    T::ok(!IpFilter::allows(IpFilter::PRIVATE_ONLY, "172.32.1.1"), "172.32 不在 172.16/12 内");
    T::ok(!IpFilter::allows(IpFilter::PRIVATE_ONLY, "8.8.8.8"), "拒绝公网");
    T::ok(!IpFilter::allows(IpFilter::PRIVATE_ONLY, ""), "拿不到来源时拒绝");
    T::ok(IpFilter::allows(["*"], "8.8.8.8"), "显式 * 才全放开");
    T::ok(!IpFilter::inCidr("192.168.1.5", "192.168.1.0/33"), "非法掩码一律拒绝");
    T::ok(IpFilter::inCidr("192.168.1.5", "0.0.0.0/0"), "/0 匹配全部");

    // 路由层真的拦：公网来源 403，内网 200
    $app2 = new App("127.0.0.1", 18081);
    MetricsRoute::register($app2, "/metrics", false);
    $pub = new Request("GET", "/metrics", [], [], [], "", [], "203.0.113.9");
    T::ok($app2->router()->dispatch($pub)->status === 403, "公网来源被拒 403");
    $lan = new Request("GET", "/metrics", [], [], [], "", [], "192.168.2.94");
    T::ok($app2->router()->dispatch($lan)->status === 200, "内网来源 200");

    // 10) 空指标表也不能渲染出非法内容（关掉运行时指标：下面单独测它）
    Metrics::reset();
    $empty = Prometheus::render(false, "", false);
    T::ok($empty === "", "空表渲染为空串", "len=" . strlen($empty));

    echo PHP_EOL . "== 运行时指标 ==" . PHP_EOL;
    // 11) 框架自己的统计也要能被抓到。此前导出器只翻业务埋点：调度器处理了多少消息、
    //     网关多少会话、集群断了多少次链路，全都只在 stats() 里，Prometheus 看不到
    Metrics::reset();
    Metrics::clearProviders();
    Metrics::provide("tsf_demo", function (): array {
        return ["handled" => 3, "max_tick_gap_us" => 7];
    }, ["handled"]);
    Metrics::provide("tsf_demo", function (): array {
        return ["handled" => 5, "max_tick_gap_us" => 2];
    }, ["handled"]);
    $rt = Metrics::runtimeSnapshot();
    T::ok(($rt["tsf_demo_handled"] ?? -1) === 8, "同前缀多个提供者相加（两个网关的总数）", "got=" . ($rt["tsf_demo_handled"] ?? -1));
    $txt = Prometheus::render(false);
    T::ok(has($txt, "# TYPE tsf_demo_handled counter\n"), "列进 counters 的按 counter 导出（可以 rate）");
    T::ok(has($txt, "# TYPE tsf_demo_max_tick_gap_us gauge\n"), "没列的按 gauge 导出");
    T::ok(has($txt, "tsf_demo_handled 8\n"), "值是相加之后的");
    T::ok(has($txt, "# HELP tsf_demo_handled "), "有 HELP");
    T::ok(countOf($txt, "# TYPE tsf_demo_handled ") === 1, "同名只声明一次（重复声明会让抓取端整批 400）");
    T::ok(Prometheus::render(false, "", false) === "", "withRuntime=false 时不导出运行时指标");
    Metrics::clearProviders();
    Metrics::provide("bad prefix!", function (): array {
        return ["x" => 1];
    });
    T::ok(has(Prometheus::render(false), "# TYPE bad_prefix_x gauge\n"), "前缀被规整成合法指标名");

    // 12) 框架组件自己登记的
    Metrics::clearProviders();
    Metrics::reset();
    $sched = Scheduler::instance();
    $a = $sched->spawnOf(function (): RtIdle {
        return new RtIdle();
    });
    $sched->deliverAs(0, $a, 1, "x");
    $sched->tick();
    $b = $sched->spawnOf(function (): RtIdle {
        return new RtIdle();
    });
    $txt = Prometheus::render(false);
    T::ok(has($txt, "# TYPE tsf_scheduler_handled counter\n"), "调度器：handled 是计数器");
    T::ok(has($txt, "tsf_scheduler_handled 1\n"), "调度器：处理过的消息数对得上");
    T::ok(has($txt, "tsf_scheduler_services 2\n"), "调度器：services 是当前服务数");
    T::ok(has($txt, "tsf_scheduler_ids_left 16777213\n"), "调度器：还剩多少服务 id 可见（快用尽时能提前看到）");
    T::ok(has($txt, "# TYPE tsf_scheduler_services gauge\n"), "调度器：services 是 gauge");
    $sched->kill($a);
    $sched->kill($b);

    $cl = new Cluster(9, "/tmp/tsf_prom_rt.sock");
    $txt = Prometheus::render(false);
    T::ok(has($txt, "# TYPE tsf_cluster_reconnects counter\n"), "集群：重连次数是计数器");
    T::ok(has($txt, "tsf_cluster_links 0\n"), "集群：当前链路数是 gauge");

    // RPC 是推的：Router/Client 一个进程里可能有很多个实例，各自 inc 才不会漏
    $router = new Router();
    $msg = new Message(0, Rpc::MSG_REQ, $router->codec()->packRequest("no.such.method", ""));
    $router->handle($msg);
    $router->handle($msg);
    $txt = Prometheus::render(false, "", false);
    T::ok(has($txt, "rpc_unrouted 2\n"), "RPC：路由不到的调用被计数", substr_count($txt, "rpc_unrouted") > 0 ? "" : "缺失");
    T::ok(has($txt, "# TYPE rpc_unrouted counter\n"), "RPC：按 counter 导出");

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
}

/** 什么都不做的服务 */
final class RtIdle extends Service
{
    public function onMessage(Message $m): void
    {
    }
}
