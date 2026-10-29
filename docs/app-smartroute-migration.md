# App 与 SmartRoute 部署说明

App 客户端与 SmartRoute 已集成在本仓库，包含认证、同步、订阅、设备管理、商店/订单、工单，以及入口映射、Provider 授权、遥测、安全校验和运行指标。

## 兼容性边界

- 「系统配置 → APP」里的接口前缀决定 App 路由：默认 `/api/v1/app`，SmartRoute 为 `/api/v1/app/smart-route`。修改前缀后须同步修改客户端请求地址，重启常驻服务并验证路由；需要兼容旧客户端时先在后台填写旧前缀。
- SmartRoute 设备按客户端上报的 `device_id` 或 `install_id` 精确匹配，不再拼接旧安装 ID 前缀；迁移客户端时应确认设备标识与数据库记录一致。
- `resources/rules/appclient.clash.yaml` 是 App 使用的默认 Clash 模板；普通订阅与显式自定义模板不受影响。
- 不从其他环境复制用户、设备、订阅凭证或业务流水；入口池和 Provider 包等业务数据需按目标环境配置。

## 安装与升级

需要 PHP 8.0+、MySQL/MariaDB、Redis 和 Horizon。修改数据库前备份数据库、`.env` 和 `config/v2board.php`，先部署包含 v2node / SmartRoute 适配补丁的完整代码，再在服务器项目根目录按顺序执行：

```sh
php artisan migrate --path=database/migrations/2026_09_10_000001_install_app_smartroute.php --force
php artisan migrate --path=database/migrations/2026_09_26_000001_add_ingress_probe.php --force
php artisan migrate --path=database/migrations/2026_10_09_000001_add_server_name_to_sr_ingress_map.php --force
php artisan migrate --path=database/migrations/2026_10_13_000001_restore_v2node.php --force
php artisan migrate --path=database/migrations/2026_10_17_000001_backfill_v2node_ingress_names.php --force

php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache
php artisan horizon:terminate
```

迁移依次补齐 App/SmartRoute、入口探活、入口名称字段、v2node 表及已有 v2node 映射的空名称。Laravel 会跳过已登记执行的迁移；新回填只填写空名称，不覆盖手工名称、入口地址或信任层配置。节点、用户和既有映射均保留；此前已经删除的节点需要从备份恢复。

这是已有 App/SmartRoute 部署的定向升级步骤。此次没有修改 Composer 依赖，无需删除锁文件或执行 `composer update`。不要直接运行旧 `update.sh`：它会硬重置到 `origin/master`，可能覆盖本地补丁或使用错误远端；`v2board:update` 还会重放整份历史 `update.sql`，本次只需上面的定向迁移。

`horizon:terminate` 只通知当前进程退出，须确认现有 Supervisor、systemd 或宝塔守护已重新拉起 Horizon。再通过现有进程管理器重启 Webman 或 PHP-FPM；启用了入口探活时一并重启该常驻进程。不要清空 Redis 或执行全量 `cache:clear`。

保持 scheduler 与 Horizon 运行。首次上线 App 客户端前，在「系统配置 → APP」设置更新 JSON 与 AES-128-CBC 密钥/IV（各 16 字节），或者通过 `APPCLIENT_AES_KEY` / `APPCLIENT_AES_IV` 环境变量配置加密密钥；仓库不再内置密钥。入口探活默认关闭；启用前请阅读 `readme.md` 的「App 与 SmartRoute 扩展」部分，确认 Redis、数据库迁移和常驻进程配置。未启用探活时入口映射仍按现有配置下发域名/IP。

## v2node 按信任层下发

套餐权限组决定用户可以拿到哪些节点，设备的有效暴露层决定这些节点使用哪个入口。无权限组、未关联任何权限组的节点、其他套餐组和隐藏节点不会进入 manifest。

在「智能路由 → 入口映射」选择 **v2node** 节点，分别配置 `intl_only`、`public_intl`、`domestic_limited`、`domestic_sensitive`（界面的 L1–L4）入口地址/端口或入口池。映射使用 `server_type=v2node` 和该 v2node 节点的 ID；不要按内部 SS/Trojan/AnyTLS/VLESS 协议去选择同 ID 的传统节点。已有传统节点映射不会自动复制给新建的 v2node 节点。

manifest、APP 节点列表和 APP Clash 配置使用同一入口解析器。先按 `v2node_ID` 和当前暴露层选择入口，再按节点实际协议生成客户端配置；内部协议转换不会改变映射键。端口留空时保留节点端口；未配置当前层映射时保留原节点地址，全局兜底只在显式启用时参与。旧版本客户端仍受版本闸门限制，不下发新入口。

## 验证

在测试环境核对路由、迁移重复执行、App 登录与设备注册、订阅下发、支付回调、入口解析及后台统计。可使用仓库的 `tests/Integration/AppSmartRouteSmoke.php` 做集成冒烟检查；它需要完整依赖及测试数据库，不能代替生产客户端、支付、邮件和真实入口连通性验收。
