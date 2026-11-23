<?php
// 沙箱用例：故意写死循环，验证执行预算能把它打断。
// 没有这一层的话，这个文件一旦被热更上去，整个 worker 就再也不动了。
return function ($ctx) {
    $x = 0;
    while (true) {
        $x++;
    }
    return $x;
};
