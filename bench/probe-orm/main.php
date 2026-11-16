<?php

/**
 * 探针：ORM 依赖的四类机制在 AOT 下到底能不能用。
 *
 *   1. 反射读属性 / 读 Attribute（Doctrine、Cycle 的元数据来源）
 *   2. 魔术方法 __get / __call（Eloquent 的核心）
 *   3. 动态属性赋值（ORM 习惯的写法）
 *   4. 动态类名 new $cls（代理/工厂）
 *
 * 这四项决定了「现成 ORM 能不能直接用」，而不是凭印象判断。
 */

#[Attribute(Attribute::TARGET_CLASS)]
final class Table
{
    public function __construct(public string $name)
    {
    }
}

#[Table("players")]
final class Player
{
    public int $uid = 0;

    public int $hp = 100;

    public string $name = "anon";

    /** @var array<string, int> */
    private array $bag = [];

    public function __get(string $key): string|int|array|null
    {
        return $this->bag[$key] ?? null;
    }

    public function __call(string $method, array $args): string
    {
        return "called:" . $method;
    }
}

function main(): void
{
    echo "== 1) 反射 ==" . PHP_EOL;
    $rc = new \ReflectionClass(Player::class);
    echo "  类名: " . $rc->getName() . PHP_EOL;

    $props = $rc->getProperties();
    echo "  属性数: " . count($props) . PHP_EOL;

    $p = new Player();
    $prop = $rc->getProperty("hp");
    echo "  getProperty(hp) = " . (string) $prop->getValue($p) . PHP_EOL;

    $attrs = $rc->getAttributes(Table::class);
    echo "  Attribute 数: " . count($attrs) . PHP_EOL;
    if (count($attrs) === 1) {
        $t = $attrs[0]->newInstance();
        echo "  Table 名: " . $t->name . PHP_EOL;
    }

    echo "== 2) 魔术方法 ==" . PHP_EOL;
    echo "  __get(bag.x) = " . var_export($p->bag_x, true) . PHP_EOL;
    echo "  __call(foo)  = " . $p->foo() . PHP_EOL;

    echo "== 3) 动态属性 ==" . PHP_EOL;
    // PHP 8.2 起标准 PHP 会弃用；AOT 可能直接拒绝
    echo "  跳过（下面单独测）" . PHP_EOL;

    echo "== 4) 动态类名 ==" . PHP_EOL;
    $cls = "Player";
    $p2 = new $cls();
    echo "  new \$cls() -> " . $p2->name . PHP_EOL;

    echo "== 5) class_exists / method_exists ==" . PHP_EOL;
    echo "  class_exists(Player): " . (class_exists("Player") ? "yes" : "no") . PHP_EOL;
    echo "  method_exists(Player, __get): " . (method_exists("Player", "__get") ? "yes" : "no") . PHP_EOL;

    echo "完成" . PHP_EOL;
}
