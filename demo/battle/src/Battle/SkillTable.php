<?php

namespace Game\Battle;

use TSF\Kernel\FileLoader;
use TSF\Kernel\Logger;

/**
 * L3 配置表：扁平 key-value + 按内容指纹热更。
 *
 * **先说清楚它的定位**：这是一个"程序手改几个数值"的轻量配置，不是策划表。
 * 正经的结构化数值表应该走框架自带的工具链：
 *
 *   Excel → `tsf config build` → .bin（带 schema 的二进制）+ 强类型类 + crc32 清单
 *                                 → `TSF\Config\Watcher` 轮询清单 → `Tables::reload()`
 *
 * 那套东西有 schema 校验、按表重载、坏数据不污染线上，比这里强得多。
 * 这里之所以还留一份 PHP 数组配置，是因为压测要频繁换几个旋钮（半径、血量、
 * AOI 开关），维护一个 xlsx 反而更慢。
 *
 * 三个刻意的设计选择：
 *
 * 1. **扁平化**。文件返回 `"skill.fireball.base" => 12` 这样的单层表，
 *    不是嵌套数组。AOT 下对 mixed 嵌套数组反复下钻曾被证明不可靠
 *    （tpc 0.9.6 已难复现，但扁平化本身更快，也没有理由改回去）。
 *    运行时只有一次 array_key_exists。
 *
 * 2. **不重新 new，原地换表**。改完整个替换 `$this->table`，
 *    已存在的 BattlePlayer 不受影响——玩家身上存的是结算后的 hp，
 *    不是"对配置的引用"。
 *
 * 3. **按内容指纹判断变化，不看 mtime**。这是框架里已经吃过两次亏的规矩
 *    （见 `TSF\Config\Watcher` 的注释）：filemtime 只到秒，一秒内改两次看不出来；
 *    改一个等长的值（12 → 45）连文件大小都不变。两个维度同时失效，
 *    现象就是"改了不生效、日志里一次变更都没有"。
 *    这里用 `FileLoader::hash()` 算 crc32，几 KB 的文件在微秒级。
 */
final class SkillTable
{
    private string $path;

    /** @var array<string, int|string> */
    private array $table = [];

    /** 上一次加载时的内容指纹（crc32），不是 mtime */
    private string $hash = "";

    private int $reloads = 0;

    private int $checks = 0;

    /**
     * 是否允许"自动轮询发现变化就重载"。**默认 0（关）**。
     *
     * 为什么默认关：进程行为应该只由"启动时的状态 + 显式动作"决定。
     * 自动轮询有两个说不清的地方——
     *   1. 什么时候生效取决于轮询节奏，不是任何人决定的；
     *   2. 文件写到一半（编辑器保存、导出到一半）也可能被读到。
     *
     * 也和后端常识一致：nginx 要 `-s reload`、Redis 要 `CONFIG SET`、
     * K8s 改 ConfigMap 要 rollout restart。**变更必须有一个发起人。**
     *
     * 要开发便利时在 config/skill.php 里把 `hot.auto_reload` 设成 1，
     * 但那只该出现在本机调试配置里。
     */
    private int $autoReload = 0;

    /** 每多少次检查才 stat 一次文件 */
    private int $checkEvery;

    public function __construct(string $path, int $checkEvery = 30)
    {
        $this->path = $path;
        $this->checkEvery = $checkEvery > 0 ? $checkEvery : 1;
        $this->load();
    }

    public function path(): string
    {
        return $this->path;
    }

    /** 强制加载；返回 false 表示文件不可读或形状不对（此时保留旧表，不降级成空表） */
    public function load(): bool
    {
        // 先取局部变量：`FileLoader::hash($this->path)` 这类"内置/静态函数直接吃
        // 属性访问"的写法在 AOT 下有坑（filemtime($this->path) 会被编译成不传参
        // 的调用，运行时报 Argument #1 not passed，编译期无告警）。
        $path = $this->path;
        $hash = FileLoader::hash($path);
        if ($hash === "") {
            return false;
        }
        // opcache 失效与 include 都收在 FileLoader 里。
        // 之前这段是自己写的，等于把"必须失效 opcache"这个知识散落在每个业务模块；
        // 现在只有一处，改的时候不会漏。
        $data = FileLoader::array($path);
        if ($data === null) {
            return false;
        }
        $this->table = $data;
        $this->hash = $hash;
        // 自动轮询开关本身也是配置的一部分，每次换表后刷新（默认关）
        $this->autoReload = $this->getInt("hot.auto_reload", 0);

        // 打一条加载日志：热更验证时必须能看见"这次到底读到了什么值"。
        // 只看 reloads 计数是不够的 —— 计数涨了而数值没变，说明问题出在
        // include 的语义上，而不是文件监听上。
        $probe = $this->table["skill.fireball.base"] ?? -1;
        Logger::info("config loaded", [
            "path" => $path,
            "size" => count($this->table),
            "fireball_base" => (int) $probe,
        ]);
        return true;
    }

    /**
     * 检测并热更。返回 true 表示这一帧真的换了表。
     *
     * 加载失败时**保留旧表**——半个配置表比旧表更危险，宁可继续用旧的并让告警响。
     */
    public function reloadIfChanged(): bool
    {
        // 默认不自动重载：变更必须有人发起（见 $autoReload 的说明）
        if ($this->autoReload !== 1) {
            return false;
        }
        $this->checks++;
        if ($this->checks % $this->checkEvery !== 0) {
            return false;
        }
        $path = $this->path;
        $hash = FileLoader::hash($path);
        // 空串 = 文件不可读，当作"这次不重载"，而不是"内容变了"
        if ($hash === "" || $hash === $this->hash) {
            return false;
        }
        $ok = $this->load();
        if ($ok) {
            $this->reloads++;
        }
        return $ok;
    }

    /**
     * 用**外部校验过的数据**替换整张表（GM 手动重载用）。
     *
     * 为什么不能让 GM 直接改 `$table`：GM 跑在另一个 Service 里，按 actor 规矩
     * 不能碰别人的状态。所以分工是——GM 负责读文件与校验，把结果随消息送过来，
     * 由本服务在自己的 onMessage 里应用。数据只有一条写入路径。
     *
     * 与 reloadIfChanged() 的区别：那条路径看指纹、失败保留旧表；
     * 这条路径的数据已经被 GM 校验过，直接换。
     *
     * @param array<string, int|string> $data
     */
    public function applyExternal(array $data, string $hash): void
    {
        $this->table = $data;
        $this->hash = $hash;
        // 自动轮询开关本身也是配置的一部分，每次换表后刷新（默认关）
        $this->autoReload = $this->getInt("hot.auto_reload", 0);
        $this->reloads++;
        // 手动重载必须留痕：出问题时能回答"是谁、什么时候、把哪份配置换上去了"
        $probe = $this->table["skill.fireball.base"] ?? -1;
        Logger::info("config applied (manual)", [
            "size" => count($this->table),
            "fireball_base" => (int) $probe,
            "hash" => $hash,
        ]);
    }

    public function getInt(string $key, int $default = 0): int
    {
        if (!array_key_exists($key, $this->table)) {
            return $default;
        }
        $v = $this->table[$key];
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v)) {
            return (int) $v;
        }
        return $default;
    }

    public function getString(string $key, string $default = ""): string
    {
        if (!array_key_exists($key, $this->table)) {
            return $default;
        }
        $v = $this->table[$key];
        if (is_string($v)) {
            return $v;
        }
        if (is_int($v)) {
            return (string) $v;
        }
        return $default;
    }

    public function reloads(): int
    {
        return $this->reloads;
    }

    public function size(): int
    {
        return count($this->table);
    }
}
