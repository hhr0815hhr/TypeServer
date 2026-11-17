-- 玩家余额。属性名 camelCase、列名 snake_case，靠 #[Col] 映射。
--
-- 迁移是**部署步骤**（tsf migrate），不是启动步骤：多个 worker 同时建表会互相冲突，
-- 所以服务启动只 verify，不建表。
CREATE TABLE IF NOT EXISTS player (
    uid   INTEGER PRIMARY KEY,
    coins INTEGER NOT NULL DEFAULT 0,
    name  TEXT    NOT NULL DEFAULT ''
);
