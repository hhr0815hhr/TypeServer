<?php

namespace Game\App;

use TSF\Cluster\Cluster;
use TSF\Cluster\Packet;
use TSF\Kernel\Logger;
use TSF\Script\Broadcast;
use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

/**
 * 集群收包泵：每 10ms 把 Cluster 的包取出来，转给规则宿主。
 *
 * ## 为什么要单独一个 Service
 *
 * `Cluster` 没有"注册回调"的接口，只有 `poll()`。不想让 RaidService 的
 * onMessage 里混进"处理网络包"这件事——那是传输层的职责，和结算逻辑无关。
 * 拆开之后，这个 Service 的失败模式很单一：poll 抛异常就丢掉这一批，
 * 下个 tick 继续，不影响结算。
 *
 * ## 为什么用 serviceOf 而不是发消息
 *
 * 跨节点重载是**低频运维动作**（一天几次），不是热路径。发一条内部消息再回包，
 * 要多定义两个消息号、多一层序列化，收益为零。这里 10ms 一次 serviceOf 只是
 * 一次数组查找。前提是**同进程**——真跨进程的调用一律走 Rpc。
 *
 * ## 回包
 *
 * `Broadcast::onMessage()` 对 RELOAD 返回 ACK 内容、对 ACK 返回空串。
 * 空串 = 不用回，非空就按 `MSG_ACK` 发回去。
 */
final class ClusterPump extends Service
{
    public const MSG_POLL = 1;

    private Cluster $cluster;

    private int $raidAddr;

    private int $intervalMs;

    private int $polls = 0;

    private int $handled = 0;

    private int $dropped = 0;

    public function __construct(Cluster $cluster, int $raidAddr, int $intervalMs = 10)
    {
        $this->cluster = $cluster;
        $this->raidAddr = $raidAddr;
        $this->intervalMs = $intervalMs > 0 ? $intervalMs : 10;
    }

    public function onStart(): void
    {
        $this->interval($this->intervalMs, self::MSG_POLL);
        Logger::info("cluster pump ready", [
            "node" => $this->cluster->nodeId(),
            "endpoint" => $this->cluster->endpoint(),
            "interval_ms" => $this->intervalMs,
        ]);
    }

    public function onMessage(Message $m): void
    {
        $msgId = $m->msgId;
        if ($msgId !== self::MSG_POLL) {
            return;
        }
        $this->polls = $this->polls + 1;

        /** @var array<int, Packet> $packets */
        $packets = $this->cluster->poll();
        foreach ($packets as $p) {
            $this->dispatch($p);
        }
        // flush 是"把排队的字节真的写出去"，漏了会出现"发出去了但对端永远收不到"
        $this->cluster->flush();
    }

    private function dispatch(Packet $p): void
    {
        $id = $p->msgId;
        if ($id !== Broadcast::MSG_RELOAD && $id !== Broadcast::MSG_ACK) {
            $this->dropped = $this->dropped + 1;
            return;
        }
        $data = $p->data;
        if (!is_string($data)) {
            $this->dropped = $this->dropped + 1;
            return;
        }

        $svc = Scheduler::instance()->serviceOf($this->raidAddr);
        if (!$svc instanceof RaidService) {
            $this->dropped = $this->dropped + 1;
            return;
        }

        $reply = $svc->onClusterMessage($id, $data);
        $this->handled = $this->handled + 1;
        if ($reply !== "") {
            // 回给发件人的完整地址（含节点号），不是节点号
            $this->cluster->send($p->src, 0, Broadcast::MSG_ACK, $reply);
        }
    }

    public function stats(): string
    {
        return "node=" . $this->cluster->nodeId()
            . " peers=" . count($this->cluster->peerNodes())
            . " polls=" . $this->polls
            . " handled=" . $this->handled
            . " dropped=" . $this->dropped;
    }
}
