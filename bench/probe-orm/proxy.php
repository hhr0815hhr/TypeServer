<?php

/**
 * 探针：AOT 编译的类能不能被"运行时定义的子类"继承。
 *
 * 这是 Doctrine / Cycle 懒加载代理的地基：
 *   代理类在运行时生成（eval 或写文件 include），继承你的实体类，
 *   用 __get/__set 拦截属性访问实现延迟加载。
 *
 * 官方文档只说「不能继承仅在运行时定义的类」，没说反方向。
 * 如果这里能跑通，说明代理模式在 AOT 下成立。
 */

class RealEntity
{
    public int $x = 1;

    public string $name = "real";

    public function hello(): string
    {
        return "hello-from-real";
    }
}

function main(): void
{
    echo "== 运行时子类继承 AOT 类 ==" . PHP_EOL;

    $p = new RealEntity();
    echo "  原始: " . $p->hello() . " x=" . $p->x . PHP_EOL;

    // 运行时定义子类
    eval('class RuntimeProxy extends RealEntity {
        public function hello(): string { return "hello-from-proxy"; }
        public function extra(): string { return "extra"; }
    }');

    echo "  eval 后 class_exists: " . (class_exists("RuntimeProxy") ? "yes" : "no") . PHP_EOL;

    $proxy = new RuntimeProxy();
    echo "  代理: " . $proxy->hello() . PHP_EOL;
    echo "  继承的方法: " . $proxy->extra() . PHP_EOL;
    echo "  继承的属性: x=" . $proxy->x . " name=" . $proxy->name . PHP_EOL;
    echo "  instanceof RealEntity: " . ($proxy instanceof RealEntity ? "yes" : "no") . PHP_EOL;

    // 反射看它是不是承认真实父类
    $rc = new \ReflectionClass($proxy);
    $parent = $rc->getParentClass();
    echo "  反射父类: " . ($parent === false ? "(none)" : $parent->getName()) . PHP_EOL;

    echo "完成" . PHP_EOL;
}
