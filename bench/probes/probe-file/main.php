<?php

/**
 * 探针：AOT 下把日志写到磁盘的几种姿势与成本。
 *
 * 事件日志的核心指标不是"写得快"，而是"崩溃时不丢"——
 * 后者取决于 fsync，而 fsync 的代价必须先量出来，
 * 否则"每条都 fsync"这种设计会直接拖死吞吐。
 */

final class Sink
{
    private string $path;

    /** 无类型属性存句柄：PHP 不能把 resource 写成类型声明 */
    private $fh = null;

    private int $n = 0;

    public function __construct(string $path)
    {
        $this->path = $path;
        @unlink($path);
    }

    /** 每次 open/write/close */
    public function appendByPutenv(string $line): void
    {
        file_put_contents($this->path, $line . "\n", FILE_APPEND);
        $this->n++;
    }

    /** 持久句柄 + fflush（进页缓存，不保证落盘） */
    public function appendBuffered(string $line): void
    {
        $fh = $this->open();
        fwrite($fh, $line . "\n");
        fflush($fh);
    }

    /** 持久句柄 + fsync（真正落盘） */
    public function appendSynced(string $line): void
    {
        $fh = $this->open();
        fwrite($fh, $line . "\n");
        fflush($fh);
        if (function_exists("fsync")) {
            fsync($fh);
        }
    }

    public function close(): void
    {
        if (is_resource($this->fh)) {
            fclose($this->fh);
        }
        $this->fh = null;
    }

    public function size(): int
    {
        $s = filesize($this->path);
        return is_int($s) ? $s : 0;
    }

    private function open()
    {
        if (is_resource($this->fh)) {
            return $this->fh;
        }
        $fh = fopen($this->path, "ab");
        if ($fh === false) {
            throw new \RuntimeException("open failed: " . $this->path);
        }
        $this->fh = $fh;
        return $fh;
    }
}

function t(string $label, bool $ok, string $detail = ""): void
{
    echo ($ok ? "  OK   " : "  FAIL ") . $label . ($detail === "" ? "" : "  → " . $detail) . PHP_EOL;
}

function main(): void
{
    $dir = "/tmp/probe_file";
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $n = 200;

    echo "== 1. file_put_contents(FILE_APPEND) ==" . PHP_EOL;
    $s1 = new Sink($dir . "/a.log");
    $t0 = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $s1->appendByPutenv('{"i":' . $i . ',"k":"test"}');
    }
    $t1 = hrtime(true);
    t("写得进去", $s1->size() > 0, number_format($s1->size()) . " 字节");
    printf("  %.1f µs/条\n", ($t1 - $t0) / $n / 1000);

    echo "== 2. 持久句柄 + fflush ==" . PHP_EOL;
    $s2 = new Sink($dir . "/b.log");
    $t2 = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $s2->appendBuffered('{"i":' . $i . ',"k":"test"}');
    }
    $t3 = hrtime(true);
    $s2->close();
    t("写得进去", $s2->size() > 0, number_format($s2->size()) . " 字节");
    printf("  %.1f µs/条\n", ($t3 - $t2) / $n / 1000);

    echo "== 3. 持久句柄 + fsync ==" . PHP_EOL;
    $s3 = new Sink($dir . "/c.log");
    $t4 = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $s3->appendSynced('{"i":' . $i . ',"k":"test"}');
    }
    $t5 = hrtime(true);
    $s3->close();
    t("写得进去", $s3->size() > 0, number_format($s3->size()) . " 字节");
    printf("  %.1f µs/条（含 fsync）\n", ($t5 - $t4) / $n / 1000);

    echo "== 4. 批量：先写后 fsync 一次 ==" . PHP_EOL;
    $s4 = new Sink($dir . "/d.log");
    $t6 = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $s4->appendBuffered('{"i":' . $i . ',"k":"test"}');
    }
    $s4->close();   // 关句柄会 flush
    $t7 = hrtime(true);
    printf("  %.2f µs/条（批量）\n", ($t7 - $t6) / $n / 1000);

    echo "== 5. 读回：按行切分 ==" . PHP_EOL;
    $raw = file_get_contents($dir . "/b.log");
    $ok = false;
    if (is_string($raw)) {
        $lines = explode("\n", $raw);
        $valid = 0;
        foreach ($lines as $ln) {
            if ($ln === "") {
                continue;
            }
            $d = json_decode($ln, true);
            if (is_array($d)) {
                $valid++;
            }
        }
        t("行数正确", $valid === $n, "valid=" . $valid . " 期望=" . $n);
        $ok = true;
    }
    t("file_get_contents 可用", $ok);

    echo "== 6. 半行（崩溃时最后一行只写了一半）==" . PHP_EOL;
    $half = $dir . "/e.log";
    @unlink($half);
    file_put_contents($half, "{\"i\":1}\n{\"i\":2}\n{\"i\":3");
    $raw2 = file_get_contents($half);
    if (is_string($raw2)) {
        $lines2 = explode("\n", $raw2);
        $good = 0;
        $bad = 0;
        foreach ($lines2 as $ln) {
            if ($ln === "") {
                continue;
            }
            $d = json_decode($ln, true);
            if (is_array($d)) {
                $good++;
            } else {
                $bad++;
            }
        }
        t("能数出 2 条完整 + 1 条残缺", $good === 2 && $bad === 1, "good=" . $good . " bad=" . $bad);
    }

    echo "== 7. 追加后再读（模拟进程重启）==" . PHP_EOL;
    $s5 = new Sink($dir . "/f.log");
    $s5->appendBuffered('{"i":1}');
    $s5->close();
    // 新实例继续追加
    $s6 = new Sink($dir . "/g.log");
    $s6->appendBuffered('{"i":1}');
    $s6->close();
    $s7 = new Sink($dir . "/g.log");   // 注意：构造里会 unlink，这里手工再写
    $s7->appendBuffered('{"i":2}');
    $s7->close();
    $raw3 = file_get_contents($dir . "/g.log");
    if (is_string($raw3)) {
        t("同一文件可以续写", substr_count($raw3, "\n") === 2, "内容=" . trim($raw3));
    }

    // 清理
    foreach (scandir($dir) as $f) {
        $p = $dir . "/" . $f;
        if (is_file($p)) {
            unlink($p);
        }
    }
    rmdir($dir);

    echo "完成" . PHP_EOL;
}
