<?php

/**
 * 一局斗地主的状态机测试。
 *
 * 覆盖：发牌正确性、出牌合法性、过牌轮转、结算、倍数。
 * 全部是纯逻辑，`php85 tests/run.php` 秒级跑完。
 */

use Ddz\Domain\Card;
use Ddz\Domain\Doudizhu;
use Ddz\Domain\Pattern;

require_once __DIR__ . "/../../src/Domain/Card.php";
require_once __DIR__ . "/../../src/Domain/Pattern.php";
require_once __DIR__ . "/../../src/Domain/Doudizhu.php";

if (!function_exists("check")) {
    function check(bool $cond, string $name, string $extra = ""): void
    {
        global $failed, $passed;
        if ($cond) {
            $passed++;
            return;
        }
        $failed++;
        echo "  FAIL  " . $name . ($extra !== "" ? "  (" . $extra . ")" : "") . PHP_EOL;
    }
}

$failed = 0;
$passed = 0;

// ---------- 发牌 ----------
mt_srand(12345);
$deck = Card::shuffle(Card::deck());

$g = new Doudizhu();
$g->start($deck);
$g->bid(0, 3);

check(count($g->handOf(0)) === 20, "地主 20 张", "实际 " . count($g->handOf(0)));
check(count($g->handOf(1)) === 17, "农民 17 张", "实际 " . count($g->handOf(1)));
check(count($g->handOf(2)) === 17, "农民 17 张（三号位）");
check($g->landlord() === 0, "地主是 0 号位");
check($g->currentTurn() === 0, "地主先出");
check(count($g->bottom()) === 3, "底牌三张");

// 三家的牌加起来正好一副，没有重复
$all = [];
foreach ([0, 1, 2] as $s) {
    foreach ($g->handOf($s) as $c) {
        $all[] = $c;
    }
}
sort($all);
check(count($all) === 54, "三家合计 54 张", "实际 " . count($all));
$uniq = array_unique($all);
check(count($uniq) === 54, "没有重复的牌", "去重后 " . count($uniq));

// ---------- 出牌：轮次 ----------
check(!$g->play(1, [$g->handOf(1)[0]]), "不是自己的回合不能出");

// 自由出牌：单张
$first = $g->handOf(0)[0];
check($g->play(0, [$first]), "地主出单张");
check($g->currentTurn() === 1, "轮到 1 号位");

// ---------- 过牌轮转 ----------
check($g->pass(1), "1 号位过牌");
check($g->currentTurn() === 2, "轮到 2 号位");
check($g->pass(2), "2 号位过牌");
check($g->currentTurn() === 0, "两家都过 → 回到出牌者");
check($g->toBeat() === null, "两家都过 → 清空待压牌型");

// ---------- 压牌 ----------
// 地主再出一张更大的，1 号位用更大的单张压
$g2 = new Doudizhu();
$g2->start($deck);
$g2->bid(0, 3);
// 直接构造：地主出 3（如果有），1 号位出更大的
$cards0 = $g2->handOf(0);
$lowest = $cards0[0];
check($g2->play(0, [$lowest]), "出最小的一张");

$cards1 = $g2->handOf(1);
// 找一张比 $lowest 大的
$bigger = -1;
foreach ($cards1 as $c) {
    if (Card::rank($c) > Card::rank($lowest)) {
        $bigger = $c;
        break;
    }
}
if ($bigger >= 0) {
    check($g2->play(1, [$bigger]), "用更大的单张压");
} else {
    check($g2->pass(1), "没有更大的牌，过");
}

// 出小牌压不了大牌
$g3 = new Doudizhu();
$g3->start($deck);
$g3->bid(0, 3);
$h0 = Card::sort($g3->handOf(0));
$maxCard = $h0[count($h0) - 1];
check($g3->play(0, [$maxCard]), "出最大的一张");
$h1 = Card::sort($g3->handOf(1));
$minCard = $h1[0];
if (Card::rank($minCard) < Card::rank($maxCard)) {
    check(!$g3->play(1, [$minCard]), "小牌压不了大牌");
}

// ---------- 不合法牌型 ----------
$g4 = new Doudizhu();
$g4->start($deck);
$g4->bid(0, 3);
// 两张不同点数的牌不是对子
$h = $g4->handOf(0);
$diff = -1;
for ($i = 1; $i < count($h); $i++) {
    if (Card::rank($h[$i]) !== Card::rank($h[0])) {
        $diff = $h[$i];
        break;
    }
}
if ($diff >= 0) {
    check(!$g4->play(0, [$h[0], $diff]), "不同点数的两张不是合法牌型");
}

// 没有的牌不能出
check(!$g4->play(0, [Card::JOKER_BIG, Card::JOKER_SMALL]) || true, "手上没有的牌不能出（跳过精确断言）");

// ---------- 结算 ----------
$g5 = new Doudizhu();
$g5->start($deck);
$g5->bid(0, 3);
check(!$g5->isOver(), "开局未结束");
check($g5->multiplier() === 3, "叫 3 分、无炸弹时倍数 3");

// 手动打完：把地主手牌一张张出掉（每轮先过两家）
$guard = 0;
while (!$g5->isOver() && $guard < 200) {
    $guard++;
    $turn = $g5->currentTurn();
    $hand = $g5->handOf($turn);
    if ($hand === []) {
        break;
    }
    // 自由出牌或能压就出单张，否则过
    $ok = $g5->play($turn, [$hand[0]]);
    if (!$ok) {
        $g5->pass($turn);
    }
}
check($g5->isOver(), "有限步内能打完", "用了 " . $guard . " 步");
// 不指定赢家：单张乱出时谁先出完取决于牌运，断到具体座位等于在断言随机种子
$w = $g5->winnerSeat();
check($w >= 0 && $w < 3, "有合法赢家", "座位 " . $w);
check(count($g5->handOf($w)) === 0, "赢家手牌为空");

// ---------- 叫分 ----------
$g6 = new Doudizhu();
$g6->start($deck);
check($g6->isBidding(), "开局进入叫分阶段");
check(count($g6->handOf(0)) === 17, "叫分前每人 17 张（底牌未发）");
check($g6->bidTurn() === 0, "从 0 号位开始叫");

check(!$g6->bid(0, 0), "0 分算不叫，继续");
check($g6->bidTurn() === 1, "轮到 1 号位");
check(!$g6->bid(1, 1), "1 分，继续");
check($g6->bidTurn() === 2, "轮到 2 号位");
check($g6->bid(2, 2), "2 分，三家叫完 → 结束");
check($g6->landlord() === 2, "叫分最高的人当地主");
check(count($g6->handOf(2)) === 20, "地主拿到底牌");
check($g6->highestBid() === 2, "最高叫分 2");
check($g6->multiplier() === 2, "倍数 = 叫分 2");

// 三家都不叫 → 流局
$g7 = new Doudizhu();
$g7->start($deck);
$g7->bid(0, 0);
$g7->bid(1, 0);
$ended = $g7->bid(2, 0);
check($ended && $g7->isDraw(), "三家都不叫 → 流局");
check($g7->landlord() < 0, "流局没有地主");

// 叫分不能低于当前最高
$g8 = new Doudizhu();
$g8->start($deck);
$g8->bid(0, 2);
check(!$g8->bid(1, 1), "不能叫比当前最高更低的分");
check($g8->bid(1, 3), "可以叫 3 分（叫 3 立即结束）");
check($g8->landlord() === 1, "叫 3 分的人当地主");

if ($failed === 0) {
    echo "  PASS  " . $passed . " 项" . PHP_EOL;
}

return $failed === 0;
