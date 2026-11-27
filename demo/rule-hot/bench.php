<?php

// 规则成本对照：让服务进程自己跑，一次 AOT、一次脚本，报每次耗时。
//
//   php85 bench.php [n] [kills]
//
// 为什么不在客户端压：端到端 QPS 被网络、限流、调度主导，
// 规则本身的 2–3 µs 差在里面看不出来。这里直接测规则。
$n = (int) ($argv[1] ?? 20000);
$kills = (int) ($argv[2] ?? 10);
$token = (string) (getenv("TSF_GM_TOKEN") ?: "local-dev-only");

$body = json_encode([
    "cmd" => "loot.bench",
    "args" => ["n" => $n, "kills" => $kills],
]);

$ctx = stream_context_create([
    "http" => [
        "method" => "POST",
        "header" => "Content-Type: application/json\r\nX-GM-Token: " . $token . "\r\n",
        "content" => $body,
        "timeout" => 60,
        "ignore_errors" => true,
    ],
]);

$raw = @file_get_contents("http://127.0.0.1:9702/gm", false, $ctx);
if ($raw === false) {
    fwrite(STDERR, "GM 端口没响应（服务起了吗？端口 9702）" . PHP_EOL);
    exit(1);
}

// 注意：GM 的 data 是裸字符串（不是 JSON 字符串），所以这里不用 json_decode。
// 框架的 Result::ok() 直接拼接原文，做严格 JSON 解析会失败。
if (strpos($raw, '"ok":true') === false) {
    fwrite(STDERR, "GM 调用失败: " . $raw . PHP_EOL);
    exit(1);
}

$m = [];
if (preg_match('/"data":(.*)\}$/', $raw, $m)) {
    $data = trim($m[1]);
} else {
    $data = $raw;
}
echo $data . PHP_EOL;

// 判定线：拿单次耗时换算成"1 万在线时占多少单核"，这比"能撑多少人"靠谱——
// 后者忽略了网络、战斗、持久化，算出来的数字没法用来做决策。
if (preg_match('/script_ns=(\d+)/', $data, $mm)) {
    $ns = (int) $mm[1];
    if ($ns > 0) {
        echo "脚本规则约 " . $ns . " ns/次" . PHP_EOL;
        echo "按 1 万在线折算（单核）：" . PHP_EOL;
        $rates = [1, 10, 50];
        foreach ($rates as $r) {
            $calls = 10000 * $r;
            // 千分数：calls × ns / 1e6
            $pm = intdiv($calls * $ns, 1000000);
            echo "  每人每秒 " . $r . " 次 → " . intdiv($pm, 10) . "." . ($pm % 10) . "% 单核" . PHP_EOL;
        }
        echo "  判定：<5% 放脚本没问题；>20% 说明这条路径该回 AOT" . PHP_EOL;
    }
}
