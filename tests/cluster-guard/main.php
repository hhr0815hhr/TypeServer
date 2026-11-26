<?php

use TSF\Cluster\Cluster;
use TSF\Kernel\Bootstrap;
use TSF\Kernel\Logger;
use TSF\Protocol\Frame\HeaderFrame;
use TSF\Protocol\Msg;
use TSF\Protocol\Payload\JsonPayload;
use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;
use TSF\Transport\Tcp\TcpServer;

/**
 * 集群守卫服务端：集群通道收到"能通过握手、但载荷有问题"的包之后，节点还转不转、
 * 这条链路会不会变成黑洞。
 *
 * - Ticker：每 200ms 往 stderr 打一行 TICK。它停了 = Scheduler::tick 不再往下走。
 * - Echoer：经 9412 的 TCP 入口回包。它不应答 = 网络路径也被拖住。
 * - Cluster：9413，codec=json，secret 为空（只测结构，不测密钥）。
 *
 * 对端由 client.php（解释执行）扮演。
 */
final class Ticker extends Service
{
    private int $n = 0;

    public function onStart(): void
    {
        $this->interval(200, 7001);
    }

    public function onMessage(Message $m): void
    {
        if ($m->msgId !== 7001) {
            return;
        }
        $this->n++;
        fwrite(STDERR, "TICK " . $this->n . "\n");
    }
}

final class Echoer extends Service
{
    private TcpServer $server;

    public function __construct(TcpServer $s)
    {
        $this->server = $s;
    }

    public function onMessage(Message $m): void
    {
        $body = is_string($m->data) ? $m->data : "";
        $this->server->sendFrame($m->session, Msg::ECHO_RESP, $body);
    }
}

function main(): void
{
    $base = __DIR__;
    Bootstrap::boot($base, "app");
    $frame = new HeaderFrame();
    $server = new TcpServer("127.0.0.1", 9412, $frame, ["max_conn" => 64]);
    $sched = Scheduler::instance();

    $cluster = new Cluster(1, "tcp://127.0.0.1:9413", new JsonPayload(), "");
    $cluster->listen();
    $sched->setCluster($cluster);

    $sched->spawnOf(function (): Ticker {
        return new Ticker();
    });
    $echoAddr = $sched->spawnOf(function () use ($server): Echoer {
        return new Echoer($server);
    });

    $server->onFrame(function (int $fd, int $msgId, string $body, int $flags) use ($sched, $echoAddr): void {
        $sched->deliver($echoAddr, $msgId, $body, $fd);
    });
    Logger::info("cluster-guard ready");
    $server->start();
}
