<?php

use TSF\Http\App;
use TSF\Http\Middleware\CorsMiddleware;
use TSF\Http\Request;
use TSF\Http\Response;

/**
 * pkg/http 的回归测试服务。
 *
 * run.sh 会启动它，用 curl 打几个关键路径，然后按 PID 回收。
 * 只测"路由器 + 中间件"的主干，不测每个边界（那些在 POSTMORTEM 里有记录）。
 */

function main(): void
{
    $app = new App("127.0.0.1", 19701);

    // CORS 预检 + 正常响应补头。
    // 注意要注册两次：use() 管预检（OPTIONS），after() 管正常响应。
    // 这是 pkg/http 的一个刻意设计——中间件只负责"拦"，不负责"改已生成的响应"。
    $cors = new CorsMiddleware("*", "Content-Type, Authorization", "GET,POST");
    $app->use($cors);
    $app->after(function (Response $r) use ($cors): Response {
        return $cors->decorate($r);
    });

    // 路径参数
    $app->get("/hello/:name", function (Request $req): Response {
        return Response::ok(["name" => $req->param("name")]);
    });

    // 同一个路径的另一种方法（用来测 405）
    $app->post("/hello/:name", function (Request $req): Response {
        return Response::ok(["posted" => true]);
    });

    // 需要登录的接口（AuthMiddleware 没装，靠 Request::$uid 判断会拿到 0，
    // 这里用一个显式检查模拟"没 token 就 401"，测响应构造）
    $app->get("/private", function (Request $req): Response {
        if ($req->uid === 0) {
            return Response::fail(40100, "unauthorized", 401);
        }
        return Response::ok(["uid" => $req->uid]);
    });

    $app->run();
}
