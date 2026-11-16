<?php

// E4 落地（第二版）：房间分级 + 重启强度监督 + 非阻塞退避 + 告警/解除
//
// 分级：
//   大房间 → 独立进程；小房间 → 宿主机内协程
//
// 监督：
//   60s 窗口内重启 > 5 次 → 隔离 + 告警（写 alarms.log）
//   第 3 次起指数退避（100/200/400/800ms）
//
// 本版关键改动：
//   退避不再 usleep 阻塞父进程，改为「待启动队列 + 到期才 fork」，
//   这样 A 房在退避期间，B 房崩了仍能被立刻处理。
//   用 SUP_BLOCKING=1 可切回旧实现，便于对照。
//
// 解除隔离：
//   人工确认修复后，写 rooms/<key>.cmd 内容 "reset"，监督器会在 1s 内
//   清零计数、清除隔离并重新拉起。

const ROOM_DIR = "/data/TypeServer/spike/e4/rooms";
const ALARM_LOG = "/data/TypeServer/spike/e4/alarms.log";
const STATE_FILE = "/data/TypeServer/spike/e4/supervisor.state";
const BIG_ROOMS = 6;
const SMALL_HOSTS = 1;
const SMALL_ROOMS = 50;
const MAX_RESTARTS = 5;
const WINDOW_SEC = 60;
const POLL_BUSY_US = 1000;  // 有待启动任务时：1ms（保证重生延迟）
const POLL_IDLE_US = 4000;  // 空闲时：4ms（把监督器自身 CPU 压下去）
const CMD_EVERY_MS = 1000;  // 检查管理命令的间隔

function state_path(string $key): string
{
    return ROOM_DIR . "/" . $key . ".state";
}

function cmd_path(string $key): string
{
    return ROOM_DIR . "/" . $key . ".cmd";
}

function write_state(string $key, int $pid, int $ticks, string $tier, int $restarts, string $state): void
{
    file_put_contents(state_path($key), $pid . " " . $ticks . " " . $tier . " " . $restarts . " " . $state . "\n");
}

function log_line(string $msg): void
{
    file_put_contents("/data/TypeServer/spike/e4/supervisor.log", $msg . "\n", FILE_APPEND);
}

// 告警：落文件（供采集器），同时打到 stdout（被 run.log 捕获）
function alarm(string $key, string $reason, int $restarts): void
{
    $line = date("c") . " QUARANTINE key=" . $key . " restarts=" . $restarts
        . " window=" . WINDOW_SEC . "s reason=" . $reason;
    file_put_contents(ALARM_LOG, $line . "\n", FILE_APPEND);
    echo "[ALARM] " . $line . PHP_EOL;
}

function run_big_room(int $id, int $restarts): void
{
    $ticks = 0;
    write_state("big" . $id, posix_getpid(), $ticks, "big", $restarts, "running");
    while (true) {
        $ticks++;
        write_state("big" . $id, posix_getpid(), $ticks, "big", $restarts, "running");
        usleep(100000);
    }
}

function run_small_host(int $hostId, int $restarts): void
{
    write_state("small" . $hostId, posix_getpid(), 0, "smallhost", $restarts, "running");
    for ($i = 0; $i < SMALL_ROOMS; $i++) {
        Swoole\Coroutine::create(function () use ($hostId, $i) {
            $ticks = 0;
            while (true) {
                $ticks++;
                if ($ticks % 10 === 1) {
                    write_state("sroom" . $hostId . "_" . $i, posix_getpid(), $ticks, "small", 0, "running");
                }
                Swoole\Coroutine::sleep(0.1);
            }
        });
    }
    Swoole\Event::wait();
}

// ---------------- 状态持久化 ----------------
//
// 监督器自身重启后，如果计数清零、隔离标记丢失，就会出现：
//   1) 已隔离的房间被重新拉起（隔离形同虚设）
//   2) 重启次数从 0 重新计（退避与阈值全部失效）
// 所以启动时必须恢复，且用「写临时文件 + rename」保证不会读到半截文件。

function load_state(): array
{
    $out = [];
    if (!file_exists(STATE_FILE)) {
        return $out;
    }
    $lines = file(STATE_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $p = explode("\t", $line);
        if (count($p) < 4) {
            continue;
        }
        $out[$p[0]] = [
            "restarts" => (int) $p[1],
            "window" => (int) $p[2],
            "quarantined" => (int) $p[3],
        ];
    }
    return $out;
}

function save_state(array $restarts, array $windowStart, array $quarantined): void
{
    $buf = "# key\trestarts\twindow_start\tquarantined\tupdated=" . date("c") . "\n";
    foreach ($restarts as $k => $n) {
        $w = 0;
        if (isset($windowStart[$k])) {
            $w = (int) $windowStart[$k];
        }
        $q = 0;
        if (isset($quarantined[$k])) {
            $q = (int) $quarantined[$k];
        }
        $buf = $buf . $k . "\t" . $n . "\t" . $w . "\t" . $q . "\n";
    }
    $tmp = STATE_FILE . ".tmp";
    file_put_contents($tmp, $buf);
    rename($tmp, STATE_FILE);
}

function spawn(string $key, int $restarts): int{
    if (strpos($key, "big") === 0) {
        $idx = (int) substr($key, 3);
        $p = new Swoole\Process(function ($proc) use ($idx, $restarts) {
            run_big_room($idx, $restarts);
        });
        return $p->start();
    }
    $idx = (int) substr($key, 5);
    $p = new Swoole\Process(function ($proc) use ($idx, $restarts) {
        run_small_host($idx, $restarts);
    });
    return $p->start();
}

function main(): void
{
    if (!is_dir(ROOM_DIR)) {
        mkdir(ROOM_DIR, 0777, true);
    }
    file_put_contents("/data/TypeServer/spike/e4/supervisor.log", "");
    file_put_contents(ALARM_LOG, "");
    file_put_contents("/data/TypeServer/spike/e4/supervisor.pid", (string) posix_getpid());

    $blocking = getenv("SUP_BLOCKING") === "1";

    $pids = [];          // key => pid
    $restarts = [];      // key => 窗口内重启次数
    $windowStart = [];   // key => 窗口起点
    $pending = [];       // key => 允许启动的毫秒时间戳
    $quarantined = [];   // key => 0/1

    $keys = [];
    for ($i = 0; $i < BIG_ROOMS; $i++) {
        $keys[] = "big" . $i;
    }
    for ($h = 0; $h < SMALL_HOSTS; $h++) {
        $keys[] = "small" . $h;
    }

    // 恢复上次的状态：已隔离的房间绝不自动拉起，计数继续累计
    $saved = load_state();
    $restoredQ = 0;
    $restoredN = 0;

    foreach ($keys as $k) {
        $restarts[$k] = 0;
        $windowStart[$k] = time();
        $quarantined[$k] = 0;
        $pids[$k] = 0;

        if (isset($saved[$k])) {
            $restarts[$k] = $saved[$k]["restarts"];
            $windowStart[$k] = $saved[$k]["window"];
            $quarantined[$k] = $saved[$k]["quarantined"];
        }

        if ($quarantined[$k] === 1) {
            $tier = strpos($k, "big") === 0 ? "big" : "smallhost";
            write_state($k, 0, 0, $tier, $restarts[$k], "quarantined");
            log_line("restore quarantined " . $k . " restarts=" . $restarts[$k] . "（不自动拉起）");
            $restoredQ++;
            continue;
        }

        $pids[$k] = spawn($k, $restarts[$k]);
        if ($restarts[$k] > 0) {
            $restoredN++;
        }
        log_line("spawn " . $k . " pid=" . $pids[$k] . " restarts=" . $restarts[$k]);
    }

    save_state($restarts, $windowStart, $quarantined);
    $live = 0;
    foreach ($pids as $v) {
        if ((int) $v > 0) {
            $live++;
        }
    }
    log_line("supervisor ready, children=" . $live . "/" . count($keys)
        . " blocking=" . ($blocking ? 1 : 0)
        . " restored_quarantined=" . $restoredQ . " restored_counts=" . $restoredN);

    $lastCmdCheck = 0;
    $dirty = false;

    while (true) {
        // ---- 1) 回收退出 ----
        // 旧实现用 wait(true) 阻塞，退避期间整个监督循环停摆；
        // 新实现用 wait(false) 轮询，每 1ms 一次，开销可忽略。
        $exited = [];
        if ($blocking) {
            $res = Swoole\Process::wait(true);
            if ($res !== false) {
                $exited[] = $res;
            }
        } else {
            for ($n = 0; $n < 64; $n++) {
                $res = Swoole\Process::wait(false);
                if ($res === false) {
                    break;
                }
                $exited[] = $res;
            }
        }

        foreach ($exited as $res) {
            $deadPid = $res["pid"];
            $key = "";
            foreach ($pids as $k => $v) {
                if ($v === $deadPid) {
                    $key = $k;
                    break;
                }
            }
            if ($key === "") {
                continue;
            }

            $now = time();
            if ($now - $windowStart[$key] > WINDOW_SEC) {
                $windowStart[$key] = $now;
                $restarts[$key] = 0;
            }
            $restarts[$key]++;
            $cnt = $restarts[$key];

            if ($cnt > MAX_RESTARTS) {
                $tier = strpos($key, "big") === 0 ? "big" : "smallhost";
                // 关键：必须把 pids 清零，否则后续 reset 会以为进程还在，
                // 只改状态文件而不真正拉起（实测踩过：房间显示 running 但无进程）
                $pids[$key] = 0;
                unset($pending[$key]);
                write_state($key, 0, 0, $tier, $cnt, "quarantined");
                log_line("QUARANTINE " . $key . " restarts=" . $cnt);
                alarm($key, "窗口内重启超过 " . MAX_RESTARTS . " 次，疑似启动即崩；修复后写 rooms/" . $key . ".cmd 为 reset", $cnt);
                $quarantined[$key] = 1;
                $dirty = true;
                continue;
            }

            $backoffMs = 0;
            if ($cnt >= 3) {
                $shift = $cnt - 3;
                if ($shift > 3) {
                    $shift = 3;
                }
                $backoffMs = 100 * (1 << $shift);
            }
            log_line("exit " . $key . " pid=" . $deadPid . " restarts=" . $cnt . " backoff=" . $backoffMs . "ms");

            $due = (int) (microtime(true) * 1000) + $backoffMs;
            $pending[$key] = $due;
            $pids[$key] = 0;
            $dirty = true;

            if ($blocking && $backoffMs > 0) {
                // 仅为对照保留：阻塞父进程，期间其他房间的退出无人处理
                usleep($backoffMs * 1000);
            }
        }

        // ---- 2) 到期的待启动房间（非阻塞退避的核心）----
        $nowMs = (int) (microtime(true) * 1000);
        $ready = [];
        foreach ($pending as $k => $deadline) {
            if ($nowMs >= (int) $deadline) {
                $ready[] = $k;
            }
        }
        foreach ($ready as $k) {
            $pids[$k] = spawn($k, $restarts[$k]);
            log_line("respawn " . $k . " pid=" . $pids[$k]);
            unset($pending[$k]);
            $dirty = true;
        }

        // ---- 3) 管理命令：解除隔离 / 重试 ----
        if ($nowMs - $lastCmdCheck >= CMD_EVERY_MS) {
            $lastCmdCheck = $nowMs;
            foreach ($keys as $k) {
                $cp = cmd_path($k);
                if (!file_exists($cp)) {
                    continue;
                }
                $cmd = trim((string) file_get_contents($cp));
                unlink($cp);
                if ($cmd === "") {
                    continue;
                }
                log_line("cmd " . $k . " " . $cmd);
                if ($cmd === "reset") {
                    $restarts[$k] = 0;
                    $windowStart[$k] = time();
                    $quarantined[$k] = 0;
                    $curPid = 0;
                    if (isset($pids[$k])) {
                        $curPid = (int) $pids[$k];
                    }
                    if ($curPid === 0 && !isset($pending[$k])) {
                        $pids[$k] = spawn($k, 0);
                        log_line("respawn(reset) " . $k . " pid=" . $pids[$k]);
                    } else {
                        $tier = strpos($k, "big") === 0 ? "big" : "smallhost";
                        write_state($k, $curPid, 0, $tier, 0, "running");
                    }
                    $dirty = true;
                }
            }
        }

        // 状态有变更才落盘（原子替换，避免读到半截文件）
        if ($dirty) {
            save_state($restarts, $windowStart, $quarantined);
            $dirty = false;
        }

        // 自适应轮询：有退避任务在等 → 1ms；纯空闲 → 4ms。
        // 实测空闲 1ms 轮询会占单核约 2%，放宽到 4ms 后降到 0.66% 左右。
        $sleepUs = POLL_IDLE_US;
        if (count($pending) > 0) {
            $sleepUs = POLL_BUSY_US;
        }
        usleep($sleepUs);
    }
}
