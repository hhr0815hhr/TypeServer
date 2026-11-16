// S2：把 TypePHP 的 wasm 组件宿主封装成 C ABI 动态库。
//
// 目的：测量"嵌进常驻进程"的三项成本——库体积、加载耗时、每次调用的边界开销，
//      以及符号污染程度（决定能否安全加载进 PHP/Swoole 进程）。
//
// 设计上刻意把导出面收窄到 6 个符号；Engine 与 Component 进程级共享（OnceLock），
// 每实例只新建 Store + Instance（对应 S1/S3 测到的 2.5 ms 恢复成本）。

use std::collections::HashMap;
use std::ffi::{c_char, CStr};
use std::sync::{Mutex, OnceLock};

use wasmtime::component::{Component, Func, Instance, Linker, ResourceAny, Val};
use wasmtime::{Config, Engine, Store};
use wasmtime_wasi::{WasiCtx, WasiCtxBuilder, WasiCtxView, WasiView};
use wasmtime_wasi_http::p2::{default_hooks, WasiHttpCtxView, WasiHttpView};
use wasmtime_wasi_http::WasiHttpCtx;

const IFACE: &str = "typephp:s1-probe/api@1.0.0";

/// 编译期配置指纹：预编译与运行期必须一致，否则 fuel/epoch 这类
/// "编译期插桩"特性会出现"模块没插桩但宿主以为有"的静默错配。
pub fn config_fingerprint() -> String {
    let no_epoch = std::env::var("TP_NO_EPOCH").is_ok();
    let no_fuel = std::env::var("TP_NO_FUEL").is_ok();
    format!(
        "component=1;epoch={};fuel={};os={};arch={}",
        if no_epoch { 0 } else { 1 },
        if no_fuel { 0 } else { 1 },
        std::env::consts::OS,
        std::env::consts::ARCH
    )
}

/// 构建引擎配置（预编译工具与宿主共用，保证指纹与配置同源）
pub fn build_config() -> (Config, String) {
    let no_epoch = std::env::var("TP_NO_EPOCH").is_ok();
    let no_fuel = std::env::var("TP_NO_FUEL").is_ok();
    let mut cfg = Config::new();
    cfg.wasm_component_model(true);
    cfg.epoch_interruption(!no_epoch);
    cfg.consume_fuel(!no_fuel);
    (cfg, config_fingerprint())
}

/// 读取并校验 `<artifact>.manifest`。
/// 返回 Err 时宿主必须拒绝启动，绝不能"带病运行"。
fn verify_manifest(path: &str, artifact_size: u64) -> Result<(), String> {
    let mpath = format!("{path}.manifest");
    let text = std::fs::read_to_string(&mpath).map_err(|e| {
        format!("缺少 manifest（{mpath}）：{e}；请用 precompile.sh 生成产物")
    })?;

    let mut fp = None;
    let mut size = None;
    for line in text.lines() {
        let line = line.trim();
        if line.is_empty() || line.starts_with('#') {
            continue;
        }
        let Some((k, v)) = line.split_once('=') else {
            continue;
        };
        match k.trim() {
            "fingerprint" => fp = Some(v.trim().to_string()),
            "artifact_size" => size = v.trim().parse::<u64>().ok(),
            _ => {}
        }
    }

    let expected_fp = config_fingerprint();
    match fp {
        Some(v) if v == expected_fp => {}
        Some(v) => {
            return Err(format!(
                "配置漂移：产物指纹 [{v}] 与当前宿主 [{expected_fp}] 不一致；\
                 请用相同配置重新预编译（fuel/epoch 属于编译期特性，运行期改不了）"
            ))
        }
        None => return Err("manifest 缺少 fingerprint 字段".to_string()),
    }

    match size {
        Some(v) if v == artifact_size => Ok(()),
        Some(v) => Err(format!(
            "产物损坏：manifest 记录 {v} 字节，实际 {artifact_size} 字节"
        )),
        None => Err("manifest 缺少 artifact_size 字段".to_string()),
    }
}

struct Ctx {
    wasi: WasiCtx,
    http: WasiHttpCtx,
    table: wasmtime::component::ResourceTable,
}

impl WasiView for Ctx {
    fn ctx(&mut self) -> WasiCtxView<'_> {
        WasiCtxView {
            ctx: &mut self.wasi,
            table: &mut self.table,
        }
    }
}

impl WasiHttpView for Ctx {
    fn http(&mut self) -> WasiHttpCtxView<'_> {
        WasiHttpCtxView {
            ctx: &mut self.http,
            table: &mut self.table,
            hooks: default_hooks(),
        }
    }
}

/// 进程级共享：一个 Engine（JIT 代码只有一份）+ 按路径缓存的组件表。
///
/// 早期版本只有单个 Component 字段，导致"第二个模块加载了却复用第一个组件的实例"——
/// 表现为 v2 模块调用返回 v1 的结果。多模块必须按路径缓存。
struct Shared {
    engine: Engine,
    cache: Mutex<HashMap<String, Component>>,
}

static SHARED: OnceLock<Result<Shared, String>> = OnceLock::new();

fn engine_shared() -> Result<&'static Shared, String> {
    let r = SHARED.get_or_init(|| {
        // 预算治理：epoch 按墙钟切断、fuel 按指令数切断（S3 已验证两者均有效）
        // 配置与指纹同源，避免"预编译和运行期不一致"却没人发现
        let (cfg, _fp) = build_config();
        let engine = Engine::new(&cfg).map_err(|e| format!("engine: {e}"))?;

        // 心跳线程：每 TICK_MS 递增一次 epoch。dealine=N 表示"再过 N 个 tick 就 trap"。
        let tick_ms: u64 = std::env::var("TP_TICK_MS")
            .ok()
            .and_then(|s| s.parse().ok())
            .unwrap_or(10);
        let ticker_engine = engine.clone();
        std::thread::spawn(move || loop {
            std::thread::sleep(std::time::Duration::from_millis(tick_ms));
            ticker_engine.increment_epoch();
        });

        Ok(Shared {
            engine,
            cache: Mutex::new(HashMap::new()),
        })
    });
    match r {
        Ok(s) => Ok(s),
        Err(e) => Err(e.clone()),
    }
}

/// 取指定路径的组件（同一路径只反序列化/JIT 一次）。
/// 支持两种加载方式：
///   *.cwasm —— 预编译产物，运行期只做反序列化，加载前校验 manifest
///   其他     —— 现场 JIT 编译，并顺手产出 .cwasm 供下次使用
fn component_for(path: &str) -> Result<Component, String> {
    let s = engine_shared()?;
    {
        let guard = s
            .cache
            .lock()
            .map_err(|_| "component cache poisoned".to_string())?;
        if let Some(c) = guard.get(path) {
            return Ok(c.clone());
        }
    }

    let component = if path.ends_with(".cwasm") {
        let bytes = std::fs::read(path).map_err(|e| format!("read cwasm: {e}"))?;
        verify_manifest(path, bytes.len() as u64)?;
        unsafe { Component::deserialize(&s.engine, &bytes) }
            .map_err(|e| format!("deserialize 失败（产物与当前 wasmtime 版本可能不兼容）: {e}"))?
    } else {
        let c = Component::from_file(&s.engine, path).map_err(|e| format!("component: {e}"))?;
        if let Ok(raw) = std::fs::read(path) {
            if let Ok(blob) = s.engine.precompile_component(&raw) {
                let _ = std::fs::write(format!("{path}.cwasm"), blob);
            }
        }
        c
    };

    let mut guard = s
        .cache
        .lock()
        .map_err(|_| "component cache poisoned".to_string())?;
    guard.insert(path.to_string(), component.clone());
    Ok(component)
}

pub struct TpHost {
    store: Store<Ctx>,
    #[allow(dead_code)]
    instance: Instance,
    handle: ResourceAny,
    methods: HashMap<String, Func>,
    /// 方法名按注册顺序排列，供"按索引调用"使用（P1：避免每次调用的字符串哈希）
    order: Vec<String>,
    /// 与 order 一一对应，按索引直接取 Func，不查哈希表
    funcs: Vec<Func>,
    /// trap 之后 wasmtime 拒绝再次进入该实例（S3 实测），必须由宿主标记并丢弃重建
    dead: bool,
    /// 每次调用允许的 epoch tick 数
    ticks: u64,
    /// 每次调用允许的 fuel（u64::MAX/4 视为不限）
    fuel: u64,
}

static ERR: Mutex<String> = Mutex::new(String::new());

fn set_err(msg: impl Into<String>) {
    if let Ok(mut g) = ERR.lock() {
        *g = msg.into();
    }
}

unsafe fn cstr(p: *const c_char) -> Option<String> {
    if p.is_null() {
        return None;
    }
    unsafe { CStr::from_ptr(p) }
        .to_str()
        .ok()
        .map(|s| s.to_string())
}

fn build(shared: &'static Shared, path: &str, methods: &[&str]) -> Result<TpHost, String> {
    let component = component_for(path)?;
    let mut store = Store::new(
        &shared.engine,
        Ctx {
            wasi: WasiCtxBuilder::new().build(),
            http: WasiHttpCtx::new(),
            table: wasmtime::component::ResourceTable::new(),
        },
    );
    // 启动阶段（instantiate + create-runtime）要放行：给足 fuel 与 epoch deadline
    let _ = store.set_fuel(u64::MAX / 4);
    store.set_epoch_deadline(1_000_000_000);

    let mut linker: Linker<Ctx> = Linker::new(&shared.engine);
    wasmtime_wasi::p2::add_to_linker_sync(&mut linker).map_err(|e| format!("wasi: {e}"))?;
    wasmtime_wasi_http::p2::add_only_http_to_linker_sync(&mut linker)
        .map_err(|e| format!("wasi-http: {e}"))?;

    let instance = linker
        .instantiate(&mut store, &component)
        .map_err(|e| format!("instantiate: {e}"))?;

    let iface = instance
        .get_export_index(&mut store, None, IFACE)
        .ok_or_else(|| "iface missing".to_string())?;

    // create-runtime
    let idx = instance
        .get_export_index(&mut store, Some(&iface), "create-runtime")
        .ok_or_else(|| "create-runtime missing".to_string())?;
    let create = instance
        .get_func(&mut store, &idx)
        .ok_or_else(|| "create-runtime not func".to_string())?;
    let mut out = [Val::Result(Ok(None))];
    create
        .call(&mut store, &[], &mut out)
        .map_err(|e| format!("create-runtime: {e}"))?;
    let handle = match &out[0] {
        Val::Result(Ok(Some(v))) => match &**v {
            Val::Resource(r) => r.clone(),
            other => return Err(format!("非资源返回: {other:?}")),
        },
        other => return Err(format!("异常返回: {other:?}")),
    };

    // 预先解析方法，避免每次调用都走名字查找
    let mut map = HashMap::new();
    let mut order: Vec<String> = Vec::new();
    let mut funcs: Vec<Func> = Vec::new();
    for name in methods {
        if let Some(i) = instance.get_export_index(&mut store, Some(&iface), name) {
            if let Some(f) = instance.get_func(&mut store, &i) {
                map.insert((*name).to_string(), f);
                order.push((*name).to_string());
                funcs.push(f);
            }
        }
    }

    Ok(TpHost {
        store,
        instance,
        handle,
        methods: map,
        order,
        funcs,
        dead: false,
        ticks: 3,
        fuel: u64::MAX / 4,
    })
}

impl TpHost {
    fn invoke(&mut self, name: &str, args: &[i64], out: *mut i64) -> i32 {
        let Some(f) = self.methods.get(name).copied() else {
            set_err(format!("unknown method {name}"));
            return -1;
        };
        self.invoke_func(f, args, out)
    }

    /// 按索引调用：不查哈希表、不比字符串，直接取 Func（P1 的"编译期绑定"）
    fn invoke_idx(&mut self, idx: i32, args: &[i64], out: *mut i64) -> i32 {
        if idx < 0 || (idx as usize) >= self.funcs.len() {
            set_err(format!("method index out of range: {idx}"));
            return -1;
        }
        let f = self.funcs[idx as usize];
        self.invoke_func(f, args, out)
    }

    fn invoke_func(&mut self, f: Func, args: &[i64], out: *mut i64) -> i32 {
        // 实例已被 trap 打死：直接拒绝，由调用方丢弃重建（S3 实测不可复用）
        if self.dead {
            set_err("instance is dead after trap, rebuild required");
            return 2;
        }
        // 每次调用前重置预算：epoch 按 tick 计、fuel 按指令计
        store_budget_guard(&mut self.store, self.ticks, self.fuel);

        let mut params = Vec::with_capacity(args.len() + 1);
        params.push(Val::Resource(self.handle.clone()));
        for a in args {
            params.push(Val::S64(*a));
        }
        let mut res = [Val::Result(Ok(None))];
        match f.call(&mut self.store, &params, &mut res) {
            Ok(()) => match &res[0] {
                Val::Result(Ok(Some(v))) => {
                    if let Val::S64(n) = &**v {
                        if !out.is_null() {
                            unsafe { *out = *n };
                        }
                        0
                    } else {
                        set_err(format!("非 s64 返回: {v:?}"));
                        -2
                    }
                }
                Val::Result(Err(Some(e))) => {
                    set_err(format!("业务错误: {e:?}"));
                    1
                }
                other => {
                    set_err(format!("形状异常: {other:?}"));
                    -3
                }
            },
            Err(e) => {
                // 超时 / 超预算 / 其他 trap：实例一律作废
                self.dead = true;
                set_err(format!("trap: {e}"));
                2
            }
        }
    }
}

/// 把方法名解析成索引（只在加载时调一次），后续用 tp_call_idx 调用。
/// 返回 -1 表示不存在。
#[no_mangle]
pub unsafe extern "C" fn tp_method_index(h: *mut TpHost, name: *const c_char) -> i32 {
    if h.is_null() {
        return -1;
    }
    let Some(n) = (unsafe { cstr(name) }) else {
        return -1;
    };
    let host = unsafe { &*h };
    let mut i = 0;
    while i < host.order.len() {
        if host.order[i] == n {
            return i as i32;
        }
        i += 1;
    }
    -1
}

/// 按索引调用（两个 int 参数）。idx 由 tp_method_index 在加载时取得。
#[no_mangle]
pub unsafe extern "C" fn tp_call_idx(
    h: *mut TpHost,
    idx: i32,
    a: i64,
    b: i64,
    out: *mut i64,
) -> i32 {
    if h.is_null() {
        set_err("null host");
        return -1;
    }
    let host = unsafe { &mut *h };
    host.invoke_idx(idx, &[a, b], out)
}

/// 按索引调用（一个 int 参数）
#[no_mangle]
pub unsafe extern "C" fn tp_call_idx1(h: *mut TpHost, idx: i32, a: i64, out: *mut i64) -> i32 {
    if h.is_null() {
        set_err("null host");
        return -1;
    }
    let host = unsafe { &mut *h };
    host.invoke_idx(idx, &[a], out)
}

fn store_budget_guard(store: &mut Store<Ctx>, ticks: u64, fuel: u64) {
    if ticks > 0 {
        store.set_epoch_deadline(ticks);
    }
    if fuel != u64::MAX / 4 {
        let _ = store.set_fuel(fuel);
    } else {
        let _ = store.set_fuel(u64::MAX / 4);
    }
}

// ---------------- C ABI ----------------

/// 纯 FFI 基线：不碰 wasm，只测 C→Rust 的边界成本
#[no_mangle]
pub extern "C" fn tp_ping(x: i64) -> i64 {
    x + 1
}

/// 打开一个组件实例；返回不透明句柄，失败返回空指针
#[no_mangle]
pub unsafe extern "C" fn tp_open(path: *const c_char) -> *mut TpHost {
    let Some(p) = (unsafe { cstr(path) }) else {
        set_err("bad path");
        return std::ptr::null_mut();
    };
    let s = match engine_shared() {
        Ok(s) => s,
        Err(e) => {
            set_err(e);
            return std::ptr::null_mut();
        }
    };
    let methods = [
        "[method]runtime.add",
        "[method]runtime.greet",
        "[method]runtime.simulate-frame",
        "[method]runtime.spin",
        "[method]runtime.forever",
    ];
    match build(s, &p, &methods) {
        Ok(h) => Box::into_raw(Box::new(h)),
        Err(e) => {
            set_err(e);
            std::ptr::null_mut()
        }
    }
}

/// 调用一个 (int,int)->int 方法
#[no_mangle]
pub unsafe extern "C" fn tp_call2(
    h: *mut TpHost,
    name: *const c_char,
    a: i64,
    b: i64,
    out: *mut i64,
) -> i32 {
    if h.is_null() {
        set_err("null host");
        return -1;
    }
    let Some(n) = (unsafe { cstr(name) }) else {
        set_err("bad name");
        return -1;
    };
    let host = unsafe { &mut *h };
    host.invoke(&n, &[a, b], out)
}

/// 调用一个 (int)->int 方法
#[no_mangle]
pub unsafe extern "C" fn tp_call1(
    h: *mut TpHost,
    name: *const c_char,
    a: i64,
    out: *mut i64,
) -> i32 {
    if h.is_null() {
        set_err("null host");
        return -1;
    }
    let Some(n) = (unsafe { cstr(name) }) else {
        set_err("bad name");
        return -1;
    };
    let host = unsafe { &mut *h };
    host.invoke(&n, &[a], out)
}

#[no_mangle]
pub unsafe extern "C" fn tp_close(h: *mut TpHost) {
    if !h.is_null() {
        drop(unsafe { Box::from_raw(h) });
    }
}

/// 设置单次调用的预算：ticks = 允许的 epoch 心跳数（0 表示不限制），
/// fuel = 允许的指令数（u64::MAX/4 表示不限制）。
#[no_mangle]
pub unsafe extern "C" fn tp_set_budget(h: *mut TpHost, ticks: u64, fuel: u64) -> i32 {
    if h.is_null() {
        return -1;
    }
    let host = unsafe { &mut *h };
    host.ticks = ticks;
    host.fuel = if fuel == 0 { u64::MAX / 4 } else { fuel };
    0
}

/// 1 = 实例已因 trap 作废（调用方必须丢弃重建），0 = 可用
#[no_mangle]
pub unsafe extern "C" fn tp_is_dead(h: *mut TpHost) -> i32 {
    if h.is_null() {
        return 1;
    }
    let host = unsafe { &*h };
    if host.dead {
        1
    } else {
        0
    }
}

/// 取最近一次错误，返回写入字节数
#[no_mangle]
pub unsafe extern "C" fn tp_last_error(buf: *mut c_char, len: usize) -> usize {
    let Ok(g) = ERR.lock() else { return 0 };
    let bytes = g.as_bytes();
    let n = bytes.len().min(len.saturating_sub(1));
    if !buf.is_null() && n > 0 {
        unsafe {
            std::ptr::copy_nonoverlapping(bytes.as_ptr(), buf as *mut u8, n);
            *buf.add(n) = 0;
        }
    }
    n
}
