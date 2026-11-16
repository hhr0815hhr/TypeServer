<?php

/**
 * T6-06 的被观察对象。
 *
 * 启动时打一行版本号，然后一直跑——watch 要靠这一行判断"新版本起来了"。
 * 版本号在 `boot version=N` 里，测试脚本改的就是这个 N。
 */

function main(): void
{
    
    
    
    
    
    
    
    
    
    echo "boot version=1 pid=" . posix_getpid() . " at " . (int) (microtime(true) * 1000) . PHP_EOL;

    // 20 秒，够测试改一次文件并等到重启
    $deadline = hrtime(true) + 20000000000;
    while (hrtime(true) < $deadline) {
        usleep(50000);
    }
}
// x

// probe

// probe2

// c1
