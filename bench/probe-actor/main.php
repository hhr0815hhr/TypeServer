<?php

/**
 * 探针：房间里「不同身份的成员」怎么表达。
 *
 * 三种写法，测同一件事——遍历 1000 个成员，按身份做不同的事：
 *
 *   A 继承：abstract Actor + PlayerActor / NpcActor，数组存父类，靠虚方法派发
 *   B 单类 + role 字段：一个 Member 类，switch($role) 分支
 *   C 单类 + 旁表：Member 只放公共字段，身份专属数据放 array<int, XxxData>
 *
 * 关键问题不是"哪个快几纳秒"，而是**AOT 能不能干净地支持多态派发**——
 * 如果不支持，继承方案在编译期就得放弃。
 */

abstract class Actor
{
    public function __construct(public int $uid)
    {
    }

    abstract public function kind(): int;

    abstract public function describe(): string;
}

final class PlayerActor extends Actor
{
    public int $fd = 0;

    public string $name = "";

    public function kind(): int
    {
        return 1;
    }

    public function describe(): string
    {
        return "player:" . $this->uid . ":" . $this->name;
    }
}

final class NpcActor extends Actor
{
    public int $aiId = 0;

    public int $patrol = 0;

    public function kind(): int
    {
        return 2;
    }

    public function describe(): string
    {
        return "npc:" . $this->uid . ":" . $this->aiId;
    }
}

final class Member
{
    public const PLAYER = 1;

    public const NPC = 2;

    public int $uid = 0;

    public int $role = 1;

    /** 各身份共用的附加槽（玩家存 fd，NPC 存 aiId） */
    public int $slot = 0;

    public string $name = "";
}

function t(string $label, bool $ok, string $detail = ""): void
{
    echo ($ok ? "  OK   " : "  FAIL ") . $label . ($detail === "" ? "" : "  → " . $detail) . PHP_EOL;
}

function main(): void
{
    $n = 1000;

    // ---------- A 继承 ----------
    echo "== A 继承：抽象类数组 + 虚方法 ==" . PHP_EOL;
    $actors = [];
    for ($i = 1; $i <= $n; $i++) {
        if ($i % 2 === 0) {
            $p = new PlayerActor($i);
            $p->fd = $i;
            $p->name = "p" . $i;
            $actors[] = $p;
        } else {
            $c = new NpcActor($i);
            $c->aiId = $i;
            $actors[] = $c;
        }
    }
    t("混装进一个数组", count($actors) === $n);

    $t0 = hrtime(true);
    $sum = 0;
    foreach ($actors as $a) {
        $sum += $a->kind();
    }
    $t1 = hrtime(true);
    t("虚方法派发可用", $sum === 1500, "sum=" . $sum);
    echo "  虚方法派发 " . number_format(($t1 - $t0) / $n, 1) . " ns/个" . PHP_EOL;

    // 取子类专属字段：必须 instanceof 收窄
    $t2 = hrtime(true);
    $acc = 0;
    foreach ($actors as $a) {
        if ($a instanceof PlayerActor) {
            $acc += $a->fd;
        } elseif ($a instanceof NpcActor) {
            $acc += $a->aiId;
        }
    }
    $t3 = hrtime(true);
    t("instanceof 收窄后取子类字段", $acc > 0, "acc=" . $acc);
    echo "  instanceof 分支 " . number_format(($t3 - $t2) / $n, 1) . " ns/个" . PHP_EOL;

    // ---------- B 单类 + role ----------
    echo "== B 单类 + role 字段 ==" . PHP_EOL;
    $members = [];
    for ($i = 1; $i <= $n; $i++) {
        $m = new Member();
        $m->uid = $i;
        $m->role = ($i % 2 === 0) ? Member::PLAYER : Member::NPC;
        $m->slot = $i;
        $m->name = "m" . $i;
        $members[] = $m;
    }

    $t4 = hrtime(true);
    $sum2 = 0;
    foreach ($members as $item) {
        $sum2 += $item->role;
    }
    $t5 = hrtime(true);
    t("字段直读", $sum2 === 1500, "sum=" . $sum2);
    echo "  role 直读 " . number_format(($t5 - $t4) / $n, 1) . " ns/个" . PHP_EOL;

    $t6 = hrtime(true);
    $acc2 = 0;
    foreach ($members as $item) {
        if ($item->role === Member::PLAYER) {
            $acc2 += $item->slot;
        } else {
            $acc2 += $item->slot;
        }
    }
    $t7 = hrtime(true);
    t("switch 分支", $acc2 > 0, "acc=" . $acc2);
    echo "  role 分支 " . number_format(($t7 - $t6) / $n, 1) . " ns/个" . PHP_EOL;

    // ---------- C 造对象的成本 ----------
    echo "== 造对象成本 ==" . PHP_EOL;
    $t8 = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $x = new PlayerActor($i);
    }
    $t9 = hrtime(true);
    echo "  PlayerActor  " . number_format(($t9 - $t8) / $n, 1) . " ns/个" . PHP_EOL;

    $t10 = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $y = new Member();
    }
    $t11 = hrtime(true);
    echo "  Member        " . number_format(($t11 - $t10) / $n, 1) . " ns/个" . PHP_EOL;

    // ---------- D 继承 + db 映射会怎样 ----------
    echo "== D 继承与「不跑构造函数造实例」的兼容性 ==" . PHP_EOL;
    $rc = new ReflectionClass(PlayerActor::class);
    $obj = $rc->newInstanceWithoutConstructor();
    t("子类可跳过构造函数实例化", $obj instanceof PlayerActor);
    t("父类字段默认值在", $obj->uid === 0, "uid=" . $obj->uid);
    $parent = $rc->getParentClass();
    t("能反射到父类", $parent !== false);
    if ($parent !== false) {
        t("父类是 Actor", $parent->getName() === "Actor", "parent=" . $parent->getName());
    }

    // 父类的 private 属性，子类实例上能不能用反射写
    $all = $rc->getProperties();
    $names = "";
    foreach ($all as $rp) {
        $names .= $rp->getName() . ",";
    }
    echo "  PlayerActor 可见属性: " . $names . PHP_EOL;
    t("getProperties 能拿到继承来的属性", str_contains($names, "uid"));

    echo "完成" . PHP_EOL;
}
