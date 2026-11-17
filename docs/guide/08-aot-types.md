# 08 · AOT 下的类型契约

> 这一篇不是讲框架，是讲**一个 PHP 程序员在 AOT 编译下会踩什么**。
>
> 前七篇的坑大多是"用法不对"，这一篇的坑是**"写法在 PHP 里完全合法，在 AOT 下语义变了"**。
> 它们的共同点：编译期不报错、运行时报错信息指不到真实原因。

## 为什么会有这些坑

PHP 是动态语言：变量的类型在运行时决定，`callable` 可以是任何能被调用的东西，
数组元素可以是任何类型，参数可以多传少传。**AOT 编译器必须在编译期把每个变量的类型
定死**，于是所有"运行时才知道"的地方都要靠推断——推断不出来就报错，推断错了就在运行时炸。

下面五类，全部是这几轮真实踩到的。

## 一、扩展点的类型要写具体，不要写 `callable`

```php
// 框架里原来的写法
private ?\Closure $onDisconnect = null;

public function onDisconnect(callable $cb): void   // ✗
{
    $this->onDisconnect = $cb;
}
```

调用时：

```
Invalid callback ... / stdClass->{closure}()
```

`callable` 在 AOT 里是**联合类型**（`string|array|Closure|...`），赋给 `?\Closure` 属性时
会被转成 `stdClass`。之后调用就崩。

**正确写法**：

```php
public function onDisconnect(\Closure $cb): void   // ✓
{
    $this->onDisconnect = $cb;
}
```

框架里 `on(int $msgId, \Closure $fn)` 一直正常，正是因为它用了精确类型。

**规则**：凡是"存起来以后要调用"的参数，类型写 `\Closure`，不要写 `callable`。

## 二、`array` 的元素不能直接调方法

```php
$alive = $registry->alive();     // 返回裸 array（签名里没写元素类型）
$node = $alive[0];
$node->nodeId();                 // ✗
```

报错：

```
Invalid callback TSF\Discovery\NodeInfo::nodeId,
class TSF\Discovery\NodeInfo does not have a method "nodeId"
```

`NodeInfo` **明明有** `nodeId()` 方法。真实原因是 `$alive[0]` 的类型是 `php::Var`，
在 `Var` 上调方法会被当成 **callback 字符串**解析。

**加 `instanceof` 收窄也没用**（我试过）：

```php
if ($node instanceof NodeInfo) {
    $nodeId = $node->nodeId();   // 仍然报同样的错
}
```

**对比**：

```php
$n = $registry->get(1);          // 返回 ?NodeInfo —— 类型明确
if ($n !== null) {
    $n->nodeId();                // ✓ 正常
}
```

**区别只在返回类型是否写明**。所以：

- 自己写方法时，**返回值尽量给具体类型**（`?NodeInfo`、`array<NodeInfo>` 不行就给 `NodeInfo[]` 并让 AOT 能推断）
- 拿不到具体类型时，**改用数组字段**（`$row["id"]`）而不是对象方法
- 这也是为什么 `toArray()` 这类方法在 AOT 项目里很常见——把对象压成数组来绕开这个限制

## 三、标量类型会被"锁死"在同名变量上

```php
foreach ($near as $other) {
    $o = (int) $other;        // $o 被推断成 int
}
foreach ($now as $o => $one) { // ✗ $o 要当 key（php::Var）
```

编译期就报：

```
Cannot assign value to variable $o of type php::Int with type php::Var
```

这个还算友好（编译期报错）。但同样的模式在别处会以更隐蔽的方式出现：

```php
$data = $body;                  // $body 是 string
$data = unpack($msgId, $body);  // ✗ AOT 把 $data 推断成 string，
                                //   后赋数组会被**静默转成字符串 "Array"**
```

框架源码里有一条注释专门记这个：

> 注意：不能写成 `$data = $body; $data = unpack(...);`
> AOT 会把 `$data` 推断成 string，后赋数组会被静默转成 `"Array"`（踩过）。

**规则**：同一个变量不要承载不同类型的值。要给不同形状的数据用不同名字。

## 四、闭包的参数个数是契约

```php
// Gateway 内部：$cb($fd, $uid);
$gw->onDisconnect(function (int $fd): void { });   // ✗ 少一个参数
```

PHP 里这完全合法（多传的参数被忽略）。AOT 下**直接崩**：

```
stdClass->{closure}()
```

**编译期不报**。

**规则**：回调的参数个数、顺序、类型都要和注册处文档一致。框架文档没写清时，
去源码里看调用点（`$cb($fd, $uid)` 这一行）。

## 五、联合类型在 AOT 下是"结构性障碍"

框架的 lint 有一条硬规则：**不出现 `mixed`**。原因是 AOT 需要每个值有确定的物理表示
（int 是 int64、string 是引用计数指针、对象是指针……`mixed` 没法静态分配）。

这带来两个连锁反应：

**① 需要"任意数据"的地方要用 JSON 字符串**

```php
// 业务消息经 Scheduler 投递时
$this->deliver($addr, $msgId, json_encode(["uid" => $uid, "p" => $data]));
```

而不是传数组——数组里再塞 `string|array|object` 会踩 `mixed` 禁令。

**② 定时器等内部消息的载荷也要遵循同一形状**

我踩过两次：

```php
// ✗ 传数组：onMessage 里 (string) $data 变成 "Array"，json_decode 失败 → 消息被静默丢弃
$this->timeout(1000, Msg::AUTO, ["seat" => $seat]);

// ✗ 传了 JSON 但少了 p 这一层：onMessage 统一从 $argv["p"] 取载荷
$this->timeout(1000, Msg::AUTO, json_encode(["seat" => $seat]));

// ✓ 和业务消息完全同构
$this->timeout(1000, Msg::AUTO, json_encode([
    "uid" => 0, "fd" => 0, "p" => ["seat" => $seat],
]));
```

**两次都是静默失败**——没有报错，只是"定时器不触发"。

## 六、编译期与运行时之间还有一条缝：构建缓存

改了 `tsf/src` 里的代码，`tsf build` 说成功，但行为没变——**增量构建不检测框架源码的变更**。

```bash
rm -rf ~/typephp-src/build/cache
tsf build .
```

判断依据：**改的是框架代码（`tsf/src`、`tsf/pkg`），而不是自己工程里的文件**。

我因此在同一个 bug 上浪费了两轮：改完 `onDisconnect` 签名、编译成功、跑起来还是崩，
因为跑的是旧二进制。

## 怎么排查这类问题

报错信息和真实原因脱节，是这类问题的核心难点。可行的办法：

**① 从"谁调用了它"反推，不要从报错文字猜**

`stdClass->{closure}()` 里的 `stdClass` 是**闭包被转换后的形态**，
不是"某个对象"。看到它就往"闭包类型/参数个数"方向查。

`does not have a method X` 里的 X 明明存在时，往"在 `Var` 上调方法"方向查。

**② 加文件写入做实证，别只信日志**

有一轮我怀疑 `onStart` 没被调用，加日志看不到（日志级别/通道问题），
改成 `file_put_contents` 立刻确认它是被调用的：

```php
file_put_contents("/tmp/probe.txt", "onStart called\n", FILE_APPEND);
```

**③ 编译通过 ≠ 代码生效**

每次改完框架代码，先确认不是缓存问题（清缓存重编），再开始调试。

## 一张检查清单

写 AOT PHP 时，逐条过：

- [ ] 存起来以后要调的参数，类型是 `\Closure` 不是 `callable`
- [ ] 从数组取出的对象**没有直接调方法**（改用数组字段，或让返回类型明确）
- [ ] 同一个变量没承载过不同类型的值
- [ ] 所有回调的参数个数/顺序和注册处一致
- [ ] 需要"任意数据"的地方用 JSON 字符串，不用数组套联合类型
- [ ] 内部消息（定时器/自投递）的载荷结构和业务消息**同构**
- [ ] 改完框架代码清过缓存
- [ ] 每个改动都跑过一次**真实流程**，不是只看编译成功

最后一条最重要。这五个坑里，**三个是静默失败**——只有跑真实流程才会暴露。
