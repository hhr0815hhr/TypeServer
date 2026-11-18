<?php

namespace Gen\Proto;

/**
 * 协议号常量。**自动生成，不要手改**。
 *
 * 数字来自 .proto 里每个 message 上方的 `// @id N` 注释。
 */
final class Msg
{
    public const LOGIN_REQ = 3001;
    public const LOGIN_RESP = 3002;
    public const COLLECT_REQ = 3003;
    public const COLLECT_RESP = 3004;
    public const RANK_REQ = 3005;
    public const RANK_RESP = 3006;
    public const RANK_ITEM = 3101;
    public const MAIL_LIST_REQ = 3007;
    public const MAIL_LIST_RESP = 3008;
    public const MAIL_INFO = 3102;
    public const MAIL_CLAIM_REQ = 3009;
    public const MAIL_CLAIM_RESP = 3010;
    public const ITEM = 3103;
    public const ERROR_MSG = 3011;

    /** 数字 → 名字，日志/抓包用 */
    public static function name(int $msgId): string
    {
        $m = self::all();
        return $m[$msgId] ?? ("#" . $msgId);
    }

    /** @return array<int, string> */
    public static function all(): array
    {
        return [
            3001 => "LoginReq",
            3002 => "LoginResp",
            3003 => "CollectReq",
            3004 => "CollectResp",
            3005 => "RankReq",
            3006 => "RankResp",
            3101 => "RankItem",
            3007 => "MailListReq",
            3008 => "MailListResp",
            3102 => "MailInfo",
            3009 => "MailClaimReq",
            3010 => "MailClaimResp",
            3103 => "Item",
            3011 => "ErrorMsg",
        ];
    }
}
