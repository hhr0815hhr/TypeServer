// E1/P1 桥接层（第三版）：模块注册表 + 索引绑定 + 灰度切换 + 观测
//
// 相比第二版的三个变化：
//   1) 从"单模块硬编码"变成注册表，可同时加载多个模块（各自独立实例）
//   2) 方法在加载时解析成索引，调用走 tp_call_idx，不再做字符串哈希
//   3) 灰度切换：新实例就绪后原子替换句柄，旧实例立即释放
//
// 观测口径（按模块）：调用数 / trap 数 / 重建数 / 单次最大耗时

#include <phpx.h>
#include <chrono>
#include <string>

using namespace php;

extern "C" {
void *tp_open(const char *path);
int tp_call1(void *h, const char *name, long long a, long long *out);
int tp_call2(void *h, const char *name, long long a, long long b, long long *out);
int tp_call_idx(void *h, int idx, long long a, long long b, long long *out);
int tp_call_idx1(void *h, int idx, long long a, long long *out);
int tp_method_index(void *h, const char *name);
int tp_set_budget(void *h, unsigned long long ticks, unsigned long long fuel);
int tp_is_dead(void *h);
long long tp_ping(long long x);
void tp_close(void *h);
}

static const unsigned long long kTicks = 3;
static const unsigned long long kFuel = 0;
static const int MAX_MODULES = 8;

struct Module {
    std::string name;
    std::string path;
    void *host;
    int idxAdd;
    int idxSim;
    int idxSpin;
    // 观测
    int calls;
    int traps;
    int rebuilds;
    long long maxUs;
    bool alive;
};

static Module g_mods[MAX_MODULES];
static int g_modCount = 0;
static bool g_timing = false;

static int find_mod(const std::string &name) {
    for (int i = 0; i < g_modCount; i++) {
        if (g_mods[i].alive && g_mods[i].name == name) {
            return i;
        }
    }
    return -1;
}

// 调用路径上只做一次下标范围检查，不做字符串转换、不扫表。
// 早期版本按名字查表，每次调用都要 Str→std::string 分配 + 线性扫描，
// 实测把 782 ns 推到 1100 ns，故改为整数句柄。
static Module *slot(Int h) {
    if (h < 0 || h >= g_modCount) {
        return nullptr;
    }
    Module &m = g_mods[h];
    if (!m.alive) {
        return nullptr;
    }
    return &m;
}

// 打开实例并按名字绑定方法索引（只在加载/重建时做，调用路径上不再查名字）
static bool open_instance(Module &m, const std::string &path) {
    void *h = tp_open(path.c_str());
    if (h == nullptr) {
        return false;
    }
    tp_set_budget(h, kTicks, kFuel);
    m.host = h;
    m.path = path;
    m.idxAdd = tp_method_index(h, "[method]runtime.add");
    m.idxSim = tp_method_index(h, "[method]runtime.simulate-frame");
    m.idxSpin = tp_method_index(h, "[method]runtime.spin");
    return m.idxAdd >= 0;
}

static void close_instance(Module &m) {
    if (m.host != nullptr) {
        tp_close(m.host);
        m.host = nullptr;
    }
}

// trap 后的统一恢复：丢弃 + 同路径重建
static void recover(Module &m) {
    close_instance(m);
    if (open_instance(m, m.path)) {
        m.rebuilds++;
    } else {
        m.alive = false;
    }
}

// 索引绑定调用（P1 主路径）
static Int call_idx2(Module &m, int idx, Int a, Int b) {
    if (!m.alive || m.host == nullptr) {
        return -1000;
    }
    long long out = 0;
    int rc;
    if (g_timing) {
        auto t0 = std::chrono::steady_clock::now();
        rc = tp_call_idx(m.host, idx, a, b, &out);
        auto t1 = std::chrono::steady_clock::now();
        auto us = std::chrono::duration_cast<std::chrono::microseconds>(t1 - t0).count();
        if (us > m.maxUs) {
            m.maxUs = (long long) us;
        }
    } else {
        // 计时关闭时不取时钟：clock_gettime 每次约 20–30 ns，会污染调用开销的测量
        rc = tp_call_idx(m.host, idx, a, b, &out);
    }
    m.calls++;
    if (rc == 2) {
        m.traps++;
        recover(m);
        return -3000;
    }
    return rc == 0 ? static_cast<Int>(out) : static_cast<Int>(-2000 + rc);
}

// ---------------- 对外 API ----------------

/// 加载模块；成功返回句柄（>=0），失败返回负数：-1 打开失败，-2 重名，-3 表满
Int php_wasm_load(Str name, Str path) {
    std::string n = name.toStdString();
    std::string p = path.toStdString();
    if (find_mod(n) >= 0) {
        return -2;
    }
    if (g_modCount >= MAX_MODULES) {
        return -3;
    }
    int h = g_modCount;
    Module &m = g_mods[h];
    m.name = n;
    m.calls = 0;
    m.traps = 0;
    m.rebuilds = 0;
    m.maxUs = 0;
    m.alive = true;
    if (!open_instance(m, p)) {
        close_instance(m);
        m.alive = false;
        return -1;
    }
    g_modCount++;
    return h;
}

/// 索引绑定的调用（P1 主路径）
Int php_wasm_add(Int h, Int a, Int b) {
    Module *m = slot(h);
    if (m == nullptr) {
        return -1000;
    }
    return call_idx2(*m, m->idxAdd, a, b);
}

/// 字符串查找的调用（对照用，等价于第二版实现：每次传方法名做哈希查找）
Int php_wasm_add_byname(Int h, Int a, Int b) {
    Module *mp = slot(h);
    if (mp == nullptr) {
        return -1000;
    }
    Module &m = *mp;
    long long out = 0;
    int rc = tp_call2(m.host, "[method]runtime.add", a, b, &out);
    m.calls++;
    if (rc == 2) {
        m.traps++;
        recover(m);
        return -3000;
    }
    return rc == 0 ? static_cast<Int>(out) : static_cast<Int>(-2000 + rc);
}

Int php_wasm_sim(Int h, Int entities, Int seed) {
    Module *m = slot(h);
    if (m == nullptr) {
        return -1000;
    }
    return call_idx2(*m, m->idxSim, entities, seed);
}

/// 故意触发 trap 的入口（验证自动重建）
Int php_wasm_burn(Int h, Int iters) {
    Module *mp = slot(h);
    if (mp == nullptr) {
        return -1000;
    }
    Module &m = *mp;
    long long out = 0;
    int rc = tp_call_idx1(m.host, m.idxSpin, iters, &out);
    m.calls++;
    if (rc == 2) {
        m.traps++;
        recover(m);
        return -3000;
    }
    return rc == 0 ? static_cast<Int>(out) : static_cast<Int>(-2000 + rc);
}

/// 灰度切换：新实例就绪后原子替换，旧实例立刻释放。返回切换耗时（微秒），失败 -1
Int php_wasm_swap(Int h, Str newPath) {
    Module *mp = slot(h);
    if (mp == nullptr) {
        return -1;
    }
    Module &m = *mp;
    std::string p = newPath.toStdString();

    auto t0 = std::chrono::steady_clock::now();
    void *nh = tp_open(p.c_str());
    if (nh == nullptr) {
        return -1;
    }
    tp_set_budget(nh, kTicks, kFuel);
    int iAdd = tp_method_index(nh, "[method]runtime.add");
    int iSim = tp_method_index(nh, "[method]runtime.simulate-frame");
    int iSpin = tp_method_index(nh, "[method]runtime.spin");
    if (iAdd < 0) {
        tp_close(nh);
        return -1;
    }
    // 原子替换：先换句柄，再释放旧的
    void *old = m.host;
    m.host = nh;
    m.path = p;
    m.idxAdd = iAdd;
    m.idxSim = iSim;
    m.idxSpin = iSpin;
    if (old != nullptr) {
        tp_close(old);
    }
    auto t1 = std::chrono::steady_clock::now();
    m.rebuilds++;
    return static_cast<Int>(
        std::chrono::duration_cast<std::chrono::microseconds>(t1 - t0).count());
}

/// 观测：which 0=calls 1=traps 2=rebuilds 3=max_us 4=alive
Int php_wasm_stats(Int h, Int which) {
    Module *mp = slot(h);
    if (mp == nullptr) {
        return -1;
    }
    Module &m = *mp;
    if (which == 0) return m.calls;
    if (which == 1) return m.traps;
    if (which == 2) return m.rebuilds;
    if (which == 3) return m.maxUs;
    if (which == 4) return m.alive ? 1 : 0;
    return -1;
}

Int php_wasm_count() {
    int n = 0;
    for (int i = 0; i < g_modCount; i++) {
        if (g_mods[i].alive) {
            n++;
        }
    }
    return n;
}

void php_wasm_set_timing(Int on) {
    g_timing = (on != 0);
}

void php_wasm_close(Int h) {
    Module *m = slot(h);
    if (m != nullptr) {
        close_instance(*m);
        m->alive = false;
    }
}

void php_wasm_shutdown() {
    for (int i = 0; i < g_modCount; i++) {
        close_instance(g_mods[i]);
        g_mods[i].alive = false;
    }
    g_modCount = 0;
}

Int php_wasm_ping(Int x) {
    return static_cast<Int>(tp_ping(x));
}
