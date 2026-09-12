# App 与 SmartRoute 源站迁移

来源：2026-09-10 在线源站 `/opt/lnmpr/www/v2_jx` 的实际文件，包括未提交实现。源站 HEAD 为 `5148557`；目标基础为 xv2board `3e27aee2`。

## 保持当前配置

- `config/appclient.php`、`config/smartroute.php` 保留源站设置；`SmartRouteSchema` 仅移除了入口主动探测部分。加密参数不在文档或日志中展示。
- 接口仍为 `/api/v1/jiuxiang` 和 `/api/v1/jiuxiang/smart-route`，未增加 `/app` 别名。
- `database/schema/app-smartroute-settings.json` 保存源站当时的 89 项数据库配置。迁移仅补充不存在的项，不覆盖目标已有配置。
- 源站 App 默认 Clash 模板完整保存在 `resources/rules/appclient.clash.yaml`。App 构建器使用此副本，配置文件值不变；普通订阅模板不变，显式自定义模板仍优先。
- 分级、设备限制、入口选择、安全校验、降级、遥测等设置沿用源站。
- 不复制生产用户/设备/订阅凭证/业务流水。入口映射、入口池、Provider 包是业务数据，需要在目标管理页面配置；没有将源站节点 ID/入口数据覆盖到其他站点。

## 内容与兼容

迁移独立 App 的认证、同步、订阅、设备管理、商店/订单、兑换、个人中心、工单、版本查询；以及完整 SmartRoute 的设备注册、Manifest、Provider 授权/下发、遥测/会话聚合、信任评估、安全守卫、普通入口池、降级、运行指标、清理任务、管理页面、用户画像和 Horizon 入口。

必要适配：保留 xv2board 订单取消条件更新和现有折算/结算，仅补充 App 赠送订单的时长字段；App 礼品卡对接目标的类型/有效期/次数/使用用户结构；画像使用目标 IPv4/IPv6 ip2region；补充 User::plan() 和 App 后台配置字段。普通订阅、非目标队列和原有配置不被整文件覆盖。

## 安装与升级

需要 PHP 8.0+、MariaDB/MySQL、Redis、Horizon。修改数据库前先备份。迁移建立或补齐 15 张功能表、共享订单/优惠券字段及缺少的索引，可重复执行。`v2board:install` 与 `v2board:update` 已接入。单独执行：

```sh
php artisan migrate --path=database/migrations/2026_09_10_000001_install_app_smartroute.php --force
```

不要改用所有历史迁移：已有 xv2board 的 failed_jobs 表可能与历史 Laravel 迁移重复。迁移 down() 保留业务数据；结构回退需恢复已验证备份。

保持 scheduler 和 Horizon 运行。local/production 环境均增加源站 SmartRouteTelemetry 配置：队列 smart_route_telemetry，最多 4 进程、3 次尝试、60 秒超时。入口池直接下发配置域名/IP，不需要额外探测进程。2026-09-11 按要求删除主动探测、健康 IP 筛选、探测日志接口和进程模板；新安装不再创建两张探测表。已有数据库中的历史探测表不做破坏性删除。

## 验证记录

使用无外部网络的独立 PHP 8.3 / MariaDB 测试容器，未改源站业务库、未重启生产服务。验证 PHP 语法、Blade 编译、路由方法存在性、迁移与重复执行、加密往返、App 登录/同步/订阅、设备幂等/跨账号隔离/解绑限制、Manifest、Provider 授权、遥测任务落库、nonce 防重放、验证码第五次失败失效和礼品卡重复兑换拒绝。已补充入口池直接下发、空端口、禁用入口池回退测试。

可复现：专用 qa_ 开头的临时数据库导入 database/install.sql，执行上述迁移后，以 APP_ENV=testing 运行：

```sh
php tests/Integration/AppSmartRouteSmoke.php
```

尚未执行目标生产客户端实机验收、第三方支付、邮件投递、真实设备签名或真实入口连通性验证。
