# 自托管 runner 注册（让 `verify` job 真正跑起来）

> 现状：**这台机器上还没装 runner**。没装之前，手动触发 CI 时 `verify` job 会一直
> 停在 "Waiting for a runner to pick up this job" —— 这是预期行为，不是配置错了。

## 为什么必须自托管

`verify` 要跑 `tsf verify` 的 197 项断言，它依赖三样仓库外的、且版本敏感的东西：

| 依赖 | 位置 | 为什么托管 runner 不行 |
|:--|:--|:--|
| AOT 编译器 `tpc` | `~/typephp-src/bin/tpc.php` | **不在本仓库**（866 个 git 文件里没有） |
| PHP 8.5 **ZTS** | 系统安装 | tpc 生成的代码要求运行时与编译器 ZTS 状态一致；托管 runner 装 NTS 会在链接期报一堆无关错误 |
| Swoole 6.2 + phpx | 系统安装 | 需要按 PHP 版本编译，托管 runner 每次跑都要重装，十几分钟且易漂移 |

所以 `lint` job 走托管 runner（只依赖 PHP CLI），`verify` 走自托管。

## 注册步骤（在 WSL 里，不是 Windows）

WSL 里 `systemd` 可用（`ps -p 1 -o comm=` 输出 `systemd`），所以能装成服务。

**1. 拿注册令牌**

GitHub 仓库 → Settings → Actions → Runners → New self-hosted runner → Linux / x64。
页面上会给一条完整命令，其中含 `--token <TOKEN>`（有效期 1 小时）。

**2. 下载并配置**

```bash
mkdir -p ~/actions-runner && cd ~/actions-runner
curl -sL -o runner.tar.gz https://github.com/actions/runner/releases/download/v2.329.0/actions-runner-linux-x64-2.329.0.tar.gz
tar -xzf runner.tar.gz

./config.sh \
  --url https://github.com/hhr0815hhr/TypeServer \
  --token <TOKEN> \
  --labels tsf \
  --name wsl-tsf \
  --unattended
```

`--labels tsf` 对应 `ci.yml` 里的 `runs-on: [self-hosted, tsf]`——
不加标签的话，这台 runner 会被仓库里所有 `self-hosted` 的 job 抢走。

**3. 装成服务（WSL 关机重启后自动恢复）**

```bash
sudo ./svc.sh install
sudo ./svc.sh start
sudo ./svc.sh status      # 看 active (running)
```

不想装服务就前台跑：`./run.sh`（关掉终端就停，只适合临时验证）。

**4. 验证**

```bash
cd /data/TypeServer && ./tsf/bin/tsf verify
```

先确认本机能跑通，再触发 CI。Actions 页面 → CI → Run workflow。

## 两个容易踩的点

**PATH 不一致。** runner 服务的环境变量和你 shell 里的不一样，尤其不会包含
`~/.cargo/bin` 与 `~/.local/bin`。`ci.yml` 里已经用 `$GITHUB_PATH` 补上了，
但如果以后加了新工具，记得同步加——否则会出现"我本地明明装了，CI 说没有"。

**WSL 重启后 systemd。** 若 `/etc/wsl.conf` 里 `systemd=false`，`svc.sh` 装的服务
不会自启。确认方式：`systemctl is-enabled actions.runner.*`。

## 没 runner 时怎么办

不需要 CI 也能跑全套：

```bash
cd /data/TypeServer
bash scripts/ci-preflight.sh    # 先看环境
./tsf/bin/tsf verify            # 9 套件 197 项
```

`tsf verify` 就是 CI 里跑的那条命令，两者没有第二套逻辑——这是刻意的：
CI 与本地不一致时，绿的意义就没了。
