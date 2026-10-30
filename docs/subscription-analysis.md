# 订阅分析

入口位于管理后台“用户管理”下方。SPA 路径 `/subscription/analysis`，数据接口位于现有管理员前缀下：

- `GET /subscription-analysis/fetch`：`q`（邮箱、UID、IP、UA、备注）、`event`（all/attention/frequent/multi_ip/geo/multi_ua）、`marked`、`page`、`page_size`（默认20，最大50）。
- `POST /subscription-analysis/mark`：`user_id`、`marked`（布尔）、`note`（最多500字）。取消标记删除该用户的标记记录。
- 历史弹窗复用 `GET /user/fetchSubscribeLogs`，按用户分页展示所有现存普通订阅记录。
- 用户列表直接展示“IP 排行（3 天）”“UA 排行（3 天）”，包含每项完整值、拉取次数和占比，无需点击或打开弹窗。每个 IP 下方展示位置及 ASN 号码，不显示组织／运营商和额外的最近 IP 区块；无记录时显示未知。按次数降序排列，同次数按最近时间降序、值升序；全部排名随用户列表返回，条目较多时在单元格内滚动查看，隐藏滚动条并保留滚轮、触控及键盘滚动。
- `fetch` 每行新增 `ip_ranking`、`ua_ranking`，各包含 `data`（value/count/percentage/first_at/last_at）、不同值总数 `total`、用户同期请求数 `requests`、未记录次数 `missing_requests`。IP 条目额外返回 `as`、`isp`、`country`、`city`，取自该用户、该 IP 在统计窗口内最近一条订阅记录，时间相同时按 ID 取最新；不重新进行外部归属地查询。占比以用户同期所有有效请求为分母，空值单独计数；UA 去除首尾空格并按数据库排序规则分组。只对当前页用户执行两次批量分组查询及一次按主键的元数据批量查询，使用与列表相同的72小时窗口及节点 IP 排除规则，不为每个用户逐次发请求。
- 群发邮件的“订阅分析受众”是**多选排除**：`POST /user/sendMail` 的 `audience` 为可选数组（`marked`、`attention`、`priority`）。选中的任一类别均不发送；空数组或省略表示不额外排除，仍遵守用户列表已有筛选。已标记用户即使最近三天没有订阅记录也排除；异常提示和重点排查依据最近三天的分析阈值。管理员和员工群发均适用。

- `POST /subscription-analysis/settings`：保存全局阈值。字段：`frequent_2m`、`frequent_5m`、`frequent_1h`、`ip_10m`、`ip_3d`、`ua_3d`、`countries_3d`、`cities_3d`。频率最小1，其余最小2，最大100000，均为整数。
- 阈值保存在 `v2_subscription_analysis_settings`，返回在 fetch 的 `thresholds` 中；保存后立即影响汇总、筛选和行提示。
- 统计字段：`count_3d`、`ips_3d`、`uas_3d`、`countries_3d`、`cities_3d`；原来的24小时字段已替换。
- 用户栏直接显示1天、3天流量，取自 `v2_stat_user` 的日统计，上传下载合计并计入节点倍率。1天为站点时区的今天，3天为今天及前两天，以已入库统计为准；不是滚动24/72小时。当前页所有用户只增加一次批量查询，接口每行返回 `traffic.day_1/day_3`，页面脚注和提示说明口径。列表、按钮、筛选和分页采用现有后台组件的紧凑样式。
- 表格新增[连接 IP 分析](connection-analysis.md)列，直接展示该用户最近3天节点上报的 IP、位置、ASN 号码和最近上报时间；由 `v2_user_connect_log` 按当前页用户一次批量读取，随订阅列表返回 `connection_ips`，无需额外点击。没有独立菜单或页面。

## 数据口径

只读取已有 `v2_subscribe_log` 普通订阅日志，不采集或修改 APP 订阅。次数表示鉴权后的请求，不保证账号可用或下载成功。日志采集和 SmartRoute 保持不变；普通订阅可应用下方人工标记替换规则。

一次查询固定应用时区下的当前时间，窗口左右端点均包含。仅展示最近72小时有请求且账号仍存在的用户。搜索 IP/UA 也限定在这个窗口；历史弹窗不限72小时。空 IP 不计入去重数。

频繁拉取：2分钟至少5次，或5分钟至少10次，或1小时至少30次。多 IP：10分钟至少3个，或72小时至少5个不同 IP。上述数字是默认阈值，管理员可以修改。异地要求72小时内至少两个不同IP，且至少两个已知国家或城市；多UA默认至少三种非空UA字符串，去除首尾空白后按数据库排序规则去重，并不代表设备数量。未知国家/城市不计入。它们仅为人工排查提示，不执行封禁或限流。

均隔 = 72小时内首末请求秒数 / (次数 - 1)，不足两次显示空。最近请求按时间优先、ID次之选取，避免旧数据后补导致最近 IP/UA 错配。未采集注册/登录 IP，不在本页推测或补造。

顶部卡片始终是最近72小时总体统计；列表单独应用筛选。标记按用户持久化，超过72小时不活跃的用户暂不显示，再次有请求后标记仍在。

## 安装与更新

本次新增索引通过 `database/update.sql` 更新，沿用项目统一的更新入口：

```sh
bash update.sh
```

脚本调用 `v2board:update` 执行升级 SQL，重复索引沿用现有更新器的重复执行处理。此次不新增迁移文件，也不需要另外执行 `php artisan migrate`。新安装的索引直接包含在 `database/install.sql` 中。

2026-10-30 的性能升级增加 `(user_id,created_at)` 索引，供当前页用户的统计、排行和搜索定位使用。原有 `(created_at,user_id)` 索引保留。大日志表创建索引可能耗时，建议在维护窗口执行并按数据库版本确认在线 DDL 支持，不在页面请求中自动建索引。

## 大数据量查询

- 无搜索的全部列表先用窄索引选出当前页用户，再计算这些用户的不同 IP、UA、地区和请求次数；总体汇总仍对最近3天做精确统计。
- 全部、重点排查、异常提示及各单事件筛选复用总体汇总中的精确总数；单独“只看已标记”也复用其总数，避免分页再次扫描完整窗口计算 COUNT。搜索或组合筛选仍单独精确计数。
- IP 分组时同时计算按时间／ID 排序的最新记录键，再按主键一次性读取所有归属信息；同一批数据也提供每个用户的最近记录，去掉独立的“最近一次订阅”查询及重复按 IP 聚合。排序键由日期时间与20位补零的 unsigned bigint ID 组成，不能简化为 `MAX(id)`，以免旧数据后补覆盖较新事件。
- 不引入统计缓存或截断排行榜，保留原窗口、节点排除规则、分页排序和统计精度。仍需读取最近3天日志的总体汇总与复杂筛选，实际耗时应结合线上日志规模与执行计划判断。

## 验证

排行单元测试默认使用内存 SQLite：

```sh
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Unit/SubscriptionFrequencyTest.php
```

MariaDB 回归可设置 `SUBSCRIPTION_FREQUENCY_TEST_MYSQL=1`，并通过环境变量传入同前缀的 `HOST`、`DATABASE`、`USERNAME`、`PASSWORD`（可选 `PORT`），再执行相同命令。不要把密码写入命令行或提交。测试为当前连接启用 `ONLY_FULL_GROUP_BY`，使用随机表前缀的 `TEMPORARY` 表并在断开连接时释放；不会读写现有订阅日志。此模式用于验证 IP/UA 归一化分组在 MariaDB 严格模式下不会产生 1055 错误。

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
