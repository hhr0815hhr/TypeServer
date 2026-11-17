<?php

namespace Gen\Proto;

use TSF\Proto\Message;
use TSF\Proto\Reader;
use TSF\Proto\Wire;

/**
 * MailListResp（协议号 3008）
 *
 * **自动生成，不要手改**。源是 .proto，改完跑 tsf proto build。
 */
final class MailListResp extends Message
{
    /** @var MailInfo[] */
    public array $mails = [];


    public function msgId(): int
    {
        return 3008;
    }

    public function encode(): string
    {
        $b = "";
        foreach ($this->mails as $v_mails) {
            $b .= Wire::tag(1, 2) . Wire::bytes($v_mails->encode());
        }
        return $b;
    }

    public static function decode(string $buf): MailListResp
    {
        $m = new MailListResp();
        $r = new Reader($buf, "MailListResp");
        while ($r->next()) {
            $f = $r->field();
            if ($f === 1) {
                $m->mails[] = MailInfo::decode($r->string());
            }
else {
                $r->skip();
            }
        }
        return $m;
    }
}
