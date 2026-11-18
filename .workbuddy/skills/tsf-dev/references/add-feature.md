# 加功能的四类场景

> 每类都是「三步 + 一个验证命令」。**验证命令必须实际跑**，不要凭代码判断"应该没问题"。

---

## 一、加一个 HTTP 接口（登录服 / Web API）

### 步骤 1：写 handler

```php
<?php
namespace App\Handler;

use TSF\Http\Request;
use TSF\Http\Response;

final class NoticeHandler
{
    public function handle(Request $req): Response
    {
        $page = (int) $req->queryOf("page", "1");
        if ($page < 1) {
            $page = 1;
        }

        // 业务错误用 Response::fail，不要 return 一个自己拼的数组
        if ($page > 1000) {
            return Response::fail(1002, "page too large", 400);
        }

        return Response::ok([
            "list" => [],
            "page" => $page,
        ]);
    }
}
```

**要点**：
- `final class` + `handle(Request): Response` —— 不要用闭包（AOT 对闭包跨边界捕获不稳）
- 参数校验就地做，失败**立刻 return**，不要往下走
- 业务错误码自己定区间（1000~1999 参数错误，2000~2999 账号，40000+ 传输层）

### 步骤 2：注册

```php
$app->get("/notice", \App\Handler\NoticeHandler::class);
```

**注册位置**：`main.php` 里 `$app->run()` 之前。

**要不要登录**：看 `AuthMiddleware` 的白名单。

```php
// 不需要登录
$app->use(new AuthMiddleware($auth, ["/login", "/health", "/notice"]));

// 需要登录（默认行为，不用改）
$app->use(new AuthMiddleware($auth, ["/login", "/health"]));
```

### 步骤 3：验证

```bash
tsf build . && tsf serve .

# 需要登录的接口
TOKEN=$(curl -s -X POST -d 'name=alice' http://127.0.0.1:9101/login \
    | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["token"])')
curl -s -H "Authorization: Bearer $TOKEN" "http://127.0.0.1:9101/notice?page=1"

# 预期
# {"code":0,"msg":"","data":{"list":[],"page":1}}
```

**必须验证三种情况**：
1. 正常请求 → `code: 0`
2. 缺 token → `401 / code 40100`
3. 参数非法 → `400 / 自定义 code`

---

## 二、加一个玩法（游戏服）

以「每日签到」为例。

### 步骤 1：定消息号

在 `src/Protocol/MsgId.php`（或工程自己的常量类）里加一个号。**区间约定**（看现有代码用的是哪段）：

```php
final class MsgId
{
    const SIGN_IN_REQ = 3001;
    const SIGN_IN_RESP = 3002;
}
```

**为什么要成对定义**：请求和响应分开，客户端才好按 msgId 分发。

### 步骤 2：写 handler

```php
// Service 收到消息后的分发
private function onSignIn(int $fd, \TSF\Service\Message $m): void
{
    $uid = $this->uidOfFd($fd);

    // 业务：今天签过没有
    $today = date("Ymd");
    $last = $this->signDays[$uid] ?? 0;
    if ($last === (int) $today) {
        $this->reply($fd, MsgId::SIGN_IN_RESP, ["code" => 2001, "msg" => "already signed"]);
        return;
    }

    $this->signDays[$uid] = (int) $today;
    $this->reply($fd, MsgId::SIGN_IN_RESP, ["code" => 0, "reward" => 100]);
}
```

**要点**：
- **AOT 下状态放哪**：内存（数组/对象，快，但重启丢）或 DB（`pkg/db` 的 `Repo`，慢，持久）
- **签到这种"一天一次"的**：内存足够，配合 DB 每天落一次盘
- **消息体**：`payload: raw` 时必须是 string，`json` 时可以是数组 —— 对不上会**静默失败**

### 步骤 3：验证

```bash
tsf build . && tsf serve .
# 另开终端
php85 client.php 9001        # 或用工程自己的测试脚本
```

**必须验证**：
1. 首次签到 → `code: 0`
2. 重复签到 → `code: 2001`
3. **重启进程后**再生效吗？（内存态会丢，这可能是预期，也可能不是）

---

## 三、加一个 pkg（可复用模块）

### 步骤 1：建结构

```
tsf/pkg/myfeature/
├── pkg.json
└── src/
    └── Manager.php
```

### 步骤 2：写 pkg.json

```json
{
  "name": "myfeature",
  "ns": "TSF\\MyFeature",
  "desc": "一句话说清它解决什么",
  "requires": [],
  "hint": "什么场景该用、什么场景不该用"
}
```

`ns` 是命名空间前缀，`requires` 是依赖的其他包（会自动带上）。

### 步骤 3：启用

工程根的 `project.json`：

```json
{
  "name": "myapp",
  "packages": ["db", "myfeature"]
}
```

**然后** `tsf build` 会把 `pkg/myfeature/src` 加进 sources。

**验证**：

```bash
grep -A8 'sources:' project.yml | grep myfeature
```

---

## 四、加定时任务

用 `pkg/schedule`（先确认工程 `project.json` 里有 `"schedule"`）。

```php
// 每 5 秒
$this->every(5000, function (int $timerId): void {
    // 注意：形参必须是 int $timerId
});

// 每天 4:00
$this->cron("0 4 * * *", "dailyReset");
```

**两个必踩的坑**：

1. **形参必须是 `int $timerId`**。空参闭包注册成功但一次不触发，且**没有任何报错**
2. **不要在定时任务里做长耗时操作**（会阻塞整个 Service 的消息处理）

---

## 五、加完之后的通用检查

```bash
tsf lint .        # 禁 mixed + 命名空间与目录一致
tsf build .       # 编译
```

**框架层改动**（`tsf/` 下）额外跑：

```bash
tsf verify
```

**提交前**：如果改了 `tsf/`，记得 **submodule 两步**：

```bash
cd tsf && git add -A && git commit -m "..." && git push
cd .. && git add tsf && git commit -m "chore: 更新 tsf submodule"
```
