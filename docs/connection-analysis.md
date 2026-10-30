# 订阅分析中的连接 IP 列

后台“订阅分析”的表格新增“连接 IP 分析（3 天）”列，位于订阅 IP 排行旁边，直接展示每个用户的连接 IP、位置、ASN 号码和最近上报时间。列表较长时在单元格内滚动，隐藏滚动条，保留滚轮、触控和键盘滚动。独立连接分析菜单、页面和接口已移除。

数据来自 **`v2_user_connect_log`**，由节点 `alive` 上报写入，唯一键是 `(user_id, ip)`。这些 IP 是节点观察到的用户连接地址，不能用上报请求的 `$request->getClientIp()` 代替，否则得到的是节点请求来源。订阅 IP 和 UA 的请求排行继续读取 `v2_subscribe_log`。

连接列只查询订阅分析当前页用户，沿用同一次请求的统计时间。按 `updated_at` 筛选最近72小时，包含窗口边界，排除未来记录、空 IP，以及已识别的节点公网 IP 和其 IPv4 /24；按最近上报时间降序、IP 升序排列。无上报记录时显示空状态。没有逐次连接事件，不显示连接频次，也不把这些记录当作实时在线。订阅分析的用户范围、筛选和异常判定保持原口径。

`GET /subscription-analysis/fetch` 每行增加 `connection_ips` 数组，条目包含 `ip/as_number/country/region/updated_at`；`meta.connection_scope` 说明数据口径。整个当前页只增加一次批量查询，无用户时不查询连接表，不为每个用户逐次发请求。

`created_at` 保留该用户/IP 对的首次记录时间，`updated_at` 每次上报均刷新。分批 upsert 保留首次时间，并在单次上报内复用相同 IP 的本地归属查询。旧数据失真的最近时间不能追溯恢复，后续上报会刷新。

## 用户流量

订阅分析用户栏继续展示 `traffic.day_1`、`traffic.day_3`，单位自动转换为 KiB、MiB、GiB 等。数据取自 `v2_stat_user` 的 `record_type=d`，合计 `(u+d)*server_rate`，包含上传、下载和节点倍率。

现有数据按自然日累计：**1天为站点时区的今天，3天为今天及前两天**，以已入库统计为准；不是滚动24/72小时。页面脚注和流量提示均说明此口径。

## 更新与验证

沿用 `bash update.sh`，通过现有 SQL 更新器增加 `v2_user_connect_log(user_id,updated_at)` 索引，供当前页用户的时间范围查询使用。新安装包含在 `database/install.sql`，无需手动运行迁移。先前发布的连接时间索引保留，避免对已更新站点执行删除索引。

隔离测试：

```sh
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Unit/ConnectionAnalysisTest.php
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Unit/SubscriptionFrequencyTest.php
```

覆盖当前页批量查询、时间窗口及固定快照、节点 IP 排除、分页用户对应关系、重复上报和自然日流量。浏览器验证订阅表格内直接显示连接列，原有 IP/UA 排行及流量继续展示。
