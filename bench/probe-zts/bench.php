<?php

/**
 * ZTS vs NTS 开销基准。
 *
 * 只测 TSF 热路径上用到的四类操作，跑 3 轮取最快值（排除调度噪声）。
 *
 * 注意：本机只有 8.3 NTS 和 8.5 ZTS，版本不同会干扰结论。
 * 所以判读方式是：如果 8.5 ZTS 相对 8.3 NTS 没有明显领先，
 * 说明 ZTS 的 TSRM 开销吃掉了版本红利——而不是"8.5 本来就慢"。
 */

const N = 3000000;
const NS = 300000;

class EntityLike
{
    public int $x = 0;
    public int $y = 0;
    public int $vx = 1;
    public int $vy = 1;
    public int $hp = 100;
}

function f_dispatch(int $x): int
{
    return $x + 1;
}

function runOnce(): array
{
    // 1. 对象属性读写——Scheduler 每批消息、Room 每帧实体都走这条路
    $t = hrtime(true);
    $e = new EntityLike();
    for ($i = 0; $i < N; $i++) {
        $e->x += $e->vx;
        $e->y += $e->vy;
        $e->hp -= 1;
    }
    $obj = (hrtime(true) - $t) / 1e6;

    // 2. 数组当队列——Mailbox 的 push/pop
    $t = hrtime(true);
    $q = [];
    $head = 0;
    for ($i = 0; $i < N; $i++) {
        $q[] = $i;
        if ($i % 8 === 7) {
            while ($head < count($q)) {
                $v = $q[$head];
                $head++;
            }
            $q = [];
            $head = 0;
        }
    }
    $queue = (hrtime(true) - $t) / 1e6;

    // 3. 字符串拼接收包——拆包循环
    $t = hrtime(true);
    $buf = "";
    for ($i = 0; $i < NS; $i++) {
        $buf .= "\x04\x00\x00\x00ABCD";
        if (strlen($buf) > 4096) {
            $buf = substr($buf, 4096);
        }
    }
    $str = (hrtime(true) - $t) / 1e6;

    // 4. 函数派发——onMessage 每条的调用开销
    $t = hrtime(true);
    $r = 0;
    for ($i = 0; $i < N; $i++) {
        $r = f_dispatch($r);
    }
    $call = (hrtime(true) - $t) / 1e6;

    return [$obj, $queue, $str, $call];
}

$best = null;
for ($round = 0; $round < 3; $round++) {
    $r = runOnce();
    if ($best === null) {
        $best = $r;
        continue;
    }
    for ($k = 0; $k < 4; $k++) {
        if ($r[$k] < $best[$k]) {
            $best[$k] = $r[$k];
        }
    }
}

printf(
    "对象属性 %7.1f ms | 数组队列 %7.1f ms | 字符串 %7.1f ms | 函数调用 %7.1f ms\n",
    $best[0],
    $best[1],
    $best[2],
    $best[3]
);
