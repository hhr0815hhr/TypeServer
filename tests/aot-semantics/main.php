<?php

/**
 * AOT 语义差分测试：同一份代码，用 php85 跑和用 tpc 编出来的二进制跑，
 * 每个用例的输出必须**逐字一致**。
 *
 * 为什么要有这个：AOT 编译器（tpc + PHPX）和 PHP 解释器在边界行为上会有差异，
 * 而差异**不一定报错**。这个仓库一天之内踩到三次"不报错但不干活"：
 *
 *   1. `Swoole\Timer::tick` 的回调不声明 `int $timerId` → 注册成功、一次不触发
 *   2. `Gateway::send()` 在 payload=raw 时传数组 → 抛异常被 handler 的 try/catch 吞掉
 *   3. master 上的 SIGTERM handler 注册成功但从不触发
 *
 * 三次都是"跑起来看着正常"。靠读代码和读文档都发现不了，只能靠**对照**。
 *
 * 用法：`bash run.sh`（先 build，再分别用 php85 和二进制跑，diff 两份输出）。
 * 一个用例都不许挂：挂了的要么改成 AOT 友好的写法，要么记进 README 的"已知差异"。
 *
 * 用例只输出**规范化的字符串**：浮点用 `%.6F`、数组用 JSON、顺序显式排序——
 * 不然 diff 会被格式噪声淹没，真正的问题反而看不见。
 */

const CASES = [
    "closure_empty_arity",
    "closure_extra_args",
    "closure_use_by_ref",
    "closure_use_by_value",
    "closure_nested_capture",
    "recursion_via_closure",
    "array_union_operator",
    "array_keys_types",
    "array_sparse_foreach",
    "array_numeric_string_keys",
    "array_plus_order",
    "array_spread",
    "array_multisort_stability",
    "usort_callback",
    "intdiv_negative",
    "modulo_negative",
    "int_overflow_64",
    "float_to_int_truncate",
    "numeric_string_arith",
    "string_concat_num",
    "sprintf_precision",
    "json_roundtrip_unicode",
    "json_float_repr",
    "json_big_int",
    "json_empty_array_vs_object",
    "magic_tostring",
    "late_static_binding",
    "clone_shallow",
    "instanceof_inherit",
    "exception_finally_order",
    "exception_previous_chain",
    "generator_yield_keys",
    "call_user_func_static",
    "static_var_in_function",
    "null_coalesce_chain",
    "null_in_arithmetic",
    "isset_vs_array_key_exists",
    "string_functions_edge",
    "mb_string_length",
    "hrtime_monotonic_shape",
    "sort_with_mixed_keys",
    "switch_loose_compare",
    "ternary_shorthand",
    "list_destructuring",
    "reference_param_mutation",
];

/** 把任意值规范成一行字符串（浮点定精度、数组排序、对象取类名） */
function norm($v): string
{
    if (is_float($v)) {
        return "f:" . sprintf("%.6F", $v);
    }
    if (is_bool($v)) {
        return $v ? "true" : "false";
    }
    if ($v === null) {
        return "null";
    }
    if (is_int($v)) {
        return "i:" . $v;
    }
    if (is_string($v)) {
        return "s:" . $v;
    }
    if (is_array($v)) {
        $out = [];
        foreach ($v as $k => $item) {
            $out[] = (is_int($k) ? "i" : "s") . ":" . $k . "=" . norm($item);
        }
        return "[" . implode(",", $out) . "]";
    }
    if (is_object($v)) {
        return "o:" . get_class($v);
    }
    return "?:" . gettype($v);
}

// ---------- 闭包 / 回调 ----------

function case_closure_empty_arity()
{
    // 空参闭包被传入额外实参：PHP 允许，AOT 下要确认不炸
    $f = function () {
        return 7;
    };
    return $f(1, 2, 3);
}

function case_closure_extra_args()
{
    $f = function (int $a, int $b) {
        return $a + $b;
    };
    // 多传的参数应当被忽略
    return $f(3, 4, 5, 6);
}

function case_closure_use_by_ref()
{
    $n = 0;
    $add = function () use (&$n): void {
        $n = $n + 5;
    };
    $add();
    $add();
    return $n;
}

function case_closure_use_by_value()
{
    $n = 1;
    $f = function () use ($n): int {
        return $n;
    };
    $n = 99;      // 改外面不影响已捕获的值
    return $f();
}

function case_closure_nested_capture()
{
    $base = 10;
    $outer = function (int $x) use ($base) {
        $inner = function (int $y) use ($base, $x): int {
            return $base + $x + $y;
        };
        return $inner(1);
    };
    return $outer(2);
}

function case_recursion_via_closure()
{
    // 闭包递归必须靠引用自捕获：`use (&$self)`
    $fact = function (int $n) use (&$fact): int {
        return $n <= 1 ? 1 : $n * $fact($n - 1);
    };
    return $fact(5);
}

// ---------- 数组 ----------

function case_array_union_operator()
{
    // `+` 保留左侧、补右侧缺失键（与 array_merge 语义不同）——本项目踩过键类型被放宽
    $a = [1 => "a", 2 => "b"];
    $b = [2 => "X", 3 => "c"];
    $r = $a + $b;
    ksort($r);
    return $r;
}

function case_array_keys_types()
{
    $mixed = ["x" => 1, 5 => 2, "7" => 3];
    $keys = array_keys($mixed);
    $out = [];
    foreach ($keys as $k) {
        $out[] = gettype($k) . ":" . $k;
    }
    return $out;
}

function case_array_sparse_foreach()
{
    $a = [];
    $a[10] = "a";
    $a[20] = "b";
    $out = [];
    foreach ($a as $k => $v) {
        $out[] = $k . "-" . $v;
    }
    return $out;
}

function case_array_numeric_string_keys()
{
    // "07" 不是规范数字串，保留为字符串键；"7" 会被转成 int 键
    $a = [];
    $a["07"] = 1;
    $a["7"] = 2;
    ksort($a);
    return array_keys($a);
}

function case_array_plus_order()
{
    $a = ["k" => 1];
    $b = ["k" => 2, "j" => 3];
    return ($a + $b)["k"] === 1;
}

function case_array_spread()
{
    $a = [1, 2];
    $b = [0, ...$a, 3];
    return $b;
}

function case_array_multisort_stability()
{
    $rows = [
        ["b", 2],
        ["a", 2],
        ["c", 1],
    ];
    usort($rows, function (array $x, array $y): int {
        return $x[1] <=> $y[1];
    });
    $out = [];
    foreach ($rows as $r) {
        $out[] = $r[0];
    }
    return $out;   // PHP 8 起 usort 稳定：b 在 a 前（同分保持原序）
}

function case_usort_callback()
{
    $a = [3, 1, 2];
    usort($a, function (int $x, int $y): int {
        return $x <=> $y;
    });
    return $a;
}

// ---------- 数值 ----------

function case_intdiv_negative()
{
    return [intdiv(-7, 2), intdiv(7, -2), intdiv(-7, -2)];
}

function case_modulo_negative()
{
    return [-7 % 3, 7 % -3, -7 % -3];
}

function case_int_overflow_64()
{
    $max = PHP_INT_MAX;
    return [$max, $max + 1, PHP_INT_MIN];
}

function case_float_to_int_truncate()
{
    return [(int) 3.9, (int) -3.9, (int) "42abc"];
}

function case_numeric_string_arith()
{
    return ["10" + 5, "3.5" * 2, "1e3" + 0];
}

function case_string_concat_num()
{
    return 1 . 2 . 3.5;
}

function case_sprintf_precision()
{
    return sprintf("%.2F|%05d|%s|%x", 1.005, 42, "ab", 255);
}

// ---------- JSON ----------

function case_json_roundtrip_unicode()
{
    $src = ["名字" => "张三", "emoji" => "🏮", "n" => 1];
    $json = json_encode($src);
    $back = json_decode($json, true);
    return [$json, $back["名字"], strlen($json)];
}

function case_json_float_repr()
{
    return json_encode([1.0, 0.1 + 0.2, -0.0, 1e20]);
}

function case_json_big_int()
{
    $big = 9007199254740993;   // 2^53+1，超出 double 精度
    $json = json_encode(["n" => $big]);
    $back = json_decode($json, true);
    return [$json, $back["n"] === $big];
}

function case_json_empty_array_vs_object()
{
    return [json_encode([]), json_encode([1 => "a"]), json_encode(new stdClass())];
}

// ---------- 对象 ----------

class BaseThing
{
    public string $name = "base";

    public function who(): string
    {
        return static::class;
    }
}

final class ChildThing extends BaseThing
{
    public function __toString(): string
    {
        return "child:" . $this->name;
    }
}

function case_magic_tostring()
{
    $o = new ChildThing();
    return "v=" . $o;
}

function case_late_static_binding()
{
    $o = new ChildThing();
    return $o->who();
}

function case_clone_shallow()
{
    $a = new stdClass();
    $a->n = 1;
    $b = clone $a;
    $b->n = 2;
    return [$a->n, $b->n];
}

function case_instanceof_inherit()
{
    $o = new ChildThing();
    return [$o instanceof BaseThing, $o instanceof ChildThing, $o instanceof stdClass];
}

// ---------- 异常 ----------

function case_exception_finally_order()
{
    $log = [];
    try {
        $log[] = "try";
        throw new RuntimeException("boom");
    } catch (RuntimeException $e) {
        $log[] = "catch";
    } finally {
        $log[] = "finally";
    }
    $log[] = "after";
    return $log;
}

function case_exception_previous_chain()
{
    $inner = new LogicException("inner");
    $outer = new RuntimeException("outer", 0, $inner);
    $p = $outer->getPrevious();
    return [$outer->getMessage(), $p === null ? "none" : $p->getMessage()];
}

// ---------- 生成器 / callable / static ----------

function case_generator_yield_keys()
{
    $g = (function () {
        yield "a" => 1;
        yield 5 => 2;
    })();
    $out = [];
    foreach ($g as $k => $v) {
        $out[] = gettype($k) . ":" . $k . "=" . $v;
    }
    return $out;
}

function case_call_user_func_static()
{
    return [call_user_func([ChildThing::class, "who"]), call_user_func("intval", "42")];
}

function counter(): int
{
    static $n = 0;
    $n++;
    return $n;
}

function case_static_var_in_function()
{
    return [counter(), counter(), counter()];
}

// ---------- 空值 / 字符串 / 时间 ----------

function case_null_coalesce_chain()
{
    $a = null;
    $b = null;
    $c = "hit";
    return [$a ?? $b ?? $c, $a ?? "dflt", isset($a) ? "set" : "unset"];
}

function case_null_in_arithmetic()
{
    $n = null;
    return [norm($n + 1), norm(1 + $n), norm($n . "x")];
}

function case_isset_vs_array_key_exists()
{
    $a = ["n" => null, "z" => 0];
    return [
        isset($a["n"]),
        array_key_exists("n", $a),
        isset($a["z"]),
        array_key_exists("missing", $a),
    ];
}

function case_string_functions_edge()
{
    return [
        str_pad("7", 3, "0", STR_PAD_LEFT),
        substr("abcdef", -3),
        str_repeat("ab", 3),
        ltrim("00012", "0"),
        explode(",", ",a,,b"),
    ];
}

function case_mb_string_length()
{
    return [strlen("张三abc"), mb_strlen("张三abc"), mb_substr("张三abc", 1, 2)];
}

function case_hrtime_monotonic_shape()
{
    $a = hrtime(true);
    $b = hrtime(true);
    // 只断言形状：单调不减、量纲是纳秒（两位以上），不比对具体数值
    return [$b >= $a, $b - $a >= 0, is_int($a)];
}

function case_sort_with_mixed_keys()
{
    $a = ["10" => "x", "9" => "y", "b" => "z"];
    ksort($a);
    return array_keys($a);
}

function case_switch_loose_compare()
{
    $hits = [];
    foreach (["1", 1, "1.0", true] as $v) {
        switch ($v) {
            case 1:
                $hits[] = gettype($v) . ":" . norm($v) . "->1";
                break;
            default:
                $hits[] = gettype($v) . ":" . norm($v) . "->other";
                break;
        }
    }
    return $hits;
}

function case_ternary_shorthand()
{
    $a = "x";
    $empty = "";
    return [$a ?: "fallback", $empty ?: "fallback", $a ? "yes" : "no"];
}

function case_list_destructuring()
{
    [$a, [$b, $c]] = [1, [2, 3]];
    ["k" => $d] = ["k" => 4];
    return [$a, $b, $c, $d];
}

function case_reference_param_mutation()
{
    $arr = [1, 2];
    $f = function (array &$a): void {
        $a[] = 3;
    };
    $f($arr);
    return $arr;
}

// ---------- 主流程 ----------

function main(): void
{
    $fail = 0;
    foreach (CASES as $name) {
        $fn = "case_" . $name;
        if (!function_exists($fn)) {
            echo $name . " = MISSING" . PHP_EOL;
            $fail++;
            continue;
        }
        try {
            echo $name . " = " . norm($fn()) . PHP_EOL;
        } catch (\Throwable $e) {
            echo $name . " = THREW(" . get_class($e) . ":" . $e->getMessage() . ")" . PHP_EOL;
        }
    }
    if ($fail > 0) {
        exit(1);
    }
}
