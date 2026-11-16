<?php

namespace Bench;

use TSF\Service\Message;
use TSF\Service\Service;

/** 只计数：用来测纯粹的"投递+派发"吞吐，不含业务逻辑 */
final class CountService extends Service
{
    public int $count = 0;

    public function onMessage(Message $m): void
    {
        $this->count++;
    }
}

/** 记录每条消息的到达时刻：用来算队列等待 + 派发延迟 */
final class LatencyService extends Service
{
    /** @var array<int, int> */
    public array $recvAt = [];

    public function onMessage(Message $m): void
    {
        $idx = 0;
        if (is_string($m->data)) {
            $idx = (int) $m->data;
        }
        $this->recvAt[$idx] = hrtime(true);
    }
}
