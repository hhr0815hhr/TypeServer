# demo 目录里的验证脚本

这些是**手工验证工具**，不是"跑 demo 的必经步骤"。
它们对 `demo-echo` 有硬编码的路径和配置假设（`./demo_echo`、特定端口），
所以留在 `demo/` 根目录——**移动它们会同时打断内部相对路径**。

什么时候会用到：改了传输层、Gateway、协议配置之后，拿它们快速确认没有回归。

## 四组验证

| 组 | 脚本 | 验证什么 | 覆盖的回归套件 |
|:--|:--|:--|:--|
| 拆包/粘包 | ~~`frame-test.php`~~ | 分段发送、两帧合并、非法帧断开、异常后恢复 | **已转正**：`tests/frame-test`（`tsf verify frame`） |
| 背压 | `bp-run.sh` + `bp-test.php` | 客户端只发不读时，服务端发送缓冲堆满应断开它 | 无（手工） |
| 网关 | `m1-run.sh` + `m1-test.php` | 鉴权、顶号、心跳、限流 | 无（手工） |
| 协议切换 | `proto-run.sh` + `proto-test.php` | 同一份二进制，只改 `protocol.payload` 跑 raw / json | 无（手工） |
| WebSocket | `ws-run.sh` + `ws-test.php` | WS 传输的基本收发 | 无（手工） |

## 共同的前置与收尾

```bash
cd demo/demo-echo && ../../tsf/bin/tsf build .   # 先有产物
cd ..                                            # 回到 demo/
```

这几个 `*-run.sh` 会**临时改 `demo-echo/config/tsf.json`**（改 transport / payload /
限流参数），跑完调 `fix-config.php` 复位。所以：

- **跑完一定看一眼 `git diff demo/demo-echo/config/`**，配置没复位会污染后续测试
- 不要在这些脚本运行时同时跑 `tsf verify`（它们会改同一个配置文件、抢同一个端口）

## 为什么没有全部转成回归套件

`frame-test` 转正了，因为它的四条断言都是"错了不崩但致命"（偶发丢消息、
一条坏连接拖垮 worker），值得每次回归都跑。

其余四组没转，原因是它们需要**改运行时配置**（proto 要切 payload、m1 要调限流、
bp 要关限流并调小发送阈值）。`tsf verify` 的套件约定是"不改共享配置、不抢固定端口"，
硬转会让回归之间互相污染。

**判断：需要改配置的验证留在手工，靠这份清单保证"想起来了能找到"。**
