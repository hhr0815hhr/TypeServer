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
        if ($msgId === 3001) {
            return LoginReq::decode($buf);
        }
        if ($msgId === 3002) {
            return LoginResp::decode($buf);
        }
        if ($msgId === 3003) {
            return CollectReq::decode($buf);
        }
        if ($msgId === 3004) {
            return CollectResp::decode($buf);
        }
        if ($msgId === 3005) {
            return RankReq::decode($buf);
        }
        if ($msgId === 3006) {
            return RankResp::decode($buf);
        }
        if ($msgId === 3101) {
            return RankItem::decode($buf);
        }
        if ($msgId === 3007) {
            return MailListReq::decode($buf);
        }
        if ($msgId === 3008) {
            return MailListResp::decode($buf);
        }
        if ($msgId === 3102) {
            return MailInfo::decode($buf);
        }
        if ($msgId === 3009) {
            return MailClaimReq::decode($buf);
        }
        if ($msgId === 3010) {
            return MailClaimResp::decode($buf);
        }
        if ($msgId === 3103) {
            return Item::decode($buf);
        }
        if ($msgId === 3011) {
            return ErrorMsg::decode($buf);
        }
        return null;
    }
}
