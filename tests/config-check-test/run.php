<?php

/**
 * `tsf config check` 的负例测试。
 *
 * ## 为什么要有这个
 *
 * 导出期的校验（主键重复、引用不存在、字段重名、越界）**一条都没被测过**。
 * 这类代码最危险：它保证别人不出错，自己错了却没人知道——而且策划改表时
 * 看到"校验通过"就放心了，错表会一路流到线上。
 *
 * 已经在 `tests/config-test` 里测过的是**运行期** `Reader` 的 schema 校验；
 * 这里测的是**导出期** `tools/config/Exporter`。
 *
 * ## 为什么解释执行而不是编译
 *
 * Exporter 用 ZipArchive / SimpleXML 读 xlsx，这两个扩展只在开发机上有
 * （AOT 产物里不需要，配置表在开发期就转成 .bin 了）。所以这个套件是
 * `php85 run.php`，不生成产物。
 *
 * ## 怎么造表
 *
 * 手写 xlsx 的最小结构（workbook.xml + rels + sheet1.xml，单元格用 inlineStr）。
 * 不依赖 PhpSpreadsheet——和 Xlsx.php 的取舍一致：工具链不能因为依赖装不上而挂。
 */

$root = dirname(__DIR__, 2);
$tsf = $root . "/tsf";
$php = PHP_BINARY;

// ZipArchive 只在开发机的工具链里有。缺了是"环境没装"，按约定 SKIP（exit 77）而不是红：
// 2026-11-27 CI 第一次在 GitHub 上跑，环境脚本没把 zip 编进 PHP，这个套件直接死在 makeXlsx 里，
// 汇总行只留下一串调用栈。
if (!class_exists("ZipArchive")) {
    echo "SKIP 需要 zip 扩展（ZipArchive）；scripts/ci-setup.sh 编 PHP 时要带 --enable-zip" . PHP_EOL;
    exit(77);
}

$pass = 0;
$fail = 0;

function ok(bool $cond, string $label, string $detail = ""): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  OK   " . $label . PHP_EOL;
    } else {
        $fail++;
        echo "  FAIL " . $label . ($detail === "" ? "" : "  → " . $detail) . PHP_EOL;
    }
}

/** 造一个最小 xlsx：$rows 是二维数组，第 1 行列名、第 2 行类型声明 */
function makeXlsx(string $path, array $rows): void
{
    $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet><sheetData>';
    foreach ($rows as $r => $cols) {
        $rn = $r + 1;
        $sheet .= '<row r="' . $rn . '">';
        foreach ($cols as $c => $v) {
            $ref = chr(65 + $c) . $rn;
            if (is_int($v) || is_float($v)) {
                $sheet .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
            } else {
                $sheet .= '<c r="' . $ref . '" t="inlineStr"><is><t>'
                    . htmlspecialchars((string) $v, ENT_XML1) . '</t></is></c>';
            }
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';

    $zip = new ZipArchive();
    $rc = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($rc !== true) {
        throw new RuntimeException("打不开 xlsx: " . $path);
    }
    $zip->addFromString(
        "xl/workbook.xml",
        '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>'
    );
    $zip->addFromString(
        "xl/_rels/workbook.xml.rels",
        '<?xml version="1.0" encoding="UTF-8"?><Relationships>'
        . '<Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="ws"/></Relationships>'
    );
    $zip->addFromString("xl/worksheets/sheet1.xml", $sheet);
    $zip->close();
}

/**
 * 造工程 → 跑 `tsf config check` → 返回 [退出码, 合并输出]
 *
 * 走命令行而不是直接 new Exporter：一来 report() 用 fwrite(STDOUT)，缓冲拦不住；
 * 二来这样测的正是用户会敲的那条命令。
 */
function runCheck(string $tsf, string $php, string $dir): array
{
    $cmd = escapeshellarg($php) . " " . escapeshellarg($tsf . "/bin/tsf")
        . " config check " . escapeshellarg($dir) . " 2>&1";
    $out = [];
    $rc = 0;
    exec($cmd, $out, $rc);
    return [$rc, implode("\n", $out)];
}

function scenario(string $tsf, string $php, string $label, array $tables, int $wantRc, string $wantSub): void
{
    $dir = sys_get_temp_dir() . "/cfgcheck-" . substr(md5($label), 0, 8);
    exec("rm -rf " . escapeshellarg($dir));
    mkdir($dir . "/config/tables", 0777, true);
    foreach ($tables as $name => $rows) {
        makeXlsx($dir . "/config/tables/" . $name . ".xlsx", $rows);
    }

    [$rc, $out] = runCheck($tsf, $php, $dir);
    $hit = $wantSub === "" || strpos($out, $wantSub) !== false;
    ok($rc === $wantRc && $hit, $label, "rc=" . $rc . " want=" . $wantRc
        . ($hit ? "" : "；输出里没有「" . $wantSub . "」\n" . $out));
    exec("rm -rf " . escapeshellarg($dir));
}

// 正常的两张表：item 引用 monster 的主键
$goodItem = [
    ["id", "name", "monster"],
    ["int(1..9999)", "string(1..8)", "ref:monster"],
    [1001, "木剑", 2001],
];
$goodMonster = [
    ["id", "hp"],
    ["int(1..9999)", "int(0..999)"],
    [2001, 100],
];

echo "== 对照：合法表应当通过 ==" . PHP_EOL;
scenario($tsf, $php, "合法表校验通过", ["item" => $goodItem, "monster" => $goodMonster], 0, "校验通过");

echo "== 负例 1：主键重复 ==" . PHP_EOL;
scenario($tsf, $php, "同一张表两行主键相同被拒", [
    "item" => [
        ["id", "name", "monster"],
        ["int(1..9999)", "string(1..8)", "ref:monster"],
        [1001, "木剑", 2001],
        [1001, "铁剑", 2001],
    ],
    "monster" => $goodMonster,
], 1, "重复");

echo "== 负例 2：引用不存在的值 ==" . PHP_EOL;
scenario($tsf, $php, "ref 指向不存在的行被拒", [
    "item" => [
        ["id", "name", "monster"],
        ["int(1..9999)", "string(1..8)", "ref:monster"],
        [1001, "木剑", 9999],
    ],
    "monster" => $goodMonster,
], 1, "不存在");

echo "== 负例 3：字段名重复 ==" . PHP_EOL;
scenario($tsf, $php, "表头两列同名被拒", [
    "item" => [
        ["id", "id"],
        ["int(1..9999)", "int(1..9999)"],
        [1001, 1002],
    ],
], 1, "字段名");

echo "== 负例 4：值越界 ==" . PHP_EOL;
scenario($tsf, $php, "hp=5000 超出 int(0..999) 被拒", [
    "monster" => [
        ["id", "hp"],
        ["int(1..9999)", "int(0..999)"],
        [2001, 5000],
    ],
], 1, "hp");

// 注意 `string` 不带区间是合法的（不限长度），所以"类型写错"要另找例子
echo "== 负例 5：未知类型 ==" . PHP_EOL;
scenario($tsf, $php, "未知类型 strin 被拒", [
    "item" => [
        ["id", "name"],
        ["int(1..9999)", "strin(1..8)"],
        [1001, "木剑"],
    ],
], 1, "未知类型");

echo "== 负例 6：区间写反 ==" . PHP_EOL;
scenario($tsf, $php, "int(99..1) 区间反了被拒", [
    "monster" => [
        ["id", "hp"],
        ["int(1..9999)", "int(99..1)"],
        [2001, 50],
    ],
], 1, "区间反");

echo PHP_EOL . "通过 " . $pass . " / 失败 " . $fail . PHP_EOL;
exit($fail === 0 ? 0 : 1);
