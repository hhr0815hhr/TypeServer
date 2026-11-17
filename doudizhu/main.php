<?php

// 自测：飞机带牌 + 完整一局（跑到结算）

use Ddz\Card;
use Ddz\DdzService;
use Ddz\Msg;
use Ddz\Pattern;
use TSF\Service\Message;

function ids(string $spec): array
{
    $out = [];
    $used = [];
    foreach (explode(" ", trim($spec)) as $tok) {
        $rank = rankByName($tok);
        if ($rank === Card::SMALL_JOKER || $rank === Card::BIG_JOKER) {
            $out[] = $rank;
        } else {
            $n = $used[$rank] ?? 0;
            $out[] = $rank * 4 + $n;
            $used[$rank] = $n + 1;
        }
    }
    return $out;
}

function rankByName(string $t): int
{
    if ($t === "SJ") { return Card::SMALL_JOKER; }
    if ($t === "BJ") { return Card::BIG_JOKER; }
    $m = ["3" => 0, "4" => 1, "5" => 2, "6" => 3, "7" => 4, "8" => 5, "9" => 6,
          "10" => 7, "J" => 8, "Q" => 9, "K" => 10, "A" => 11, "2" => 12];
    return $m[$t] ?? -1;
}

function check(string $spec, int $expect, string $desc): void
{
    $p = Pattern::parse(ids($spec));
    if ($p === null) {
        echo ($expect === Pattern::INVALID ? "  OK   " : "  FAIL ") . $desc . "（判为非法）" . PHP_EOL;
        return;
    }
    echo ($p->type === $expect ? "  OK   " : "  FAIL ") . $desc
        . " → " . $p->name() . " len=" . $p->len . PHP_EOL;
}

function probePatterns(): void
{
    echo "=== 飞机带牌 ===" . PHP_EOL;
    check("3 3 3 4 4 4 5 6", Pattern::PLANE_ONE, "333444 + 5 6（带单）");
    check("3 3 3 4 4 4 5 5 6 6", Pattern::PLANE_PAIR, "333444 + 55 66（带对）");
    check("3 3 3 4 4 4 5 5 5 6 7 8", Pattern::PLANE_ONE, "333444555 + 678（三组）");
    check("3 3 3 4 4 4 5 5", Pattern::INVALID, "333444 + 5（带牌数量不对）");
    check("3 3 3 4 4 4 5 6 6", Pattern::INVALID, "带单却混进了对子");
    check("3 3 3 3 4 4 4 5", Pattern::INVALID, "用炸弹拆飞机（3333 不能当 333）");
    check("3 3 3 4 4 4 5 6 7 7", Pattern::INVALID, "带单多一张");
    check("2 2 2 A A A 5 6", Pattern::INVALID, "含 2 的飞机");

    echo PHP_EOL . "=== 牌型回归 ===" . PHP_EOL;
    check("3 4 5 6 7", Pattern::STRAIGHT, "顺子");
    check("3 3 3 4 4 4", Pattern::PLANE, "纯飞机");
    check("SJ BJ", Pattern::ROCKET, "王炸");
}

function send(DdzService $svc, int $uid, int $fd, int $msgId, array $p): void
{
    $svc->onMessage(new Message(0, $msgId, json_encode([
        "uid" => $uid, "fd" => $fd, "p" => $p,
    ])));
}

function probeSettle(): void
{
    echo PHP_EOL . "=== 完整一局（地主连续出单张，农民一直过，直到结算）===" . PHP_EOL;

    $log = [];
    $publish = function (int $fd, int $msgId, string|array|object $data) use (&$log): void {
        $log[] = ["fd" => $fd, "msg" => $msgId,
                  "data" => is_string($data) ? $data : (string) json_encode($data)];
    };
    $svc = new DdzService($publish);

    $i = 0;
    while ($i < 3) {
        send($svc, 1001 + $i, 10 + $i, Msg::SEAT, []);
        $i++;
    }
    $i = 0;
    while ($i < 3) {
        send($svc, 1001 + $i, 10 + $i, Msg::READY, []);
        $i++;
    }
    // 座位 0 叫地主
    send($svc, 1001, 10, Msg::CALL, ["call" => true]);

    // 取地主手牌
    $hand = [];
    foreach ($log as $row) {
        if ($row["msg"] === Msg::HAND && $row["fd"] === 10) {
            $d = json_decode($row["data"], true);
            if (count($d["cards"] ?? []) > 0) {
                $hand = $d["cards"];
            }
        }
    }
    echo "  地主起手: " . count($hand) . " 张" . PHP_EOL;
    $log = [];

    // 地主每次出最小的一张，两个农民过
    $rounds = 0;
    $settled = "";
    while ($hand !== [] && $rounds < 30) {
        $one = $hand[0];
        send($svc, 1001, 10, Msg::PLAY, ["cards" => [$one]]);

        $rejected = "";
        $newHand = $hand;
        foreach ($log as $row) {
            if ($row["msg"] === Msg::ERROR && $row["fd"] === 10) {
                $rejected = $row["data"];
            }
            if ($row["msg"] === Msg::HAND && $row["fd"] === 10) {
                $d = json_decode($row["data"], true);
                $newHand = $d["cards"] ?? [];
            }
            if ($row["msg"] === Msg::SETTLED) {
                $settled = $row["data"];
            }
        }
        $log = [];

        if ($rejected !== "") {
            echo "  出牌被拒（第 " . ($rounds + 1) . " 轮）: " . $rejected . PHP_EOL;
            break;
        }
        $hand = $newHand;

        if ($settled !== "") {
            break;
        }

        // 两个农民过
        send($svc, 1002, 11, Msg::PASS, []);
        send($svc, 1003, 12, Msg::PASS, []);
        foreach ($log as $row) {
            if ($row["msg"] === Msg::SETTLED) {
                $settled = $row["data"];
            }
        }
        $log = [];
        $rounds++;
    }

    echo "  出牌轮数: " . $rounds . "，剩余手牌: " . count($hand) . " 张" . PHP_EOL;
    if ($settled === "") {
        echo "  FAIL 没有触发结算" . PHP_EOL;
        return;
    }
    $d = json_decode($settled, true);
    echo "  结算: 地主" . ($d["landlordWin"] ? "胜" : "负")
        . " 倍数×" . $d["mult"]
        . " 春天=" . ($d["spring"] === "" ? "否" : $d["spring"]) . PHP_EOL;
    foreach ($d["rows"] as $r) {
        echo "    座位" . $r["seat"] . " " . ($r["win"] ? "赢 " : "输 ")
            . $r["delta"] . "（剩 " . $r["left"] . "）" . PHP_EOL;
    }
    // 校验：地主 1 打 2，农民赢则地主 -2×score，两个农民各 +score
    $sum = 0;
    foreach ($d["rows"] as $r) {
        $sum += $r["delta"];
    }
    echo "  分数守恒（应为 0）: " . $sum . ($sum === 0 ? " OK" : " FAIL") . PHP_EOL;
}

function main(): void
{
    probePatterns();
    probeSettle();
}
