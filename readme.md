<img src="https://avatars.githubusercontent.com/u/56885001?s=200&v=4" alt="logo" width="130" height="130" align="right"/>

[![](https://img.shields.io/badge/TgChat-@UnOfficialV2board讨论-blue.svg)](https://t.me/unofficialV2board)

## 本分支支持的后端
 - [修改版V2bX](https://github.com/wyx2685/V2bX)

## 开发约定：推送目标

本仓库是 `wyx2685/v2board` 的 fork，改动不回流原版。克隆后可启用仓库内置的 pre-push 钩子：

    git config core.hooksPath .githooks

钩子会拒绝推向原版上游 `wyx2685/v2board`，以及直接推 `master` / `main`。本仓库 `origin` 是 `grrhuipp/xv2board`，原版 `upstream` 是 `wyx2685/v2board`。日常开发请推特性分支并向本仓库提交 PR：

    git push -u origin <你的分支>

在 GitHub 网页建 PR 时，请确认 base repository 是 `grrhuipp/xv2board`，不要误选原版。钩子仅拦截本地 `git push`，不能拦截网页 PR。

确有必要绕过钩子时：

    ALLOW_PUSH_UPSTREAM=1 git push <远端> <分支>

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

## 旧巷站点集成

`/api/v1/shop/*` 提供下单即注册；`/api/v2/<后台安全路径>/stat/*` 提供 V2 统计接口。
定时任务包含试用用户流量限制、每日支付方式收款统计和探活日志清理。
Telegram 新增签到、优惠码查询、管理员查邮箱及收入统计命令。

部署入口池探活前先执行 `php artisan migrate --force`，确认 Redis 连接可用，并在
`v2_sr_ingress_pool` 中按需将具体入口的 `enabled` 设为 1；该字段默认关闭。
配置 `SMARTROUTE_INGRESS_PROBE_ENABLED=true`，刷新配置缓存后，通过 supervisor/systemd
单实例常驻运行 `php artisan smartroute:ingress-probe`。探活默认关闭，不会自动启动。
探活健康快照及日志分别存入 `v2_sr_ingress_ip`、`v2_sr_ingress_probe_log`。

## 原版迁移步骤

按以下步骤进行面板代码文件迁移：

    git remote set-url origin https://github.com/wyx2685/v2board  
    git checkout master  
    ./update.sh  


按以下步骤配置缓存驱动为redis，然后刷新设置缓存，重启队列:

    sed -i 's/^CACHE_DRIVER=.*/CACHE_DRIVER=redis/' .env
    php artisan config:clear
    php artisan config:cache
    php artisan horizon:terminate

最后进入后台重新保存主题： 主题配置-选择default主题-主题设置-确定保存

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

- PHP7.3+
- Composer
- MySQL5.5+
- Redis
- Laravel

## Demo
[Demo_user](https://v2bdemo.v-50.me/)
[Demo_admin](https://v2bdemo.v-50.me/admindashboard)
邮箱和密码可随意输入

## Document
[Click](https://v2board.com)

## Sponsors
Thanks to the open source project license provided by [Jetbrains](https://www.jetbrains.com/)

## Community
🔔Telegram Group: [@unofficialV2board](https://t.me/unofficialV2board)  

## How to Feedback
Follow the template in the issue to submit your question correctly, and we will have someone follow up with you.
