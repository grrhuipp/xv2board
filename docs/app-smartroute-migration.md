# App 与 SmartRoute 部署说明

App 客户端与 SmartRoute 已集成在本仓库，包含认证、同步、订阅、设备管理、商店/订单、工单，以及入口映射、Provider 授权、遥测、安全校验和运行指标。

## 兼容性边界

- 「系统配置 → APP」里的接口前缀决定 App 路由：默认 `/api/v1/app`，SmartRoute 为 `/api/v1/app/smart-route`。修改前缀后须同步修改客户端请求地址，重启常驻服务并验证路由；需要兼容旧客户端时先在后台填写旧前缀。
- SmartRoute 设备按客户端上报的 `device_id` 或 `install_id` 精确匹配，不再拼接旧安装 ID 前缀；迁移客户端时应确认设备标识与数据库记录一致。
- `resources/rules/appclient.clash.yaml` 是 App 使用的默认 Clash 模板；普通订阅与显式自定义模板不受影响。
- 不从其他环境复制用户、设备、订阅凭证或业务流水；入口池和 Provider 包等业务数据需按目标环境配置。

## 安装与升级

需要 PHP 8.0+、MySQL/MariaDB、Redis 和 Horizon。修改数据库前先备份。在目标环境按顺序执行：

```sh
php artisan migrate --path=database/migrations/2026_09_10_000001_install_app_smartroute.php --force
php artisan migrate --path=database/migrations/2026_09_26_000001_add_ingress_probe.php --force
```

第一项安装或补齐 App/SmartRoute 表与共享字段；第二项为入口探活增加数据库结构。迁移保留已有业务数据，回退前应恢复已验证备份。执行时应检查线上已有迁移记录和数据库备份，不要盲目运行所有历史迁移。

保持 scheduler 与 Horizon 运行。首次上线 App 客户端前，在「系统配置 → APP」设置更新 JSON 与 AES-128-CBC 密钥/IV（各 16 字节），或者通过 `APPCLIENT_AES_KEY` / `APPCLIENT_AES_IV` 环境变量配置加密密钥；仓库不再内置密钥。入口探活默认关闭；启用前请阅读 `readme.md` 的「App 与 SmartRoute 扩展」部分，确认 Redis、数据库迁移和常驻进程配置。未启用探活时入口映射仍按现有配置下发域名/IP。

## 验证

在测试环境核对路由、迁移重复执行、App 登录与设备注册、订阅下发、支付回调、入口解析及后台统计。可使用仓库的 `tests/Integration/AppSmartRouteSmoke.php` 做集成冒烟检查；它需要完整依赖及测试数据库，不能代替生产客户端、支付、邮件和真实入口连通性验收。
