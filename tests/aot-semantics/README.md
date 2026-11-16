# AOT 语义差分测试

同一份 `main.php`，用 `php85`（解释器）和 `tpc` 编译出的二进制各跑一遍，
**每个用例的输出必须逐字一致**。不一致的要么是 AOT 的设计限制（登记 + 写规避），
要么是回归（去修 / 去提 issue）。

```bash
bash run.sh
# 一致 41 / 已知差异 4 / 新差异 0
# 判定：PASS（没有新差异）
```

45 个用例覆盖：闭包/回调、数组、数值、JSON、对象与魔术方法、异常、生成器、
字符串、时间、空值、排序、解构。

## 为什么要做这个

`tsf/README.md` §5.2.2 记了三条"不报错但不干活"的坑，全是**一天之内**踩到的。
这类问题的共同特征是：跑起来看着正常，日志里什么都没有，只能靠偶然发现。
读完文档、读透代码都不管用——**只能靠对照**。

这个 bench 把"偶然发现"变成"每次构建都能检查"。

## 四条已知差异

### 1. 闭包的实参个数：AOT 严格，PHP 宽松 ← 最重要

```
closure_empty_arity   php=i:7
                      aot=THREW(ArgumentCountError: ... expects exactly 0 arguments, 3 given)
```

PHP 允许给闭包多传参数（多余的被忽略）；AOT 直接抛 `ArgumentCountError`。

**这条解释了 §5.2.2 那个定时器坑的根因**：Swoole 调 `Timer::tick` 的回调时固定传
`$timerId`，而注册的是一个空参闭包——AOT 下抛异常，异常被 Swoole 的事件循环吞掉，
表现就是"注册成功、一次都不触发"。当时只能靠往回调里写探针文件才发现它压根没跑。

**规则：任何注册给框架或 Swoole 的回调，形参必须精确匹配。**

| 注册点 | 必须声明的形参 |
|:--|:--|
| `Swoole\Timer::tick/after` | `function (int $timerId)` |
| `$server->onWorkerStart` | `function (int $wid)` |
| `$gw->on($msgId, ...)` | `function (int $fd, int $uid, string $body)` |
| `$gw->onDisconnect` | `function (int $fd, int $uid)` |

### 2. 整数溢出：PHP 降级成 float，AOT 回绕成负数

```
int_overflow_64   php=[i:0=i:9223372036854775807, i:1=f:9223372036854775808.000000, ...]
                  aot=[i:0=i:9223372036854775807, i:1=i:-9223372036854775808, ...]
```

`PHP_INT_MAX + 1` 在 PHP 里变成 float（有精度损失，但**不会变号**）；
AOT 按 C 语义回绕成 `PHP_INT_MIN`——**正的变负的**。

对钱最危险：金币总额、`seq`、计数器一旦回绕，账本直接反号，而且不报错。
规避：不要依赖溢出语义；累加前显式判上界（`if ($sum > PHP_INT_MAX - $add)`），
或者接受 float（但要自己处理精度）。

### 3. 闭包形参的引用传递失效

```
reference_param_mutation   php=[i:0=i:1, i:1=i:2, i:2=i:3]
                           aot=[i:0=i:1, i:1=i:2]        + Warning
```

声明 `function (array &$a)` 的闭包，在 AOT 下调用 `$f($arr)` 只给一条
`Warning: Argument #1 ($a) must be passed by reference, value given`，
**数组根本没被改**。prod 日志里 warning 很容易被淹掉，于是变成"数据没写进去"的静默 bug。

**注意区分**：`use (&$x)` 捕获引用是**正常**的（`closure_use_by_ref` 一致通过）；
坏的只有"形参声明 `&$a`"。

规避：形参不用 `&`，改成返回新值。

### 4.（编译期）两条结构性限制

这两个好在会在构建时炸，不用跑起来才发现：

- 可执行代码必须在函数里。顶层直接写 `main();` → `All execution code must be within a function`。
  （入口由 tpc 自动调 `main()`，不用自己调。）
- `switch` 的每个分支必须以 `return/break/continue/exit/throw` 收尾 →
  `switch case must end with ...`。连 `default:` 也不能"落到底"。

## 怎么加用例

1. 在 `main.php` 的 `CASES` 里加名字，写 `case_<名字>()` 函数，**返回可规范化的值**；
2. `bash run.sh`；
3. 有差异就先判断性质：设计限制 → 写进 `known-diff.txt` 并在上面加一段说明；
   回归 → 别登记，去修。

用例只输出规范化字符串（浮点 `%.6F`、数组 `norm()` 排好序、对象取类名），
否则 diff 会被格式噪声淹没。

## 没覆盖到的

- **并发与多进程**：这是单进程语义对照，集群行为在 `demo/duel/run-cluster.sh`。
- **内存/生命周期**：循环引用、闭包捕获大对象的内存增长，需要专门的压测才能看出来。
- **Swoole 扩展的具体 API**：这里只覆盖语言语义，扩展行为靠 demo 和 bench 的实测。
