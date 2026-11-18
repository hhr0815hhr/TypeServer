# 11 · 登录服：HTTP 和 TCP 分工

> 前面十篇讲的都是游戏服（长连接 + 帧协议）。这一篇讲另一个角色：**登录服**。
>
> 它该不该归 tsf？为什么归 tsf 也不吃亏？两个工程怎么串起来？

## 一、先看两种协议的本质差异

| | HTTP | 帧协议（tsf 的） |
|:--|:--|:--|
| 连接 | 短连接 / 复用 | 长连接 |
| 头开销 | 每请求几十~几百字节文本头 | **8 字节二进制头** |
| 解析 | 文本解析 + 路由匹配 | 读 4 字节长度 |
| 状态 | 无状态 | 有状态（会话、心跳、顶号） |
| 适合 | 低频、一次性（登录、支付、公告） | **高频、持续（30Hz 同步）** |

**所以它们不该用同一套东西**——这不是框架能力的缺失，是协议特性决定的分工。

## 二、为什么登录服也能用 AOT

很多人（包括我）的第一反应是：登录是低频操作，AOT 的收益用不上，编译的代价却要付。

**但这个判断漏了一个前提**：**Swoole 项目本来就要重启才能生效**。

```bash
# 普通 Swoole 项目
vim login.php && php login.php      # 改完必须重启

# AOT 项目
vim main.php && tsf serve .         # 重编 1.9s + 重启
```

**两种方式都要重启**，AOT 只多了一次编译（1.9 秒）。而登录服**没有长连接**，重启只影响几百毫秒内的在途请求。

**换来的东西**：

| 收益 | 说明 |
|:--|:--|
| **统一部署** | `tsf build` → 一个二进制，`scp` 过去就能跑，目标机不用装 PHP |
| **统一日志/配置** | 复用 `Kernel\Logger`、`Kernel\Config`，不用另起一套 |
| **统一命令** | `tsf new` / `tsf build` / `tsf serve`，不用记两套 |

**唯一代价**：编译期 1.9 秒。对登录服来说，这个代价可以忽略。

## 三、两个工程怎么建

```bash
# 游戏服（长连接）
tsf new mygame

# 登录服（HTTP）
tsf new mylogin --login
```

**`--login` 生成的工程和游戏服的差别**：

| | 游戏服 | 登录服 |
|:--|:--|:--|
| 传输 | TCP / WS | **HTTP**（Swoole 原生 HttpServer） |
| 协议 | 分帧 + 消息体 | 普通 HTTP 请求-响应 |
| `client.php` | 有（测试客户端） | **无**（curl 就是客户端） |
| `modules/` | 有（wasm 热更） | **无**（登录不需要热更） |
| config | `protocol` / `server` / `auth` | `login`（host / port / secret / ttl / gateway） |
| 默认端口 | 9001 | **9101** |

> 路由、中间件、加接口的具体写法见 [12 · HTTP 接口](11-http-api.md)。

## 四、token：签和验必须是同一份代码

这是登录服和游戏服**唯一真正耦合的地方**。

`tsf/src/Gateway/TokenAuth.php` 同时负责签和验：

```php
// 登录服：签
$auth = new TokenAuth([], $secret);
$token = $auth->sign($uid, 300);      // uid.exp.sig

// 网关：验（同一个类，同一个 secret）
$uid = $auth->resolve($token);        // 0 = 无效或过期
```

**为什么共用一个类很重要**：如果两边各写一遍签名逻辑，编码、分隔符、hmac 参数顺序任何一处不一致，就会变成"登录成功但连不上游戏"——**而且这种错很难查**，因为两边单独看都对。

### token 的三种格式

| 格式 | 什么时候用 | 有没有过期 |
|:--|:--|:--|
| `uid.sig` | `sign($uid)`，兼容旧部署 | ❌ 永久有效 |
| `uid.exp.sig` | `sign($uid, 300)`，**推荐** | ✅ 300 秒 |
| 静态表 | `server.auth.tokens` 配死的 | ❌ |

**签名覆盖 `uid.exp`**，所以想改 `exp` 延长有效期会验签失败。

### 实测

```
$ curl -X POST -d 'name=alice' http://127.0.0.1:9101/login
{"uid":763665735,"token":"763665735.1794970497.461fde4e...","gateway":"127.0.0.1:9001","ttl":300}

# 网关侧（另一个进程，只共享 secret）
$ resolve("763665735.1794970497.461fde4e...")
763665735

# 换个 secret
$ resolve(...)
0
```

## 五、端到端串起来

```
┌──────────┐   ① POST /login          ┌──────────────┐
│  客户端  │ ───────────────────────> │  登录服      │  HTTP 9101
└──────────┘                          │  (AOT)       │
     │                                └──────────────┘
     │  ② { uid, token, gateway }            │
     │ <─────────────────────────────────────┘
     │
     │  ③ TCP 连 gateway，首包带 token       ┌──────────────┐
     └────────────────────────────────────> │  游戏服      │  TCP 9001
                                            │  (AOT)       │
                                            │  TokenAuth   │  ← 同一个 secret
                                            └──────────────┘
```

**关键：登录服不需要知道游戏服的任何内部结构**，它只返回一个地址和一张票。

## 六、部署

两个二进制，各自 `scp`：

```bash
# 登录服
scp mylogin/mylogin root@login-1:/opt/login/
ssh root@login-1 'cd /opt/login && ./mylogin'

# 游戏服
scp mygame/mygame root@game-1:/opt/game/
ssh root@game-1 'cd /opt/game && ./mygame'
```

**目标机不需要装 PHP**（产物是原生二进制），但**需要**：

- `libphp.so`（PHP 8.5 ZTS 的运行时，如果产物动态链接）
- `libphpx.so`（随产物或放系统路径）

> 静态链接可以省掉这些，但产物体积会大很多。当前默认是动态链接。

**配置文件两边要对齐的只有一个**：`secret`。

```json
// 登录服 config/tsf.json
"login": { "secret": "df0f2dea..." }

// 游戏服 config/tsf.json
"server": { "auth": { "require": true, "secret": "df0f2dea..." } }
```

## 七、几个常见问题

**① 多网关怎么办**

登录服返回的 `gateway` 字段现在写死了一个地址。真实项目应该按大区/负载挑一个：

```php
$gateway = $this->pickGateway($uid);   // 按 uid 取模、或查服务发现
$res->end(json_encode(["gateway" => $gateway, ...]));
```

**secret 可以是全局的**（所有网关共享），这样玩家换网关不用重新登录。

**② 跨区/跨服**

如果不同区用不同 secret，登录服要按区返回对应的 token 和 gateway。**建议 secret 全局唯一**，用 `gateway` 地址区分区服——否则 token 无法跨区复用。

**③ token 快过期了怎么办**

客户端在 TTL 剩余 < 60s 时重新调 `/login`（或者做个 `/refresh` 接口，用旧 token 换新 token）。

**不要在游戏服里做续期**——那会把 HTTP 逻辑塞进帧协议里。

**④ secret 轮换**

```php
// 支持新旧两个 secret 同时有效
$auth = new TokenAuth([], $newSecret);
$uid = $auth->resolve($token) ?: (new TokenAuth([], $oldSecret))->resolve($token);
```

**登录服先切新 secret 签发**，等旧 token 全部过期（TTL 时间）后再删旧 secret。

**⑤ 为什么不干脆在游戏服里加一个 HTTP 端口**

技术上可以（Swoole 支持 `HttpServer` + `TcpServer` 同进程），但**不建议**：

- HTTP 的短连接模型和长连接的连接管理会打架（`max_conn`、心跳、fd 复用）
- 登录服通常是**独立的扩容单位**（登录高峰和游戏高峰不同）
- 分开部署后，登录服挂了不影响在线玩家，游戏服挂了也不影响新登录

## 一句话

> **按协议特性分工，不按"是不是同一个框架"分工。**
>
> 登录是低频一次性（HTTP），游戏是高频持续（帧协议）。两个都归 tsf 只是为了共享
> 编译/部署/日志这一层，**协议上各用各的**，通过 token 解耦。
