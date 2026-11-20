<?php

use TSF\Http\App;
use TSF\Http\IpFilter;
use TSF\Http\MetricsRoute;
use TSF\Http\Request;
use TSF\Metrics\Metrics;
use TSF\Metrics\Prometheus;

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

    // 10) 空指标表也不能渲染出非法内容
    Metrics::reset();
    $empty = Prometheus::render(false);
    T::ok($empty === "", "空表渲染为空串", "len=" . strlen($empty));

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
}
