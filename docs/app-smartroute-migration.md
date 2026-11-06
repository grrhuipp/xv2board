# App 与 SmartRoute 部署说明

App 客户端与 SmartRoute 已集成在本仓库，包含认证、同步、订阅、设备管理、商店/订单、工单，以及入口映射、Provider 授权、遥测、安全校验和运行指标。

## 兼容性边界

- 「系统配置 → APP」里的接口前缀决定 App 路由：默认 `/api/v1/jiuxiang/`，SmartRoute 为 `/api/v1/jiuxiang/smart-route/`。后台只填写路径段（默认 `jiuxiang`），不填写 `/api/v1/` 或斜杠。未配置时自动保留旧客户端路径；已设置的有效自定义前缀继续生效。修改前缀后须同步修改客户端请求地址，重载 PHP-FPM 和常驻队列服务并验证路由。
- SmartRoute 设备按客户端上报的 `device_id` 或 `install_id` 精确匹配，不再拼接旧安装 ID 前缀；迁移客户端时应确认设备标识与数据库记录一致。
- `resources/rules/appclient.clash.yaml` 是 App 使用的默认 Clash 模板；普通订阅与显式自定义模板不受影响。
- 不从其他环境复制用户、设备、订阅凭证或业务流水；入口池和 Provider 包等业务数据需按目标环境配置。

## 安装与升级

需要 PHP 8.2+、MySQL/MariaDB、Redis 和 Horizon。在服务器项目根目录沿用原版升级入口：

```sh
bash update.sh
```

迁移补齐 App/SmartRoute、入口探活、入口名称字段、UA 分组与七种节点的分组选择字段。重复升级保留受支持节点、用户和分组数据。v2node 的恢复迁移不再执行；升级删除其专属表及明确标记为 v2node 的入口映射、节点统计，旧 manifest 缓存失效。

脚本按原版流程拉取代码、更新 Composer 依赖并调用 `v2board:update`。数据库字段及索引通过 `database/update.sql` 和现有增量步骤更新，无需配置 `.deploy.env` 或单独执行迁移命令。Webman/Adapterman 已移除，升级脚本不安装、停止或启动它们。

`horizon:terminate` 只通知当前进程退出，须确认现有 Supervisor、systemd 或宝塔守护已重新拉起 Horizon。PHP-FPM 和已启用的入口探活继续由现有进程管理器管理。

保持 scheduler 与 Horizon 运行。首次上线 App 客户端前，在「系统配置 → APP」设置更新 JSON 与 AES-128-CBC 密钥/IV（各 16 字节），或者通过 `APPCLIENT_AES_KEY` / `APPCLIENT_AES_IV` 环境变量配置加密密钥；仓库不再内置密钥。入口探活默认关闭；启用前请阅读 `readme.md` 的「App 与 SmartRoute 扩展」部分，确认 Redis、数据库迁移和常驻进程配置。未启用探活时入口映射仍按现有配置下发域名/IP。

## 节点按信任层下发

套餐权限组和客户端 UA 分组共同决定可见节点。未选择 UA 分组的节点允许全部客户端；选择后只在请求 User-Agent 命中任意已选分组关键字时下发。无套餐权限组、其他套餐组及隐藏节点不会进入 manifest。

在「智能路由 → 入口映射」选择实际协议类型及节点 ID，分别配置 L1–L4 的入口地址、端口或入口池。manifest、App 节点列表和 App Clash 配置使用同一入口解析器；未配置当前信任层映射时保留节点原地址，端口留空时保留节点原端口。全局兜底仅在显式启用时参与。

## 验证

在测试环境核对路由、迁移重复执行、App 登录与设备注册、订阅下发、支付回调、入口解析及后台统计。可使用仓库的 `tests/Integration/AppSmartRouteSmoke.php` 做集成冒烟检查；它需要完整依赖及测试数据库，不能代替生产客户端、支付、邮件和真实入口连通性验收。
