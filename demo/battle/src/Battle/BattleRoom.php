<?php

namespace Game\Battle;

use TSF\Aoi\Scene;
use TSF\Entity\EntitySet;

/**
 * 战斗地图房间：**组合机制，不继承框架的房间语义**。
 *
 * 为什么不用 `TSF\Room\Room`：
 *   Room 的语义是"一局"（有开始/进行/结算，人多则散场）。而这里是一张**常驻地图**，
 *   玩家进出是常态，没有"这一局结束了"这种时刻。硬套 Room 会得到一堆
 *   永远停在 playing 的状态，还要为"结算时回收"写特例。
 *
 *   这正好印证上一轮的判断：Room 是框架给的一种**可选辅助实现**，
 *   战斗地图该组合 Scene + EntitySet + 自己的玩家表，而不是被状态机框住。
 *
 * 分层落位：
 *   L1  TSF\Aoi\Scene / Grid      九宫格视野（纯几何，跨游戏通用）
 *       TSF\Entity\EntitySet      坐标推进（可变 delta，跨游戏通用）
 *   L2  本类                       玩家战斗属性、技能冷却、伤害结算、复活策略
 *   L3  SkillTable                 数值与半径（可热更，不许进 AOT 常量）
 *
 * 一件事必须守住：**技能数值只从 SkillTable 读，不在代码里出现字面量**。
 * 一旦公式里写死 `* 15 / 10`，热更就白做了。
 */
final class BattleRoom
{
    /**
     * 事件类型：**一次技能结算只产生一个事件**。
     *
     * 第一版是"每个命中目标一个事件"——AOE 打中 12 人就是 1 个施法事件
     * + 12 个伤害事件 + 若干死亡事件，13 条消息。视野广播下每条还要
     * 各带一份观众列表，1000 人时直接把调度器打爆（tick 65.9ms）。
     *
     * 现在合成一个包：`[EVT_SKILL, from, x, y, [[uid,dmg,hp], ...]]`。
     * 一次施法 = 一条推送行，与命中人数无关。
     * 客户端遍历目标列表即可，hp=0 就是死了（不需要单独的死亡事件）。
     */
    public const EVT_SKILL = 1;

    /** 复活：`[EVT_REVIVE, uid, x, y, hp]` */
    public const EVT_REVIVE = 2;

    private Scene $scene;

    private EntitySet $entities;

    /** @var array<int, BattlePlayer> */
    private array $players = [];

    private SkillTable $skills;

    private int $maxPlayers;

    private int $width;

    private int $height;

    /** 房间时间轴（毫秒）：所有冷却/复活都基于它，不用墙上时间 */
    private int $elapsedMs = 0;

    private int $frame = 0;

    /** @var array<int, int> uid => skillId，下一帧统一结算 */
    private array $pending = [];

    /**
     * 本帧事件缓冲。每帧开头清空，结算往里 push。
     *
     * 用成员变量而不是"每次施法 new 一个数组再合并"：
     * 后者在 30Hz × 多人同时施法时会产生大量短命数组，
     * 实测这是 p99 的主要来源（200 人场景下 p99 7.5ms）。
     *
     * @var array<int, array<int, int>>
     */
    private array $emitted = [];

    /**
     * 本次施法的命中目标：`[[uid, dmg, hp], ...]`。
     *
     * 用实例属性而不是闭包引用（`use (&$targets)`）：AOT 下引用捕获的语义
     * 容易出岔子，而这条路径每帧要走几十次，不值得冒险。
     * 每次 resolveCast 开头清空，结算完整体塞进 emitted。
     *
     * @var array<int, array<int, int>>
     */
    private array $castTargets = [];

    // ---- 统计（压测要看的量，只在房间内部累加，不进热路径的消息层）----
    private int $casts = 0;

    private int $hits = 0;

    private int $deaths = 0;

    private int $aoiCandidates = 0;

    private int $respawns = 0;

    public function __construct(SkillTable $skills)
    {
        $this->skills = $skills;

        // 容量也从 L3 读。写死 512 的后果实测过：1000 个连接只有 512 个进图，
        // 客户端显示"建连 1000 / 进图 512"，压测数据直接失真一半。
        $max = $skills->getInt("map.max_players", 512);
        $this->maxPlayers = $max > 0 ? $max : 512;

        // 地图尺寸从 L3 配置读，不写死在构造参数里。
        // 原因很实际：调密度是压测最常做的动作，改一行配置 + 存盘就好，
        // 不用改 main.php 再走一遍编译。
        $cell = $skills->getInt("map.cell_size", 64);
        $cols = $skills->getInt("map.cols", 16);
        $rows = $skills->getInt("map.rows", 16);
        if ($cell <= 0) {
            $cell = 64;
        }
        if ($cols <= 0) {
            $cols = 16;
        }
        if ($rows <= 0) {
            $rows = 16;
        }
        $this->width = $cell * $cols;
        $this->height = $cell * $rows;
        $this->scene = new Scene($cell, $cols, $rows, $this->width, $this->height);

        // 位置变化的唯一出口：实体一动就同步进 AOI，并把权威坐标写回玩家对象。
        // 用闭包注入而不是让 BattleRoom 继承 EntitySet，是为了让"坐标"和"视野"
        // 两个包保持互不认识 —— 换掉任何一个都不影响另一个。
        $this->entities = new EntitySet(function (int $uid, int $x, int $y): void {
            $this->scene->place($uid, $x, $y);
            $p = $this->players[$uid] ?? null;
            if ($p instanceof BattlePlayer) {
                $p->x = $x;
                $p->y = $y;
            }
        });
    }

    /**
     * 出生点：中心散列。
     *
     * `player.spawn_radius` 决定聚集程度，这是**压测的核心旋钮**：
     *   - 半径 0（或负数）→ 全图均匀散布，200 人时九宫格里几乎没人，
     *     AOI 看起来"没有收益"，其实是场景本身就不该用 AOI。
     *   - 半径 320 → 约 10×10 格、200 人时每格 2 人，九宫格 15~20 人，
     *     这才是 AOI 该发挥作用的密度。
     *
     * 用乘法散列而不是随机数：同一 uid 每次都算到同一个点，重放和排查都稳定。
     *
     * @return array<int, int> [x, y]
     */
    private function spawnPos(int $uid, int $salt = 0): array
    {
        $radius = $this->skills->getInt("player.spawn_radius", 320);
        $n = $uid + $salt;

        if ($radius <= 0) {
            $margin = 64;
            $x = $margin + (($n * 131 + 17) % ($this->width - $margin * 2));
            $y = $margin + (($n * 977 + 31) % ($this->height - $margin * 2));
            return [$x, $y];
        }

        $cx = intdiv($this->width, 2);
        $cy = intdiv($this->height, 2);
        $x = $cx + (($n * 131 + 17) % ($radius * 2)) - $radius;
        $y = $cy + (($n * 977 + 31) % ($radius * 2)) - $radius;

        $margin = 8;
        $maxX = $this->width - $margin;
        $maxY = $this->height - $margin;
        if ($x < $margin) {
            $x = $margin;
        } elseif ($x > $maxX) {
            $x = $maxX;
        }
        if ($y < $margin) {
            $y = $margin;
        } elseif ($y > $maxY) {
            $y = $maxY;
        }
        return [$x, $y];
    }

    public function scene(): Scene
    {
        return $this->scene;
    }

    public function frame(): int
    {
        return $this->frame;
    }

    public function elapsedMs(): int
    {
        return $this->elapsedMs;
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function count(): int
    {
        return count($this->players);
    }

    /** @return array<int, int> 当前在图的 uid 列表（推送时遍历用） */
    public function uids(): array
    {
        $out = [];
        foreach ($this->players as $uid => $_) {
            $out[] = $uid;
        }
        return $out;
    }

    public function isFull(): bool
    {
        return count($this->players) >= $this->maxPlayers;
    }

    public function maxPlayers(): int
    {
        return $this->maxPlayers;
    }

    public function skills(): SkillTable
    {
        return $this->skills;
    }

    public function player(int $uid): ?BattlePlayer
    {
        $p = $this->players[$uid] ?? null;
        if (!$p instanceof BattlePlayer) {
            return null;
        }
        return $p;
    }

    /**
     * 进图。返回 0=成功，1=已在图中，2=地图满员。
     *
     * 出生点用 uid 的乘法散列而不是随机数：同一 uid 重连回到同一位置，
     * 压测重放时也稳定可复现。随机出生会让"两个人刚好重叠"变成偶发问题。
     */
    public function add(int $uid): int
    {
        if (isset($this->players[$uid])) {
            return 1;
        }
        if (count($this->players) >= $this->maxPlayers) {
            return 2;
        }

        $pos = $this->spawnPos($uid);
        $x = $pos[0];
        $y = $pos[1];

        $hp = $this->skills->getInt("player.hp", 500);
        $atk = $this->skills->getInt("player.atk", 40);
        $speed = $this->skills->getInt("player.speed", 240);

        $p = new BattlePlayer($uid, $x, $y, $hp, $atk, $speed);
        $p->joinedMs = $this->elapsedMs;
        $this->players[$uid] = $p;

        // spawn 会立即触发一次位置回调 → Scene::add，不需要再手动插一次
        $this->entities->spawn($uid, $x, $y, 0, 0);
        return 0;
    }

    public function remove(int $uid): bool
    {
        if (!isset($this->players[$uid])) {
            return false;
        }
        unset($this->players[$uid]);
        unset($this->pending[$uid]);
        $this->entities->remove($uid);
        $this->scene->remove($uid);
        return true;
    }

    /**
     * 移动意图：只接受方向（-1 / 0 / 1），坐标由服务端算。
     *
     * 这是安全边界而不是风格问题：如果客户端能发坐标，改包就能瞬移。
     * 方向进、位置出，服务端始终持有权威坐标。
     */
    public function move(int $uid, int $dx, int $dy): bool
    {
        $p = $this->players[$uid] ?? null;
        if (!$p instanceof BattlePlayer || !$p->alive()) {
            return false;
        }
        if ($dx > 1) {
            $dx = 1;
        } elseif ($dx < -1) {
            $dx = -1;
        }
        if ($dy > 1) {
            $dy = 1;
        } elseif ($dy < -1) {
            $dy = -1;
        }

        // 斜向不加速：两个分量同时非零时各乘 0.707，避免"斜着走更快"的经典问题
        if ($dx !== 0 && $dy !== 0) {
            $vx = intdiv($dx * $p->speed * 707, 1000);
            $vy = intdiv($dy * $p->speed * 707, 1000);
        } else {
            $vx = $dx * $p->speed;
            $vy = $dy * $p->speed;
        }
        return $this->entities->setVelocity($uid, $vx, $vy);
    }

    /**
     * 施法请求。返回 0=已排队，1=无此玩家，2=已死亡，3=冷却中，4=技能不存在。
     *
     * 不在这里立即结算，而是记入 pending 由下一帧统一处理：
     * 一帧内的所有输入应该在同一时刻结算，否则"谁先到包谁先打中"，
     * 结果依赖网络到达顺序（这也是帧同步服务器把输入缓冲到帧边界的原因）。
     */
    public function cast(int $uid, int $skillId): int
    {
        $p = $this->players[$uid] ?? null;
        if (!$p instanceof BattlePlayer) {
            return 1;
        }
        if (!$p->alive()) {
            return 2;
        }
        $id = $this->skills->getInt("skill.fireball.id", 1);
        if ($skillId !== $id) {
            return 4;
        }
        if ($this->elapsedMs < $p->nextCastMs) {
            return 3;
        }
        $cd = $this->skills->getInt("skill.fireball.cd_ms", 800);
        $p->nextCastMs = $this->elapsedMs + $cd;
        $this->pending[$uid] = $skillId;
        return 0;
    }

    /**
     * 推进一帧，返回本帧产生的事件。
     *
     * 顺序是有意义的，不要调换：
     *   1. 先推进坐标（实体移动 → AOI 换格），结算用的是**移动后**的位置
     *   2. 再热更配置，保证本帧结算用的是最新数值
     *   3. 最后结算施法
     *
     * @return array<int, array<int, int>>
     */
    public function tick(int $deltaMs): array
    {
        if ($deltaMs < 0) {
            $deltaMs = 0;
        }
        $this->elapsedMs += $deltaMs;
        $this->frame++;

        $this->entities->tick($deltaMs);
        $this->skills->reloadIfChanged();

        $this->emitted = [];
        if ($this->pending !== []) {
            $queue = $this->pending;
            $this->pending = [];
            foreach ($queue as $uid => $skillId) {
                $this->resolveCast($uid, $skillId);
            }
        }
        $this->respawnDead();
        return $this->emitted;
    }

    /**
     * 结算一次施法：AOI 粗筛 + 精确半径二次筛。
     *
     * 两级筛选是这一层的核心价值：
     *   AOI 把候选从"全图 N 人"压到"半径内的十几人"，
     *   半径精筛再把"格内但够不着"的剔掉。少了第一级，1000 人地图每帧
     *   就是几十万次平方比较；少了第二级，格边界上的目标会被误伤。
     *
     * 事件写入 `$this->emitted` 而不是返回数组：每帧一次清空，
     * 而不是每次施法都新建一个数组 —— 后者在 30Hz × 多人施法下是 p99 的主要来源。
     */
    private function resolveCast(int $uid, int $skillId): void
    {
        $caster = $this->players[$uid] ?? null;
        if (!$caster instanceof BattlePlayer || !$caster->alive()) {
            return;
        }

        $base = $this->skills->getInt("skill.fireball.base", 12);
        $ratio = $this->skills->getInt("skill.fireball.ratio_pct", 150);
        $radius = $this->skills->getInt("skill.fireball.radius", 260);
        $dmg = $base + intdiv($caster->atk * $ratio, 100);

        $this->casts++;
        $this->castTargets = [];

        $r2 = $radius * $radius;

        // 候选来源四选一，由配置决定：
        //   aoi.enabled=0  → 遍历全图，对照组（证明其余三种没有漏判）
        //   aoi.mode=view  → 九宫格 viewOf()，**故意保留**用于复现漏判，
        //                    它不是可选实现，是踩过的坑留下的证据
        //   aoi.mode=around→ 按半径构造候选数组（要完整集合时用）
        //   aoi.mode=each  → 按半径回调遍历，零分配（热路径推荐）
        $useAoi = $this->skills->getInt("aoi.enabled", 1) === 1;
        $mode = $this->skills->getString("aoi.mode", "each");
        $n = 0;

        if (!$useAoi) {
            foreach ($this->players as $tid => $_) {
                if ($tid !== $uid) {
                    $n++;
                    $this->hitOne($caster, $tid, $dmg, $r2);
                }
            }
        } elseif ($mode === "view") {
            $view = $this->scene->viewOf($uid);
            $n = count($view);
            foreach ($view as $tid => $_) {
                if ($tid !== $uid) {
                    $this->hitOne($caster, $tid, $dmg, $r2);
                }
            }
        } elseif ($mode === "around") {
            $view = $this->scene->around($uid, $radius);
            $n = count($view);
            foreach ($view as $tid => $_) {
                if ($tid !== $uid) {
                    $this->hitOne($caster, $tid, $dmg, $r2);
                }
            }
        } else {
            // each：零分配路径。回调里只做判定与写入，不改动 AOI 结构
            $casterUid = $caster->uid;
            $n = $this->scene->eachAround($uid, $radius, function (int $tid) use ($casterUid, $caster, $dmg, $r2): void {
                if ($tid === $casterUid) {
                    return;
                }
                $this->hitOne($caster, $tid, $dmg, $r2);
            });
        }
        $this->aoiCandidates += $n;

        // 一次施法 = 一个事件包。命中 0 人也要发（客户端要放技能特效）
        $this->emitted[] = [self::EVT_SKILL, $uid, $caster->x, $caster->y, $this->castTargets];
    }

    /**
     * 单个目标的命中结算：距离校验 → 扣血 → 记入本次施法的目标列表。
     *
     * 单独成方法是为了让四条候选路径共用同一套规则。多一次方法调用（约 50ns）
     * 换来"规则只有一份"，值得——规则分叉的代价远高于这点开销。
     *
     * hp 直接写进目标元组，`hp === 0` 就代表死亡，不再单独发死亡事件：
     * 客户端拿到 [uid, dmg, hp] 三件事，自己能判断该播受击还是倒地。
     */
    private function hitOne(BattlePlayer $caster, int $tid, int $dmg, int $r2): void
    {
        $t = $this->players[$tid] ?? null;
        if (!$t instanceof BattlePlayer || !$t->alive()) {
            return;
        }
        $dx = $t->x - $caster->x;
        $dy = $t->y - $caster->y;
        if ($dx * $dx + $dy * $dy > $r2) {
            return;
        }

        $t->hp -= $dmg;
        $this->hits++;
        if ($t->hp <= 0) {
            $t->hp = 0;
            $t->deaths++;
            $caster->kills++;
            $this->deaths++;
            $t->respawnAtMs = $this->elapsedMs + $this->skills->getInt("player.respawn_ms", 1000);
        }
        $this->castTargets[] = [$tid, $dmg, $t->hp];
    }

    /**
     * 到点复活。死亡不散场、就地满血——这是本工程的业务策略，不是框架语义。
     *
     * 真实的死亡惩罚（掉装备、回城、冷却）都应该写在这里或更上层，
     * 框架不需要知道"死亡之后会发生什么"。
     */
    private function respawnDead(): void
    {
        foreach ($this->players as $uid => $p) {
            if ($p->alive() || $p->respawnAtMs <= 0 || $this->elapsedMs < $p->respawnAtMs) {
                continue;
            }
            $pos = $this->spawnPos($uid, $this->deaths * 7);
            $x = $pos[0];
            $y = $pos[1];
            $p->revive($x, $y, $this->elapsedMs);
            $p->respawnAtMs = 0;
            $this->entities->moveTo($uid, $x, $y);
            $this->respawns++;
            $this->emitted[] = [self::EVT_REVIVE, $uid, $x, $y, $p->hp];
        }
    }

    /**
     * 视野快照的**手写 JSON 文本**：`[uid, frame, [[uid,x,y,hp], ...]]`。
     *
     * 为什么不用 `json_encode(array)`：
     *   1000 人 × 5Hz = 每秒 5000 次编码。json_encode 走的是通用路径
     *   （类型推断、递归下行、字符串转义判断），而这里的结构完全固定、
     *   全是整数、没有任何需要转义的字符 —— 通用路径每一条都用不上。
     *
     * 为什么返回字符串而不是数组：
     *   返回数组意味着"构造嵌套数组 → 交给上层 encode"，多一次完整的数据搬运。
     *   这一步的代价在大规模下是可见的：p50 里 4ms 有一块就是它。
     *   严格说这让房间知道了序列化格式，但 JSON 只是**紧凑载体**，
     *   不含任何业务语义，也不含协议号——换二进制帧时只改这一个方法。
     *
     * 视野仍用九宫格 viewOf()：快照是"我能看到谁"，与命中半径无关。
     */
    public function snapshotJson(int $uid): string
    {
        $p = $this->players[$uid] ?? null;
        if (!$p instanceof BattlePlayer) {
            return "";
        }
        $s = "[" . $p->uid . "," . $this->frame . ",[";
        $s .= "[" . $p->uid . "," . $p->x . "," . $p->y . "," . $p->hp . "]";
        foreach ($this->scene->viewOf($uid) as $tid => $_) {
            if ($tid === $uid) {
                continue;
            }
            $t = $this->players[$tid] ?? null;
            if (!$t instanceof BattlePlayer) {
                continue;
            }
            $s .= ",[" . $t->uid . "," . $t->x . "," . $t->y . "," . $t->hp . "]";
        }
        return $s . "]]";
    }

    /**
     * 给某个玩家生成视野快照（数组形式，调试/测试用）。
     *
     * 线上走 snapshotJson()。保留这个是因为"数组"更适合断言和排查，
     * 而压测路径要的是"少一次数据搬运"。
     *
     * @return array<string, int|string|array>
     */
    public function snapshotFor(int $uid): array
    {
        $p = $this->players[$uid] ?? null;
        if (!$p instanceof BattlePlayer) {
            return [];
        }
        $rows = [];
        $rows[] = [$p->uid, $p->x, $p->y, $p->hp];
        foreach ($this->scene->viewOf($uid) as $tid => $_) {
            if ($tid === $uid) {
                continue;
            }
            $t = $this->players[$tid] ?? null;
            if ($t instanceof BattlePlayer) {
                $rows[] = [$t->uid, $t->x, $t->y, $t->hp];
            }
        }
        return [
            "me" => $p->uid,
            "f" => $this->frame,
            "e" => $rows,
        ];
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        $g = $this->scene->grid();
        return [
            "players" => count($this->players),
            "frame" => $this->frame,
            "elapsed_ms" => $this->elapsedMs,
            "casts" => $this->casts,
            "hits" => $this->hits,
            "deaths" => $this->deaths,
            "respawns" => $this->respawns,
            "aoi_entities" => $g->entityCount(),
            "aoi_cells" => $g->cellCount(),
            "aoi_crossings" => $this->scene->crossings(),
            "aoi_candidates" => $this->aoiCandidates,
            "aoi_enabled" => $this->skills->getInt("aoi.enabled", 1),
            "aoi_mode" => $this->skills->getString("aoi.mode", "around"),
            "config_reloads" => $this->skills->reloads(),
        ];
    }
}
