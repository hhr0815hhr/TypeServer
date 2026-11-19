<?php

namespace Game\Battle;

use TSF\Gateway\Gateway;
use TSF\Gateway\SessionManager;
use TSF\Service\Message;
use TSF\Service\Service;

/**
 * 推送出口：把"房间算出来的结果"翻译成"某个 uid 的某个 fd 上的一帧"。
 *
 * 为什么单独做成一个 Service 而不是让 BattleRoom 直接调 Gateway：
 *
 *   1. **保持房间不依赖网络**。BattleRoom 只产出事件和 uid 列表，
 *      它不知道 fd、不知道协议、不知道有人可能已经掉线。
 *      换传输层（TCP→WS、单机→网关集群）时房间代码一行不动。
 *   2. **发送失败有地方统计**。Backpressure、连接已关、限流丢弃，
 *      这些都在这一层收敛，房间侧不需要为它们写分支。
 *
 * 代价是每次推送多一条 Scheduler 消息（约 100ns 级）。相对于一次
 * 网络发送（微秒级），这个开销可以忽略，换来的是清晰的边界。
 */
final class Output extends Service
{
    public const MSG_PUSH = 1;

    /** 批量推送：载荷是多行 `协议号;uid1,uid2;body`，一行一条 */
    public const MSG_BATCH = 2;

    private Gateway $gw;

    private SessionManager $sessions;

    private int $sent = 0;

    private int $dropped = 0;

    private int $noConn = 0;

    private int $batches = 0;

    private int $bridged = 0;

    /**
     * 跨 worker 广播闭包：`function (string $data): void`。
     *
     * 多 worker 时，玩家可能连在别的 worker 上 —— 本 worker 的 SessionManager
     * 里没有他的 fd。这时把"发不出去的 uid + 消息体"广播给其他 worker，
     * 由持有该连接的 worker 完成发送。
     *
     * 为什么广播而不是维护 uid → worker 路由表：
     *   路由表（Swoole\Table）要处理登录写入、断线清理、进程重启后的残留，
     *   而广播只需一个条件："你有这个 uid 就发，没有就丢"。
     *   worker 数在个位数时，广播的浪费远小于路由表出错的代价。
     *   超过 8 个 worker 再考虑路由表。
     */
    private ?\Closure $broadcast;

    public function __construct(Gateway $gw, SessionManager $sessions, ?\Closure $broadcast = null)
    {
        $this->gw = $gw;
        $this->sessions = $sessions;
        $this->broadcast = $broadcast;
    }

    /**
     * 载荷格式：`协议号;uid1,uid2,...;消息体`
     *
     * 用字符串而不是数组，是为了让"一次编码、多人广播"成立：
     * 消息体只序列化一次，多个接收方共用同一段字节。
     * 如果传数组，每条消息都要在接收端重新 pack 一遍，N 个接收方就是 N 次。
     */
    public function onMessage(Message $m): void
    {
        $d = $m->data;
        if (!is_string($d) || $d === "") {
            return;
        }
        $msgId = $m->msgId;
        if ($msgId === self::MSG_BATCH) {
            // 一帧一条消息，内部再拆。拆的粒度只影响这一层的循环次数，
            // 不影响 Scheduler（这才是关键：消息数从"每事件一条"降到"每帧一条"）
            $lines = explode("\n", $d);
            foreach ($lines as $line) {
                if ($line !== "") {
                    $this->deliver($line);
                }
            }
            return;
        }
        if ($msgId === self::MSG_PUSH) {
            $this->deliver($d);
        }
    }

    /** 解析一行 `协议号;uid1,uid2;body` 并逐人发送 */
    private function deliver(string $payload): void
    {
        $parts = explode(";", $payload, 3);
        if (count($parts) !== 3) {
            return;
        }
        $proto = (int) $parts[0];
        $body = $parts[2];
        if ($body === "") {
            return;
        }

        $this->batches++;
        $uids = explode(",", $parts[1]);
        $missing = [];
        foreach ($uids as $u) {
            $uid = (int) $u;
            if ($uid <= 0) {
                continue;
            }
            $fd = $this->sessions->fdOf($uid);
            if ($fd <= 0) {
                // 本 worker 上没这个连接。不一定是错——多 worker 时他可能连在别的进程上
                $missing[] = $uid;
                continue;
            }
            if ($this->gw->send($fd, $proto, $body)) {
                $this->sent++;
            } else {
                $this->dropped++;
            }
        }

        if ($missing === []) {
            return;
        }
        $this->noConn += count($missing);
        $cb = $this->broadcast;
        if ($cb === null) {
            return;
        }
        // 交给其他 worker：它们各自查自己的会话表，谁有就谁发
        $this->bridged++;
        $cb("out|" . $proto . "|" . implode(",", $missing) . "|" . $body);
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        return [
            "batches" => $this->batches,
            "sent" => $this->sent,
            "dropped" => $this->dropped,
            "no_conn" => $this->noConn,
            "bridged" => $this->bridged,
        ];
    }
}
