<?php

namespace Ddz;

use TSF\Db\Col;
use TSF\Db\Id;
use TSF\Db\Table;

/**
 * 玩家余额行。
 *
 * 用 `#[Table] / #[Id] / #[Col]` 声明映射，元数据只反射一次（Meta 缓存），
 * 之后 find/update 都走缓存好的列映射。
 */
#[Table("player")]
final class PlayerRow
{
    #[Id]
    public int $uid = 0;

    #[Col]
    public int $coins = 0;

    #[Col]
    public string $name = "";
}
