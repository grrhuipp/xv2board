# SmartRoute 行为分析 IP

行为分析与普通订阅记录统一使用 `$request->getClientIp()`，不再单独读取 `X-Forwarded-For`、`X-Real-IP` 或 `X-Client-Real-IP`。

SmartRoute 设备注册及 manifest 拉取更新设备时，将框架返回的地址同时写入 `last_ip`、`last_client_ip` 和 `last_request_ip`，来源统一为 `request_ip`，后台显示“请求来源”。不再保留旧客户端上报 IP 来覆盖新的请求 IP；框架无法获得地址时记录空值。

是否使用 XFF 由全局 `TrustProxies` 配置决定，可在“系统配置 → 安全 → 可信代理 IP / 网段”维护，详见 [可信代理配置](trusted-proxies.md)。不增加 SmartRoute 专用代理白名单。普通订阅记录原本已使用同一个方法，保持不变。

历史记录不批量回填，在下次设备注册或 manifest 更新时写入新值；manifest 更新仍受设备写入间隔控制。此改动不影响登录、限流及其他业务的取 IP 逻辑。
