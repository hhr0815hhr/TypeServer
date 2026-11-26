<?php

namespace Ddz\Protocol;

/**
 * 大厅里的一条房间信息（给"房间列表"用）。
 *
 * 只有展示需要的字段——真实项目里可能还有底分、人数上限、创建时间等。
 */
final class RoomInfo
{
    public int $roomId = 0;

    public int $level = 1;

    /** 已入座人数 */
    public int $seated = 0;

    /** 0=等待 1=对局中 */
    public int $phase = 0;

    /** 房主 uid */
    public int $owner = 0;
}
