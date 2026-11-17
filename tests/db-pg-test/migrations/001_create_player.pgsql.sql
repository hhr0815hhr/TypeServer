-- 只在 PostgreSQL 上跑（Migrator 按 DSN 驱动匹配 .pgsql 后缀）
CREATE TABLE IF NOT EXISTS pg_t_player (
    id    SERIAL PRIMARY KEY,
    name  VARCHAR(32) NOT NULL,
    coins INT NOT NULL DEFAULT 0
);
