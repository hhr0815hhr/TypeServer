# battle —— 填满之后的游戏服（2038 行）

**定位**：业务做大之后**自然形成**的分层。看"一个真实玩法长什么样"。

```bash
./run.sh                 # 编译 + 启动，端口 9002，GM 在 9701
./run.sh --no-build      # 已经编过，直接起
php85 bench.php 9002 500 12 "" 5000 6    # 压测：500 连接 / 12 秒
```

GM 通道：

```bash
curl -s -X POST http://127.0.0.1:9701/gm -H "X-GM-Token: local-dev-only" \
     -d '{"cmd":"config.reload","args":{}}'
```

## 覆盖了什么

| 层 | 在哪 |
|:--|:--|
| 帧循环（20Hz） | `BattleRoom` 的 `interval` 推进 |
| AOI 视野 | `pkg/aoi`，按半径查询 |
| 技能与数值 | `SkillTable`（表驱动）、`Output`（伤害结算） |
| 实体 | `BattlePlayer` |
| 装配 | `main.php`（428 行，**留了业务判断**——这正是它和 ddz-hall 的差别） |

## 看什么

- 想学"房间怎么用帧循环"：`src/Battle/BattleRoom.php`（616 行）
- 想学"技能表怎么组织"：`src/Battle/SkillTable.php`（213 行）
- 想学"伤害怎么算"：`src/Battle/Output.php`（155 行）

## 和 ddz-hall 的区别

两个都 2000 行以上，但参照点不同：

| | battle | ddz-hall |
|:--|:--|:--|
| 分层 | 业务长出来之后整理的 | 从第一行就按四层写 |
| `main.php` | 428 行，含业务判断 | 130 行，只有函数调用 |
| Domain 单测 | 无 | 72 条，不需要编译 |
| 适合看 | "真实项目长什么样" | "怎么从第一天就有形状" |

框架的 CI 用它跑 100 人压测和 GM 通道验证，所以**它必须一直能跑**。
