<?php

/**
 * 场景定义：怎么起服务、怎么切变体、用什么压测、采哪些指标。
 *
 * 一个场景 = 一个"被测系统 + 一组变体"。
 * 变体通过"改配置 / 换二进制"来表达，harness 负责交替执行它们。
 */

return [
    // ---- 编解码实现：php vs native ----
    "codec" => [
        "desc" => "帧编解码实现：PHP 版 vs C++ 版",
        "server" => [
            "bin" => "/data/TypeServer/demo/demo-echo/demo_echo",
            "cwd" => "/data/TypeServer/demo/demo-echo",
            "port" => 9001,
            "proc" => "demo_echo",
            "config" => "/data/TypeServer/demo/demo-echo/config/tsf.json",
        ],
        "variants" => [
            // 压测端既不带 token 也不做节流，所以两个开关都要关：
            //   auth.require=false  否则全部被 "login required" 拒掉
            //   rate_per_sec=0      否则超出 50/s 的请求被限流丢弃（会看到吞吐掉到几百）
            // 这两个开关的正确性由 demo/m1-test.php 覆盖，压测只测传输与编解码。
            "php" => ["set" => [
                "protocol.frame" => "php",
                "server.auth.require" => false,
                "server.rate_per_sec" => 0,
            ]],
            "native" => ["set" => [
                "protocol.frame" => "native",
                "server.auth.require" => false,
                "server.rate_per_sec" => 0,
            ]],
        ],
        "loader" => [
            "bin" => "/data/TypeServer/bench/load_tsf",
            "conns" => 32,
        ],
    ],

    // ---- 网关层开销：与"裸转发"对比，量化会话/限流/心跳的每帧成本 ----
    "gateway" => [
        "desc" => "网关层开销（会话+限流+心跳 vs 裸转发），native codec",
        "server" => [
            "bin" => "/data/TypeServer/demo/demo-echo/demo_echo",
            "cwd" => "/data/TypeServer/demo/demo-echo",
            "port" => 9001,
            "proc" => "demo_echo",
            "config" => "/data/TypeServer/demo/demo-echo/config/tsf.json",
        ],
        "variants" => [
            "bare" => ["set" => [
                "protocol.frame" => "native",
                "server.gateway" => false,
            ]],
            "gateway" => ["set" => [
                "protocol.frame" => "native",
                "server.gateway" => true,
                "server.auth.require" => false,
                "server.rate_per_sec" => 0,
            ]],
        ],
        "loader" => [
            "bin" => "/data/TypeServer/bench/load_tsf",
            "conns" => 32,
        ],
    ],

    // ---- 包大小对吞吐的影响（同一实现，只换压测端的包体）----
    "packet_size" => [
        "desc" => "包体大小对吞吐的影响（native codec）",
        "server" => [
            "bin" => "/data/TypeServer/demo/demo-echo/demo_echo",
            "cwd" => "/data/TypeServer/demo/demo-echo",
            "port" => 9001,
            "proc" => "demo_echo",
            "config" => "/data/TypeServer/demo/demo-echo/config/tsf.json",
        ],
        "variants" => [
            "16B" => ["set" => ["protocol.frame" => "native"], "loader" => "/data/TypeServer/bench/load_tsf"],
            "1KB" => ["set" => ["protocol.frame" => "native"], "loader" => "/data/TypeServer/bench/load_tsf_1k"],
        ],
        "loader" => [
            "bin" => "/data/TypeServer/bench/load_tsf",
            "conns" => 32,
        ],
    ],
];
