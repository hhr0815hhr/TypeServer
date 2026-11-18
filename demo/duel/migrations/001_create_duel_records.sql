-- 对局记录。属性名是 camelCase，列名是 snake_case，靠 #[Col] 映射。
CREATE TABLE IF NOT EXISTS duel_records (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    room      INTEGER NOT NULL,
    winner    INTEGER NOT NULL,
    loser     INTEGER NOT NULL,
    winner_hp INTEGER NOT NULL,
    frames    INTEGER NOT NULL,
    ended_at  INTEGER NOT NULL
);

-- 按房间号查历史战绩
CREATE INDEX IF NOT EXISTS idx_duel_room ON duel_records (room);
