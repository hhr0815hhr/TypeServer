<?php
// 热更脚本：+100。测试里会改成 +999 验证热更。
return function ($ctx) {
    $ctx->addInt("gold", 100);
    return $ctx->getInt("gold");
};
