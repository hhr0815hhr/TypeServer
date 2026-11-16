<?php

namespace Duel;

use TSF\Db\Col;
use TSF\Db\Id;
use TSF\Db\Table;

/**
 * 对局记录。这是 `pkg/db` 在真实服务里的用法示范。
 *
 * 注意属性名用 camelCase、列名用 snake_case：数据库侧的命名习惯不该
 * 反过来污染业务代码，`#[Col("winner_hp")]` 就是为这种分歧准备的。
 */
#[Table("duel_records")]
final class DuelRecord
{
    #[Id(true)]
    public int $id = 0;

    #[Col]
    public int $room = 0;

    /** 胜者 uid；-1 表示平局 */
    #[Col]
    public int $winner = 0;

    #[Col]
    public int $loser = 0;

    #[Col("winner_hp")]
    public int $winnerHp = 0;

    /** 打了多少帧（20 Hz 判定） */
    #[Col]
    public int $frames = 0;

    #[Col("ended_at")]
    public int $endedAt = 0;
}
