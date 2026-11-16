<?php

use TSF\Gateway\RateLimiter;
use TSF\Gateway\SessionManager;

/**
 * 网关每帧开销基准（重构后）。
 *
 * 对照：AOT 下基础操作成本 isset 205ns / 数组读 153ns / 写 174ns / time() 129ns /
 * microtime 233ns。所以热路径的设计目标不是"少写几行"，而是"少做几次哈希查找"。
 */

function main(): void
{
    $frames = 2000000;
    $fd = 7;

    $sessions = new SessionManager(30);
    $limiter = new RateLimiter(100000, 100000);
    $sessions->open($fd, time());
    $sessions->bind($fd, 1001, "tok", time());
    $limiter->open($fd);
    $now = time();

    // 1) 重构后的每帧记账：一次方法调用完成 touch+计数，加一次 take
    $t0 = hrtime(true);
    for ($i = 0; $i < $frames; $i++) {
        $sessions->touchFrame($fd, $now);
        $limiter->take($fd);
    }
    $t1 = hrtime(true);
    $perFrame = ($t1 - $t0) / $frames;
    echo "每帧记账(合并版):        " . round($perFrame) . " ns" . PHP_EOL;

    // 2) 拆开测，看两项各自的份额
    $t0 = hrtime(true);
    for ($i = 0; $i < $frames; $i++) {
        $sessions->touchFrame($fd, $now);
    }
    $t1 = hrtime(true);
    echo "  会话 touchFrame:       " . round(($t1 - $t0) / $frames) . " ns" . PHP_EOL;

    $t0 = hrtime(true);
    for ($i = 0; $i < $frames; $i++) {
        $limiter->take($fd);
    }
    $t1 = hrtime(true);
    echo "  限流 take:             " . round(($t1 - $t0) / $frames) . " ns" . PHP_EOL;

    // 3) 每秒一次的批量补充（不同连接数）
    foreach ([1000, 5000, 10000] as $n) {
        $lim = new RateLimiter(50, 100);
        for ($i = 1; $i <= $n; $i++) {
            $lim->open($i);
        }
        $t0 = hrtime(true);
        for ($k = 0; $k < 100; $k++) {
            $lim->refillAll();
        }
        $t1 = hrtime(true);
        echo "每秒补充 " . str_pad((string) $n, 6) . " 连接:  " . round(($t1 - $t0) / 100 / 1000, 2) . " us/次" . PHP_EOL;
    }

    // 4) 空闲扫描
    foreach ([1000, 5000, 10000] as $n) {
        $m = new SessionManager(30);
        for ($i = 1; $i <= $n; $i++) {
            $m->open($i, time());
        }
        $t0 = hrtime(true);
        $m->sweep(time());
        $t1 = hrtime(true);
        echo "空闲扫描 " . str_pad((string) $n, 6) . " 连接:  " . round(($t1 - $t0) / 1000, 1) . " us/次" . PHP_EOL;
    }
}
