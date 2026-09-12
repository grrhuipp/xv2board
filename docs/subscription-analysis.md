# 订阅分析

入口位于管理后台“用户管理”下方。SPA 路径 `/subscription/analysis`，数据接口位于现有管理员前缀下：

- `GET /subscription-analysis/fetch`：`q`（邮箱、UID、IP、UA、备注）、`event`（all/attention/frequent/multi_ip）、`marked`、`page`、`page_size`（默认20，最大50）。
- `POST /subscription-analysis/mark`：`user_id`、`marked`（布尔）、`note`（最多500字）。取消标记删除该用户的标记记录。
- 历史弹窗复用 `GET /user/fetchSubscribeLogs`，按用户分页展示所有现存普通订阅记录。

## 数据口径

只读取已有 `v2_subscribe_log` 普通订阅日志，不采集或修改 APP 订阅。次数表示鉴权后的请求，不保证账号可用或下载成功。日志采集、客户端响应、SmartRoute 均不修改。

一次查询固定应用时区下的当前时间，窗口左右端点均包含。仅展示最近24小时有请求且账号仍存在的用户。搜索 IP/UA 也限定在这个窗口；历史弹窗不限24小时。空 IP 不计入去重数。

频繁拉取：2分钟至少5次，或5分钟至少10次，或1小时至少30次。多 IP：10分钟至少3个，或24小时至少5个不同 IP。它们仅为人工排查提示，不执行封禁或限流。

均隔 = 24小时内首末请求秒数 / (次数 - 1)，不足两次显示空。最近请求按时间优先、ID次之选取，避免旧数据后补导致最近 IP/UA 错配。未采集注册/登录 IP，不在本页推测或补造。

顶部卡片始终是最近24小时总体统计；列表单独应用筛选。标记按用户持久化，超过24小时不活跃的用户暂不显示，再次有请求后标记仍在。

## 安装与更新

`v2board:install` 和 `v2board:update` 已接入 `database/migrations/2026_09_13_000001_add_subscription_analysis.php`。现有站点可单独执行：

```sh
php artisan migrate --path=database/migrations/2026_09_13_000001_add_subscription_analysis.php --force
```

迁移为日志表增加 `(created_at,user_id)` 索引，并创建 `v2_subscription_analysis_marks`。生产执行前备份日志表；代码切换前完成迁移。`down()` 保留历史数据和人工标记。

## 验证

仅在 `APP_ENV=testing`、数据库名以 `qa_` 开头的隔离数据库运行：

```sh
php tests/Integration/SubscriptionAnalysisSmoke.php
```

覆盖窗口边界、未来/历史记录排除、已删除用户、空IP、IP去重、时间排序、搜索通配符、标记幂等与取消、管理员保护和分页校验。前端用普通 DOM 文本节点显示邮箱、UA和备注，避免作为 HTML 执行。
