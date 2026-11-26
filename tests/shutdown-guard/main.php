<?php

use TSF\Kernel\Bootstrap;
use TSF\Kernel\Logger;
use TSF\Protocol\Frame\HeaderFrame;
use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;
use TSF\Transport\Tcp\TcpServer;

/**
 * 停机守卫服务端：进程收到 SIGTERM 之后，该做的事有没有做。
 *
 * 三个服务按 A → B → C 的顺序创建（A 依赖 B）：
 *   A（Depended）被依赖方，停机时必须**最后**停；它的 onStop 里验证"停机后不能再发/建/定时"
 *   B（Worker）    记录自己一共处理了多少条消息
 *   C（Bad）       onStop 直接抛异常——不能因为它抛了就不停别的
 *
 * 过程写进 TRACE 文件，run.sh 按文件内容判定。环境变量：
 *   SG_MAX_WAIT  传给 Swoole 的 max_wait_time（秒），不设 = 0（不等窗口）
 *   SG_DRAIN     排空预算（毫秒），不设 = 500；off = 不排空
 */
const TRACE = "/tmp/shutdown_guard_trace.log";

function trace(string $line): void
{
    file_put_contents(TRACE, $line . "\n", FILE_APPEND);
}

final class Depended extends Service
{
    /** B 的地址；main 里注入 */
    public int $peer = 0;

    public function onStart(): void
    {
        $this->interval(20, 8001);
    }

    public function onMessage(Message $m): void
    {
    }

    public function onStop(): void
    {
        $sched = Scheduler::instance();
        // 停机之后：投递必须被拒（不能往一个没人读的信箱里塞）、定时器注册返回 0、不能再建服务
        $sent = $this->send($this->peer, 8002, "late");
        $timer = $this->interval(20, 8003);
        $spawned = 0;
        try {
            $spawned = $sched->spawnOf(function (): Depended {
                return new Depended();
            });
        } catch (\Throwable $e) {
            $spawned = -1;
        }
        trace("A sent=" . ($sent ? "1" : "0") . " timer=" . $timer . " spawn=" . $spawned);
    }
}

final class Worker extends Service
{
    public int $handled = 0;

    public function onMessage(Message $m): void
    {
        if ($m->msgId === 8001) {
            $this->handled++;
        }
    }

    public function onStop(): void
    {
        trace("B handled=" . $this->handled);
    }
}

final class Bad extends Service
{
    public function onMessage(Message $m): void
    {
    }

    public function onStop(): void
    {
        trace("C");
        throw new \RuntimeException("boom in onStop");
    }
}

function main(): void
{
    Bootstrap::boot(__DIR__, "app");
    // 环境变量先转成字符串再判断：`(int) (getenv(..) ?: "500")` 会把 "0" 当假值回落成默认值，
    // "不排空"场景就会静默变成"排空 500ms"——断言全错却看不出原因。排空预算 0 用 SG_DRAIN=off 表示。
    $maxWaitRaw = (string) getenv("SG_MAX_WAIT");
    $drainRaw = (string) getenv("SG_DRAIN");
    $maxWait = $maxWaitRaw === "" ? 0 : (int) $maxWaitRaw;
    $drain = 500;
    if ($drainRaw === "off") {
        $drain = 0;
    } elseif ($drainRaw !== "") {
        $drain = (int) $drainRaw;
    }

    $server = new TcpServer("127.0.0.1", 9415, new HeaderFrame(), [
        "max_conn" => 64,
        "max_wait_time" => $maxWait,
    ]);
    $server->setDrainMs($drain);
    $sched = Scheduler::instance();

    $a = $sched->spawnOf(function (): Depended {
        return new Depended();
    });
    $b = $sched->spawnOf(function (): Worker {
        return new Worker();
    });
    $c = $sched->spawnOf(function (): Bad {
        return new Bad();
    });
    $svcA = $sched->serviceOf($a);
    if ($svcA instanceof Depended) {
        $svcA->peer = $b;
    }

    // 外部打进来的帧都交给 B
    $server->onFrame(function (int $fd, int $msgId, string $body, int $flags) use ($sched, $b): void {
        $sched->deliverAs(0, $b, 8001, $body, $fd);
    });

    // 服务停机**之前**的钩子：这里排进信箱的消息，由排空阶段处理
    $server->onShutdown(function (int $workerId) use ($sched, $b): void {
        trace("server-hook");
        for ($i = 0; $i < 100; $i++) {
            $sched->deliverAs(0, $b, 8001, "q");
        }
    });
    // 所有服务都停了**之后**的钩子
    $sched->onShutdown(function (): void {
        trace("scheduler-hook");
    });

    Logger::info("shutdown-guard ready", ["services" => $sched->serviceCount(), "c" => $c]);
    $server->start();
}
