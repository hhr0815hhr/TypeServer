<?php

/**
 * 探针：AOT 下 pack/unpack 到底能不能用。
 *
 * `Cluster\Packet` 里写着"AOT 下对 pack 的支持面不确定，所以用 chr/ord"——
 * 那是保守选择，不是实测结论。协议生成器要生成 float/double 的编解码，
 * 自己实现 IEEE 754 容易在网络字节序和次正规数上出错，所以先探一下：
 * 能用就省一大坨代码，不能用就老老实实手写。
 */

function main(): void
{
    $ok = 0;
    $bad = 0;

    // 1. pack("g") = 小端 float32
    $f = 1.5;
    $b = pack("g", $f);
    $hex = "";
    for ($i = 0; $i < strlen($b); $i++) {
        $hex .= sprintf("%02X", ord($b[$i]));
    }
    echo "pack(g, 1.5) = " . $hex . "（期望 0000C03F）" . PHP_EOL;
    if ($hex === "0000C03F") {
        $ok++;
    } else {
        $bad++;
    }

    // 2. unpack("g")
    $v = unpack("g", $b);
    $got = is_array($v) && isset($v[1]) ? (float) $v[1] : -1.0;
    echo "unpack(g) = " . $got . PHP_EOL;
    if ($got === 1.5) {
        $ok++;
    } else {
        $bad++;
    }

    // 3. double："e" = 小端 64 位浮点
    $d = pack("e", 3.141592653589793);
    $back = unpack("e", $d);
    $dv = is_array($back) && isset($back[1]) ? (float) $back[1] : -1.0;
    echo "double 往返 = " . $dv . PHP_EOL;
    if ($dv === 3.141592653589793) {
        $ok++;
    } else {
        $bad++;
    }

    // 4. 负数 / 次正规数 / 0
    $cases = [-0.0, -1.25, 1.0e-40, 0.0];
    $allOk = true;
    foreach ($cases as $c) {
        $r = unpack("e", pack("e", $c));
        $rv = is_array($r) && isset($r[1]) ? (float) $r[1] : 999.0;
        if ($rv !== $c) {
            $allOk = false;
            echo "  不一致：" . $c . " → " . $rv . PHP_EOL;
        }
    }
    echo "边界值往返 " . ($allOk ? "OK" : "FAIL") . PHP_EOL;
    if ($allOk) {
        $ok++;
    } else {
        $bad++;
    }

    // 5. 64 位整数（varint 用得到）
    $q = pack("P", 1234567890123);
    $qq = unpack("P", $q);
    $qv = is_array($qq) && isset($qq[1]) ? (int) $qq[1] : -1;
    echo "pack(P) 往返 = " . $qv . PHP_EOL;
    if ($qv === 1234567890123) {
        $ok++;
    } else {
        $bad++;
    }

    echo PHP_EOL . "结果: " . $ok . " 通过 / " . $bad . " 失败" . PHP_EOL;
    exit($bad === 0 ? 0 : 1);
}
