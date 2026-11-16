<?php

/**
 * 协议层成本基准：分帧 vs 消息体编解码。
 *
 * 为什么消息体这块只能微基准：
 * 现有 C 压测端（load_tsf）只会发二进制包体，切到 json 后服务端解析全部失败、
 * 连接被断开，端到端测不出数（会得到 0 吞吐）。要端到端测 json 得另写一个
 * 会说 JSON 的压测端——成本不划算，先给微基准 + 与已知帧成本对照。
 *
 * 注意：微基准会低估端到端影响（codec 那次实测低估约 2.6 倍），所以结论只用来
 * 判断"量级"，不当作精确值。
 */

use TSF\Protocol\Frame\HeaderFrame;
use TSF\Protocol\Payload\JsonPayload;
use TSF\Protocol\Frame\NativeHeaderFrame;
use TSF\Protocol\Payload\RawPayload;

function bench(string $name, int $n, \Closure $fn): void
{
    // 预热，避免首次调用的分支预测/缓存影响
    for ($i = 0; $i < 1000; $i++) {
        $fn();
    }
    $t0 = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $fn();
    }
    $t1 = hrtime(true);
    echo "  " . str_pad($name, 30) . round(($t1 - $t0) / $n) . " ns" . PHP_EOL;
}

function main(): void
{
    $n = 300000;

    // 典型消息：小（技能指令）与大（状态同步）
    $small = ["uid" => 1001, "hp" => 88, "x" => 12.5, "y" => -3.25];
    $big = [];
    for ($i = 0; $i < 20; $i++) {
        $big["f" . $i] = $i * 1.5;
    }

    $raw = new RawPayload();
    $json = new JsonPayload();
    $head = new HeaderFrame();
    $nat = new NativeHeaderFrame();

    $smallJson = $json->pack(1001, $small);
    $bigJson = $json->pack(1001, $big);
    $body = str_repeat("x", 64);
    $frame = $head->encode(1001, $body, 0);

    echo "== 分帧层（8 字节头）==" . PHP_EOL;
    bench("HeaderFrame::encode (64B)", $n, function () use ($head, $body) { $head->encode(1001, $body, 0); });
    bench("HeaderFrame::decode (64B)", $n, function () use ($head, $frame) { $head->decode($frame); });
    bench("NativeHeaderFrame::encode (64B)", $n, function () use ($nat, $body) { $nat->encode(1001, $body, 0); });
    bench("NativeHeaderFrame::decode (64B)", $n, function () use ($nat, $frame) { $nat->decode($frame); });

    echo PHP_EOL . "== 消息体层：raw（透传）==" . PHP_EOL;
    bench("Raw::pack", $n, function () use ($raw, $body) { $raw->pack(1001, $body); });
    bench("Raw::unpack", $n, function () use ($raw, $body) { $raw->unpack(1001, $body); });

    echo PHP_EOL . "== 消息体层：json ==" . PHP_EOL;
    echo "  （小消息 " . strlen($smallJson) . " B，大消息 " . strlen($bigJson) . " B）" . PHP_EOL;
    bench("Json::pack 小", $n, function () use ($json, $small) { $json->pack(1001, $small); });
    bench("Json::unpack 小", $n, function () use ($json, $smallJson) { $json->unpack(1001, $smallJson); });
    bench("Json::pack 大(20字段)", $n, function () use ($json, $big) { $json->pack(1001, $big); });
    bench("Json::unpack 大(20字段)", $n, function () use ($json, $bigJson) { $json->unpack(1001, $bigJson); });

    echo PHP_EOL . "== 一帧的完整往返（编码+解码）==" . PHP_EOL;
    bench("raw: frame+payload 往返", $n, function () use ($head, $raw, $body) {
        $f = $head->encode(1001, $raw->pack(1001, $body), 0);
        $d = $head->decode($f);
        $raw->unpack(1001, $d->body);
    });
    bench("json 小: 往返", $n, function () use ($head, $json, $small) {
        $f = $head->encode(1001, $json->pack(1001, $small), 0);
        $d = $head->decode($f);
        $json->unpack(1001, $d->body);
    });
    bench("json 大: 往返", $n, function () use ($head, $json, $big) {
        $f = $head->encode(1001, $json->pack(1001, $big), 0);
        $d = $head->decode($f);
        $json->unpack(1001, $d->body);
    });
}
