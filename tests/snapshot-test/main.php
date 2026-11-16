<?php

/**
 * pkg/snapshot 测试。
 *
 * 验收三条：**恢复 ≤50 ms**、**冷哈希一致**、两份存储实现行为一致。
 *
 * 「冷哈希」不是整份文件的哈希——同状态在不同时刻采集，`atMs` 必然不同。
 * 要一致的是 `Codec::bodyHash()`，也就是"状态本身"。
 */

use TSF\Entity\EntitySet;
use TSF\Room\Member;
use TSF\Room\Room;
use TSF\Snapshot\Codec;
use TSF\Snapshot\FileStore;
use TSF\Snapshot\MemoryStore;
use TSF\Snapshot\Restorer;
use TSF\Snapshot\Snapshot;

final class T
{
    public static int $pass = 0;

    public static int $fail = 0;

    public static function ok(bool $cond, string $label, string $detail = ""): void
    {
        if ($cond) {
            self::$pass++;
            echo "  OK   " . $label . PHP_EOL;
        } else {
            self::$fail++;
            echo "  FAIL " . $label . ($detail === "" ? "" : "  → " . $detail) . PHP_EOL;
        }
    }

    public static function eq(int|string|float|bool $got, int|string|float|bool $want, string $label): void
    {
        self::ok($got === $want, $label, "got=" . var_export($got, true) . " want=" . var_export($want, true));
    }
}

/** 造一个状态不简单的房间：有状态、有帧、有玩家、有观战、有席位空档 */
function makeRoom(): Room
{
    $room = new Room(7, 4);
    $room->add(101, "Alice");
    $room->add(102, "Bob");
    $room->add(103, "Carol");
    $room->remove(102);                     // 留一个席位空档
    $room->addAs(900, Member::SPECTATOR, "观众");
    $room->start();
    $room->tick(50);
    $room->tick(48);
    return $room;
}

function makeEnts(): EntitySet
{
    $e = new EntitySet();
    $e->spawn(101, 2000, 5000, 100, -50);
    $e->spawn(103, 8000, 5000, -100, 0);
    return $e;
}

/** 1. 编解码往返 */
function caseCodec(): void
{
    echo "== [1] 编解码 ==" . PHP_EOL;

    $s = new Snapshot("room", 42);
    $s->put("a", ["x" => 1, "y" => "中文"]);
    $s->put("b", ["list" => [1, 2, 3]]);

    $json = Codec::encode($s);
    $back = Codec::decode($json);
    T::ok($back !== null, "往返成功");
    if ($back !== null) {
        T::eq($back->kind, "room", "kind 保留");
        T::eq($back->key, 42, "key 保留");
        T::eq($back->version, Codec::VERSION, "版本保留");
        T::eq($back->atMs, $s->atMs, "采集时间保留");
        $a = $back->get("a");
        T::eq((int) $a["x"], 1, "块 a 的 x");
        T::eq((string) $a["y"], "中文", "中文不被转义");
        T::eq(count($back->get("b")["list"]), 3, "块 b 的列表长度");
    }

    // 空块
    $empty = new Snapshot("room", 1);
    $back2 = Codec::decode(Codec::encode($empty));
    T::ok($back2 !== null && $back2->blocks() === [], "空 body 往返");
}

/** 2. 坏数据一律返回 null，不抛 */
function caseBadData(): void
{
    echo "== [2] 坏数据 ==" . PHP_EOL;

    T::ok(Codec::decode("not json") === null, "非 JSON 返回 null");
    T::ok(Codec::decode("{}") === null, "缺版本号返回 null");
    T::ok(Codec::decode('{"v":99,"k":"room","id":1}') === null, "版本不符返回 null");
    T::ok(Codec::decode('{"v":1,"id":1}') === null, "缺 kind 返回 null");
    T::ok(Codec::decode('{"v":1,"k":"room"}') === null, "缺 key 返回 null");

    // 失败原因可查
    Codec::decode('{"v":99,"k":"room","id":1}');
    T::ok(str_contains(Codec::why(), "版本"), "why() 给出原因", Codec::why());

    // 版本不符时旧数据不会被误读成新格式
    $old = new Snapshot("room", 1, 0);
    $old->put("x", ["v" => 1]);
    T::ok(Codec::decode(Codec::encode($old)) === null, "旧版本快照被拒绝");
}

/** 3. 哈希确定性 */
function caseHash(): void
{
    echo "== [3] 哈希 ==" . PHP_EOL;

    $a = new Snapshot("room", 1);
    $a->put("x", ["k" => 1, "j" => 2]);
    $b = new Snapshot("room", 1);
    $b->put("x", ["k" => 1, "j" => 2]);

    T::eq(Codec::hash(Codec::encode($b)), Codec::hash(Codec::encode($a)), "同内容整份哈希相同");

    // bodyHash 忽略采集时间——两次采集时间可能相同（同一毫秒），
    // 所以这里直接比 body 哈希的稳定性
    T::eq(Codec::bodyHash($b), Codec::bodyHash($a), "bodyHash 只取决于状态");

    // 内容变了哈希就变
    $c = new Snapshot("room", 1);
    $c->put("x", ["k" => 1, "j" => 3]);
    T::ok(Codec::bodyHash($c) !== Codec::bodyHash($a), "内容不同哈希不同");
}

/** 4. MemoryStore */
function caseMemoryStore(): void
{
    echo "== [4] MemoryStore ==" . PHP_EOL;

    $st = new MemoryStore();
    $s = new Snapshot("room", 1);
    $s->put("room", ["state" => 2]);
    T::ok($st->put($s), "put 成功");

    $g = $st->get("room", 1);
    T::ok($g !== null, "get 拿得到");
    if ($g !== null) {
        T::eq((int) $g->get("room")["state"], 2, "内容正确");
    }
    T::ok($st->get("room", 2) === null, "不存在的返回 null");

    // 覆盖
    $s2 = new Snapshot("room", 1);
    $s2->put("room", ["state" => 3]);
    $st->put($s2);
    $g2 = $st->get("room", 1);
    if ($g2 !== null) {
        T::eq((int) $g2->get("room")["state"], 3, "覆盖生效");
    }

    // 多 key
    for ($i = 2; $i <= 5; $i++) {
        $x = new Snapshot("room", $i);
        $x->put("room", ["state" => 1]);
        $st->put($x);
    }
    $keys = $st->keys("room");
    T::eq(count($keys), 5, "keys 列出 5 个");
    T::eq(count($st->keys("match")), 0, "其他 kind 为空");

    T::ok($st->delete("room", 3), "delete 成功");
    T::ok(!$st->delete("room", 3), "重复删除返回 false");
    T::eq(count($st->keys("room")), 4, "删后剩 4 个");
}

/** 5. FileStore（含原子写的临时文件清理） */
function caseFileStore(): void
{
    echo "== [5] FileStore ==" . PHP_EOL;

    $dir = "/tmp/tsf_snap_test";
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            $p = $dir . "/" . $f;
            if (is_file($p)) {
                unlink($p);
            }
        }
    }

    $st = new FileStore($dir);
    $s = new Snapshot("room", 10);
    $s->put("room", ["state" => 2, "frame" => 7]);
    T::ok($st->put($s), "put 成功");

    $g = $st->get("room", 10);
    T::ok($g !== null, "get 拿得到");
    if ($g !== null) {
        T::eq((int) $g->get("room")["frame"], 7, "内容正确");
    }
    T::ok($st->get("room", 11) === null, "不存在的返回 null");

    // 写完之后不该残留 .tmp
    $tmpLeft = 0;
    foreach (scandir($dir) as $f) {
        if (str_ends_with((string) $f, ".tmp")) {
            $tmpLeft++;
        }
    }
    T::eq($tmpLeft, 0, "没有残留临时文件");

    // keys
    for ($i = 11; $i <= 13; $i++) {
        $x = new Snapshot("room", $i);
        $x->put("room", ["state" => 1]);
        $st->put($x);
    }
    T::eq(count($st->keys("room")), 4, "keys 列出 4 个");

    // 换个新 store 实例（模拟进程重启）还能读到
    $st2 = new FileStore($dir);
    T::ok($st2->get("room", 10) !== null, "新实例读到旧数据（重启可恢复）");

    // 坏文件不会让程序崩
    file_put_contents($dir . "/room_99.snap", "garbage");
    T::ok($st2->get("room", 99) === null, "坏文件返回 null");

    T::ok($st2->delete("room", 10), "delete 成功");

    // 清理
    foreach (scandir($dir) as $f) {
        $p = $dir . "/" . $f;
        if (is_file($p)) {
            unlink($p);
        }
    }
    rmdir($dir);
}

/** 6. 两份实现行为一致 */
function caseParity(): void
{
    echo "== [6] 两种存储行为一致 ==" . PHP_EOL;

    $mem = new MemoryStore();
    $dir = "/tmp/tsf_snap_parity";
    $file = new FileStore($dir);

    $s = new Snapshot("room", 5);
    $s->put("room", ["state" => 2, "members" => [["uid" => 1, "seat" => 0]]]);
    $mem->put($s);
    $file->put($s);

    $a = $mem->get("room", 5);
    $b = $file->get("room", 5);
    T::ok($a !== null && $b !== null, "两边都读得到");
    if ($a !== null && $b !== null) {
        T::eq(Codec::bodyHash($b), Codec::bodyHash($a), "读出来的状态哈希一致");
    }

    foreach (scandir($dir) as $f) {
        $p = $dir . "/" . $f;
        if (is_file($p)) {
            unlink($p);
        }
    }
    rmdir($dir);
}

/** 7. 房间快照往返 */
function caseRoomSnapshot(): void
{
    echo "== [7] 房间快照 ==" . PHP_EOL;

    $room = makeRoom();
    T::eq($room->state(), Room::PLAYING, "原房间在 PLAYING");
    T::eq($room->frame(), 2, "帧数 2");
    T::eq($room->playerCount(), 2, "占席位 2（102 走了）");
    T::eq($room->memberCount(), 3, "总人数 3（含观战）");

    $d = $room->toArray();
    $room2 = Room::fromArray($d);
    T::ok($room2 !== null, "恢复出房间");
    if ($room2 === null) {
        return;
    }

    T::eq($room2->id(), 7, "id 一致");
    T::eq($room2->state(), Room::PLAYING, "状态一致");
    T::eq($room2->frame(), 2, "帧数一致");
    T::eq($room2->elapsedMs(), 98, "累计时间一致");
    T::eq($room2->maxPlayers(), 4, "容量一致");
    T::eq($room2->playerCount(), 2, "占席位一致");
    T::eq($room2->memberCount(), 3, "总人数一致");

    // 席位号必须原样保留，不能重新分配
    T::eq($room2->members()->seatOf(101), 0, "101 还在 0 号位");
    T::eq($room2->members()->seatOf(103), 2, "103 还在 2 号位（没被压到 1 号）");
    T::eq($room2->members()->seatOf(900), -1, "观战仍然无席位");

    $m = $room2->members()->get(101);
    if ($m !== null) {
        T::eq($m->name, "Alice", "名字保留");
        T::eq($m->role, Member::PLAYER, "身份保留");
    }

    // 恢复后还能正常继续（不是死状态）
    T::ok($room2->tick(50), "恢复后能继续推进");
    T::eq($room2->frame(), 3, "帧数 +1");

    // 不能往非空房间里灌快照
    T::ok(!$room->loadFrom($d), "已有成员的房间拒绝 loadFrom");

    // 非法 id
    T::ok(Room::fromArray(["id" => 0]) === null, "id=0 拒绝");
    T::ok(Room::fromArray([]) === null, "空数组拒绝");
}

/** 8. EntitySet 快照往返 */
function caseEntitySnapshot(): void
{
    echo "== [8] 坐标快照 ==" . PHP_EOL;

    $ents = makeEnts();
    T::eq($ents->count(), 2, "原集合 2 个");

    $rows = $ents->toArray();
    $ents2 = new EntitySet();
    T::eq($ents2->loadFrom($rows), 2, "载入 2 个");

    $a = $ents2->get(101);
    T::ok($a !== null, "101 在");
    if ($a !== null) {
        T::eq($a->x, 2000, "x 一致");
        T::eq($a->y, 5000, "y 一致");
        T::eq($a->vx, 100, "vx 一致");
        T::eq($a->vy, -50, "vy 一致");
    }

    // 恢复不触发位置回调（否则视野统计会被打乱）
    $calls = 0;
    $ents3 = new EntitySet(function (int $uid, int $x, int $y) use (&$calls): void {
        $calls++;
    });
    $ents3->loadFrom($rows);
    T::eq($calls, 0, "loadFrom 不触发位置回调");
}

/** 9. 冷哈希一致 + 恢复耗时（核心验收） */
function caseColdHashAndPerf(): void
{
    echo "== [9] 冷哈希一致与恢复耗时 ==" . PHP_EOL;

    // 200 实体 + 200 成员，模拟一个真实房间
    $room = new Room(1, 200);
    $ents = new EntitySet();
    for ($i = 1; $i <= 200; $i++) {
        $room->add($i, "p" . $i);
        $ents->spawn($i, $i * 10, 5000 + ($i % 7) * 100, 100, -100);
    }
    $room->start();
    for ($f = 0; $f < 10; $f++) {
        $room->tick(50);
        $ents->tick(50);
    }

    // 采集
    $t0 = hrtime(true);
    $snap = new Snapshot("room", 1);
    $snap->put("room", $room->toArray());
    $snap->put("ents", $ents->toArray());
    $json = Codec::encode($snap);
    $t1 = hrtime(true);
    $h1 = Codec::bodyHash($snap);
    echo "  采集+编码: " . number_format(($t1 - $t0) / 1e6, 2) . " ms，"
        . number_format(strlen($json)) . " 字节" . PHP_EOL;

    // 恢复
    $t2 = hrtime(true);
    $decoded = Codec::decode($json);
    T::ok($decoded !== null, "解码成功");
    if ($decoded === null) {
        return;
    }
    $room2 = Room::fromArray($decoded->get("room"));
    $ents2 = new EntitySet();
    $ents2->loadFrom($decoded->get("ents"));
    $t3 = hrtime(true);
    $restoreMs = ($t3 - $t2) / 1e6;
    echo "  恢复: " . number_format($restoreMs, 2) . " ms（目标 ≤50 ms）" . PHP_EOL;

    T::ok($room2 !== null, "房间恢复成功");
    T::eq($room2 === null ? 0 : $room2->memberCount(), 200, "200 个成员回来了");
    T::eq($ents2->count(), 200, "200 个坐标回来了");
    T::ok($restoreMs <= 50, "恢复 ≤50 ms", number_format($restoreMs, 2) . " ms");

    // 再采集一次，比状态哈希
    $snap2 = new Snapshot("room", 1);
    if ($room2 !== null) {
        $snap2->put("room", $room2->toArray());
    }
    $snap2->put("ents", $ents2->toArray());
    $h2 = Codec::bodyHash($snap2);
    T::eq($h2, $h1, "冷哈希一致（" . $h1 . "）");

    // 逐字节比一次，确认不是碰巧哈希相同
    $json2 = Codec::encode($snap2);
    $same = str_contains($json2, substr($json, strpos($json, '"b":') + 4));
    T::ok($same, "body 字节相同", strlen($json) . " vs " . strlen($json2));
}

/** 10. 脏快照容错 */
function caseDirty(): void
{
    echo "== [10] 脏快照容错 ==" . PHP_EOL;

    // 混入各种非法条目，其余应当照常恢复
    $d = [
        "id" => 5,
        "state" => 2,
        "frame" => 3,
        "elapsedMs" => 150,
        "maxPlayers" => 4,
        "members" => [
            ["uid" => 1, "role" => 1, "seat" => 0, "name" => "ok"],
            ["uid" => 0],                                   // 非法 uid
            ["uid" => -3],                                  // 非法 uid
            ["uid" => 2, "role" => 99, "seat" => 1],        // 未知身份 → 退回 PLAYER
            ["uid" => 3, "role" => 1, "seat" => 0],         // 席位冲突 → 不占座
            ["uid" => 1, "role" => 1, "seat" => 2],         // 重复 uid → 跳过
            "not an array",                                 // 非数组
        ],
    ];

    $room = Room::fromArray($d);
    T::ok($room !== null, "脏快照仍能恢复");
    if ($room === null) {
        return;
    }
    T::eq($room->memberCount(), 3, "有效成员 3 个（uid 1/2/3）");
    $m2 = $room->members()->get(2);
    if ($m2 !== null) {
        T::eq($m2->role, Member::PLAYER, "未知身份退回 PLAYER");
    }
    $m3 = $room->members()->get(3);
    if ($m3 !== null) {
        T::eq($m3->seat, -1, "席位冲突的降级为无座");
    }
    // 席位 0 仍然属于先来的 1 号
    T::eq($room->members()->seatOf(1), 0, "先到的保住座位");
}

/** 11. 规模曲线：全量快照到多大才需要"增量" */
function caseScale(): void
{
    echo "== [11] 规模曲线 ==" . PHP_EOL;
    echo "  实体数    采集(ms)   恢复(ms)   字节" . PHP_EOL;

    $sizes = [200, 1000, 5000, 10000];
    foreach ($sizes as $n) {
        $room = new Room(1, $n);
        $ents = new EntitySet();
        for ($i = 1; $i <= $n; $i++) {
            $room->add($i);
            $ents->spawn($i, $i * 7 % 10000, $i * 13 % 10000, 100, -100);
        }
        $room->start();
        $room->tick(50);
        $ents->tick(50);

        $t0 = hrtime(true);
        $snap = new Snapshot("room", 1);
        $snap->put("room", $room->toArray());
        $snap->put("ents", $ents->toArray());
        $json = Codec::encode($snap);
        $t1 = hrtime(true);

        $t2 = hrtime(true);
        $d = Codec::decode($json);
        if ($d !== null) {
            $r2 = Room::fromArray($d->get("room"));
            $e2 = new EntitySet();
            $e2->loadFrom($d->get("ents"));
        }
        $t3 = hrtime(true);

        printf(
            "  %-8s  %8.2f  %8.2f  %s\n",
            number_format($n),
            ($t1 - $t0) / 1e6,
            ($t3 - $t2) / 1e6,
            number_format(strlen($json))
        );

        if ($n === 200) {
            T::ok(($t3 - $t2) / 1e6 <= 50, "200 实体恢复 ≤50 ms");
        }
        if ($n === 10000) {
            // 记下最大规模的实测，供"要不要做增量"的判断用
            $ms = ($t3 - $t2) / 1e6;
            T::ok($ms <= 500, "10000 实体恢复 < 500 ms（单房间极端情况）",
                number_format($ms, 2) . " ms");
        }
    }
}

/** 12. 批量恢复：60 / 500 个房间要多久（T4-05 的输入） */
function caseRestoreMany(): void
{
    echo "== [12] 批量恢复 ==" . PHP_EOL;

    $dir = "/tmp/tsf_snap_many";
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            $p = $dir . "/" . $f;
            if (is_file($p)) {
                unlink($p);
            }
        }
    } else {
        mkdir($dir, 0777, true);
    }

    $store = new FileStore($dir);

    echo "  房间数   落盘(ms)   枚举(ms)   恢复(ms)" . PHP_EOL;

    foreach ([60, 500] as $n) {
        // 每个房间 2 人 2 实体——1v1 的真实规模
        $t0 = hrtime(true);
        for ($i = 1; $i <= $n; $i++) {
            $room = new Room($i, 2);
            $room->add($i * 10 + 1);
            $room->add($i * 10 + 2);
            $room->start();
            $room->tick(50);

            $ents = new EntitySet();
            $ents->spawn($i * 10 + 1, 2000, 5000, 3000, 0);
            $ents->spawn($i * 10 + 2, 8000, 5000, 0, 0);

            $snap = new Snapshot("room", $i);
            $snap->put("room", $room->toArray());
            $snap->put("ents", $ents->toArray());
            $store->put($snap);
        }
        $t1 = hrtime(true);

        $t2 = hrtime(true);
        $keys = $store->keys("room");
        $t3 = hrtime(true);

        // 顺序恢复（就是 restoreAll 现在的做法）
        $t4 = hrtime(true);
        $rooms = [];
        foreach ($keys as $id) {
            $snap = $store->get("room", $id);
            if ($snap === null) {
                continue;
            }
            $r = Room::fromArray($snap->get("room"));
            $e = new EntitySet();
            $e->loadFrom($snap->get("ents"));
            if ($r !== null) {
                $rooms[] = $r;
            }
        }
        $t5 = hrtime(true);

        printf(
            "  %-7s  %8.2f  %8.2f  %8.2f\n",
            number_format($n),
            ($t1 - $t0) / 1e6,
            ($t3 - $t2) / 1e6,
            ($t5 - $t4) / 1e6
        );

        T::eq(count($rooms), $n, $n . " 个房间全部恢复");

        if ($n === 60) {
            $ms = ($t5 - $t4) / 1e6;
            T::ok($ms <= 500, "60 房恢复 ≤500 ms（T4-05 验收）", number_format($ms, 2) . " ms");
        }

        // 清掉，免得下一轮把上一轮的也算进去
        foreach ($store->keys("room") as $id) {
            $store->delete("room", $id);
        }
    }

    // 目录文件数对枚举的影响（这是"所有快照放一个目录"的隐患）
    echo "  单房间快照大小: " . number_format(strlen(Codec::encode(buildSampleSnapshot()))) . " 字节" . PHP_EOL;

    foreach (scandir($dir) as $f) {
        $p = $dir . "/" . $f;
        if (is_file($p)) {
            unlink($p);
        }
    }
    rmdir($dir);
}

function buildSampleSnapshot(): Snapshot
{
    $room = new Room(1, 2);
    $room->add(11);
    $room->add(12);
    $room->start();
    $room->tick(50);
    $ents = new EntitySet();
    $ents->spawn(11, 2000, 5000, 3000, 0);
    $ents->spawn(12, 8000, 5000, 0, 0);
    $s = new Snapshot("room", 1);
    $s->put("room", $room->toArray());
    $s->put("ents", $ents->toArray());
    return $s;
}

/** 13. Restorer：分批、坏数据清理、统计 */
function caseRestorer(): void
{
    echo "== [13] Restorer ==" . PHP_EOL;

    $dir = "/tmp/tsf_snap_restorer";
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            $p = $dir . "/" . $f;
            if (is_file($p)) {
                unlink($p);
            }
        }
    } else {
        mkdir($dir, 0777, true);
    }

    $store = new FileStore($dir);

    // 10 条好的
    for ($i = 1; $i <= 10; $i++) {
        $s = new Snapshot("room", $i);
        $s->put("room", ["id" => $i, "state" => 2]);
        $store->put($s);
    }
    // 1 条坏的（半个 JSON）
    file_put_contents($dir . "/room_99.snap", '{"v":1,"k":"room","id":99,"b":{');
    // 1 条版本不对的
    $old = new Snapshot("room", 98, 0);
    $old->put("room", ["id" => 98]);
    $store->put($old);

    $rebuilt = [];
    $r = new Restorer(3, 200);
    $stat = $r->restore($store, "room", function (Snapshot $s) use (&$rebuilt): bool {
        $body = $s->get("room");
        if (!isset($body["id"])) {
            return false;
        }
        $rebuilt[] = (int) $body["id"];
        return true;
    });

    T::eq($stat["done"], 10, "恢复 10 条");
    T::eq($stat["skipped"], 2, "跳过 2 条（坏 JSON + 旧版本）");
    T::eq(count($rebuilt), 10, "回调被调用 10 次");
    T::ok($stat["ms"] >= 0, "有耗时统计", $stat["ms"] . " ms");
    T::ok(!$stat["slow"], "10 条不算慢");

    // 坏的那两条应该被清掉，不再反复拖慢启动
    $left = $store->keys("room");
    T::eq(count($left), 10, "坏快照已删除，只剩 10 个");
    T::ok(!in_array(99, $left, true), "坏 JSON 被删");
    T::ok(!in_array(98, $left, true), "旧版本被删");

    // dropBad=false 时保留
    file_put_contents($dir . "/room_97.snap", "garbage");
    $r2 = new Restorer();
    $stat2 = $r2->restore($store, "room", function (Snapshot $s): bool {
        return true;
    }, false);
    T::eq($stat2["skipped"], 1, "不删模式仍算跳过");
    T::ok(in_array(97, $store->keys("room"), true), "dropBad=false 时坏文件保留");

    // 空 store
    $stat3 = $r2->restore($store, "nonexistent", function (Snapshot $s): bool {
        return true;
    });
    T::eq($stat3["done"], 0, "空类别恢复 0 条");

    // 60 条走 Restorer，耗时应当和手写循环一个量级
    $store2 = new FileStore("/tmp/tsf_snap_r60");
    for ($i = 1; $i <= 60; $i++) {
        $room = new Room($i, 2);
        $room->add($i * 10 + 1);
        $room->add($i * 10 + 2);
        $room->start();
        $snap = new Snapshot("room", $i);
        $snap->put("room", $room->toArray());
        $store2->put($snap);
    }
    $r3 = new Restorer(50, 200);
    $stat4 = $r3->restore($store2, "room", function (Snapshot $s): bool {
        $room = Room::fromArray($s->get("room"));
        return $room !== null;
    });
    T::eq($stat4["done"], 60, "60 条全部恢复");
    T::ok($stat4["ms"] <= 500, "60 条 ≤500 ms（T4-05 验收）", $stat4["ms"] . " ms");
    echo "  60 条经 Restorer: " . $stat4["ms"] . " ms" . PHP_EOL;

    // 清理
    foreach ([$dir, "/tmp/tsf_snap_r60"] as $d) {
        if (!is_dir($d)) {
            continue;
        }
        foreach (scandir($d) as $f) {
            $p = $d . "/" . $f;
            if (is_file($p)) {
                unlink($p);
            }
        }
        rmdir($d);
    }
}

function main(): void
{
    caseCodec();
    caseBadData();
    caseHash();
    caseMemoryStore();
    caseFileStore();
    caseParity();
    caseRoomSnapshot();
    caseEntitySnapshot();
    caseColdHashAndPerf();
    caseDirty();
    caseScale();
    caseRestoreMany();
    caseRestorer();

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
}
