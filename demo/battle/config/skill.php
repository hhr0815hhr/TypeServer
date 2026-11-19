<?php

/**
 * L3 数值配置：扁平 key-value。
 *
 * 这个文件不参与 AOT 编译（config/ 不在 project.yml 的 sources 里），
 * 运行时由 SkillTable 用 include 加载，改完保存即生效，不用重编译、不用重启。
 *
 * **但有一个前提必须知道**：AOT 二进制里的 include 会命中 opcache，
 * 而且不会因为文件 mtime 变化自动失效——实测即使 validate_timestamps=On，
 * 改完文件后 reload 仍然读到旧值。SkillTable::load() 里显式调了
 * `opcache_invalidate($path, true)` 才让热更真正成立。
 * 如果哪天热更又"不生效"了，先怀疑这一行被删掉或被编译器优化掉。
 *
 * 约定：
 *   1. 只用 string key + int|string value。嵌套数组会让"读配置"在 AOT 下
 *      重新变成 mixed 下钻（0.9.6 已难复现，但没有理由冒这个险）。
 *   2. 生效延迟最多 30 帧（约 1 秒），来自 mtime 检查的节流，不是缓存。
 *
 * 压测/验证时改这几行就够了：
 *   base 60 → 90      观察伤害跳变（伤害 = base + atk × ratio_pct / 100）
 *   aoi.enabled 1 → 0 关掉九宫格，跑对照组（会有漏判，见下）
 */
return [
    // 地图：格宽 × 格数 = 世界坐标。
    //
    // 格宽取小值（64）是**对的**，前提是命中判定用 Scene::around(uid, radius)
    // 而不是九宫格 viewOf()。九宫格只覆盖约一个格宽，radius 超过它就会静默漏判
    // （实测 cell=64 / radius=500 用 viewOf 时漏判约一半）；而 around 会按半径扩圈，
    // 所以小格宽 + 大半径可以同时拿到"不漏判"和"高筛除率"。
    //
    // 反面教材：为了不漏判把格宽放大到 256 —— 每格人一多，筛除率崩，
    // 实测 p99 从 3.5ms 涨到 18.5ms。
    "map.cell_size" => 64,
    "map.cols" => 32,
    "map.rows" => 32,

    // 单图容量上限。压测到 1000 人时必须调大，否则多出来的连接会被拒绝，
    // 而客户端只会显示"建连 1000 / 进图 512"，数据看着正常其实是半个场景。
    "map.max_players" => 2048,

    // 出生聚集半径。0 = 全图散布（对照组），320 = 中心聚集（实验组）
    "player.spawn_radius" => 900,

    // 玩家基础属性
    //
    // hp 特意拉到 100 万：压测要比较的是"AOI 候选是否漏判"和"帧耗时"，
    // 而不是死亡-复活的反馈回路。血量低时，AOE 打死一半人 →
    // 死亡玩家不能施法 → 施法量在不同 AOI 模式下不可比，对比结论就废了。
    // 真要测死亡逻辑，把 hp 调回 500，这时该看的是 deaths/respawns 而不是命中数。
    "player.hp" => 1000000,
    "player.atk" => 40,
    "player.speed" => 240,
    "player.respawn_ms" => 1000,

    // AOI 开关：1=用 AOI 候选，0=遍历全图（仅用于对照实验）
    "aoi.enabled" => 1,

    // aoi.mode 四种候选来源（热切换，用于 A/B）：
    //   each   按半径回调遍历，零分配 —— 热路径默认
    //   around 按半径构造候选数组（需要完整集合时用）
    //   view   九宫格，会漏判 92.6%，仅用于复现问题
    //   配合 aoi.enabled=0 可退化为全量遍历，作为正确性基线
    "aoi.mode" => "each",

    // 每几帧推一次快照（6 ≈ 5Hz）。调大只用于测量"推送占帧预算多少"
    "net.push_every" => 6,

    // 事件观众范围：1=施法者九宫格视野（真实做法），0=只发当事人（对照实验）。
    //
    // 早先默认 0，因为视野广播在千人下会把调度器打爆（tick 65.9ms，
    // 客户端发 3.6 万次施法、服务端只结算 2658 次）。
    // 做完「一次施法只产出一个事件包」之后成本降下来了：
    // 千人 p99 从 65.9ms → 15.2ms，比不发广播只多 1.4ms。所以现在默认开。
    "net.event_audience" => 1,

    // 火球术：伤害 = base + atk * ratio_pct / 100
    "skill.fireball.id" => 1,
    "skill.fireball.base" => 60,
    "skill.fireball.ratio_pct" => 150,

    // 命中半径。用 around 后不再受格宽限制（扩圈由 ringsFor 自动算）
    "skill.fireball.radius" => 128,
    "skill.fireball.cd_ms" => 800,
    // 自动轮询热更：0=关（默认，变更必须有人发起），1=开（仅限本机调试）
    //
    // 关掉的原因不是性能，是"可解释"：自动轮询下，进程行为取决于"文件什么时候
    // 被改 + 轮询什么时候轮到"，没有任何人决定它何时生效。而出问题时想复现
    // "当时是什么配置"，也得靠翻日志时间线猜。
    //
    // 需要热更时用 GM 命令显式触发：
    //   curl -X POST http://127.0.0.1:9701/gm -H "X-GM-Token: ..." \
    //        -d '{"cmd":"config.reload"}'
    // 生产上这一步通常由"配置发布系统"代劳——但发起人仍然是点那个按钮的人。
    "hot.auto_reload" => 0,
];
