<?php

// 灰度 B（新版本）。返回值只用于区分走了哪个版本。
return [
    "val" => function ($ctx) {
        return 2;
    },
    // 灰度版本也要能读私有状态：否则"灰度期间状态不共享"会变成新的坑
    "state" => function ($ctx) {
        return $ctx->addStateInt("cnt", 1);
    },
];
