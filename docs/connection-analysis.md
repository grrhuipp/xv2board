# 连接 IP 分析

后台“用户管理 → 连接 IP 分析”，SPA 路径 `/connection/analysis`。接口在现有管理员路由组内：`GET /connection-analysis/fetch`，支持 `q`（邮箱、UID、IP、ASN、国家或地区）、`days`（1/3/7/30，0 为全部，默认3）、`event`（all/multi_ip/geo）、`page`、`page_size`（默认20，最大50）。

数据来自 **`v2_user_connect_log`**，由节点 `alive` 上报写入，唯一键是 `(user_id, ip)`。这些 IP 是节点观察到的用户连接地址，不能用上报请求的 `$request->getClientIp()` 代替，否则得到的是节点请求来源。订阅分析仍独立读取 `v2_subscribe_log`。

按最近上报时间 `updated_at` 筛选，包含窗口边界，排除未来记录、已删除用户，以及已识别的节点公网 IP 和其 IPv4 /24。每个用户直接展示所有符合范围的 IP、位置、ASN 号码，按最近上报时间降序排列；隐藏单元格滚动条，保留滚轮、触控和键盘滚动。没有逐次连接事件，不显示连接频次，也不把这些记录当作实时在线。多个 IP 指至少2个不同 IP；跨地区要求至少2个不同 IP 且至少2个已知国家或地区，仅供查看。

`created_at` 保留该用户/IP 对的首次记录时间，`updated_at` 每次上报均刷新。旧的 `updateOrCreate` 在属性未变化时可能不更新最近时间；本次改用分批 upsert，保留首次时间、强制刷新最近时间，并在单次请求内复用相同 IP 的本地归属查询。历史失真的最近时间不能追溯恢复，后续上报会刷新。

列表和分页沿用后台现有 Ant Design 组件，使用紧凑间距。无搜索的正常非空页共4次查询：总体汇总、当前页用户、当前页全部 IP、当前页流量；搜索额外执行一次分页计数，不按用户逐个查询。汇总显示所选时间范围的总体数据，列表再应用搜索及事件筛选。大表仍需要按窗口精确聚合，实际耗时取决于数据量和执行计划。

## 用户流量

连接分析和订阅分析的用户栏均展示 `traffic.day_1`、`traffic.day_3`，单位自动转换为 KiB、MiB、GiB 等。数据取自 `v2_stat_user` 的 `record_type=d`，合计 `(u+d)*server_rate`，包含上传、下载和节点倍率，与后台流量统计口径一致。

现有数据按自然日累计：**1天为站点时区的今天，3天为今天及前两天**，以已入库统计为准；不是滚动24/72小时。页面脚注和流量提示均说明此口径。流量范围不随连接时间范围筛选变化。

## 更新与验证

沿用 `bash update.sh`，通过现有 SQL 更新器增加以下索引；新安装包含在 `database/install.sql`，本次没有新增迁移文件：

- `v2_user_connect_log(updated_at,user_id)`：连接分析时间范围。
- `v2_stat_user(user_id,record_type,record_at)`：当前页用户的最近流量。

隔离 SQLite 测试：

```sh
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Unit/ConnectionAnalysisTest.php
```

覆盖自然日及站点时区边界、倍率与上传下载、批量查询数、搜索通配符、时间范围、节点 IP 排除、用户分页、重复上报及首次时间保留。浏览器验证复用实际后台组件，检查筛选、分页、错误重试、内联 IP 信息、流量和手机横向滚动。
