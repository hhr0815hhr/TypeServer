<?php

namespace Probe;

/** 消息信封：字段都有类型，payload 用联合类型而不是 mixed */
final class Message
{
    public int $from = 0;

    public int $session = 0;

    public int $msgId = 0;

    public int $enqueuedAt = 0;

    public string|array|object $data = "";

    public function __construct(int $from, int $msgId, string|array|object $data, int $session = 0, int $at = 0)
    {
        $this->from = $from;
        $this->msgId = $msgId;
        $this->data = $data;
        $this->session = $session;
        $this->enqueuedAt = $at;
    }
}
