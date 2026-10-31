<img src="https://avatars.githubusercontent.com/u/56885001?s=200&v=4" alt="logo" width="130" height="130" align="right"/>

[![](https://img.shields.io/badge/TgChat-@UnOfficialV2board讨论-blue.svg)](https://t.me/unofficialV2board)

## 本分支支持的后端
 - [修改版V2bX](https://github.com/wyx2685/V2bX)
 - [v2node](https://github.com/wyx2685/v2node)

## v2node 对接

后台节点管理支持创建、编辑、复制和排序 v2node 节点；订阅按节点实际协议生成。节点通过 `/api/v2/server/config` 拉取配置，用户、流量和在线设备继续使用 `/api/v1/server/UniProxy/` 接口，`node_type` 填 `v2node`，使用系统配置中的通讯密钥。

「系统配置 → 服务端」中的节点对接 API 地址用于生成一键安装指令；留空时使用站点 URL。支持 `trusted_x_forwarded_for` 配置，以及 AnyTLS 的 Reality 和传输配置。旧节点路由组功能保持停用。

已有 App/SmartRoute 部署请按[定向升级步骤](docs/app-smartroute-migration.md)补齐 v2node 表和入口映射名称，然后刷新配置并重启常驻进程。v2node 支持按设备信任层选择入口，后台入口映射的节点类型选择 `v2node`。迁移保留已有节点；如果之前的移除更新已经删除节点表和统计，只能从备份恢复原数据或重新创建节点。本次恢复不会从空表重建旧记录。

## 开发约定

本仓库的推送远端为 `origin`（`grrhuipp/xv2board`）。建议通过特性分支和 PR 合并到 `master`：

    git push -u origin <你的分支>

克隆后可执行 `git config core.hooksPath .githooks` 启用本地推送保护；钩子会阻止误推到原版仓库及直接推送 `master` / `main`。GitHub 网页创建 PR 时也应核对目标仓库。

## 订阅抵扣策略

「系统配置 → 订阅 → 抵扣策略」支持自动保存。配置项 `surplus_strategy` 只控制零元赠送权益是否参与抵扣，不切换残值公式。

| 值 | 策略 | 计算口径 |
| --- | --- | --- |
| `0`（默认） | 订单金额残值（赠送不抵扣） | 取上一笔非零元赠送的已完成订阅订单，计算其未消耗残值；赠送权益不计价 |
| `1` | 套餐剩余价值（赠送参与抵扣） | 保留同一笔付费订单的残值，额外计入未消耗赠送权益的套餐价值 |

- 只查询当前用户、当前套餐的已完成订阅订单；按订单 ID 倒序找到上一笔有价值的订单，不汇总历史付费订单金额。充值、流量重置、取消订单和其他套餐订单不参与。
- 订单价值 = 实付金额 + 余额支付 + 原订单抵扣 - 原订单退回金额。不能仅因 `total_amount` 为零就判为赠送；余额全额支付、旧套餐抵扣支付仍有订单价值。
- 周期权益按购买周期或 `gift_days` 赠送天数，从用户当前到期时间倒推其剩余部分。后续赠送不会覆盖付费残值，也不会被误当成仍未消耗的付费时长；付费权益耗尽、只剩赠送时，策略 `0` 抵扣为零。
- 两种策略共用时间/流量残值公式：短周期沿用原有时间与流量折算；长周期按固定 30 天分段，当前分段折算、后续完整分段计入全额，剩余恰好 30 天属于当前分段。一次性权益从用户当前流量额度，按当前套餐每次购买授予的额度倒推，各自按未消耗流量比例折算；已消耗的旧赠送不再计入。
- 策略 `1` 中，零元购买周期订单按对应套餐周期现价估值；按天赠送沿用邀请奖励的最高月均价值口径（周期价格除以月数，一次性价格按 12 个月折算），以 30 天折算赠送天数。套餐没有价格时，赠送价值为零，不生成虚拟抵扣。付费订单折扣不因策略切换而重新按标价计算。
- 金额按分向下取整。换套餐后旧权益统一作废，包括不计价的赠送订单，防止重复计入；超出新订单金额的抵扣仍在订单开通时返还余额。
- 抵扣总开关 `surplus_enable` 关闭时两种策略均不生效，所选策略保留；切换仅影响新建的换套餐订单，已有订单不重新计价。此前的四种公式选项已移除。

## AS 节点地址替换

后台站点配置提供 AS 黑名单和白名单模式：

- 黑名单模式：客户端 ASN 在 AS 列表内时替换节点 host。
- 白名单模式：客户端 ASN 不在 AS 列表内时替换节点 host。
- AS 列表每行填写一个 ASN，支持 `4134`、`AS4134` 和前导零。
- 节点关键词填写 `*` 时匹配全部节点，否则按节点名称进行不区分大小写的包含匹配。
- 规则只修改节点 host，不修改端口；用户匹配规则最后执行并保留最高优先级。
- AS 无法识别、AS 列表为空、节点关键词为空或替换 host 为空时不会替换。
- 旧版 `ASN,节点关键词,新host` 三字段文本（配置项 `as_rule`）已移除，不再兼容。
- 仅白名单最近向面板上报过的节点公网 IP；IPv4 按所在 `/24` 整段拉白，过期时间跟随 `server_pull_interval`（10 个上报周期未再上报则过期，也可用 `node_ip_ttl` 覆盖）。这些网段拉取订阅时不走 AS host 替换；订阅分析的次数、最近记录、多 IP / 地区也不计入，UA 仍按全部请求计算。

## App 与 SmartRoute 扩展

「系统配置 → APP」管理 App 客户端接口前缀（默认 `/api/v1/app`）、更新 JSON 和加密密钥。SmartRoute 接口跟随同一前缀；更改后须同步客户端地址并重启常驻服务。旧客户端如需继续使用原路径，可在后台配置对应前缀。管理员配置接口会回传 AES 密钥与 IV，后台 APP 页面默认明文展示；请保护管理员会话和浏览器环境，不要缓存或分享配置响应。

`/api/v1/shop/*` 提供下单即注册；`/api/v2/<后台安全路径>/stat/*` 提供 V2 统计接口。
定时任务包含试用用户流量限制、每日支付方式收款统计和探活日志清理。
Telegram 新增签到、优惠码查询、管理员查邮箱及收入统计命令。

部署入口池探活前先按[部署说明](docs/app-smartroute-migration.md)执行对应的定向迁移，确认 Redis 连接可用，并在
`v2_sr_ingress_pool` 中按需将具体入口的 `enabled` 设为 1；该字段默认关闭。
配置 `SMARTROUTE_INGRESS_PROBE_ENABLED=true`，刷新配置缓存后，通过 supervisor/systemd
单实例常驻运行 `php artisan smartroute:ingress-probe`。探活默认关闭，不会自动启动。
探活健康快照及日志分别存入 `v2_sr_ingress_ip`、`v2_sr_ingress_probe_log`。

## 部署提示

### mise 必备环境（Linux / WSL）

项目根目录的 `mise.toml` 固定 PHP 8.3.35；PHP 后端同时安装 Composer。
该后端从源码编译，仅支持 Linux/macOS。Windows 请在 WSL 的 Linux 发行版内
执行以下命令；Horizon 依赖 `pcntl` / `posix`，不能在原生 Windows 完整运行。
mise 管理 PHP / Composer，MySQL 和 Redis 使用系统服务，不由 mise 自动启动。

Windows 尚未配置 WSL 时，在管理员 PowerShell 中执行 `wsl --install -d Ubuntu`，
按系统提示启用虚拟化并重启，完成 Ubuntu 首次用户初始化后再进入项目目录。
WSL 内须另行安装 Linux 版 mise，不能使用 Windows 的 `mise.exe`。

Ubuntu / Debian 安装服务和 PHP 编译依赖：

```bash
sudo apt-get update
sudo apt-get install -y curl ca-certificates git build-essential autoconf bison \
    re2c pkg-config libxml2-dev libssl-dev libicu-dev libzip-dev libonig-dev \
    libcurl4-openssl-dev libpng-dev libjpeg-dev libfreetype6-dev libwebp-dev \
    libgmp-dev libsodium-dev libreadline-dev libbz2-dev libsqlite3-dev \
    mysql-server redis-server

curl -fsSL https://mise.run | sh
# 在项目根目录执行；无需更改 shell 配置即可使用 mise exec。
export PATH="$HOME/.local/bin:$PATH"
mise trust
mise install

# 扩展须安装到 mise 的 PHP，不能用 apt 的 php-redis 混装。
mise exec -- pecl install igbinary redis
php_prefix="$(mise exec -- php-config --prefix)"
printf 'extension=igbinary.so\nextension=redis.so\n' > "$php_prefix/conf.d/xv2board.ini"
```

启动服务（有 systemd 使用第一组；没有 systemd 的 WSL 使用第二组）：

```bash
sudo systemctl enable --now mysql redis-server
# 无 systemd 时：
# sudo service mysql start
# sudo service redis-server start
```

验证工具、必需扩展与服务：

```bash
mise exec -- php -v
mise exec -- composer --version
mise exec -- php -r '$required = ["bcmath", "ctype", "curl", "dom", "fileinfo", "json", "mbstring", "openssl", "pcntl", "pdo_mysql", "posix", "redis", "igbinary", "tokenizer", "xml", "zip"]; $missing = array_filter($required, fn($ext) => !extension_loaded($ext)); if ($missing) { fwrite(STDERR, "Missing extensions: ".implode(", ", $missing).PHP_EOL); exit(1); } echo "PHP extensions OK".PHP_EOL;'
redis-cli ping
sudo mysql -e 'SELECT VERSION();'
```

Redis 应返回 `PONG`，MySQL 应返回版本号。环境验证通过后，执行
`mise exec -- composer install` 安装项目依赖；不要通过忽略平台要求或关闭安全审计绕过失败。
应用安装前需自行创建数据库和专用用户，并在 `.env` 配置数据库凭据、Redis 和 `APP_KEY`；
不要沿用 `.env.example` 的 root 示例密码。以上步骤只准备运行环境，不会初始化或迁移业务数据。
生产部署还需配置 Web 服务、PHP-FPM 与队列进程守护，不应使用开发服务器对外提供服务。

按部署环境设置 Redis 缓存驱动后，刷新 Laravel 配置缓存并重启队列进程：

    php artisan config:clear
    php artisan config:cache
    php artisan horizon:terminate

App/SmartRoute 的数据库迁移与兼容接口详见 [部署说明](docs/app-smartroute-migration.md)。

## ip2region 离线 IP 归属地

订阅日志和用户连接日志通过内置 PHP ip2region 读取器查询本地 XDB，无需 HTTP
归属地服务、Docker 或额外 Composer 依赖。IPv4、IPv6 自动选择对应数据库。

项目包含以下数据库（`storage/app/ip2region`）：

- `v4.xdb`
- `v6.xdb`

这两个数据库的记录格式为 `国家|省|市|ASN|组织`，不是标准 ip2region ISP 数据格式。
ASN 去掉 `AS` 前缀后用于黑白名单规则；组织名写入 AS 名称和订阅 ISP 字段。
缺失的地理字段保持为空，数据库不包含区县。不能使用字段格式不同的 XDB 替换。

默认直接使用项目内的数据；可在 `.env` 设置 `IP2REGION_DATABASE_PATH` 指向其他目录。
每个地址族按需打开数据库并缓存 512 KiB 索引，不把整个数据库加载到每个 PHP 进程。
查询失败时返回空归属地，不阻断订阅和连接日志流程。没有额外的 Redis 查询结果缓存。

更新数据库时替换同名文件，执行 `php scripts/check-ip2region.php` 验证，然后重启
PHP-FPM 和常驻队列进程。若修改路径配置，需刷新 Laravel 配置缓存。

# **V2Board**

- PHP 8.0+
- Composer
- MySQL5.5+
- Redis
- Laravel

## Document
[Click](https://v2board.com)

## Sponsors
Thanks to the open source project license provided by [Jetbrains](https://www.jetbrains.com/)

## Community
🔔Telegram Group: [@unofficialV2board](https://t.me/unofficialV2board)  

## How to Feedback
Follow the template in the issue to submit your question correctly, and we will have someone follow up with you.
