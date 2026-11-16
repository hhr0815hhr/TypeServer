<?php

/**
 * 探针 2：Repo 的四个必需机制。
 *
 * 探针 1 证明了「反射读 + Attribute + 动态类名」可用，
 * 但 Repo 真正靠的是下面这四条，任何一条不成立都得换设计：
 *
 *   1. newInstanceWithoutConstructor()  —— 从行数据造实体，不走构造函数
 *   2. ReflectionProperty::setValue()   —— 把列值写进属性
 *   3. ReflectionProperty::getValue()   —— 读回来
 *   4. getProperties() 的顺序与类型声明 —— 决定能否一次拿全列清单
 */

#[Attribute(Attribute::TARGET_CLASS)]
final class Table
{
    public function __construct(public string $name)
    {
    }
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Col
{
    public function __construct(public string $name = "")
    {
    }
}

#[Table("players")]
final class Player
{
    #[Col]
    public int $uid = 0;

    #[Col("hp")]
    public int $hp = 100;

    #[Col]
    public string $name = "anon";

    /** 没有 #[Col] 的属性不该被映射 */
    public string $cache = "";

    public function __construct(int $uid)
    {
        $this->uid = $uid;
        $this->cache = "ctor-ran";
    }
}

function t(string $label, bool $ok, string $detail = ""): void
{
    echo ($ok ? "  OK   " : "  FAIL ") . $label . ($detail === "" ? "" : "  → " . $detail) . PHP_EOL;
}

function main(): void
{
    $rc = new ReflectionClass(Player::class);

    echo "== 1. 不走构造函数造实例 ==" . PHP_EOL;
    $p = $rc->newInstanceWithoutConstructor();
    t("newInstanceWithoutConstructor", $p instanceof Player);
    t("构造函数确实没跑", $p->cache === "", "cache=[" . $p->cache . "]");
    t("属性有默认值", $p->hp === 100, "hp=" . $p->hp);

    echo "== 2. 反射写属性 ==" . PHP_EOL;
    $wrote = true;
    $err = "";
    try {
        $prop = $rc->getProperty("hp");
        $prop->setValue($p, 42);
        $prop->setValue($p, 43);
    } catch (\Throwable $e) {
        $wrote = false;
        $err = $e->getMessage();
    }
    t("setValue 可用", $wrote, $err);
    t("写进去读得出来", $p->hp === 43, "hp=" . $p->hp);

    echo "== 3. 反射读属性 ==" . PHP_EOL;
    $back = 0;
    try {
        $prop2 = $rc->getProperty("hp");
        $back = (int) $prop2->getValue($p);
    } catch (\Throwable $e) {
        $err = $e->getMessage();
    }
    t("getValue 可用", $back === 43, "got=" . $back);

    echo "== 4. 属性清单与 Attribute ==" . PHP_EOL;
    $props = $rc->getProperties(ReflectionProperty::IS_PUBLIC);
    $mapped = 0;
    $names = "";
    foreach ($props as $pr) {
        $attrs = $pr->getAttributes(Col::class);
        if ($attrs === []) {
            continue;
        }
        $mapped++;
        $names .= $pr->getName() . ",";
    }
    t("只数带 #[Col] 的属性", $mapped === 3, "mapped=" . $mapped . " [" . $names . "]");

    // 属性类型：决定 setValue 时要不要手工转型
    $type = "";
    foreach ($props as $pr) {
        if ($pr->getName() === "hp") {
            $t2 = $pr->getType();
            if ($t2 !== null) {
                $type = $t2->getName();
            }
        }
    }
    t("能拿到属性类型", $type === "int", "hp type=" . $type);

    echo "== 5. 批量造 1000 个实体（每行一次反射）==" . PHP_EOL;
    $n = 1000;
    $t0 = hrtime(true);
    $ids = [];
    for ($i = 0; $i < $n; $i++) {
        $e = $rc->newInstanceWithoutConstructor();
        $prop = $rc->getProperty("hp");
        $prop->setValue($e, $i);
        $ids[] = $e;
    }
    $t1 = hrtime(true);
    echo "  每实体 " . number_format(($t1 - $t0) / $n, 1) . " ns" . PHP_EOL;
    t("造了 1000 个", count($ids) === $n);

    echo "== 6. 元数据缓存后重复用 ==" . PHP_EOL;
    $t2 = hrtime(true);
    $prop3 = $rc->getProperty("hp");
    for ($i = 0; $i < $n; $i++) {
        $e = $rc->newInstanceWithoutConstructor();
        $prop3->setValue($e, $i);
    }
    $t3 = hrtime(true);
    echo "  缓存 ReflectionProperty 后 " . number_format(($t3 - $t2) / $n, 1) . " ns/个" . PHP_EOL;
}
