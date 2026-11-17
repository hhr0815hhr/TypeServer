-- 分片场景：主键必须由业务分配（uid 就是主键），不能用 AUTO_INCREMENT ——
-- 各分片的自增会算出重复的 id。
CREATE TABLE IF NOT EXISTS shard_player (
    uid   INT PRIMARY KEY,
    name  VARCHAR(32) NOT NULL,
    coins INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
