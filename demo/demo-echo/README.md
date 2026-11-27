# demo-echo —— 最小的能跑的服务（280 行）

**定位**：**验证工具链和协议往返**。装完环境第一件事就是跑它——
能跑通说明 AOT 编译、帧编解码、Gateway 鉴权三条链都是通的。

```bash
tsf build . && ./demo_echo      # 或 tsf serve .
php85 client.php                # 另开终端，发一条 ECHO_REQ 收回来
```

端口 9001，TCP + header 帧 + raw payload。

## 文件

| 文件 | 内容 |
|:--|:--|
| `main.php` | 装配（Gateway + 4 个 handler），212 行 |
| `client.php` | 最小编码客户端，用框架同一份帧实现 |
| `config/tsf.json` | 端口、鉴权 token |

## 为什么它值得单独存在

`tsf new` 生成的工程和你手上这个基本一样，但它**在仓库里被 CI 跑**——
框架改了装配代码、改了帧格式，这里先红。

排障顺序：如果 `demo-echo` 跑不起来，先别查业务代码，
按 `docs/guide/00-quickstart.md` 的检查清单过一遍环境。

## 和 demo-echo-nts 的区别

那个是**反面教材**，证明了 NTS 版 PHP 编不过（见它的 README）。
