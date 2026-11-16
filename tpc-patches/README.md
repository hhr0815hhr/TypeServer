# typephp-src 本地补丁

tpc 不是本仓库的一部分（源码在 `~/typephp-src`，非 git 仓库），所以改动只能落在文件里。
**升级 tpc 之后这些补丁会丢，需要重新应用。**

---

## 0001 arginfo 的 stub hash 不再随函数体变动

**文件**：`src/Translator.php`（`genStubFile()`，约 5185 行）
**动机**：`tsf watch` 改一行代码要 4.4 s，根因不在这条链上任何一处"看得见"的地方。

### 症状

改一行方法体（甚至只加一个注释），整个工程的聚合单元 `extension-<工程>.cc`
（157 KB）就会被重编，2.6 s。工程里其他 63 个类 TU 都正确命中了缓存。

### 因果链

1. `gen_stub.php:139` —— `$stubHash = sha1(整个 PHP 源文件)`，注释和函数体都算进"接口哈希"；
2. 于是 `<类>_arginfo.h` 第 2 行的 `Stub hash` 变了（实测**整个文件只差这一行**）；
3. 聚合单元 include 了全部 `_decl.h` + `_arginfo.h`，而它的对象缓存键是这些头的
   **内容哈希**（`Translator::getGeneratedObjectCacheKey()`）→ 键变 → 重编。

调用点还传了 `forceRegeneration = true`，把 gen_stub 自带的"hash 相同就跳过"关掉了。

### 改动

改成"先生成到临时文件，只在**除 hash 行外**确有差异时才替换正式文件"。
这样 arginfo 的字节保持稳定，内容寻址的缓存键就不会假失效。

用临时文件而不是"写完再恢复原内容"，是因为后者会改 mtime，而另一条编译路径
（`NativeSourceProjectBuilder::needsCompile`）是按 depfile 里的 mtime 判定的。

```php
    protected function genStubFile(string $file): void
    {
        $headerFile = $this->getArgInfoHeaderFile($file, true);
        $target = $this->getIncludeDir() . '/' . $headerFile;

        $this->climate->info('generate arginfo file: ' . $this->getRelativePath($file));

        $temporary = $target . '.typephp-new';
        @unlink($temporary);
        generateStubFile($file, $temporary, true, $this->getPhpVersion());
        $this->replaceArgInfoIfChanged($temporary, $target);

        $this->registerArgInfoHeader($headerFile);
    }
```

外加三个私有方法：

- `replaceArgInfoIfChanged(string $temporary, string $target): bool`
  —— 内容相同（忽略 hash 行）就丢弃临时文件、保留原文件；否则 rename 替换；
  Windows 上 rename 不能覆盖，退化为直接写内容（**不要**"先删再 rename"：
  rename 再失败会把头文件弄丢）。
- `sameArgInfoModuloStubHash(string $a, string $b): bool`
  —— 把 `/^ \* Stub hash: [0-9a-f]+ \*\/$/m` 归一化后比较，顺带统一 CRLF/LF。

### 效果（同一套 g++ 壳计时，`bench/watch-test`）

| 场景 | 修前 | 修后 |
|:--|--:|--:|
| 改一个字符（端到端，含检测） | 4.4~4.6 s | **1.45 s**（检测 211 + 编译重启 1236） |
| 只改注释（TUs） | 聚合单元重编 2.6 s | 一次编译都不做 |
| 改方法体（真实语句） | 聚合单元 + 类 TU | 只重编该类 TU（443 ms） |
| **改签名** | 聚合单元 + 依赖它的类 | 一样（应该的：args 真的变了） |

`demo/duel`（9 个包）改一行：1.85 s。

### 验证方法

```bash
# 1. 结构：arginfo 仍能正确生成
rm build/include/php_tsf_src_Metrics_Histogram_arginfo.h   # 删掉
tsf build .                                                 # 应重新生成且内容正确

# 2. 行为：改方法体不该重编聚合单元
#    在一个包装了 g++ 的 PATH 下构建，记录每次 g++ 的 -c 目标
```

### 遗留（已由 0002 解决）

`tsf/ext/codec/cpp-src/codec.cc` **每次构建都重编**（约 160 ms），连无改动构建也编。
原因：`Translator::prepareCompileFileTask()` 里它既不算 `isPhpxMiscFile`
（不在 phpx 目录），也不算 `isGeneratedProjectSource`（不是生成物），
三条对象缓存都不覆盖它，于是永远落到"必须编译"。→ 见 0002。

---

## 0002 工程自带的原生源码不再每次重编

**文件**：`src/Translator.php`（`prepareCompileFileTask()` / `finalizeCompileFileTask()` 及三个新方法）、
`src/Backend/GccLikeBackend.php`（`buildSharedCompileFlags()` 支持 `dependency_file`）
**动机**：`codec.cc` 这类"工程自己写的 .cc"不落在任何一条对象缓存里，每次构建白烧 160 ms。

### 判据

键 = 编译命令 + ABI + **源文件内容摘要**（`sha1_file`），再逐条比对 depfile 里
每个依赖的 mtime。这和 `NativeSourceProjectBuilder::needsCompile()` 是同一套判据。

三个刻意的决定：

1. **不猜 include**。第一版想扫 `#include`、把每个头落到目录上再比 mtime，但
   `<sys/types.h>` 这种带斜杠的系统头和第三方 `mylib/foo.h` 分不出来——猜错就是
   **静默用旧对象**，这是最难查的故障。改成编译时顺手让编译器产出 depfile
   （`-MD -MF`），依赖清单由编译器给。
2. **源文件自己不看 mtime**。内容摘要已经进了键，再叠一层时间戳只会让
   `touch` / `git checkout` / `rsync` 白白触发重编——本项目第四次踩这个坑了。
3. **没有 depfile 就不缓存**。老缓存、或上次编译失败没写出 depfile 时，
   退回"每次都编"，自愈而不是赌。

### 效果

| | 修前 | 修后 |
|:--|--:|--:|
| 无改动构建（watch-test） | 735 ms | **331 ms** |
| 改一个类的方法体（同场景 A/B） | 1594 ms | **1469 ms** |
| `touch codec.cc`（内容没变） | 重编 | **跳过** |
| 改 codec.cc 内容 / touch 它 include 的头 | 重编 | **重编**（正确） |

### 验证方法

```bash
# 无改动构建两次，第二次不该出现 codec.cc
# touch 源文件 → 仍应跳过；touch phpx.h → 应重编
```

---

## 升级 tpc 之后必须回归的东西

补丁本身按上面的说明重新应用。另有三处**不是补丁、但依赖 tpc 具体行为**的地方，
升级后要专门跑一遍：

| 要回归的 | 为什么 | 怎么跑 |
|:--|:--|:--|
| `bench/db-test` | `pkg/db` 是唯一依赖**运行期反射**的模块（`Meta` 用 `ReflectionClass::getAttributes`、`Repo` 用 `ReflectionProperty::setValue`）。这条路径的正确性取决于 phpx 对反射的实现，升级 tpc 可能静默改变行为 | `tsf build . && ./db_test` |
| `bench/watch-test` | 看 `arginfo` 那条补丁还在不在：端到端回到 4.4 s 左右就是补丁丢了 | `./run.sh`（应 ≤3 s） |
| `bench/migrate-test` | 迁移切分器依赖 `PDO` 的行为；`sqlite` 的 DDL 事务语义与 tpc 无关，但连接层变了要复验 | `tsf build . && ./migrate_test` |
