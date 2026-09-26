# 订阅分析

入口位于管理后台“用户管理”下方。SPA 路径 `/subscription/analysis`，数据接口位于现有管理员前缀下：

- `GET /subscription-analysis/fetch`：`q`（邮箱、UID、IP、UA、备注）、`event`（all/attention/frequent/multi_ip/geo/multi_ua）、`marked`、`page`、`page_size`（默认20，最大50）。
- `POST /subscription-analysis/mark`：`user_id`、`marked`（布尔）、`note`（最多500字）。取消标记删除该用户的标记记录。
- 历史弹窗复用 `GET /user/fetchSubscribeLogs`，按用户分页展示所有现存普通订阅记录。
- 群发邮件的“订阅分析受众”是**多选排除**：`POST /user/sendMail` 的 `audience` 为可选数组（`marked`、`attention`、`priority`）。选中的任一类别均不发送；空数组或省略表示不额外排除，仍遵守用户列表已有筛选。已标记用户即使最近三天没有订阅记录也排除；异常提示和重点排查依据最近三天的分析阈值。管理员和员工群发均适用。

- `POST /subscription-analysis/settings`：保存全局阈值。字段：`frequent_2m`、`frequent_5m`、`frequent_1h`、`ip_10m`、`ip_3d`、`ua_3d`、`countries_3d`、`cities_3d`。频率最小1，其余最小2，最大100000，均为整数。
- 阈值保存在 `v2_subscription_analysis_settings`，返回在 fetch 的 `thresholds` 中；保存后立即影响汇总、筛选和行提示。
- 统计字段：`count_3d`、`ips_3d`、`uas_3d`、`countries_3d`、`cities_3d`；原来的24小时字段已替换。

## 数据口径

只读取已有 `v2_subscribe_log` 普通订阅日志，不采集或修改 APP 订阅。次数表示鉴权后的请求，不保证账号可用或下载成功。日志采集和 SmartRoute 保持不变；普通订阅可应用下方人工标记替换规则。

一次查询固定应用时区下的当前时间，窗口左右端点均包含。仅展示最近72小时有请求且账号仍存在的用户。搜索 IP/UA 也限定在这个窗口；历史弹窗不限72小时。空 IP 不计入去重数。

频繁拉取：2分钟至少5次，或5分钟至少10次，或1小时至少30次。多 IP：10分钟至少3个，或72小时至少5个不同 IP。上述数字是默认阈值，管理员可以修改。异地要求72小时内至少两个不同IP，且至少两个已知国家或城市；多UA默认至少三种非空UA字符串，去除首尾空白后按数据库排序规则去重，并不代表设备数量。未知国家/城市不计入。它们仅为人工排查提示，不执行封禁或限流。

均隔 = 72小时内首末请求秒数 / (次数 - 1)，不足两次显示空。最近请求按时间优先、ID次之选取，避免旧数据后补导致最近 IP/UA 错配。未采集注册/登录 IP，不在本页推测或补造。

顶部卡片始终是最近72小时总体统计；列表单独应用筛选。标记按用户持久化，超过72小时不活跃的用户暂不显示，再次有请求后标记仍在。

## 安装与更新

`v2board:install` 和 `v2board:update` 已接入 `database/migrations/2026_09_13_000001_add_subscription_analysis.php`。现有站点可单独执行：

```sh
php artisan migrate --path=database/migrations/2026_09_13_000001_add_subscription_analysis.php --force
php artisan migrate --path=database/migrations/2026_09_13_000002_add_subscription_analysis_settings.php --force
php artisan migrate --path=database/migrations/2026_09_13_000003_add_marked_subscription_host_rule.php --force
```

迁移为日志表增加 `(created_at,user_id)` 索引，并创建 `v2_subscription_analysis_marks`。生产执行前备份日志表；代码切换前完成迁移。`down()` 保留历史数据和人工标记。

## 验证

仅在 `APP_ENV=testing`、数据库名以 `qa_` 开头的隔离数据库运行：

```sh
php tests/Integration/SubscriptionAnalysisSmoke.php
php tests/Integration/MarkedSubscriptionHostSmoke.php
```

覆盖窗口边界、未来/历史记录排除、已删除用户、空IP、IP去重、时间排序、搜索通配符、标记幂等与取消、管理员保护和分页校验。页面复用现有后台的布局、Ant Design Table/Input/Select/Button/Modal和订阅记录弹窗，不使用iframe。前端由React转义文本显示邮箱、UA和备注，避免作为 HTML 执行。

## 标记用户的节点域名替换

“替换规则”按钮位于“阈值设置”之后，弹窗内部滚动。默认关闭；开启后仅对全部手动标记用户的下一次普通订阅拉取生效，不受72小时统计范围限制，不自动标记高危用户，不影响 APP。

每行格式为 `节点关键词,新域名`，最多100条、10000字符。`*` 匹配所有节点，否则按名称不区分大小写包含匹配；后面的匹配项覆盖前面的结果。目标只填写域名或 IP，不含协议、路径和端口。仅替换响应里的 host，节点数据库、端口和 TLS 参数保持不变。执行顺序为 AS 规则、标记规则、用户专属规则。关闭规则或取消用户标记后，下次拉取恢复原有规则结果。

`POST /subscription-analysis/host-rule` 接收 `enabled`（布尔）、`rules`（字符串）；需管理员权限。配置以 JSON 保存到 `v2_subscription_analysis_settings` 的 `id=1` 行的 `marked_host_rule` 字段，fetch 同名字段返回配置。保存阈值与保存替换规则互不覆盖。

标记用户保存在 `v2_subscription_analysis_marks`，`user_id` 对应 `v2_user.id`，另含 `note`、`updated_by`、`updated_at`。取消标记删除该行，原始 `v2_subscribe_log` 不受影响。

`database/install.sql` 已包含两张分析表、替换规则字段及日志索引；`database/update.sql` 已包含对应升级 SQL，沿用 `v2board:update` 忽略重复字段/索引的执行方式。安装及更新命令也接入三项增量迁移。已有分析功能的生产站点只需备份 settings 表后执行第三项迁移，再切换代码。不要为此单独执行整份历史 update.sql。

隔离测试覆盖新安装 SQL、旧 schema 的增量迁移和 SQL 重复升级、阈值/规则互相保留、非法域名校验、标记/取消标记、实际订阅生成、用户专属规则优先级和节点原始数据不变。