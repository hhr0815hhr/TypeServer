# 12 · HTTP 接口：路由、中间件、加一个新接口

> 11 篇讲的是"登录服为什么用 HTTP"（架构判断）。这一篇讲 `pkg/http` 怎么用：
> 加接口、写中间件、处理错误。

## 一、最小例子

```php
use TSF\Http\App;
use TSF\Http\Request;
use TSF\Http\Response;

$app = new App("0.0.0.0", 9101);

$app->get("/health", function (Request $req): Response {
    return Response::ok(["ok" => true]);
});

$app->run();
```

```
$ curl http://127.0.0.1:9101/health
{"code":0,"msg":"","data":{"ok":true}}
```

## 二、路由

### 路径参数

```php
$app->get("/player/:uid/items/:slot", function (Request $req): Response {
    $uid = $req->param("uid");
    $slot = $req->param("slot");
    return Response::ok(["uid" => $uid, "slot" => $slot]);
});
```

`:xxx` 是参数，按**段数严格匹配**。不做正则路由——热路径上正则回溯是白给的开销。

### 两种 handler

```php
// ① 类名（推荐）：能单测、能依赖注入
$app->post("/notice", NoticeHandler::class);

// ② 闭包：三五个接口的临时项目
$app->get("/ping", fn (Request $r): Response => Response::ok());
```

类名 handler 只需要一个 `handle` 方法：

```php
final class NoticeHandler
{
    public function handle(Request $req): Response
    {
        return Response::ok(["list" => []]);
    }
}
```

**为什么推荐类名**：AOT 对"编译期能确定的调用"最友好，而且 handler 可以单独 `new` 出来测。

## 三、统一响应

```php
Response::ok($data)                          // {"code":0,"data":...}
Response::fail(1001, "name required", 400)   // {"code":1001,"msg":"...","status":400}
Response::text("pong")                       // text/plain
```

**HTTP 状态码和业务码分开用**：

| 用哪个 | 表达什么 | 谁看 |
|:--|:--|:--|
| **HTTP 状态码** | 传输层结果（200 / 401 / 429 / 500） | 网关、监控、LB |
| **code** | 业务结果（0 = 成功，非 0 是业务错误） | 客户端业务分支 |

**只用其中一个都会出问题**：

- 只用状态码表达业务错误 → `400` 既可能是"参数错"也可能是"余额不足"，语义被滥用
- 只用 code → 网关和监控看到的全是 200，故障发现不了

## 四、中间件

### 三个内置中间件

```php
$cors = new CorsMiddleware("*");
$app->use($cors);
$app->after(fn (Response $r): Response => $cors->decorate($r));   // 给正常响应补头
$app->use(new RateLimitMiddleware(20, 40));                       // 20/秒，突发 40
$app->use(new AuthMiddleware($auth, ["/login", "/health"]));      // 白名单
```

**为什么 CORS 需要两个注册点**：

| 注册 | 管什么 |
|:--|:--|
| `use($cors)` | OPTIONS **预检**请求，直接返回 204 |
| `after(...decorate)` | **正常响应**也要带 `Access-Control-Allow-Origin` |

只做前者的话，浏览器还是会拦——因为预检通过后，实际请求的响应也必须带这个头。

### 顺序不是随便定的

```
CORS  →  限流  →  鉴权
```

| 顺序 | 理由 |
|:--|:--|
| **CORS 最先** | OPTIONS 预检**不带 Authorization**。放后面会被 401 挡掉，前端看到的是一个和 CORS 无关的报错——这种错极难查 |
| **限流在鉴权前** | 否则伪造 token 的请求会先消耗一次验签 CPU 才被拒 |
| **鉴权用白名单** | 默认要登录、例外显式列出。反过来（默认不登录、需要的挂中间件）**新增接口忘挂就是一个无鉴权接口** |

### 实测

```
1. /health 白名单              →  200 {"code":0,...}
2. /player/info 无 token       →  401 {"code":40100,"msg":"missing token"}
3. /login 白名单               →  200 拿到 token
4. /player/info 带 token       →  200 {"uid":623767104,...}
5. 伪造 token                  →  401 {"code":40101,"msg":"invalid or expired token"}
6. OPTIONS 预检                →  204 + Access-Control-Allow-Origin: *
7. 正常响应的 CORS 头          →  有
8. 连打 50 次 (burst 40, rate 20) →  47 个 200 + 3 个 429
```

### 自己写中间件

实现 `TSF\Http\Middleware` 接口：

```php
use TSF\Http\Middleware;
use TSF\Http\Request;
use TSF\Http\Response;

final class TraceMiddleware implements Middleware
{
    public function handle(Request $req): ?Response
    {
        // 返回 null 继续；返回 Response 短路
        if ($req->headers["x-debug"] ?? "" === "") {
            return null;
        }
        return Response::fail(40000, "debug header not allowed", 400);
    }
}
```

**注意不能用闭包**：AOT 严检参数类型，`use(\Closure $mw)` 传一个有 `__invoke` 的对象会编译失败：

```
Argument `mw` must be an instance of `Closure`, `RateLimitMiddleware` given
```

## 五、加一个真实接口：公告列表

```php
// src/Handler/NoticeHandler.php
namespace App\Handler;

use TSF\Http\Request;
use TSF\Http\Response;

final class NoticeHandler
{
    public function handle(Request $req): Response
    {
        // 分页参数
        $page = (int) $req->queryOf("page", "1");
        $size = (int) $req->queryOf("size", "20");
        if ($page < 1) {
            $page = 1;
        }
        if ($size < 1 || $size > 100) {
            $size = 20;
        }

        // 真实项目这里查 db；示例用内存
        $all = [
            ["id" => 1, "title" => "开服公告", "at" => "2026-11-18"],
            ["id" => 2, "title" => "维护通知", "at" => "2026-11-17"],
        ];
        $total = count($all);
        $list = array_slice($all, ($page - 1) * $size, $size);

        return Response::ok([
            "list" => $list,
            "page" => $page,
            "size" => $size,
            "total" => $total,
        ]);
    }
}
```

```php
// main.php 里注册
$app->get("/notice", \App\Handler\NoticeHandler::class);
```

```
$ curl "http://127.0.0.1:9101/notice?page=1&size=1" \
    -H "Authorization: Bearer <token>"
{"code":0,"msg":"","data":{"list":[{"id":1,"title":"开服公告","at":"2026-11-18"}],
 "page":1,"size":1,"total":2}}
```

**注意**：`/notice` 不在白名单里，所以需要 token（公告一般也要求登录后才能看）。

## 六、错误处理

### 未捕获异常

`App` 会兜住 handler 抛的任何 `Throwable`，记 error 日志，返回 500：

```php
catch (\Throwable $e) {
    Logger::error("http handler threw", ["path" => $path, "err" => $e->getMessage()]);
    $resp = Response::fail(500, "internal error", 500);
}
```

**不给客户端返回异常信息**——那会泄露内部结构（SQL、路径、类名）。

### 参数校验

```php
$name = $req->postOf("name");
if ($name === "") {
    return Response::fail(1001, "name required", 400);
}
```

**业务码自己定一套区间**，比如：

| 区间 | 含义 |
|:--|:--|
| `1000~1999` | 参数错误 |
| `2000~2999` | 账号相关 |
| `40000+` | 传输层（鉴权/限流/内部错误） |

## 七、AOT 下写 HTTP 的三个约束

**① 中间件用接口，不用闭包**

理由见第四节——编译期就报错。

**② 不要用反射**

包括：`ReflectionClass` 做自动注入、`new $class($params)` 做容器、`call_user_func_array` 展开参数。
这些在 tpc 下要么编不过，要么编过了行为不对。

**handler 的依赖在构造函数里显式传**：

```php
// ✗ 容器自动注入
$app->post("/x", autoWire(XHandler::class));

// ✓ 显式构造
$app->post("/x", fn (Request $r): Response => (new XHandler($db, $cache))->handle($r));
```

**③ JSON body 用 `array` 访问，不要 `object`**

```php
$body = $req->json();          // 返回 array
$name = (string) ($body["name"] ?? "");
```

对着 `mixed` 反复下标是踩过的坑（`08-aot-types.md` 第一条）。

## 一句话

> **`pkg/http` 刻意做得很小**：路由 + 统一响应 + 三个中间件，约 700 行。
>
> 不做 ORM、模板、容器——这些要么 AOT 编不过，要么不需要。
> 需要加接口时，一行注册、写个 `handle` 方法就够了。
