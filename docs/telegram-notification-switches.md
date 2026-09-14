# Telegram 通知开关

后台「系统配置 → Telegram」包含总开关及两个独立的通知开关：

| 配置键 | 作用 | 缺省值 |
| --- | --- | --- |
| `telegram_bot_enable` | 机器人通知总开关 | `0` |
| `telegram_bot_ticket_notify` | 网页和 APP 的工单通知，接收人为已绑定 TG 的管理员和客服 | `1` |
| `telegram_bot_order_notify` | 支付回调成功后的订单通知，接收人为已绑定 TG 的管理员 | `1` |

某类通知只有在总开关和对应开关都开启时才入队。关闭通知不会阻止工单保存、订单支付状态更新或订单处理任务。开关针对新触发的通知，不自动撤回已入队消息或补发历史消息。

未配置独立开关时保留原版默认发送行为；如果配置中已存在 `telegram_bot_order_notify = 0`，升级后该值会实际生效。部署时应核对希望保留的通知状态。

后台接口通过 `ConfigController::fetch` 返回两项配置，`ConfigSave::RULES` 接受 `0/1`。UI 使用受控开关，异步加载后显示已保存的值。

验证：在隔离 `qa_` 数据库、`APP_ENV=testing` 下运行 `php tests/Integration/TelegramNotificationSwitchSmoke.php`。覆盖总开关及两项独立开关的 8 种组合、网页/APP 通知、订单处理不受影响、回调去重和配置校验；所有任务由假队列截获，不发送真实通知。
