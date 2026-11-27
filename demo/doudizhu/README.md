# doudizhu —— 三人斗地主（2086 行，带网页端）

**定位**：**最小可玩**的斗地主。单文件装配 + 网页端 + DB 存档，规则完整能真打一局。
适合照着改成自己的玩法。

```bash
./run-web.sh     # WebSocket 9301 + 网页 8080
# 浏览器开三个标签页，三人各坐一桌
```

网页在 `web/index.html`（469 行，原生 JS + WS，自己实现帧编解码）。

## 源码（平铺在 `src/`，7 个文件）

| 文件 | 职责 |
|:--|:--|
| `DdzService.php` | 消息分发 |
| `DdzRoom.php` | 一桌的状态机 |
| `DdzState.php` | 一局的状态（发牌/出牌/结算） |
| `Pattern.php` | 牌型识别与比大小 |
| `Card.php` | 牌的编码 |
| `PlayerRow.php` | 玩家存档行 |
| `Msg.php` | 消息 ID |

`migrations/001_create_player.sql` 是存档表，起服务前要先迁移。

## 和 ddz-hall 的关系

玩法相同，**结构不同**，两个都留着是有意的：

| | doudizhu（本目录） | ddz-hall |
|:--|:--|:--|
| 结构 | 单文件装配 + 平铺 `src/` | 四层 `src/{Domain,App,Protocol}` |
| 网页 | 有 | 有 |
| DB 存档 | 有（`migrations/`） | 无（房卡在内存） |
| 大厅/房卡/叫分 | 无 | 有 |
| 单测 | 无 | 72 条 |
| 适合看 | "怎么快速做出一个能玩的" | "代码到几千行之后怎么组织" |

看 `docs/guide/07-walkthrough.md`——它逐层拆解的就是这个 demo。

## 注意

网页端占 **8080**，和 `ddz-hall` 的 8090 不冲突，但和很多本地服务冲突。
`run-web.sh` 会先检查端口，被占会明确报错而不是静默失败。
