<?php

namespace App\Handler;

use TSF\Http\Request;
use TSF\Http\Response;

/**
 * HTTP 接口 handler 模板。
 *
 * 用法：拷到工程的 src/Handler/ 下，改名换实现，然后在 main.php 里注册：
 *
 *   $app->post("/thing", \App\Handler\ThingHandler::class);
 *
 * 约定：
 *   - final class + handle(Request): Response
 *   - 参数校验就地做，失败立刻 return
 *   - 业务码自定区间（1000~1999 参数，2000~2999 账号，40000+ 传输层）
 */
final class ThingHandler
{
    public function handle(Request $req): Response
    {
        // 1. 取参数（表单 / JSON 都试一遍）
        $name = $req->postOf("name");
        if ($name === "") {
            $body = $req->json();
            $name = (string) ($body["name"] ?? "");
        }

        // 2. 校验
        if ($name === "") {
            return Response::fail(1001, "name required", 400);
        }
        if (strlen($name) > 64) {
            return Response::fail(1002, "name too long", 400);
        }

        // 3. 鉴权信息（AuthMiddleware 已经填好，不需要 token 的接口这里是 0）
        $uid = $req->uid;

        // 4. 干活
        $result = [
            "uid" => $uid,
            "name" => $name,
        ];

        // 5. 返回
        return Response::ok($result);
    }
}
