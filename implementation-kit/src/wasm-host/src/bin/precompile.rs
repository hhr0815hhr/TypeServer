// tp-precompile：把 .wasm 预编译成 .cwasm，并写出配套 manifest。
//
// 关键点：这里用的 Engine 配置与运行时宿主完全同源（都调 tp_host::build_config），
// 所以 manifest 里的 fingerprint 一定反映真实编译条件。fuel/epoch 属于编译期插桩，
// 运行期改不了，因此这一步是"配置一致性"的唯一可执行约束点。

use std::process::ExitCode;
use wasmtime::Engine;

fn main() -> ExitCode {
    let args: Vec<String> = std::env::args().collect();
    if args.len() < 3 {
        eprintln!("用法: tp-precompile <input.wasm> <output.cwasm>");
        return ExitCode::from(2);
    }
    let input = &args[1];
    let output = &args[2];

    let (cfg, fingerprint) = tp_host::build_config();
    let engine = match Engine::new(&cfg) {
        Ok(e) => e,
        Err(e) => {
            eprintln!("engine 创建失败: {e}");
            return ExitCode::from(1);
        }
    };

    let raw = match std::fs::read(input) {
        Ok(v) => v,
        Err(e) => {
            eprintln!("读取 {input} 失败: {e}");
            return ExitCode::from(1);
        }
    };

    let blob = match engine.precompile_component(&raw) {
        Ok(v) => v,
        Err(e) => {
            eprintln!("预编译失败: {e}");
            return ExitCode::from(1);
        }
    };

    if let Err(e) = std::fs::write(output, &blob) {
        eprintln!("写入 {output} 失败: {e}");
        return ExitCode::from(1);
    }

    let manifest = format!(
        "# 由 tp-precompile 自动生成，请勿手改\n\
         format=1\n\
         fingerprint={fingerprint}\n\
         artifact_size={}\n\
         source_size={}\n\
         source={input}\n",
        blob.len(),
        raw.len()
    );
    let mpath = format!("{output}.manifest");
    if let Err(e) = std::fs::write(&mpath, manifest) {
        eprintln!("写入 {mpath} 失败: {e}");
        return ExitCode::from(1);
    }

    println!("fingerprint={fingerprint}");
    println!("artifact_size={}", blob.len());
    println!("source_size={}", raw.len());
    println!("wrote {output}");
    println!("wrote {mpath}");
    ExitCode::SUCCESS
}
