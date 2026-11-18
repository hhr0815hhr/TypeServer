<?php
// 故意抛异常的脚本：验证异常隔离
return function ($ctx) {
    throw new \RuntimeException("测试用异常");
};
