# 旧版 dhm 兑换接口

来源为 2026-09-10 保存的旧源站代码。普通端入口在 APP 迁移后遗漏，本次恢复：

- `POST /api/v1/user/redeemPlan`：用户鉴权，参数 `redeem_code`，成功返回 `data.state=true`、`data.msg=兑换成功`。
- `POST /api/v1/passport/redeem/register`：沿用普通注册参数，加 `code`。原有 `/passport/auth/register` 同样支持可选 `code`，与旧版一致；不带券码仍为普通注册。当前邮箱验证、注册限制保持有效。

兑换券从 `v2_coupon` 查询，要求 **name 以 dhm 开头**，并且绑定唯一套餐、唯一周期、未过期、有剩余次数。不是要求 code 以 dhm 开头。沿用 RedemptionCodeService 校验和 CouponService 次数扣减。

用户兑换保留原有新购/续费/变更计算；兑换注册保留类型5。零元订单通过现有 OrderService::open 同步开通，账号、券次数、订单在同一数据库事务中完成。失败回滚，券行加锁防止超兑。APP 的兑换接口和服务未改。

无需新增表或字段，使用已有 v2_coupon、v2_order、v2_user，因此本次无需额外迁移 SQL。

验证：在隔离 qa_ 数据库执行 `php tests/Integration/LegacyRedemptionSmoke.php`，覆盖旧响应格式、实际套餐开通、券次数、重复兑换拒绝、普通券/过期券拒绝、兑换注册成功及失败回滚、普通注册和路由鉴权。