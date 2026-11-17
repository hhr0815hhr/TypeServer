<?php

// 验证 Room 不再内置任何状态语义：
//   1) 不传状态机 → 裸状态值，业务自己 setState
//   2) 注入自定义状态机 → 斗地主四段（等待/叫地主/出牌/结算）
//   3) 用 ThreeStage 辅助类 → 默认三段式仍然可用（duel 走这条路）

use TSF\Fsm\Machine;
use TSF\Room\Member;
use TSF\Room\Room;
use TSF\Room\ThreeStage;

function probeBareState(): void
{
    echo "=== 1) 不传状态机（裸状态）===" . PHP_EOL;
    $r = new Room(1, 3);
    echo "  初始 state=" . $r->state() . " name=" . $r->stateName() . PHP_EOL;

    // 业务自己定义的「叫地主」
    $r->setState(5);
    $r->setStateName(5, "calling");
    echo "  setState(5) 后 name=" . $r->stateName() . PHP_EOL;
    echo "  fsm() 是 null: " . ($r->fsm() === null ? "yes" : "no") . PHP_EOL;
}

function probeCustomFsm(): void
{
    echo PHP_EOL . "=== 2) 注入自定义状态机（斗地主四段）===" . PHP_EOL;

    // 业务定义自己的状态号与转移，框架一概不参与
    $fsm = new Machine(10);
    $fsm->allow(10, "call", 20)      // 等待 → 叫地主
        ->allow(20, "start", 30)      // 叫地主 → 出牌
        ->allow(30, "settle", 40);    // 出牌 → 结算

    $r = new Room(2, 3, $fsm);
    $r->setStateName(10, "waiting");
    $r->setStateName(20, "calling");
    $r->setStateName(30, "playing");
    $r->setStateName(40, "settled");

    echo "  初始: " . $r->stateName() . " (state=" . $r->state() . ")" . PHP_EOL;

    $r->fsm()->fire("call");
    echo "  fire(call): " . $r->stateName() . " (state=" . $r->state() . ")" . PHP_EOL;

    $r->fsm()->fire("start");
    echo "  fire(start): " . $r->stateName() . PHP_EOL;

    // Room 不判断能不能进人 —— 出牌阶段依然允许（业务自己要不要拦是业务的事）
    echo "  出牌阶段进人: " . ($r->add(1001) ? "ok（Room 不拦，业务自己判断）" : "no") . PHP_EOL;

    $r->fsm()->fire("settle");
    echo "  fire(settle): " . $r->stateName() . PHP_EOL;
    $r->close();
    echo "  close() 后进人: " . ($r->add(1002) ? "ok" : "no（已关闭，这个 Room 确实知道）") . PHP_EOL;
}

function probeThreeStage(): void
{
    echo PHP_EOL . "=== 3) ThreeStage 默认三段式（duel 走这条）===" . PHP_EOL;
    $r = ThreeStage::room(3, 4);
    echo "  初始: " . $r->stateName() . PHP_EOL;
    $r->fsm()->fire("start");
    echo "  fire(start): " . $r->stateName() . PHP_EOL;
    $r->fsm()->fire("finish");
    echo "  fire(finish): " . $r->stateName() . PHP_EOL;

    // 帧推进：Room 不再按状态判断，调用方自己决定
    echo "  tick(50): " . ($r->tick(50) ? "ok" : "blocked") . " frame=" . $r->frame() . PHP_EOL;
}

function probeSnapshot(): void
{
    echo PHP_EOL . "=== 4) 快照：自定义状态能正确恢复 ===" . PHP_EOL;
    $fsm = new Machine(10);
    $fsm->allow(10, "call", 20);
    $src = new Room(4, 3, $fsm);
    $src->setStateName(20, "calling");
    $src->fsm()->fire("call");
    $src->add(1001, "甲");

    $data = $src->toArray();
    echo "  导出 state=" . $data["state"] . " members=" . count($data["members"]) . PHP_EOL;

    $fsm2 = new Machine(10);
    $fsm2->allow(10, "call", 20);
    $dst = new Room(5, 3, $fsm2);
    $dst->setStateName(20, "calling");
    $ok = $dst->loadFrom($data);
    echo "  恢复: " . ($ok ? "ok" : "fail")
        . " → state=" . $dst->stateName() . " (state=" . $dst->state() . ")"
        . " members=" . $dst->memberCount() . PHP_EOL;
}

function main(): void
{
    probeBareState();
    probeCustomFsm();
    probeThreeStage();
    probeSnapshot();
}
