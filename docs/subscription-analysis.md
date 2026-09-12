# 订阅分析

入口位于管理后台“用户管理”下方。SPA 路径 `/subscription/analysis`，数据接口位于现有管理员前缀下：

- `GET /subscription-analysis/fetch`：`q`（邮箱、UID、IP、UA、备注）、`event`（all/attention/frequent/multi_ip/geo/multi_ua）、`marked`、`page`、`page_size`（默认20，最大50）。
- `POST /subscription-analysis/mark`：`user_id`、`marked`（布尔）、`note`（最多500字）。取消标记删除该用户的标记记录。
- 历史弹窗复用 `GET /user/fetchSubscribeLogs`，按用户分页展示所有现存普通订阅记录。

- `POST /subscription-analysis/settings`：保存全局阈值。字段：`frequent_2m`、`frequent_5m`、`frequent_1h`、`ip_10m`、`ip_3d`、`ua_3d`、`countries_3d`、`cities_3d`。频率最小1，其余最小2，最大100000，均为整数。
- 阈值保存在 `v2_subscription_analysis_settings`，返回在 fetch 的 `thresholds` 中；保存后立即影响汇总、筛选和行提示。
- 统计字段：`count_3d`、`ips_3d`、`uas_3d`、`countries_3d`、`cities_3d`；原来的24小时字段已替换。

## 数据口径

只读取已有 `v2_subscribe_log` 普通订阅日志，不采集或修改 APP 订阅。次数表示鉴权后的请求，不保证账号可用或下载成功。日志采集、客户端响应、SmartRoute 均不修改。

一次查询固定应用时区下的当前时间，窗口左右端点均包含。仅展示最近72小时有请求且账号仍存在的用户。搜索 IP/UA 也限定在这个窗口；历史弹窗不限72小时。空 IP 不计入去重数。

频繁拉取：2分钟至少5次，或5分钟至少10次，或1小时至少30次。多 IP：10分钟至少3个，或72小时至少5个不同 IP。上述数字是默认阈值，管理员可以修改。异地要求72小时内至少两个不同IP，且至少两个已知国家或城市；多UA默认至少三种非空UA字符串，去除首尾空白后按数据库排序规则去重，并不代表设备数量。未知国家/城市不计入。它们仅为人工排查提示，不执行封禁或限流。

均隔 = 72小时内首末请求秒数 / (次数 - 1)，不足两次显示空。最近请求按时间优先、ID次之选取，避免旧数据后补导致最近 IP/UA 错配。未采集注册/登录 IP，不在本页推测或补造。

顶部卡片始终是最近72小时总体统计；列表单独应用筛选。标记按用户持久化，超过72小时不活跃的用户暂不显示，再次有请求后标记仍在。

## 安装与更新

`v2board:install` 和 `v2board:update` 已接入 `database/migrations/2026_09_13_000001_add_subscription_analysis.php`。现有站点可单独执行：

```sh
php artisan migrate --path=database/migrations/2026_09_13_000001_add_subscription_analysis.php --force
php artisan migrate --path=database/migrations/2026_09_13_000002_add_subscription_analysis_settings.php --force
```

迁移为日志表增加 `(created_at,user_id)` 索引，并创建 `v2_subscription_analysis_marks`。生产执行前备份日志表；代码切换前完成迁移。`down()` 保留历史数据和人工标记。

## 验证

仅在 `APP_ENV=testing`、数据库名以 `qa_` 开头的隔离数据库运行：

```sh
php tests/Integration/SubscriptionAnalysisSmoke.php
```

覆盖窗口边界、未来/历史记录排除、已删除用户、空IP、IP去重、时间排序、搜索通配符、标记幂等与取消、管理员保护和分页校验。页面复用现有后台的布局、Ant Design Table/Input/Select/Button/Modal和订阅记录弹窗，不使用iframe。前端由React转义文本显示邮箱、UA和备注，避免作为 HTML 执行。
