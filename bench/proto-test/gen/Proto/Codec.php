<?php

namespace Gen\Proto;

use TSF\Proto\Message;

/**
 * 按协议号派发到具体的消息类。**自动生成，不要手改**。
 *
 * 返回 `?Message`：不认识的协议号返回 null，由调用方决定是丢包还是报错。
 * 这里不抛异常——收到一个未来版本才有的协议号是正常现象（灰度发布），
 * 不该因此断连接。
 */
final class Codec
{
    public static function decode(int $msgId, string $buf): ?Message
    {
        if ($msgId === 1001) {
            return Vector::decode($buf);
        }
        if ($msgId === 1002) {
            return StrMsg::decode($buf);
        }
        if ($msgId === 1003) {
            return NegMsg::decode($buf);
        }
        if ($msgId === 1004) {
            return FloatMsg::decode($buf);
        }
        if ($msgId === 1005) {
            return PackedMsg::decode($buf);
        }
        if ($msgId === 1010) {
            return Item::decode($buf);
        }
        if ($msgId === 1011) {
            return Bag::decode($buf);
        }
        if ($msgId === 1012) {
            return Everything::decode($buf);
        }
        return null;
    }
}
