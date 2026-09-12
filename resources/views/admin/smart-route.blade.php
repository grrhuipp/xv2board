<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>SmartRoute 管理 - {{$title}}</title>
    <link rel="icon" href="{{$logo}}">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js"></script>
    <style>
        [x-cloak]{display:none!important}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f2f5}
        .tab-active{border-bottom:2px solid #3b82f6;color:#3b82f6;font-weight:600}
        .card{background:#fff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1);padding:24px;margin-bottom:20px}
        .input-field{width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;transition:border-color .2s}
        .input-field:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.1)}
        .btn-primary{background:#3b82f6;color:#fff;padding:8px 20px;border-radius:6px;font-size:14px;font-weight:500;cursor:pointer;border:none;transition:background .2s}
        .btn-primary:hover{background:#2563eb}
        .btn-primary:disabled{background:#93c5fd;cursor:not-allowed}
        .btn-back{background:#f3f4f6;color:#374151;padding:8px 16px;border-radius:6px;font-size:14px;cursor:pointer;border:1px solid #d1d5db;text-decoration:none;transition:background .2s;display:inline-block}
        .btn-back:hover{background:#e5e7eb}
        .label{font-size:13px;font-weight:500;color:#374151;margin-bottom:4px;display:block}
        .hint{font-size:12px;color:#9ca3af;margin-top:2px}
        .section-title{font-size:16px;font-weight:600;color:#111827;margin-bottom:16px;padding-bottom:8px;border-bottom:1px solid #e5e7eb}
        .toast{position:fixed;top:20px;right:20px;padding:12px 24px;border-radius:8px;color:#fff;font-size:14px;z-index:9999;transition:opacity .3s}
        .toast-success{background:#10b981}
        .toast-error{background:#ef4444}
        .stat-card{border-radius:12px;padding:20px;color:#fff}
        .stat-card.purple{background:linear-gradient(135deg,#667eea,#764ba2)}
        .stat-card.blue{background:linear-gradient(135deg,#3b82f6,#1d4ed8)}
        .stat-card.green{background:linear-gradient(135deg,#10b981,#059669)}
        .stat-card.orange{background:linear-gradient(135deg,#f59e0b,#d97706)}
        select.input-field{appearance:auto}
        .tier-badge{display:inline-block;padding:2px 10px;border-radius:9999px;font-size:12px;font-weight:500}
        .tier-intl{background:#dbeafe;color:#1d4ed8}
        .tier-public{background:#d1fae5;color:#059669}
        .tier-limited{background:#fef3c7;color:#d97706}
        .tier-sensitive{background:#fee2e2;color:#dc2626}
        .trust-blacklisted{background:#fee2e2;color:#dc2626}
        .trust-untrusted{background:#fef3c7;color:#d97706}
        .trust-observe{background:#dbeafe;color:#2563eb}
        .trust-basic{background:#d1fae5;color:#059669}
        .trust-trusted{background:#e0e7ff;color:#4f46e5}
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
<div x-data="smartRoute()" x-init="init()" x-cloak>
    <!-- Toast -->
    <div x-show="toast.show" x-transition :class="toast.type==='success'?'toast-success':'toast-error'" class="toast" x-text="toast.msg"></div>

    <!-- Header -->
    <div class="bg-white shadow-sm border-b">
        <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a :href="'/' + securePath" class="btn-back">&larr; 返回后台</a>
                <h1 class="text-xl font-bold text-gray-800">SmartRoute 智能路由管理</h1>
            </div>
            <div class="flex items-center gap-3">
                <a :href="'/' + securePath + '/app-audience'"
                   class="text-sm px-3 py-1.5 rounded-md border border-green-200 text-green-600 hover:bg-green-50 transition-colors"
                   title="查看用户画像统计（APP版本 / 设备品牌 / 归属地 / 运营商 / 系统分布）">
                    用户画像
                </a>
                <button @click="openHorizon()" type="button"
                        class="text-sm px-3 py-1.5 rounded-md border border-blue-200 text-blue-600 hover:bg-blue-50 transition-colors"
                        title="跳转 Laravel Horizon 队列监控（沿用后台登录，最长 6 小时）">
                    队列监控
                </button>
                <span class="text-sm text-gray-500">配置状态:</span>
                <span x-show="loaded" class="text-sm text-green-600 font-medium">● 已加载</span>
                <span x-show="!loaded" class="text-sm text-yellow-600 font-medium">● 加载中...</span>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="max-w-7xl mx-auto px-6 mt-6">
        <div class="flex gap-0 border-b border-gray-200 bg-white rounded-t-lg px-4">
            <template x-for="t in tabs" :key="t.key">
                <button @click="tab=t.key" :class="tab===t.key?'tab-active':'text-gray-500 hover:text-gray-700'" class="px-4 py-3 text-sm transition-colors whitespace-nowrap" x-text="t.label"></button>
            </template>
        </div>
    </div>

    <!-- Content -->
    <div class="max-w-7xl mx-auto px-6 pb-12">

        <!-- ========== 概览 ========== -->
        <div x-show="tab==='overview'" class="mt-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="stat-card purple">
                    <div class="text-sm opacity-80">注册设备总数</div>
                    <div class="text-3xl font-bold mt-1" x-text="overview.total_devices"></div>
                </div>
                <div class="stat-card blue">
                    <div class="text-sm opacity-80">24h 活跃设备</div>
                    <div class="text-3xl font-bold mt-1" x-text="overview.active_devices_24h"></div>
                </div>
                <div class="stat-card green">
                    <div class="text-sm opacity-80">暴露层分布</div>
                    <div class="mt-2 space-y-1 text-sm">
                        <div class="flex justify-between"><span x-text="exposureTierLabel('intl_only')"></span><span x-text="overview.tier_distribution.intl_only"></span></div>
                        <div class="flex justify-between"><span x-text="exposureTierLabel('public_intl')"></span><span x-text="overview.tier_distribution.public_intl"></span></div>
                        <div class="flex justify-between"><span x-text="exposureTierLabel('domestic_limited')"></span><span x-text="overview.tier_distribution.domestic_limited"></span></div>
                        <div class="flex justify-between"><span x-text="exposureTierLabel('domestic_sensitive')"></span><span x-text="overview.tier_distribution.domestic_sensitive"></span></div>
                    </div>
                </div>
                <div class="stat-card orange">
                    <div class="text-sm opacity-80">信任等级分布</div>
                    <div class="mt-2 space-y-1 text-sm">
                        <div class="flex justify-between"><span x-text="trustLevelLabel('blacklisted')"></span><span x-text="overview.trust_distribution.blacklisted||0"></span></div>
                        <div class="flex justify-between"><span x-text="trustLevelLabel('untrusted')"></span><span x-text="overview.trust_distribution.untrusted"></span></div>
                        <div class="flex justify-between"><span x-text="trustLevelLabel('observe')"></span><span x-text="overview.trust_distribution.observe"></span></div>
                        <div class="flex justify-between"><span x-text="trustLevelLabel('basic')"></span><span x-text="overview.trust_distribution.basic"></span></div>
                        <div class="flex justify-between"><span x-text="trustLevelLabel('trusted')"></span><span x-text="overview.trust_distribution.trusted"></span></div>
                    </div>
                </div>
            </div>
            <div class="card" style="border-left:4px solid #ef4444">
                <div class="section-title">蜂窝网络直通模式 <span style="color:#ef4444;font-size:12px">（紧急开关）</span></div>
                <p class="text-sm text-gray-500 mb-3">开启后，蜂窝网络用户无需任何行为条件直接获得最高暴露层。遭受攻击时关闭此开关，回到正常的行为评估模式。</p>
                <div class="flex items-center gap-4">
                    <div>
                        <label class="label">直通模式</label>
                        <select class="input-field" style="width:200px" x-model.number="cfg.cellular_bypass.enabled">
                            <option value="1">开启（蜂窝直通L3）</option>
                            <option value="0">关闭（正常评估）</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">直通层级</label>
                        <select class="input-field" style="width:200px" x-model="cfg.cellular_bypass.bypass_tier">
                            <template x-for="o in tierOptions"><option :value="o.value" x-text="o.label"></option></template>
                        </select>
                    </div>
                    <div style="padding-top:20px">
                        <button class="btn-primary" @click="save('cellular_bypass')" :disabled="saving">保存</button>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="section-title">暴露层说明</div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-gray-500 border-b">
                            <th class="pb-2 pr-4">层级</th><th class="pb-2 pr-4">标识</th><th class="pb-2 pr-4">说明</th><th class="pb-2">适用环境</th>
                        </tr></thead>
                        <tbody class="text-gray-700">
                            <tr class="border-b border-gray-50"><td class="py-2 pr-4"><span class="tier-badge tier-intl" x-text="exposureTierShort('intl_only')"></span></td><td class="pr-4" x-text="exposureTierLabel('intl_only')"></td><td class="pr-4" x-text="cfg.exposure?.intl_only_description||'仅国际入口'"></td><td x-text="environmentLabel('L0_desktop_low')"></td></tr>
                            <tr class="border-b border-gray-50"><td class="py-2 pr-4"><span class="tier-badge tier-public" x-text="exposureTierShort('public_intl')"></span></td><td class="pr-4" x-text="exposureTierLabel('public_intl')"></td><td class="pr-4" x-text="cfg.exposure?.public_intl_description||'国际+公共低敏感入口'"></td><td x-text="environmentLabel('L0_android_wifi')"></td></tr>
                            <tr class="border-b border-gray-50"><td class="py-2 pr-4"><span class="tier-badge tier-limited" x-text="exposureTierShort('domestic_limited')"></span></td><td class="pr-4" x-text="exposureTierLabel('domestic_limited')"></td><td class="pr-4" x-text="cfg.exposure?.domestic_limited_description||'有限国内候选入口'"></td><td x-text="environmentLabel('L0_ios_wifi')"></td></tr>
                            <tr><td class="py-2 pr-4"><span class="tier-badge tier-sensitive" x-text="exposureTierShort('domestic_sensitive')"></span></td><td class="pr-4" x-text="exposureTierLabel('domestic_sensitive')"></td><td class="pr-4" x-text="cfg.exposure?.domestic_sensitive_description||'高敏感国内私有入口'"></td><td x-text="environmentLabel('L1_mobile_cellular')"></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ========== 运行状态 ========== -->
        <div x-show="tab==='runtime'" class="mt-6">
            <div class="flex justify-end mb-3"><button class="btn-primary" @click="loadRuntimeStatus()">刷新运行状态</button></div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="stat-card blue"><div class="text-sm opacity-80">Manifest 1分钟请求</div><div class="text-3xl font-bold mt-1" x-text="runtime.manifest.requests_1m"></div></div>
                <div class="stat-card green"><div class="text-sm opacity-80">Manifest 命中率(5m)</div><div class="text-3xl font-bold mt-1" x-text="runtime.manifest.cache_hit_rate_5m + '%'"></div></div>
                <div class="stat-card purple"><div class="text-sm opacity-80">Telemetry 入队事件(1m)</div><div class="text-3xl font-bold mt-1" x-text="runtime.telemetry_queue.enqueued_events_1m"></div></div>
                <div class="stat-card orange"><div class="text-sm opacity-80">Telemetry 待处理批次</div><div class="text-3xl font-bold mt-1" x-text="runtime.telemetry_queue.pending_batches"></div></div>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="card">
                    <div class="section-title">Manifest 下发</div>
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div>5分钟请求数：<span class="font-bold" x-text="runtime.manifest.requests_5m"></span></div>
                        <div>缓存命中：<span class="font-bold" x-text="runtime.manifest.cache_hits_5m"></span></div>
                        <div>缓存未命中：<span class="font-bold" x-text="runtime.manifest.cache_misses_5m"></span></div>
                        <div>平均耗时：<span class="font-bold" x-text="runtime.manifest.avg_latency_ms_5m + ' ms'"></span></div>
                        <div>TTL：<span class="font-bold" x-text="runtime.manifest.ttl_seconds + ' 秒'"></span></div>
                    </div>
                </div>
                <div class="card">
                    <div class="section-title">入口状态</div>
                    <div class="space-y-2 text-sm">
                        <div>全局兜底：<span class="font-bold" :class="Number(runtime.ingress.fallback_enabled)===1?'text-yellow-600':'text-gray-500'" x-text="Number(runtime.ingress.fallback_enabled)===1?'已开启':'已关闭'"></span></div>
                        <div><span x-text="exposureTierLabel('intl_only')"></span>：<span class="font-mono" x-text="formatIngress(runtime.ingress.intl_only_host, runtime.ingress.intl_only_port)"></span></div>
                        <div><span x-text="exposureTierLabel('public_intl')"></span>：<span class="font-mono" x-text="formatIngress(runtime.ingress.public_intl_host, runtime.ingress.public_intl_port)"></span></div>
                        <div><span x-text="exposureTierLabel('domestic_limited')"></span>：<span class="font-mono" x-text="formatIngress(runtime.ingress.domestic_limited_host, runtime.ingress.domestic_limited_port)"></span></div>
                        <div><span x-text="exposureTierLabel('domestic_sensitive')"></span>：<span class="font-mono" x-text="formatIngress(runtime.ingress.domestic_sensitive_host, runtime.ingress.domestic_sensitive_port)"></span></div>
                        <div>Manifest Generation：<span class="font-bold" x-text="runtime.ingress.manifest_generation"></span></div>
                        <div>最后更新：<span x-text="runtime.ingress.updated_at || '暂无记录'"></span></div>
                    </div>
                </div>
                <div class="card">
                    <div class="section-title">设备活跃写入</div>
                    <div class="grid grid-cols-3 gap-3 text-sm">
                        <div>写库：<span class="font-bold" x-text="runtime.device_touch.written_5m"></span></div>
                        <div>跳过：<span class="font-bold" x-text="runtime.device_touch.skipped_5m"></span></div>
                        <div>跳过率：<span class="font-bold" x-text="runtime.device_touch.skip_rate_5m + '%'"></span></div>
                    </div>
                </div>
                <div class="card">
                    <div class="section-title">遥测队列</div>
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div>队列：<span class="font-mono" x-text="runtime.telemetry_queue.queue"></span></div>
                        <div>处理事件(1m)：<span class="font-bold" x-text="runtime.telemetry_queue.processed_events_1m"></span></div>
                        <div>失败批次(1h)：<span class="font-bold" x-text="runtime.telemetry_queue.failed_batches_1h"></span></div>
                        <div>平均延迟：<span class="font-bold" x-text="runtime.telemetry_queue.avg_delay_ms_5m + ' ms'"></span></div>
                        <div class="col-span-2">最后处理：<span x-text="runtime.telemetry_queue.last_processed_at || '暂无'"></span></div>
                    </div>
                </div>
            </div>
            <div class="card mt-4">
                <div class="section-title">最近10分钟趋势</div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-gray-500 border-b"><th class="pb-2">分钟</th><th class="pb-2">Manifest请求</th><th class="pb-2">Telemetry入队</th><th class="pb-2">Telemetry处理</th><th class="pb-2">失败批次</th></tr></thead>
                        <tbody>
                            <template x-for="(m, idx) in runtime.series.minutes" :key="m">
                                <tr class="border-b border-gray-50"><td class="py-2" x-text="m"></td><td x-text="runtime.series.manifest_requests[idx]"></td><td x-text="runtime.series.telemetry_enqueued[idx]"></td><td x-text="runtime.series.telemetry_processed[idx]"></td><td x-text="runtime.series.telemetry_failed[idx]"></td></tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ========== 环境信任分层 ========== -->
        <div x-show="tab==='environment'" class="mt-6">
            <div class="card">
                <div class="section-title">环境信任分层配置</div>
                <p class="text-sm text-gray-500 mb-4">定义不同平台/网络环境的默认暴露层和最高可达层。Windows/macOS 默认最低信任，Android/iOS Wi-Fi 可通过行为成长升级。</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-gray-500 border-b">
                            <th class="pb-2 pr-4">环境类</th><th class="pb-2 pr-4">条件</th><th class="pb-2 pr-4">默认暴露层</th><th class="pb-2">最高可达层</th>
                        </tr></thead>
                        <tbody>
                            <tr class="border-b border-gray-50">
                                <td class="py-3 pr-4 font-medium" x-text="environmentLabel('L0_desktop_low')"></td>
                                <td class="pr-4 text-gray-500">Windows / macOS</td>
                                <td class="pr-4"><select class="input-field" x-model="cfg.environment.desktop_default_tier"><template x-for="o in tierOptions"><option :value="o.value" x-text="o.label"></option></template></select></td>
                                <td><select class="input-field" x-model="cfg.environment.desktop_max_tier"><template x-for="o in tierOptions"><option :value="o.value" x-text="o.label"></option></template></select></td>
                            </tr>
                            <tr class="border-b border-gray-50">
                                <td class="py-3 pr-4 font-medium" x-text="environmentLabel('L0_android_wifi')"></td>
                                <td class="pr-4 text-gray-500">Android + Wi-Fi</td>
                                <td class="pr-4"><select class="input-field" x-model="cfg.environment.android_wifi_default_tier"><template x-for="o in tierOptions"><option :value="o.value" x-text="o.label"></option></template></select></td>
                                <td><select class="input-field" x-model="cfg.environment.android_wifi_max_tier"><template x-for="o in tierOptions"><option :value="o.value" x-text="o.label"></option></template></select></td>
                            </tr>
                            <tr class="border-b border-gray-50">
                                <td class="py-3 pr-4 font-medium" x-text="environmentLabel('L0_ios_wifi')"></td>
                                <td class="pr-4 text-gray-500">iOS + Wi-Fi</td>
                                <td class="pr-4"><select class="input-field" x-model="cfg.environment.ios_wifi_default_tier"><template x-for="o in tierOptions"><option :value="o.value" x-text="o.label"></option></template></select></td>
                                <td><select class="input-field" x-model="cfg.environment.ios_wifi_max_tier"><template x-for="o in tierOptions"><option :value="o.value" x-text="o.label"></option></template></select></td>
                            </tr>
                            <tr>
                                <td class="py-3 pr-4 font-medium" x-text="environmentLabel('L1_mobile_cellular')"></td>
                                <td class="pr-4 text-gray-500">Android/iOS + 蜂窝</td>
                                <td class="pr-4"><select class="input-field" x-model="cfg.environment.mobile_cellular_default_tier"><template x-for="o in tierOptions"><option :value="o.value" x-text="o.label"></option></template></select></td>
                                <td><select class="input-field" x-model="cfg.environment.mobile_cellular_max_tier"><template x-for="o in tierOptions"><option :value="o.value" x-text="o.label"></option></template></select></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="flex justify-end"><button class="btn-primary" @click="save('environment')" :disabled="saving">保存环境配置</button></div>
        </div>

        <!-- ========== 升级阈值 ========== -->
        <div x-show="tab==='upgrade'" class="mt-6">
            <!-- 行为评估范围 -->
            <div class="card">
                <div class="section-title">行为评估范围</div>
                <p class="text-sm text-gray-500 mb-4">平时可用账号历史行为放宽升级；攻击期切回近期设备窗口，让策略更敏感。</p>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div>
                        <label class="label">评估数据范围</label>
                        <select class="input-field" x-model="cfg.evaluation.data_scope">
                            <option value="recent_device">按判断标准期限取当前设备最近动作</option>
                            <option value="device_history">按当前设备全量历史（不限时间窗口）</option>
                            <option value="account_history">按历史判断取整个账号所有数据</option>
                        </select>
                        <p class="hint">设备历史模式只看当前设备码的全部行为，换设备后新设备从零开始；账号历史会聚合所有设备数据，有共享风险。</p>
                    </div>
                </div>
            </div>

            <!-- 快速判断 -->
            <div class="card">
                <div class="section-title">快速判断 <span style="color:#ef4444;font-size:12px">（新设备前 N 天不达标直接锁海外）</span></div>
                <p class="text-sm text-gray-500 mb-4">控制快速判断检查哪些条件。APP 未实现某项上报时，关闭对应检查项避免误杀。</p>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div>
                        <label class="label">启用快速判断</label>
                        <select class="input-field" x-model.number="cfg.fast_check.enabled">
                            <option value="1">启用</option><option value="0">禁用</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">评估窗口(天)</label>
                        <input type="number" class="input-field" x-model.number="cfg.fast_check.window_days" min="1" max="7">
                    </div>
                    <div>
                        <label class="label">检查流量</label>
                        <select class="input-field" x-model.number="cfg.fast_check.check_traffic">
                            <option value="1">检查</option><option value="0">跳过（APP未实现上报时选此项）</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">检查目标站点数</label>
                        <select class="input-field" x-model.number="cfg.fast_check.check_destinations">
                            <option value="1">检查</option><option value="0">跳过（APP未实现上报时选此项）</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">检查稳定会话数</label>
                        <select class="input-field" x-model.number="cfg.fast_check.check_sessions">
                            <option value="1">检查</option><option value="0">跳过</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">流量阈值(MB)</label>
                        <input type="number" class="input-field" x-model.number="cfg.fast_check.effective_bytes_mb" min="1">
                        <p class="hint">低于此值触发拦截</p>
                    </div>
                    <div>
                        <label class="label">目标站点数阈值</label>
                        <input type="number" class="input-field" x-model.number="cfg.fast_check.distinct_destinations" min="1">
                    </div>
                    <div>
                        <label class="label">稳定会话数阈值</label>
                        <input type="number" class="input-field" x-model.number="cfg.fast_check.stable_sessions" min="1">
                    </div>
                </div>
            </div>

            <!-- 升级到 public_intl -->
            <div class="card">
                <div class="section-title">升级到 public_intl <span class="tier-badge tier-public ml-2">L1</span></div>
                <p class="text-sm text-gray-500 mb-4">满足以下条件中的 N 条即可升级（任意 N 条模式）</p>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div>
                        <label class="label">最少满足条件数</label>
                        <input type="number" class="input-field" x-model.number="cfg.upgrade_public_intl.min_conditions" min="1" max="4">
                        <p class="hint">满足以下条件中的几条即可</p>
                    </div>
                    <div>
                        <label class="label">评估窗口(天)</label>
                        <input type="number" class="input-field" x-model.number="cfg.upgrade_public_intl.window_days" min="1" max="30">
                        <p class="hint">评估最近N天的行为数据</p>
                    </div>
                    <div>
                        <label class="label">活跃天数 ≥</label>
                        <input type="number" class="input-field" x-model.number="cfg.upgrade_public_intl.active_days" min="1" max="7">
                    </div>
                    <div>
                        <label class="label">稳定会话数 ≥</label>
                        <input type="number" class="input-field" x-model.number="cfg.upgrade_public_intl.stable_sessions" min="1">
                    </div>
                    <div>
                        <label class="label">稳定连接时长(分钟) ≥</label>
                        <input type="number" class="input-field" x-model.number="cfg.upgrade_public_intl.stable_connected_minutes" min="1">
                    </div>
                    <div>
                        <label class="label">有效流量(MB) ≥</label>
                        <input type="number" class="input-field" x-model.number="cfg.upgrade_public_intl.effective_bytes_mb" min="1">
                    </div>
                    <div>
                        <label class="label">目标站点数 ≥</label>
                        <input type="number" class="input-field" x-model.number="cfg.upgrade_public_intl.distinct_destinations" min="0">
                        <p class="hint">不重复目标站点数，APP未实现时设为 0 跳过</p>
                    </div>
                </div>
            </div>

            <!-- 升级到 domestic_limited -->
            <div class="card">
                <div class="section-title">升级到 domestic_limited <span class="tier-badge tier-limited ml-2">L2</span></div>
                <p class="text-sm text-gray-500 mb-4">必须同时满足所有条件</p>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div>
                        <label class="label">评估窗口(天)</label>
                        <input type="number" class="input-field" x-model.number="cfg.upgrade_domestic_limited.window_days" min="1" max="30">
                        <p class="hint">评估最近N天的行为数据</p>
                    </div>
                    <div>
                        <label class="label">活跃天数 ≥</label>
                        <input type="number" class="input-field" x-model.number="cfg.upgrade_domestic_limited.active_days" min="1" max="7">
                    </div>
                    <div>
                        <label class="label">稳定会话数 ≥</label>
                        <input type="number" class="input-field" x-model.number="cfg.upgrade_domestic_limited.stable_sessions" min="1">
                    </div>
                    <div>
                        <label class="label">稳定连接时长(分钟) ≥</label>
                        <input type="number" class="input-field" x-model.number="cfg.upgrade_domestic_limited.stable_connected_minutes" min="1">
                    </div>
                    <div>
                        <label class="label">有效流量(MB) ≥</label>
                        <input type="number" class="input-field" x-model.number="cfg.upgrade_domestic_limited.effective_bytes_mb" min="1">
                    </div>
                    <div>
                        <label class="label">探测无连接比率上限 ≤</label>
                        <input type="number" step="0.01" class="input-field" x-model.number="cfg.upgrade_domestic_limited.probe_without_connect_ratio_max" min="0" max="1">
                        <p class="hint">超过此值将阻止升级</p>
                    </div>
                    <div>
                        <label class="label">突发集中度上限 ≤</label>
                        <input type="number" step="0.01" class="input-field" x-model.number="cfg.upgrade_domestic_limited.burstiness_ratio_max" min="0" max="1">
                        <p class="hint">流量集中在单次突发的比例</p>
                    </div>
                    <div>
                        <label class="label">目标站点数 ≥</label>
                        <input type="number" class="input-field" x-model.number="cfg.upgrade_domestic_limited.distinct_destinations" min="0">
                        <p class="hint">不重复目标站点数，APP未实现时设为 0 跳过</p>
                    </div>
                </div>
            </div>

            <!-- 升级到 domestic_sensitive -->
            <div class="card">
                <div class="section-title">升级到 domestic_sensitive <span class="tier-badge tier-sensitive ml-2">L3</span></div>
                <p class="text-sm text-gray-500 mb-4">最高敏感层，必须同时满足所有条件 + 蜂窝网络 + 完整性验证</p>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div>
                        <label class="label">要求蜂窝网络</label>
                        <select class="input-field" x-model.number="cfg.upgrade_domestic_sensitive.require_cellular">
                            <option value="1">是</option><option value="0">否</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">要求完整性验证</label>
                        <select class="input-field" x-model.number="cfg.upgrade_domestic_sensitive.require_attestation">
                            <option value="1">是 (Play Integrity / App Attest)</option><option value="0">否</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">评估窗口(天)</label>
                        <input type="number" class="input-field" x-model.number="cfg.upgrade_domestic_sensitive.window_days" min="1" max="30">
                        <p class="hint">评估最近N天的行为数据</p>
                    </div>
                    <div>
                        <label class="label">活跃天数 ≥</label>
                        <input type="number" class="input-field" x-model.number="cfg.upgrade_domestic_sensitive.active_days" min="1" max="14">
                    </div>
                    <div>
                        <label class="label">稳定会话数 ≥</label>
                        <input type="number" class="input-field" x-model.number="cfg.upgrade_domestic_sensitive.stable_sessions" min="1">
                    </div>
                    <div>
                        <label class="label">稳定连接时长(分钟) ≥</label>
                        <input type="number" class="input-field" x-model.number="cfg.upgrade_domestic_sensitive.stable_connected_minutes" min="1">
                    </div>
                    <div>
                        <label class="label">有效流量(MB) ≥</label>
                        <input type="number" class="input-field" x-model.number="cfg.upgrade_domestic_sensitive.effective_bytes_mb" min="1">
                    </div>
                    <div>
                        <label class="label">目标站点数 ≥</label>
                        <input type="number" class="input-field" x-model.number="cfg.upgrade_domestic_sensitive.distinct_destinations" min="0">
                        <p class="hint">不重复目标站点数，APP未实现时设为 0 跳过</p>
                    </div>
                    <div>
                        <label class="label">无高风险行为天数 ≥</label>
                        <input type="number" class="input-field" x-model.number="cfg.upgrade_domestic_sensitive.no_high_risk_days" min="1">
                        <p class="hint">近N天内无高风险诊断型行为</p>
                    </div>
                </div>
            </div>
            <div class="flex justify-end"><button class="btn-primary" @click="save('evaluation','fast_check','upgrade_public_intl','upgrade_domestic_limited','upgrade_domestic_sensitive')" :disabled="saving">保存升级阈值</button></div>
        </div>

        <!-- ========== 降级策略 ========== -->
        <div x-show="tab==='downgrade'" class="mt-6">
            <div class="card">
                <div class="section-title">信任降级策略</div>
                <p class="text-sm text-gray-500 mb-4">定义何时自动降低用户信任等级。降级后有冷却期，不能立即重新升级。</p>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div>
                        <label class="label">启用自动降级</label>
                        <select class="input-field" x-model.number="cfg.downgrade.auto_downgrade_enabled">
                            <option value="1">启用</option><option value="0">禁用</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">不活跃天数阈值</label>
                        <input type="number" class="input-field" x-model.number="cfg.downgrade.inactive_days_threshold" min="1">
                        <p class="hint">连续N天无活跃自动降回上一层</p>
                    </div>
                    <div>
                        <label class="label">降级冷却时间(小时)</label>
                        <input type="number" class="input-field" x-model.number="cfg.downgrade.cooldown_hours" min="1">
                        <p class="hint">降级后N小时内不能重新升级</p>
                    </div>
                    <div>
                        <label class="label">签名失败次数阈值</label>
                        <input type="number" class="input-field" x-model.number="cfg.downgrade.signature_fail_threshold" min="1">
                        <p class="hint">签名校验失败N次触发降级</p>
                    </div>
                    <div>
                        <label class="label">探测异常比率阈值</label>
                        <input type="number" step="0.01" class="input-field" x-model.number="cfg.downgrade.probe_anomaly_threshold" min="0" max="1">
                        <p class="hint">探测行为异常比率超过此值触发降级</p>
                    </div>
                </div>
            </div>
            <div class="flex justify-end"><button class="btn-primary" @click="save('downgrade')" :disabled="saving">保存降级策略</button></div>
        </div>

        <!-- ========== 首开检测 ========== -->
        <div x-show="tab==='first_open'" class="mt-6">
            <div class="card">
                <div class="section-title">首次打开检测策略</div>
                <p class="text-sm text-gray-500 mb-4">控制 APP 首次打开时的节点可用性检测行为。允许对当前授权可见节点做全量检测，但不允许跨未授权层探测。</p>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div>
                        <label class="label">允许全量可用性检测</label>
                        <select class="input-field" x-model.number="cfg.first_open.allow_full_probe">
                            <option value="1">允许</option><option value="0">禁止</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">检测时间窗口(秒)</label>
                        <input type="number" class="input-field" x-model.number="cfg.first_open.probe_window_seconds" min="60">
                        <p class="hint">超过此窗口后回归常规限制</p>
                    </div>
                    <div>
                        <label class="label">最大检测节点数</label>
                        <input type="number" class="input-field" x-model.number="cfg.first_open.max_probe_nodes" min="1">
                    </div>
                    <div>
                        <label class="label">允许深度测速</label>
                        <select class="input-field" x-model.number="cfg.first_open.allow_deep_speedtest">
                            <option value="0">仅延迟检测</option><option value="1">允许深度测速</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">允许跨入口层探测</label>
                        <select class="input-field" x-model.number="cfg.first_open.allow_cross_ingress_probe">
                            <option value="0">禁止</option><option value="1">允许</option>
                        </select>
                        <p class="hint">强烈建议禁止</p>
                    </div>
                </div>
            </div>
            <div class="flex justify-end"><button class="btn-primary" @click="save('first_open')" :disabled="saving">保存首开策略</button></div>
        </div>

        <!-- ========== Provider 安全 ========== -->
        <div x-show="tab==='provider'" class="mt-6">
            <div class="card">
                <div class="section-title">Provider 安全配置</div>
                <p class="text-sm text-gray-500 mb-4">高敏感 provider 不走公开 URL，通过受鉴权的 brokered fetch API 下发。Grant 绑定用户+设备+暴露层，短 TTL 且限制使用次数。</p>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div>
                        <label class="label">启用 Brokered Fetch</label>
                        <select class="input-field" x-model.number="cfg.provider.brokered_fetch_enabled">
                            <option value="1">启用</option><option value="0">禁用（不推荐）</option>
                        </select>
                        <p class="hint">禁用后高敏感 provider 将走普通 URL</p>
                    </div>
                    <div>
                        <label class="label">Grant TTL(秒)</label>
                        <input type="number" class="input-field" x-model.number="cfg.provider.grant_ttl_seconds" min="60">
                        <p class="hint">Provider grant 有效期</p>
                    </div>
                    <div>
                        <label class="label">Grant 最大拉取次数</label>
                        <input type="number" class="input-field" x-model.number="cfg.provider.grant_max_fetch_count" min="1">
                        <p class="hint">同一 grant 最多拉取几次</p>
                    </div>
                    <div>
                        <label class="label">要求设备签名</label>
                        <select class="input-field" x-model.number="cfg.provider.require_device_signature">
                            <option value="1">要求</option><option value="0">不要求</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">影子验签审计</label>
                        <select class="input-field" x-model.number="cfg.provider.signature_audit">
                            <option value="1">启用</option><option value="0">关闭</option>
                        </select>
                        <p class="hint">只验签记日志、不拦截请求，用于灰度验证签名链路</p>
                    </div>
                    <div>
                        <label class="label">Payload 编码方式</label>
                        <select class="input-field" x-model="cfg.provider.payload_encoding">
                            <option value="gzip+base64">gzip+base64</option>
                            <option value="base64">base64</option>
                            <option value="plain">plain</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="flex justify-end"><button class="btn-primary" @click="save('provider')" :disabled="saving">保存 Provider 配置</button></div>
        </div>

        <!-- ========== 设备管理 ========== -->
        <div x-show="tab==='device'" class="mt-6">
            <div class="card">
                <div class="section-title">设备管理配置</div>
                <p class="text-sm text-gray-500 mb-4">限制同一账号的活跃设备数量，防止短时间内大量新设备注册。</p>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div>
                        <label class="label">每用户最大设备数</label>
                        <input type="number" class="input-field" x-model.number="cfg.device.max_devices_per_user" min="1">
                    </div>
                    <div>
                        <label class="label">新设备突发阈值</label>
                        <input type="number" class="input-field" x-model.number="cfg.device.new_device_burst_threshold" min="1">
                        <p class="hint">窗口期内注册超过此数触发风控</p>
                    </div>
                    <div>
                        <label class="label">突发检测窗口(小时)</label>
                        <input type="number" class="input-field" x-model.number="cfg.device.new_device_burst_window_hours" min="1">
                    </div>
                </div>
            </div>
            <div class="flex justify-end"><button class="btn-primary" @click="save('device')" :disabled="saving">保存设备配置</button></div>
        </div>

        <!-- ========== 安全基线 ========== -->
        <div x-show="tab==='security'" class="mt-6">
            <div class="card">
                <div class="section-title">安全基线配置</div>
                <p class="text-sm text-gray-500 mb-4">OWASP API Security Top 10 基线要求：Nonce 防重放、时间戳校验、请求签名、速率限制。</p>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div>
                        <label class="label">强制 HTTPS</label>
                        <select class="input-field" x-model.number="cfg.security.require_https">
                            <option value="1">强制</option><option value="0">不强制</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">JWT 签名算法</label>
                        <select class="input-field" x-model="cfg.security.jwt_algorithm">
                            <option value="ES256">ES256 (推荐)</option>
                            <option value="RS256">RS256</option>
                            <option value="HS256">HS256</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">Nonce TTL(秒)</label>
                        <input type="number" class="input-field" x-model.number="cfg.security.nonce_ttl_seconds" min="30">
                        <p class="hint">Nonce 在 Redis 中的存活时间</p>
                    </div>
                    <div>
                        <label class="label">时间戳容差(秒)</label>
                        <input type="number" class="input-field" x-model.number="cfg.security.timestamp_tolerance_seconds" min="10">
                        <p class="hint">请求时间戳与服务器时间的最大偏差</p>
                    </div>
                    <div>
                        <label class="label">重放缓存驱动</label>
                        <select class="input-field" x-model="cfg.security.replay_cache_driver">
                            <option value="redis">Redis (推荐)</option>
                            <option value="file">File</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">Manifest 速率限制(/分钟)</label>
                        <input type="number" class="input-field" x-model.number="cfg.security.rate_limit_manifest_per_minute" min="1">
                    </div>
                    <div>
                        <label class="label">Provider 速率限制(/分钟)</label>
                        <input type="number" class="input-field" x-model.number="cfg.security.rate_limit_provider_per_minute" min="1">
                    </div>
                    <div>
                        <label class="label">Telemetry 速率限制(/分钟)</label>
                        <input type="number" class="input-field" x-model.number="cfg.security.rate_limit_telemetry_per_minute" min="1">
                    </div>
                </div>
            </div>

            <!-- SSL Certificate Pinning -->
            <div class="card mt-4">
                <div class="section-title">SSL Certificate Pinning</div>
                <p class="text-sm text-gray-500 mb-4">客户端 APP 启动时拉取此列表，校验服务器证书指纹。换证书前先把新证书的 pin 加进来，确保过渡期新旧证书都能通过。</p>
                <div class="mb-4">
                    <label class="label">证书指纹列表（证书 DER SHA-256, Base64 编码）</label>
                    <template x-for="(pin, idx) in (cfg.security.ssl_pins || [])" :key="idx">
                        <div class="flex items-center gap-2 mb-2">
                            <input type="text" class="input-field flex-1 font-mono text-sm" x-model="cfg.security.ssl_pins[idx]" placeholder="ywPINPvl18mzy...">
                            <span class="text-xs text-gray-400" x-text="idx === 0 ? '主' : '备'"></span>
                            <button class="text-red-500 text-sm hover:text-red-700" @click="cfg.security.ssl_pins.splice(idx, 1)">删除</button>
                        </div>
                    </template>
                    <button class="text-sm text-blue-600 hover:text-blue-800 mt-1" @click="if(!cfg.security.ssl_pins) cfg.security.ssl_pins=[]; cfg.security.ssl_pins.push('')">+ 添加指纹</button>
                    <p class="hint mt-2">获取方式：<code class="bg-gray-100 px-1 rounded text-xs">echo | openssl s_client -connect 域名:443 2>/dev/null | openssl x509 -outform DER | openssl dgst -sha256 -binary | base64</code></p>
                </div>
            </div>

            <div class="flex justify-end"><button class="btn-primary" @click="save('security')" :disabled="saving">保存安全配置</button></div>
        </div>

        <!-- ========== 遥测与行为 ========== -->
        <div x-show="tab==='telemetry'" class="mt-6">
            <div class="card">
                <div class="section-title">遥测与行为评分配置</div>
                <p class="text-sm text-gray-500 mb-4">控制客户端遥测上报行为，以及服务端对上报数据的交叉校验策略。</p>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div>
                        <label class="label">批量上报最大事件数</label>
                        <input type="number" class="input-field" x-model.number="cfg.telemetry.batch_max_events" min="1">
                    </div>
                    <div>
                        <label class="label">要求会话关闭上报</label>
                        <select class="input-field" x-model.number="cfg.telemetry.session_close_required">
                            <option value="1">要求</option><option value="0">不要求</option>
                        </select>
                        <p class="hint">会话结束时必须上报聚合指标</p>
                    </div>
                    <div>
                        <label class="label">启用服务端交叉校验</label>
                        <select class="input-field" x-model.number="cfg.telemetry.cross_validate_with_server">
                            <option value="1">启用</option><option value="0">禁用</option>
                        </select>
                        <p class="hint">对比服务端流量记录与客户端上报</p>
                    </div>
                    <div>
                        <label class="label">可疑偏差阈值</label>
                        <input type="number" step="0.01" class="input-field" x-model.number="cfg.telemetry.suspicious_deviation_threshold" min="0" max="1">
                        <p class="hint">客户端与服务端数据偏差超过此值标记可疑</p>
                    </div>
                    <div>
                        <label class="label">遥测批量队列化</label>
                        <select class="input-field" x-model.number="cfg.telemetry.queue_enabled">
                            <option value="1">启用</option><option value="0">禁用，回退同步写入</option>
                        </select>
                        <p class="hint">关闭后 telemetry/events/batch 将直接同步写库，便于应急回滚</p>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="section-title">性能与回滚开关</div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div>
                        <label class="label">Manifest 服务端短缓存</label>
                        <select class="input-field" x-model.number="cfg.performance.manifest_cache_enabled">
                            <option value="1">启用</option><option value="0">禁用</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">Manifest TTL（秒）</label>
                        <input type="number" class="input-field" x-model.number="cfg.performance.manifest_ttl_seconds" min="30" max="900">
                    </div>
                    <div>
                        <label class="label">设备活跃写入节流</label>
                        <select class="input-field" x-model.number="cfg.performance.device_touch_throttle_enabled">
                            <option value="1">启用</option><option value="0">禁用</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">设备写入节流 TTL（秒）</label>
                        <input type="number" class="input-field" x-model.number="cfg.performance.device_touch_ttl_seconds" min="10" max="300">
                    </div>
                </div>
            </div>
            <div class="flex justify-end"><button class="btn-primary" @click="save('telemetry','performance')" :disabled="saving">保存遥测与性能配置</button></div>
        </div>

        <!-- ========== 设备管理 ========== -->
        <div x-show="tab==='devices'" class="mt-6">
            <div class="card">
                <div class="flex items-center justify-between mb-4">
                    <div class="section-title mb-0 border-0 pb-0">设备管理</div>
                    <div class="flex gap-2">
                        <input type="text" class="input-field" style="width:200px" placeholder="搜索邮箱 / user_id / device_id / IP" x-model="deviceSearch" @keyup.enter="devicePage=1;loadDevices()">
                        <select class="input-field" style="width:140px" x-model="deviceFilterTier" @change="devicePage=1;loadDevices()">
                            <option value="">全部暴露层</option>
                            <template x-for="o in tierOptions"><option :value="o.value" x-text="o.label"></option></template>
                        </select>
                        <select class="input-field" style="width:140px" x-model="deviceFilterTrust" @change="devicePage=1;loadDevices()">
                            <option value="">全部信任等级</option>
                            <option value="blacklisted">封禁</option>
                            <option value="untrusted">信任 L1</option>
                            <option value="observe">信任 L2</option>
                            <option value="basic">信任 L3</option>
                            <option value="trusted">信任 L4</option>
                        </select>
                        <button class="btn-primary" @click="devicePage=1;loadDevices()">搜索</button>
                        <label class="flex items-center gap-1 text-xs text-gray-600 leading-8 cursor-pointer select-none">
                            <input type="checkbox" x-model="deviceShowUnbound"> 显示已解绑设备
                        </label>
                        <button class="btn-back text-xs" @click="expandAllAccounts()" :disabled="!deviceList.length">全部展开</button>
                        <button class="btn-back text-xs" @click="collapseAllAccounts()" :disabled="!deviceList.length">全部收起</button>
                        <button class="px-2 py-1 rounded border border-orange-300 text-orange-600 text-xs hover:bg-orange-50" @click="bulkPurgeAllUnbound()">一键清理所有已解绑</button>
                        <button class="px-2 py-1 rounded border border-red-300 text-red-600 text-xs hover:bg-red-50" @click="bulkPurgeInactive()">一键清理15天未活跃</button>
                        <span class="text-xs text-gray-400 leading-8" x-text="expandedAccountCount() ? ('已展开 ' + expandedAccountCount() + ' 个账号') : '未展开'"></span>
                    </div>
                </div>
                <div class="space-y-3">
                    <template x-for="account in deviceList" :key="account.user_id">
                        <div class="border border-gray-200 rounded-lg bg-white overflow-hidden">
                            <div class="px-4 py-3 flex items-start gap-3">
                                <button class="mt-0.5 w-6 h-6 rounded-full border border-gray-300 text-gray-500 hover:text-gray-700 hover:border-gray-400 flex items-center justify-center" @click="toggleAccount(account)" type="button" :aria-expanded="accountExpanded(account)" :title="accountExpanded(account)?'收起':'展开'">
                                    <span x-text="accountExpanded(account)?'−':'+'"></span>
                                </button>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-3 flex-wrap">
                                        <div class="font-medium text-gray-800 truncate" x-text="account.user_email"></div>
                                        <div class="text-xs text-gray-400">#<span x-text="account.user_id"></span></div>
                                        <span class="text-xs px-2 py-0.5 rounded-full bg-blue-50 text-blue-700" x-text="'活跃 ' + account.active_device_count"></span>
                                        <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-600" x-text="'总数 ' + account.total_device_count"></span>
                                        <span
                                            x-show="account.ghost_device_count > 0"
                                            class="text-xs px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200"
                                            :title="'占登录额度但本页不可见的设备（额度活跃 ' + account.quota_active_device_count + ' 台）；用 php artisan device:fix-ghost 修复'"
                                            x-text="'幽灵 ' + account.ghost_device_count"
                                        ></span>
                                        <span class="text-xs px-2 py-0.5 rounded-full" :class="trustBadgeClass(account.highest_risk_trust_level)" x-text="trustLevelLabel(account.highest_risk_trust_level)"></span>
                                        <span class="text-xs px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-700" x-text="exposureTierLabel(account.highest_exposure_tier)"></span>
                                    </div>
                                    <div class="mt-2 grid grid-cols-1 md:grid-cols-4 gap-2 text-xs text-gray-500">
                                        <div><span class="text-gray-400">最后活跃</span> <span x-text="account.last_active_at || '-'"></span></div>
                                        <div><span class="text-gray-400">活跃 IP</span> <span class="font-mono" x-text="account.last_ip || '-'"></span></div>
                                        <div><span class="text-gray-400">最近设备</span> <span class="font-mono" x-text="account.last_device_id || '-'"></span></div>
                                        <div><span class="text-gray-400">风险摘要</span> <span x-text="riskSummaryText(account)"></span></div>
                                    </div>
                                </div>
                                <div class="text-right text-xs text-gray-500 min-w-[120px]">
                                    <div>匹配范围</div>
                                    <div class="font-medium" x-text="account.match_scope==='device'?'设备命中':(account.match_scope==='account'?'账号命中':'全部')"></div>
                                    <div x-show="account.matched_device_ids && account.matched_device_ids.length" x-text="'命中 ' + account.matched_device_ids.length + ' 台设备'"></div>
                                    <div class="mt-2 flex justify-end gap-2">
                                        <button
                                            class="inline-flex items-center px-2 py-1 rounded border border-red-200 text-red-600 hover:bg-red-50"
                                            @click.stop="resetAccountDevices(account)"
                                            title="注销该账号全部旧登录并释放设备名额"
                                        >
                                            重置设备登录
                                        </button>
                                        <button
                                            class="inline-flex items-center px-2 py-1 rounded border border-orange-200 text-orange-600 hover:bg-orange-50 disabled:opacity-50"
                                            :disabled="(account.total_device_count - account.active_device_count) <= 0"
                                            @click.stop="purgeAccountUnbound(account)"
                                            :title="'清理该账号下所有 status=0 的设备数据'"
                                        >
                                            清理已解绑 <span class="ml-1 font-mono" x-text="(account.total_device_count - account.active_device_count)"></span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="border-t bg-gray-50 px-4 py-3" x-show="accountExpanded(account)" x-cloak>
                                <div x-show="account.ghost_device_count > 0" class="mb-3 rounded border border-amber-200 bg-amber-50 px-3 py-2">
                                    <div class="text-xs font-medium text-amber-800">
                                        占额度但本表不可见的设备（<span x-text="account.ghost_device_count"></span> 台）
                                    </div>
                                    <div class="mt-1 text-[11px] text-amber-700">
                                        这些记录只存在于 v2_user_devices，会计入登录设备数，但强制解绑与一键清理无法命中。执行
                                        <code class="font-mono">php artisan device:fix-ghost --user-id=<span x-text="account.user_id"></span></code>
                                        预览，加 <code class="font-mono">--execute</code> 修复。
                                    </div>
                                    <template x-for="g in (account.ghost_devices || [])" :key="g.device_id">
                                        <div class="mt-1 text-[11px] text-amber-900 flex flex-wrap gap-x-3">
                                            <span class="font-mono" x-text="g.device_id"></span>
                                            <span x-text="(g.device_name || '未上报') + ' / ' + (g.os_type || '-')"></span>
                                            <span class="font-mono" x-text="g.last_ip || '-'"></span>
                                            <span x-text="'最后活跃 ' + (g.last_active_at || '-')"></span>
                                        </div>
                                    </template>
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead>
                                            <tr class="text-left text-gray-500 border-b">
                                                <th class="pb-2 pr-3">设备</th>
                                                <th class="pb-2 pr-3">平台</th>
                                                <th class="pb-2 pr-3">型号 / OS / APP</th>
                                                <th class="pb-2 pr-3">环境</th>
                                                <th class="pb-2 pr-3">信任</th>
                                                <th class="pb-2 pr-3">暴露</th>
                                                <th class="pb-2 pr-3">行为分</th>
                                                <th class="pb-2 pr-3">最后评估</th>
                                                <th class="pb-2 pr-3">IP / 网络</th>
                                                <th class="pb-2 pr-3">状态</th>
                                                <th class="pb-2">操作</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <template x-for="d in visibleDevices(account)" :key="d.id">
                                                <tr class="border-b border-gray-100" :class="d.matched?'bg-yellow-50':''">
                                                    <td class="py-2 pr-3">
                                                        <div class="font-mono text-xs" x-text="d.device_id"></div>
                                                        <div class="text-[11px] text-yellow-700" x-show="d.matched">命中搜索</div>
                                                    </td>
                                                    <td class="pr-3" x-text="d.platform"></td>
                                                    <td class="pr-3 text-xs text-gray-600">
                                                        <div x-text="(d.device_model||'未上报')"></div>
                                                        <div x-text="(d.os_version||'未上报') + ' / ' + (d.app_version||'-')"></div>
                                                    </td>
                                                    <td class="pr-3 text-xs" x-text="environmentLabel(d.environment_class)"></td>
                                                    <td class="pr-3"><span class="tier-badge" :class="trustBadgeClass(d.trust_level)" x-text="trustLevelLabel(d.trust_level)"></span></td>
                                                    <td class="pr-3"><span class="tier-badge" :class="{'tier-intl':d.exposure_tier==='intl_only','tier-public':d.exposure_tier==='public_intl','tier-limited':d.exposure_tier==='domestic_limited','tier-sensitive':d.exposure_tier==='domestic_sensitive'}" x-text="exposureTierLabel(d.exposure_tier)"></span></td>
                                                    <td class="pr-3 font-medium" x-text="d.behavior_score"></td>
                                                    <td class="pr-3 text-xs text-gray-500" x-text="d.last_evaluated_at || '未评估'"></td>
                                                    <td class="pr-3 text-xs text-gray-500">
                                                        <div class="font-mono" x-text="d.last_ip || '-'"></div>
                                                        <div x-text="networkLabel(d.last_network_type)"></div>
                                                    </td>
                                                    <td class="pr-3 text-xs">
                                                        <span class="px-2 py-0.5 rounded-full" :class="deviceStatusClass(d.status)" x-text="deviceStatusLabel(d.status)"></span>
                                                    </td>
                                                    <td class="pr-3">
                                                        <button class="text-indigo-600 text-xs hover:underline mr-2" @click="showDeviceDetail(d)">详情</button>
                                                        <button class="text-blue-600 text-xs hover:underline mr-2" @click="showAdjustModal(d)">调级</button>
                                                        <template x-if="d.status===1">
                                                            <button class="text-orange-600 text-xs hover:underline mr-2" @click="forceUnbindDevice(d)">解绑</button>
                                                        </template>
                                                        <template x-if="d.status===0">
                                                            <button class="text-gray-500 text-xs hover:underline mr-2" @click="purgeSingleDevice(d)">清理</button>
                                                        </template>
                                                        <template x-if="d.trust_level!=='blacklisted'">
                                                            <button class="text-red-600 text-xs hover:underline" @click="quickBlacklist(d)">拉黑</button>
                                                        </template>
                                                        <template x-if="d.trust_level==='blacklisted'">
                                                            <button class="text-green-600 text-xs hover:underline" @click="quickUnblacklist(d)">解除</button>
                                                        </template>
                                                    </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
                <div class="flex justify-between items-center mt-4" x-show="deviceTotal>0">
                    <span class="text-sm text-gray-500">共 <span x-text="deviceTotal"></span> 个账号</span>
                    <div class="flex gap-2">
                        <button class="btn-back text-xs" @click="devicePage>1&&(devicePage--,loadDevices())" :disabled="devicePage<=1">上一页</button>
                        <span class="text-sm text-gray-500 leading-8" x-text="'第'+devicePage+'页'"></span>
                        <button class="btn-back text-xs" @click="devicePage++,loadDevices()">下一页</button>
                    </div>
                </div>
            </div>

            <!-- 调级弹窗 -->
            <div x-show="adjustModal" class="fixed inset-0 bg-black bg-opacity-30 flex items-center justify-center z-50" @click.self="adjustModal=false">
                <div class="bg-white rounded-lg p-6 w-96 shadow-xl">
                    <h3 class="text-lg font-bold mb-4">手动调整信任等级</h3>
                    <p class="text-sm text-gray-500 mb-3">设备: <span class="font-mono" x-text="adjustTarget.device_id"></span></p>
                    <div class="space-y-3">
                        <div>
                            <label class="label">信任等级</label>
                            <select class="input-field" x-model="adjustForm.trust_level">
                                <option value="blacklisted">封禁</option>
                                <option value="untrusted">信任 L1</option>
                                <option value="observe">信任 L2</option>
                                <option value="basic">信任 L3</option>
                                <option value="trusted">信任 L4</option>
                            </select>
                        </div>
                        <div>
                            <label class="label">暴露层</label>
                            <select class="input-field" x-model="adjustForm.exposure_tier">
                                <template x-for="o in tierOptions"><option :value="o.value" x-text="o.label"></option></template>
                            </select>
                        </div>
                        <div>
                            <label class="label">原因</label>
                            <input type="text" class="input-field" x-model="adjustForm.reason" placeholder="请填写调级原因">
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 mt-4">
                        <button class="btn-back" @click="adjustModal=false">取消</button>
                        <button class="btn-primary" @click="submitAdjust()" :disabled="!adjustForm.reason">确认</button>
                    </div>
                </div>
            </div>

            <!-- 设备详情弹窗 -->
            <div x-show="detailModal" class="fixed inset-0 bg-black bg-opacity-30 flex items-start justify-center z-50 overflow-y-auto" @click.self="detailModal=false" x-transition>
                <div class="bg-white rounded-lg shadow-xl w-full max-w-5xl mx-4 my-4">
                    <div class="sticky top-0 bg-white border-b px-4 py-3 flex items-center justify-between z-10 rounded-t-lg">
                        <h3 class="text-base font-bold">设备详情</h3>
                        <button class="text-gray-400 hover:text-gray-600 text-lg leading-none" @click="detailModal=false">&times;</button>
                    </div>
                    <div class="p-4 space-y-3" x-show="detailData" style="font-size:13px">

                        <!-- 设备信息 + 信任状态 并排 -->
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                            <div class="bg-gray-50 rounded-lg p-3">
                                <div class="font-bold text-sm mb-2 text-gray-700">设备信息</div>
                                <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                                    <div class="text-gray-400">用户</div><div class="font-medium truncate" x-text="detailData?.device?.user_email||'-'"></div>
                                    <div class="text-gray-400">设备ID</div><div class="font-mono truncate" x-text="detailData?.device?.device_id||'-'"></div>
                                    <div class="text-gray-400">平台 / 型号</div><div x-text="(detailData?.device?.platform||'-')+' / '+(detailData?.device?.device_model||'未上报')"></div>
                                    <div class="text-gray-400">OS / APP</div><div x-text="(detailData?.device?.os_version||'未上报')+' / '+(detailData?.device?.app_version||'-')"></div>
                                    <div class="text-gray-400">环境分类</div><div x-text="environmentLabel(detailData?.device?.environment_class)"></div>
                                    <div class="text-gray-400">展示 IP</div><div class="font-mono" x-text="detailData?.device?.last_ip||'-'"></div>
                                    <div class="text-gray-400">客户端真实 IP</div><div class="font-mono" x-text="detailData?.device?.last_client_ip||'-'"></div>
                                    <div class="text-gray-400">请求来源 IP</div><div class="font-mono" x-text="detailData?.device?.last_request_ip||'-'"></div>
                                    <div class="text-gray-400">IP 来源</div><div x-text="ipSourceLabel(detailData?.device?.last_ip_source)"></div>
                                    <div class="text-gray-400">网络</div><div x-text="(({'wifi':'Wi-Fi','cellular':'蜂窝','ethernet':'有线'})[detailData?.device?.first_network_type]||'未知')+' → '+(({'wifi':'Wi-Fi','cellular':'蜂窝','ethernet':'有线'})[detailData?.device?.last_network_type]||'未知')"></div>
                                    <div class="text-gray-400">活跃时间</div><div x-text="(detailData?.device?.first_active_at||detailData?.device?.created_at||'-')+' ~ '+(detailData?.device?.last_active_at||'-')"></div>
                                </div>
                            </div>
                            <div class="bg-gray-50 rounded-lg p-3">
                                <div class="font-bold text-sm mb-2 text-gray-700">信任状态</div>
                                <div class="text-xs text-gray-500 mb-2" x-text="({'account_history':'当前按账号全量历史评估','device_history':'当前按该设备全量历史评估'})[detailData?.evaluation_scope]||'当前按判断窗口内当前设备近期动作评估'"></div>
                                <div class="flex items-center gap-4 mb-2">
                                    <div class="text-center">
                                        <div class="text-gray-400 text-xs">等级</div>
                                        <span class="tier-badge mt-1" :class="trustBadgeClass(detailData?.device?.trust_level)" x-text="trustLevelLabel(detailData?.device?.trust_level)"></span>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-gray-400 text-xs">行为分</div>
                                        <div class="text-xl font-bold" :class="(detailData?.device?.behavior_score||0)>=80?'text-green-600':(detailData?.device?.behavior_score||0)>=50?'text-blue-600':(detailData?.device?.behavior_score||0)>=20?'text-yellow-600':'text-red-500'" x-text="detailData?.device?.behavior_score??0"></div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-gray-400 text-xs">暴露层</div>
                                        <span class="tier-badge mt-1" :class="{'tier-intl':detailData?.device?.exposure_tier==='intl_only','tier-public':detailData?.device?.exposure_tier==='public_intl','tier-limited':detailData?.device?.exposure_tier==='domestic_limited','tier-sensitive':detailData?.device?.exposure_tier==='domestic_sensitive'}" x-text="exposureTierLabel(detailData?.device?.exposure_tier)"></span>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-gray-400 text-xs">冷却期</div>
                                        <div class="text-xs mt-1" x-text="detailData?.device?.is_in_cooldown?('至 '+detailData?.device?.cooldown_until):'无'"></div>
                                    </div>
                                </div>
                                <template x-if="detailData?.trust_profile">
                                    <div class="text-xs text-gray-500 border-t pt-2 space-y-0.5">
                                        <div x-show="detailData.trust_profile.last_upgrade_reason">升级: <span class="font-mono" x-text="detailData.trust_profile.last_upgrade_reason"></span></div>
                                        <div x-show="detailData.trust_profile.last_downgrade_reason">降级: <span class="font-mono" x-text="detailData.trust_profile.last_downgrade_reason"></span></div>
                                        <div>评估: <span x-text="detailData.trust_profile.last_evaluated_at||'从未'"></span></div>
                                    </div>
                                </template>
                                <template x-if="detailData?.fast_check_blocked">
                                    <div class="mt-2 px-2 py-1 bg-red-50 border border-red-200 rounded text-xs text-red-600">快速判断已拦截，暴露层被锁定为仅国际</div>
                                </template>
                            </div>
                        </div>

                        <!-- 升级条件对比 -->
                        <div class="bg-gray-50 rounded-lg p-3">
                            <div class="font-bold text-sm mb-2 text-gray-700">升级条件达成</div>
                            <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
                                <template x-for="(tier, tierKey) in (detailData?.upgrade_status||{})" :key="tierKey">
                                    <div class="bg-white rounded p-2 border">
                                        <div class="flex items-center gap-1 mb-1">
                                            <span class="tier-badge" :class="{'tier-public':tierKey==='public_intl','tier-limited':tierKey==='domestic_limited','tier-sensitive':tierKey==='domestic_sensitive'}" x-text="exposureTierLabel(tierKey)" style="font-size:11px"></span>
                                            <span class="text-xs" :class="tier.eligible?'text-green-600 font-bold':'text-gray-400'" x-text="tier.eligible?'✓ 达标':'✗ 未达标'"></span>
                                            <template x-if="tier.min_conditions"><span class="text-xs text-gray-400" x-text="tier.met_count+'/'+tier.min_conditions"></span></template>
                                        </div>
                                        <table class="w-full text-xs">
                                            <tbody>
                                                <template x-for="c in tier.conditions" :key="c.name">
                                                    <tr>
                                                        <td class="py-0.5 pr-1 text-gray-500" x-text="c.label"></td>
                                                        <td class="py-0.5 text-right font-mono" :class="c.met?'text-green-600':'text-red-500'" x-text="c.current+' / '+c.required"></td>
                                                        <td class="py-0.5 pl-1 w-4" x-text="c.met?'✓':'✗'" :class="c.met?'text-green-600':'text-red-500'"></td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- 行为日聚合 + 最近会话 -->
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                            <div class="bg-gray-50 rounded-lg p-3">
                                <div class="font-bold text-sm mb-2 text-gray-700">行为日聚合 (14天)</div>
                                <div class="overflow-x-auto" style="max-height:240px;overflow-y:auto">
                                    <table class="w-full text-xs">
                                        <thead class="sticky top-0 bg-gray-50"><tr class="text-gray-400 border-b">
                                            <th class="pb-1 pr-1 text-left">日期</th><th class="pb-1 pr-1 text-center">活跃</th><th class="pb-1 pr-1 text-right">会话</th>
                                            <th class="pb-1 pr-1 text-right">时长(分)</th><th class="pb-1 pr-1 text-right">↓MB</th><th class="pb-1 pr-1 text-right">↑MB</th>
                                            <th class="pb-1 text-right">站点</th>
                                        </tr></thead>
                                        <tbody>
                                            <template x-for="a in (detailData?.behavior_daily||[])" :key="a.date">
                                                <tr class="border-b border-gray-100">
                                                    <td class="py-0.5 pr-1" x-text="a.date.substring(5)"></td>
                                                    <td class="py-0.5 pr-1 text-center" x-text="a.active_flag?'●':'○'" :class="a.active_flag?'text-green-500':'text-gray-300'"></td>
                                                    <td class="py-0.5 pr-1 text-right" x-text="a.stable_session_count"></td>
                                                    <td class="py-0.5 pr-1 text-right" x-text="a.stable_connected_minutes"></td>
                                                    <td class="py-0.5 pr-1 text-right" x-text="(a.effective_bytes_down/1048576).toFixed(1)"></td>
                                                    <td class="py-0.5 pr-1 text-right" x-text="(a.effective_bytes_up/1048576).toFixed(1)"></td>
                                                    <td class="py-0.5 text-right" x-text="a.distinct_destinations_daily||0"></td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                                <p x-show="!detailData?.behavior_daily?.length" class="text-xs text-gray-400 mt-1">暂无数据</p>
                            </div>
                            <div class="bg-gray-50 rounded-lg p-3">
                                <div class="font-bold text-sm mb-2 text-gray-700">最近会话 (20条)</div>
                                <div class="overflow-x-auto" style="max-height:240px;overflow-y:auto">
                                    <table class="w-full text-xs">
                                        <thead class="sticky top-0 bg-gray-50"><tr class="text-gray-400 border-b">
                                            <th class="pb-1 pr-1 text-left">类型</th><th class="pb-1 pr-1 text-left">网络</th>
                                            <th class="pb-1 pr-1 text-right">时长(秒)</th><th class="pb-1 pr-1 text-right">↓MB</th><th class="pb-1 pr-1 text-right">↑MB</th>
                                            <th class="pb-1 pr-1 text-left">开始</th>
                                        </tr></thead>
                                        <tbody>
                                            <template x-for="s in (detailData?.recent_sessions||[])" :key="s.session_id">
                                                <tr class="border-b border-gray-100" :class="s.telemetry_suspicious?'bg-red-50':''">
                                                    <td class="py-0.5 pr-1"><span class="px-1 rounded" :class="s.session_type==='stable_use_session'?'bg-green-100 text-green-700':s.session_type==='probe_only'?'bg-yellow-100 text-yellow-700':'bg-gray-100 text-gray-600'" x-text="s.session_type==='stable_use_session'?'稳定':s.session_type==='probe_only'?'探测':'其他'"></span></td>
                                                    <td class="py-0.5 pr-1" x-text="({'wifi':'WiFi','cellular':'蜂窝','ethernet':'有线'})[s.network_type]||'-'"></td>
                                                    <td class="py-0.5 pr-1 text-right font-mono" x-text="s.effective_connected_seconds"></td>
                                                    <td class="py-0.5 pr-1 text-right" x-text="(s.effective_bytes_down/1048576).toFixed(1)"></td>
                                                    <td class="py-0.5 pr-1 text-right" x-text="(s.effective_bytes_up/1048576).toFixed(1)"></td>
                                                    <td class="py-0.5 pr-1" x-text="(s.started_at||'').substring(5)"></td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                                <p x-show="!detailData?.recent_sessions?.length" class="text-xs text-gray-400 mt-1">暂无记录</p>
                            </div>
                        </div>

                        <!-- 危险操作 -->
                        <div class="border-t pt-3 flex flex-wrap items-center gap-3" x-show="detailData">
                            <div class="text-xs text-gray-500">
                                该账号已解绑设备：
                                <span class="font-mono" x-text="detailData?.account_unbound_count||0"></span>
                                <span class="text-gray-400">(SR)</span>
                                /
                                <span class="font-mono" x-text="detailData?.account_user_device_unbound_count||0"></span>
                                <span class="text-gray-400">(UserDevice)</span>
                            </div>
                            <div class="ml-auto flex gap-2">
                                <template x-if="detailData?.device?.status===1">
                                    <button class="px-3 py-1 rounded border border-orange-300 text-orange-600 text-xs hover:bg-orange-50" @click="forceUnbindDevice(detailData.device)">管理员解绑</button>
                                </template>
                                <template x-if="detailData?.device?.status===0">
                                    <button class="px-3 py-1 rounded border border-gray-300 text-gray-600 text-xs hover:bg-gray-50" @click="purgeSingleDevice(detailData.device)">清理该设备</button>
                                </template>
                                <button class="px-3 py-1 rounded border border-orange-300 text-orange-600 text-xs hover:bg-orange-50 disabled:opacity-50"
                                    :disabled="(detailData?.account_unbound_count||0)===0 && (detailData?.account_user_device_unbound_count||0)===0"
                                    @click="purgeAccountUnbound({user_id: detailData.device.user_id, user_email: detailData.device.user_email})">
                                    清理该账号已解绑数据
                                </button>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <!-- ========== 审计日志 ========== -->
        <div x-show="tab==='audit'" class="mt-6">
            <div class="card">
                <div class="flex items-center justify-between mb-4">
                    <div class="section-title mb-0 border-0 pb-0">审计日志</div>
                    <div class="flex gap-2 flex-wrap items-center">
                        <input type="text" class="input-field" style="width:200px" placeholder="邮箱 / 用户ID / 设备ID"
                               x-model="auditFilterSearch" @keydown.enter="auditPage=1,loadAuditLogs()">
                        <input type="date" class="input-field" style="width:150px" x-model="auditFilterStart" @change="auditPage=1,loadAuditLogs()">
                        <span class="text-gray-400 text-sm">~</span>
                        <input type="date" class="input-field" style="width:150px" x-model="auditFilterEnd" @change="auditPage=1,loadAuditLogs()">
                        <select class="input-field" style="width:180px" x-model="auditFilterAction" @change="auditPage=1,loadAuditLogs()">
                            <option value="">全部操作</option>
                            <option value="trust.level_changed">信任等级变更</option>
                            <option value="trust.auto_downgrade">自动降级</option>
                            <option value="trust.admin_adjust">管理员调级</option>
                            <option value="trust.blacklisted">标记黑名单</option>
                            <option value="trust.unblacklist">解除黑名单</option>
                            <option value="device.register">设备注册</option>
                            <option value="device.user_unbind">用户解绑设备</option>
                            <option value="device.force_unbind">管理员解绑设备</option>
                            <option value="device.reset_account">重置账号设备登录</option>
                            <option value="device.purge_unbound">清理已解绑设备</option>
                            <option value="device.bulk_purge">批量清理设备</option>
                            <option value="audit.purge_logs">清理审计日志</option>
                        </select>
                        <button class="btn-primary" @click="auditPage=1,loadAuditLogs()">查询</button>
                        <button class="btn-back text-xs" @click="resetAuditFilter()">重置</button>
                        <button class="text-xs px-3 py-1 rounded border border-red-300 text-red-600 hover:bg-red-50" @click="openAuditPurge()">清理日志</button>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-gray-500 border-b">
                            <th class="pb-2 pr-3">时间</th><th class="pb-2 pr-3">操作</th><th class="pb-2 pr-3">操作者</th>
                            <th class="pb-2 pr-3">目标</th><th class="pb-2 pr-3">变更前</th><th class="pb-2 pr-3">变更后</th>
                            <th class="pb-2">原因</th>
                        </tr></thead>
                        <tbody>
                            <template x-for="l in auditLogs" :key="l.id">
                                <tr class="border-b border-gray-50">
                                    <td class="py-2 pr-3 text-xs text-gray-500" x-text="l.created_at"></td>
                                    <td class="pr-3">
                                        <div class="text-xs font-medium px-2 py-0.5 rounded bg-gray-100 inline-block" x-text="l.action_label||l.action||'-'"></div>
                                        <div class="text-[11px] text-gray-400 mt-1" x-text="l.action"></div>
                                    </td>
                                    <td class="pr-3 text-xs" x-text="l.operator_label||((l.operator_type||'')+(l.operator_id?'#'+l.operator_id:''))"></td>
                                    <td class="pr-3 text-xs" x-text="l.target_label||((l.target_type||'')+' #'+(l.target_id||''))"></td>
                                    <td class="pr-3 text-xs text-gray-700" x-text="l.before_label||'-'"></td>
                                    <td class="pr-3 text-xs text-gray-700" x-text="l.after_label||'-'"></td>
                                    <td class="text-xs text-gray-500" x-text="l.reason_label||l.reason||'-'"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div class="flex justify-between items-center mt-4" x-show="auditTotal>0">
                    <span class="text-sm text-gray-500">共 <span x-text="auditTotal"></span> 条</span>
                    <div class="flex gap-2">
                        <button class="btn-back text-xs" @click="auditPage>1&&(auditPage--,loadAuditLogs())" :disabled="auditPage<=1">上一页</button>
                        <span class="text-sm text-gray-500 leading-8" x-text="'第'+auditPage+'页'"></span>
                        <button class="btn-back text-xs" @click="auditPage++,loadAuditLogs()">下一页</button>
                    </div>
                </div>
            </div>

            <!-- 清理日志弹窗 -->
            <div x-show="auditPurgeModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @click.self="auditPurgeModal=false">
                <div class="bg-white rounded-xl shadow-xl w-full max-w-lg p-6">
                    <div class="text-lg font-semibold mb-1">清理审计日志</div>
                    <p class="text-xs text-gray-500 mb-4">物理删除符合条件的日志，不可恢复。建议清理刷屏的“注册设备”日志，保留解绑等关键记录。</p>

                    <div class="mb-3">
                        <div class="text-sm text-gray-700 mb-1">清理的操作类型</div>
                        <div class="flex flex-wrap gap-3 text-sm">
                            <label class="inline-flex items-center gap-1"><input type="checkbox" value="device.register" x-model="auditPurgeActions"> 注册设备</label>
                            <label class="inline-flex items-center gap-1"><input type="checkbox" value="trust.auto_downgrade" x-model="auditPurgeActions"> 自动降级</label>
                            <label class="inline-flex items-center gap-1"><input type="checkbox" value="trust.level_changed" x-model="auditPurgeActions"> 信任等级变更</label>
                        </div>
                        <div class="text-[11px] text-gray-400 mt-1">不勾选任何类型 = 不限操作类型（此时必须配合日期/保留天数限定范围）。</div>
                    </div>

                    <div class="mb-3 bg-gray-50 rounded p-2">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" x-model="auditPurgeKeepUnbind">
                            保留所有解绑日志（用户解绑 / 管理员解绑）
                        </label>
                    </div>

                    <div class="mb-4">
                        <div class="text-sm text-gray-700 mb-1">仅清理 N 天前的日志（可选）</div>
                        <input type="number" min="0" class="input-field" style="width:120px" placeholder="如 7" x-model="auditPurgeBeforeDays">
                        <span class="text-[11px] text-gray-400 ml-2">留空表示不按时间限制；填 7 表示只删 7 天前的。</span>
                    </div>

                    <div class="flex justify-end gap-2">
                        <button class="btn-back text-sm" @click="auditPurgeModal=false">取消</button>
                        <button class="text-sm px-4 py-1.5 rounded bg-red-600 text-white hover:bg-red-700" :disabled="auditPurging" @click="confirmAuditPurge()">
                            <span x-show="!auditPurging">预览并清理</span>
                            <span x-show="auditPurging">处理中…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========== 入口映射 ========== -->
        <div x-show="tab==='ingress'" class="mt-6">
            <div class="card">
                <div class="section-title">全局入口地址配置（可选兜底）</div>
                <p class="text-sm text-gray-500 mb-4">入口改写优先使用下方“单节点入口映射”。未配置单节点映射时，默认保留 V2Board 节点原始地址；只有开启全局兜底后，才会按暴露层使用这里的统一入口。端口留空或为 0 时继续使用节点原始端口。</p>
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                        <div>
                            <div class="text-sm font-medium text-yellow-800">全局入口兜底</div>
                            <div class="text-xs text-yellow-700 mt-1">关闭时：未配置单节点映射的节点不会被统一替换，继续使用 V2Board 原始 host/port。</div>
                        </div>
                        <select class="input-field" style="width:220px" x-model.number="cfg.global_ingress.fallback_enabled">
                            <option value="0">关闭全局兜底（推荐）</option>
                            <option value="1">开启全局兜底</option>
                        </select>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-gray-500 border-b">
                            <th class="pb-2 pr-4" style="width:200px">暴露层</th>
                            <th class="pb-2 pr-4">入口地址 (host)</th>
                            <th class="pb-2 pr-4" style="width:120px">端口 (留空用原始)</th>
                            <th class="pb-2" style="width:80px">说明</th>
                        </tr></thead>
                        <tbody>
                            <tr class="border-b border-gray-50">
                                <td class="py-3 pr-4"><span class="tier-badge tier-intl" x-text="exposureTierShort('intl_only')"></span></td>
                                <td class="pr-4"><input type="text" class="input-field" placeholder="留空则使用 V2Board 原始地址" x-model="cfg.global_ingress.intl_only_host"></td>
                                <td class="pr-4"><input type="number" class="input-field" placeholder="留空" x-model.number="cfg.global_ingress.intl_only_port"></td>
                                <td class="text-xs text-gray-500">仅国际入口</td>
                            </tr>
                            <tr class="border-b border-gray-50">
                                <td class="py-3 pr-4"><span class="tier-badge tier-public" x-text="exposureTierShort('public_intl')"></span></td>
                                <td class="pr-4"><input type="text" class="input-field" placeholder="如 pub-gw.example.com" x-model="cfg.global_ingress.public_intl_host"></td>
                                <td class="pr-4"><input type="number" class="input-field" placeholder="留空" x-model.number="cfg.global_ingress.public_intl_port"></td>
                                <td class="text-xs text-gray-500" x-text="exposureTierLabel('public_intl') + '入口'"></td>
                            </tr>
                            <tr class="border-b border-gray-50">
                                <td class="py-3 pr-4"><span class="tier-badge tier-limited" x-text="exposureTierShort('domestic_limited')"></span></td>
                                <td class="pr-4"><input type="text" class="input-field" placeholder="如 cn-gw.example.com" x-model="cfg.global_ingress.domestic_limited_host"></td>
                                <td class="pr-4"><input type="number" class="input-field" placeholder="留空" x-model.number="cfg.global_ingress.domestic_limited_port"></td>
                                <td class="text-xs text-gray-500" x-text="exposureTierLabel('domestic_limited') + '入口'"></td>
                            </tr>
                            <tr>
                                <td class="py-3 pr-4"><span class="tier-badge tier-sensitive" x-text="exposureTierShort('domestic_sensitive')"></span></td>
                                <td class="pr-4"><input type="text" class="input-field" placeholder="如 iepl-gw.example.com" x-model="cfg.global_ingress.domestic_sensitive_host"></td>
                                <td class="pr-4"><input type="number" class="input-field" placeholder="留空" x-model.number="cfg.global_ingress.domestic_sensitive_port"></td>
                                <td class="text-xs text-gray-500" x-text="exposureTierLabel('domestic_sensitive') + '入口'"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-sm text-gray-500 mt-4">示例：如果“香港 01”在 L2 单节点映射里填写 aws-hk.example.com，则只有该节点、且仅 L2 暴露层会改成这个入口；其他节点或其他层级不受影响。全局兜底只处理没有单节点映射的节点。</p>
            </div>
            <div class="flex justify-end mb-6"><button class="btn-primary" @click="save('global_ingress')" :disabled="saving">保存全局兜底配置</button></div>

            <div class="card" id="ingressPoolCard">
                <div class="section-title">入口池</div>
                <p class="text-sm text-gray-500 mb-4">集中维护可复用的入口（IP/域名）。在下方“单节点入口映射”里直接下拉选择，不必反复手填 IP。改这里的入口不会自动改已配置的节点；需要时在映射处重新选择。</p>
                <div class="grid grid-cols-1 md:grid-cols-12 gap-2 mb-3 items-end">
                    <div class="md:col-span-3"><label class="label">名称</label><input type="text" class="input-field" placeholder="如 成都BGP1G" x-model="poolForm.name"></div>
                    <div class="md:col-span-3"><label class="label">入口 host (IP/域名)</label><input type="text" class="input-field" placeholder="112.19.8.21" x-model="poolForm.host"></div>
                    <div class="md:col-span-2"><label class="label">服务端口(下发)</label><input type="number" class="input-field" placeholder="留空保留原始" x-model.number="poolForm.port"></div>
                    <div class="md:col-span-2 flex gap-2">
                        <button class="btn-primary flex-1" @click="savePool()" :disabled="saving" x-text="poolForm.id ? '更新' : '新增'"></button>
                        <button class="btn-back" x-show="poolForm.id" @click="resetPoolForm()">取消</button>
                    </div>
                    <div class="md:col-span-4"><label class="label">备注</label><input type="text" class="input-field" placeholder="如 抗通报" x-model="poolForm.remark"></div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-gray-500 border-b">
                            <th class="pb-2 pr-4">名称</th>
                            <th class="pb-2 pr-4">入口 host</th>
                            <th class="pb-2 pr-4" style="width:90px">服务端口</th>
                            <th class="pb-2 pr-4">备注</th>
                            <th class="pb-2" style="width:130px">操作</th>
                        </tr></thead>
                        <tbody>
                            <template x-for="p in poolList" :key="p.id">
                                <tr class="border-b border-gray-50">
                                    <td class="py-2 pr-4 font-medium" x-text="p.name"></td>
                                    <td class="pr-4 font-mono text-xs" x-text="p.host"></td>
                                    <td class="pr-4 font-mono text-xs" x-text="p.port || '原始'"></td>
                                    <td class="pr-4 text-gray-500" x-text="p.remark || '-'"></td>
                                    <td class="py-2">
                                        <button class="text-indigo-600 text-xs mr-3" @click="editPool(p)">编辑</button>
                                        <button class="text-red-500 text-xs" @click="deletePool(p)">删除</button>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="poolList.length===0"><td colspan="6" class="py-4 text-center text-gray-400">暂无入口，先在上方新增</td></tr>
                        </tbody>
                    </table>
                </div>


            </div>

            <div class="card">
                <div class="section-title">单节点入口映射（L1/L2/L3/L4）</div>
                <p class="text-sm text-gray-500 mb-4">选择一个 V2Board 节点后，可分别配置它在不同暴露层下发给客户端的入口 host/port。host 为空表示清除此层单节点映射并使用默认逻辑；port 为空表示保留该节点原始端口。</p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div>
                        <label class="label">协议类型</label>
                        <select class="input-field" x-model="ingressFilterType" @change="selectFirstFilteredServer()">
                            <option value="">全部协议</option>
                            <template x-for="type in ingressServerTypes" :key="type">
                                <option :value="type" x-text="serverTypeLabel(type)"></option>
                            </template>
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="label">选择节点</label>
                        <select class="input-field" x-model="selectedIngressServerKey" @change="loadSelectedIngressMappings()">
                            <option value="">请选择节点</option>
                            <template x-for="server in filteredIngressServers()" :key="serverKey(server)">
                                <option :value="serverKey(server)" x-text="serverOptionLabel(server)"></option>
                            </template>
                        </select>
                        <div class="hint" x-show="ingressServers.length===0">未加载到可显示节点，请确认 V2Board 节点 show=1。</div>
                    </div>
                </div>

                <div x-show="selectedIngressServer" class="bg-gray-50 rounded-lg p-4 mb-4 text-sm">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                        <div><span class="text-gray-500">节点：</span><span class="font-medium" x-text="selectedIngressServer?.name || '-' "></span></div>
                        <div><span class="text-gray-500">协议：</span><span class="font-mono" x-text="serverTypeLabel(selectedIngressServer?.server_type)"></span></div>
                        <div><span class="text-gray-500">原始入口：</span><span class="font-mono" x-text="formatIngress(selectedIngressServer?.host, selectedIngressServer?.port)"></span></div>
                        <div><span class="text-gray-500">映射数：</span><span x-text="selectedIngressMappingCount() + ' / ' + tierOptions.length"></span></div>
                    </div>
                </div>

                <div class="overflow-x-auto" x-show="selectedIngressServer">
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-gray-500 border-b">
                            <th class="pb-2 pr-4" style="width:160px">暴露层</th>
                            <th class="pb-2 pr-4">入口池（多健康IP下发）</th>
                            <th class="pb-2 pr-4">或手填单 host</th>
                            <th class="pb-2 pr-4" style="width:120px">端口</th>
                            <th class="pb-2 pr-4">备注</th>
                            <th class="pb-2" style="width:110px">当前状态</th>
                        </tr></thead>
                        <tbody>
                            <template x-for="tier in tierOptions" :key="tier.value">
                                <tr class="border-b border-gray-50">
                                    <td class="py-3 pr-4"><span class="tier-badge" :class="tierBadgeClass(tier.value)" x-text="exposureTierShort(tier.value) + ' · ' + tier.label"></span></td>
                                    <td class="pr-4">
                                        <select class="input-field" x-model.number="ingressForm[tier.value].pool_id" @change="onPoolSelect(tier.value)">
                                            <option :value="null">— 不使用入口池 —</option>
                                            <template x-for="p in poolList" :key="p.id">
                                                <option :value="p.id" x-text="p.name + ' (' + p.host + (p.port? ':'+p.port : '') + ')'"></option>
                                            </template>
                                        </select>
                                    </td>
                                    <td class="pr-4">
                                        <input type="text" class="input-field" placeholder="留空=不用单host" x-model="ingressForm[tier.value].ingress_host" :disabled="!!ingressForm[tier.value].pool_id">
                                    </td>
                                    <td class="pr-4"><input type="number" class="input-field" placeholder="留空用原始端口" x-model.number="ingressForm[tier.value].ingress_port"></td>
                                    <td class="pr-4"><input type="text" class="input-field" placeholder="如 AWS HK / Azure HK" x-model="ingressForm[tier.value].remark"></td>
                                    <td class="text-xs" :class="(ingressForm[tier.value].pool_id || ingressForm[tier.value].ingress_host) ? 'text-green-600' : 'text-gray-400'" x-text="ingressForm[tier.value].pool_id ? '入口池' : (ingressForm[tier.value].ingress_host ? '单host' : '默认逻辑')"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-col md:flex-row md:justify-between md:items-center gap-3 mt-4">
                    <div class="text-xs text-gray-500">保存时，host 为空的层级会删除已有映射；port 为空不会覆盖原始端口。</div>
                    <div class="flex gap-2 justify-end">
                        <button class="btn-back" @click="clearIngressForm()" :disabled="!selectedIngressServer">清空当前表单</button>
                        <button class="btn-primary" @click="saveIngressMappings()" :disabled="saving || !selectedIngressServer">保存单节点映射</button>
                    </div>
                </div>
            </div>

        </div>

        <!-- ========== 自定义规则（Provider Package）========== -->
        <div x-show="tab==='rules'" class="mt-6">
            <div class="card">
                <div class="section-title">自定义规则（Provider Package）</div>
                <p class="text-sm text-gray-500 mb-4">按暴露层维护下发给 APP 的规则内容（mihomo proxy-provider / rules YAML）。保存后 APP 通过既有的 manifest/resolve + provider/fetch 链路拉取；同一暴露层可存多条，但仅 <b>启用中且 ID 最小</b> 的那条会真正生效（标记为「生效中」）。</p>
                <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-start">
                    <div class="md:col-span-4">
                        <label class="label">名称</label>
                        <input type="text" class="input-field" placeholder="如 高敏国内-默认规则" x-model="pkgForm.name">
                    </div>
                    <div class="md:col-span-4">
                        <label class="label">暴露层 (exposure_tier)</label>
                        <select class="input-field" x-model="pkgForm.exposure_tier">
                            <template x-for="t in tierOptions" :key="t.value">
                                <option :value="t.value" x-text="t.label + ' (' + t.value + ')'"></option>
                            </template>
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="label">Provider 类型</label>
                        <input type="text" class="input-field" placeholder="mihomo_proxy_provider" x-model="pkgForm.provider_type">
                    </div>
                    <div class="md:col-span-2">
                        <label class="label">状态</label>
                        <select class="input-field" x-model.number="pkgForm.enabled">
                            <option :value="1">启用</option>
                            <option :value="0">停用</option>
                        </select>
                    </div>
                    <div class="md:col-span-12">
                        <label class="label">规则内容 (payload)</label>
                        <textarea class="input-field font-mono" style="min-height:280px;resize:vertical" placeholder="在此粘贴 mihomo rules / proxy-provider YAML 内容" x-model="pkgForm.payload"></textarea>
                        <p class="hint">原文按配置的编码方式（gzip+base64 等）压缩后下发，SHA256 由后端自动计算。</p>
                    </div>
                    <div class="md:col-span-12 flex gap-2">
                        <button class="btn-primary" @click="savePackage()" :disabled="pkgSaving" x-text="pkgForm.id ? '更新规则' : '新增规则'"></button>
                        <button class="btn-back" x-show="pkgForm.id" @click="resetPkgForm()">取消编辑</button>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="flex items-center justify-between mb-4">
                    <div class="section-title" style="margin-bottom:0;border:none;padding:0">规则列表</div>
                    <div class="flex items-center gap-2">
                        <select class="input-field" style="width:200px" x-model="pkgFilterTier" @change="loadPackages()">
                            <option value="">全部暴露层</option>
                            <template x-for="t in tierOptions" :key="t.value">
                                <option :value="t.value" x-text="t.label"></option>
                            </template>
                        </select>
                        <button class="btn-back text-xs" @click="loadPackages()">刷新</button>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-gray-500 border-b">
                            <th class="pb-2 pr-4" style="width:60px">ID</th>
                            <th class="pb-2 pr-4">名称</th>
                            <th class="pb-2 pr-4" style="width:150px">暴露层</th>
                            <th class="pb-2 pr-4" style="width:90px">大小</th>
                            <th class="pb-2 pr-4" style="width:170px">版本</th>
                            <th class="pb-2 pr-4" style="width:90px">状态</th>
                            <th class="pb-2 pr-4" style="width:160px">更新时间</th>
                            <th class="pb-2" style="width:120px">操作</th>
                        </tr></thead>
                        <tbody>
                            <template x-for="p in pkgList" :key="p.id">
                                <tr class="border-b border-gray-50">
                                    <td class="py-2 pr-4 font-mono text-xs" x-text="p.id"></td>
                                    <td class="pr-4 font-medium">
                                        <span x-text="p.name"></span>
                                        <span x-show="p.is_effective" class="ml-2 text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-700">生效中</span>
                                    </td>
                                    <td class="pr-4"><span class="tier-badge" :class="tierBadgeClass(p.exposure_tier)" x-text="exposureTierShort(p.exposure_tier)"></span></td>
                                    <td class="pr-4 font-mono text-xs" x-text="formatBytes(p.payload_bytes)"></td>
                                    <td class="pr-4 font-mono text-xs text-gray-500" x-text="p.version || '-'"></td>
                                    <td class="pr-4">
                                        <span class="text-xs px-2 py-0.5 rounded" :class="Number(p.enabled)===1?'bg-green-100 text-green-700':'bg-gray-100 text-gray-500'" x-text="Number(p.enabled)===1?'启用':'停用'"></span>
                                    </td>
                                    <td class="pr-4 text-xs text-gray-500" x-text="p.updated_at || '-'"></td>
                                    <td class="py-2">
                                        <button class="text-indigo-600 text-xs mr-3" @click="editPackage(p)">编辑</button>
                                        <button class="text-red-500 text-xs" @click="deletePackage(p)">删除</button>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="pkgList.length===0"><td colspan="8" class="py-4 text-center text-gray-400">暂无规则，先在上方新增</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div><!-- end max-w-7xl -->
</div><!-- end x-data -->

<script>
function smartRoute() {
    return {
        tab: 'overview',
        loaded: false,
        saving: false,
        securePath: '{{$secure_path}}',
        toast: { show: false, msg: '', type: 'success' },
        overview: {
            total_devices: 0, active_devices_24h: 0,
            tier_distribution: { intl_only: 0, public_intl: 0, domestic_limited: 0, domestic_sensitive: 0 },
            trust_distribution: { blacklisted: 0, untrusted: 0, observe: 0, basic: 0, trusted: 0 }
        },
        runtime: {
            manifest: { requests_1m: 0, requests_5m: 0, cache_hits_5m: 0, cache_misses_5m: 0, cache_hit_rate_5m: 0, avg_latency_ms_5m: 0, ttl_seconds: 120 },
            ingress: { fallback_enabled: 0, intl_only_host: '', intl_only_port: 0, public_intl_host: '', public_intl_port: 0, domestic_limited_host: '', domestic_limited_port: 0, domestic_sensitive_host: '', domestic_sensitive_port: 0, manifest_generation: 1, updated_at: null },
            device_touch: { written_5m: 0, skipped_5m: 0, skip_rate_5m: 0 },
            telemetry_queue: { queue: 'smart_route_telemetry', pending_batches: 0, enqueued_events_1m: 0, processed_events_1m: 0, failed_batches_1h: 0, avg_delay_ms_5m: 0, last_processed_at: null },
            series: { minutes: [], manifest_requests: [], telemetry_enqueued: [], telemetry_processed: [], telemetry_failed: [] }
        },
        cfg: {
            environment: {},
            exposure: {},
            cellular_bypass: {},
            evaluation: {},
            fast_check: {},
            upgrade_public_intl: {},
            upgrade_domestic_limited: {},
            upgrade_domestic_sensitive: {},
            downgrade: {},
            first_open: {},
            provider: {},
            device: {},
            security: {},
            telemetry: {},
            performance: {},
            global_ingress: { fallback_enabled: 0, intl_only_host: '', intl_only_port: 0, public_intl_host: '', public_intl_port: 0, domestic_limited_host: '', domestic_limited_port: 0, domestic_sensitive_host: '', domestic_sensitive_port: 0 }
        },
        tabs: [
            { key: 'overview', label: '概览' },
            { key: 'runtime', label: '运行状态' },
            { key: 'environment', label: '环境信任分层' },
            { key: 'upgrade', label: '升级阈值' },
            { key: 'downgrade', label: '降级策略' },
            { key: 'first_open', label: '首开检测' },
            { key: 'provider', label: 'Provider 安全' },
            { key: 'device', label: '设备管理' },
            { key: 'security', label: '安全基线' },
            { key: 'telemetry', label: '遥测与行为' },
            { key: 'devices', label: '设备管理' },
            { key: 'audit', label: '审计日志' },
            { key: 'ingress', label: '入口映射' },
            { key: 'rules', label: '自定义规则' }
        ],
        tierOptions: [
            { value: 'intl_only', label: '仅国际' },
            { value: 'public_intl', label: '国际+公共' },
            { value: 'domestic_limited', label: '有限国内' },
            { value: 'domestic_sensitive', label: '高敏感国内' }
        ],

        // 设备管理
        deviceList: [],
        deviceTotal: 0,
        devicePage: 1,
        deviceSearch: '',
        deviceShowUnbound: false,
        deviceFilterTier: '',
        deviceFilterTrust: '',
        expandedAccounts: {},
        adjustModal: false,
        adjustTarget: {},
        adjustForm: { trust_level: 'untrusted', exposure_tier: 'intl_only', reason: '' },
        detailModal: false,
        detailData: null,
        detailLoading: false,

        // 审计日志
        auditLogs: [],
        auditTotal: 0,
        auditPage: 1,
        auditFilterAction: '',
        auditFilterSearch: '',
        auditFilterStart: '',
        auditFilterEnd: '',
        // 审计日志清理
        auditPurgeModal: false,
        auditPurging: false,
        auditPurgeActions: [],
        auditPurgeKeepUnbind: true,
        auditPurgeBeforeDays: '',
        // 单节点入口映射
        ingressServers: [],
        // 入口池
        poolList: [],
        poolForm: { id: null, name: '', host: '', port: null, remark: '' },
        ingressFilterType: '',
        selectedIngressServerKey: '',
        // 自定义规则（Provider Package）
        pkgList: [],
        pkgFilterTier: '',
        pkgForm: { id: null, name: '', exposure_tier: 'intl_only', provider_type: 'mihomo_proxy_provider', payload: '', enabled: 1 },
        pkgSaving: false,
        ingressForm: {
            intl_only: { exposure_tier: 'intl_only', ingress_host: '', ingress_port: null, pool_id: null, remark: '' },
            public_intl: { exposure_tier: 'public_intl', ingress_host: '', ingress_port: null, pool_id: null, remark: '' },
            domestic_limited: { exposure_tier: 'domestic_limited', ingress_host: '', ingress_port: null, pool_id: null, remark: '' },
            domestic_sensitive: { exposure_tier: 'domestic_sensitive', ingress_host: '', ingress_port: null, pool_id: null, remark: '' }
        },

        getAuthData() {
            let auth = localStorage.getItem('authorization') || localStorage.getItem('auth_data') || '';
            if (!auth) {
                const cookies = document.cookie.split(';');
                for (const c of cookies) {
                    const [k, v] = c.trim().split('=');
                    if (k === 'authorization' || k === 'auth_data') { auth = decodeURIComponent(v); break; }
                }
            }
            return auth;
        },

        apiBase() {
            return '/api/v1/' + this.securePath;
        },

        async apiFetch(path, method, body) {
            const auth = this.getAuthData();
            const opts = {
                method: method || 'GET',
                headers: { 'Content-Type': 'application/json' }
            };
            if (auth) opts.headers['authorization'] = auth;
            if (body) opts.body = JSON.stringify(body);
            const res = await fetch(this.apiBase() + path, opts);
            if (!res.ok) {
                const err = await res.json().catch(() => ({}));
                const e = new Error(err.message || 'HTTP ' + res.status);
                e.status = res.status;
                e.data = err.data || null;
                throw e;
            }
            return res.json();
        },

        async openHorizon() {
            try {
                const res = await this.apiFetch('/smart-route/horizon/grant', 'POST', {});
                const redirect = res && res.data && res.data.redirect;
                if (!redirect) throw new Error('服务端未返回跳转地址');
                // 在新标签页打开，避免当前页状态丢失
                window.open(redirect, '_blank');
            } catch (e) {
                this.showToast('无法进入队列监控：' + (e.message || e), 'error');
            }
        },

        async init() {
            try {
                const [cfgRes, ovRes] = await Promise.all([
                    this.apiFetch('/smart-route/fetch'),
                    this.apiFetch('/smart-route/overview')
                ]);
                if (cfgRes.data) {
                    for (const section in cfgRes.data) {
                        if (this.cfg[section] !== undefined) {
                            this.cfg[section] = cfgRes.data[section];
                        }
                    }
                }
                if (ovRes.data) this.overview = ovRes.data;
                this.loaded = true;
                this.loadRuntimeStatus();
                this.loadDevices();
                this.loadAuditLogs();
                this.loadIngressServers();
                this.loadPools();
                this.loadPackages();
            } catch (e) {
                this.showToast('加载配置失败: ' + e.message, 'error');
            }
        },

        async save(...sections) {
            this.saving = true;
            try {
                const body = {};
                for (const s of sections) {
                    if (this.cfg[s]) body[s] = this.cfg[s];
                }
                await this.apiFetch('/smart-route/save', 'POST', body);
                if (sections.includes('global_ingress')) this.loadRuntimeStatus();
                this.showToast('保存成功');
            } catch (e) {
                this.showToast('保存失败: ' + e.message, 'error');
            } finally {
                this.saving = false;
            }
        },

        showToast(msg, type) {
            this.toast = { show: true, msg, type: type || 'success' };
            setTimeout(() => { this.toast.show = false; }, 3000);
        },

        formatIngress(host, port) {
            if (!host) return '未配置';
            return port ? (host + ':' + port) : host;
        },

        formatBytes(bytes) {
            const n = Number(bytes) || 0;
            if (n < 1024) return n + ' B';
            if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
            return (n / 1024 / 1024).toFixed(2) + ' MB';
        },

        exposureTierShort(tier) {
            const map = {
                intl_only: 'L1',
                public_intl: 'L2',
                domestic_limited: 'L3',
                domestic_sensitive: 'L4'
            };
            return map[tier] || tier || '-';
        },

        exposureTierLabel(tier) {
            const map = {
                intl_only: '仅国际',
                public_intl: '国际+公共',
                domestic_limited: '有限国内',
                domestic_sensitive: '高敏感国内'
            };
            return map[tier] || tier || '-';
        },

        trustLevelLabel(level) {
            const map = {
                blacklisted: '封禁',
                untrusted: '信任 L1',
                observe: '信任 L2',
                basic: '信任 L3',
                trusted: '信任 L4'
            };
            return map[level] || level || '-';
        },

        trustBadgeClass(level) {
            const map = {
                blacklisted: 'trust-blacklisted',
                untrusted: 'trust-untrusted',
                observe: 'trust-observe',
                basic: 'trust-basic',
                trusted: 'trust-trusted'
            };
            return map[level] || '';
        },

        tierBadgeClass(tier) {
            return {
                intl_only: 'tier-intl',
                public_intl: 'tier-public',
                domestic_limited: 'tier-limited',
                domestic_sensitive: 'tier-sensitive'
            }[tier] || '';
        },

        serverTypeLabel(type) {
            return ({
                vmess: 'VMess',
                trojan: 'Trojan',
                shadowsocks: 'Shadowsocks',
                vless: 'VLESS',
                hysteria: 'Hysteria',
                tuic: 'TUIC',
                anytls: 'AnyTLS'
            })[type] || type || '-';
        },

        serverKey(server) {
            if (!server) return '';
            return server.server_type + '_' + server.server_id;
        },

        serverOptionLabel(server) {
            if (!server) return '-';
            return '[' + this.serverTypeLabel(server.server_type) + '] ' + server.name + ' · ' + this.formatIngress(server.host, server.port);
        },

        filteredIngressServers() {
            if (!this.ingressFilterType) return this.ingressServers;
            return this.ingressServers.filter(s => s.server_type === this.ingressFilterType);
        },

        environmentLabel(env) {
            const map = {
                L0_desktop_low: '桌面低信任',
                L0_android_wifi: 'Android Wi-Fi',
                L0_ios_wifi: 'iOS Wi-Fi',
                L1_mobile_cellular: '移动蜂窝'
            };
            return map[env] || env || '-';
        },

        ipSourceLabel(source) {
            const map = {
                client_header: '客户端上报',
                request_ip: '请求来源',
                invalid_client_header: '客户端上报异常'
            };
            return map[source] || source || '-';
        },

        networkLabel(type) {
            return ({ wifi: 'Wi-Fi', cellular: '蜂窝', ethernet: '有线' })[type] || '未知';
        },

        deviceStatusLabel(status) {
            return Number(status) === 1 ? '有效' : '停用';
        },

        deviceStatusClass(status) {
            return Number(status) === 1 ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500';
        },

        accountExpanded(account) {
            return !!this.expandedAccounts[account.user_id];
        },

        toggleAccount(account) {
            this.expandedAccounts = Object.assign({}, this.expandedAccounts, {
                [account.user_id]: !this.accountExpanded(account)
            });
        },

        expandAllAccounts() {
            const expanded = {};
            for (const account of this.deviceList) expanded[account.user_id] = true;
            this.expandedAccounts = expanded;
        },

        collapseAllAccounts() {
            this.expandedAccounts = {};
        },

        expandedAccountCount() {
            return Object.values(this.expandedAccounts).filter(Boolean).length;
        },

        riskSummaryText(account) {
            const risk = account.risk_summary || {};
            const parts = [];
            if (risk.blacklisted_count) parts.push('封禁 ' + risk.blacklisted_count);
            if (risk.untrusted_count) parts.push('未信任 ' + risk.untrusted_count);
            if (risk.high_exposure_count) parts.push('高暴露 ' + risk.high_exposure_count);
            if (risk.inactive_device_count) parts.push('停用 ' + risk.inactive_device_count);
            return parts.length ? parts.join(' / ') : '无异常';
        },

        async loadRuntimeStatus() {
            try {
                const res = await this.apiFetch('/smart-route/runtime/status');
                if (res.data) this.runtime = Object.assign({}, this.runtime, res.data);
                this.runtime.manifest = Object.assign({ requests_1m: 0, requests_5m: 0, cache_hits_5m: 0, cache_misses_5m: 0, cache_hit_rate_5m: 0, avg_latency_ms_5m: 0, ttl_seconds: 120 }, this.runtime.manifest || {});
                this.runtime.ingress = Object.assign({ fallback_enabled: 0, intl_only_host: '', intl_only_port: 0, public_intl_host: '', public_intl_port: 0, domestic_limited_host: '', domestic_limited_port: 0, domestic_sensitive_host: '', domestic_sensitive_port: 0, manifest_generation: 1, updated_at: null }, this.runtime.ingress || {});
                this.runtime.device_touch = Object.assign({ written_5m: 0, skipped_5m: 0, skip_rate_5m: 0 }, this.runtime.device_touch || {});
                this.runtime.telemetry_queue = Object.assign({ queue: 'smart_route_telemetry', pending_batches: 0, enqueued_events_1m: 0, processed_events_1m: 0, failed_batches_1h: 0, avg_delay_ms_5m: 0, last_processed_at: null }, this.runtime.telemetry_queue || {});
                this.runtime.series = Object.assign({ minutes: [], manifest_requests: [], telemetry_enqueued: [], telemetry_processed: [], telemetry_failed: [] }, this.runtime.series || {});
            } catch (e) { this.showToast('加载运行状态失败: ' + e.message, 'error'); }
        },

        get ingressServerTypes() {
            return [...new Set(this.ingressServers.map(s => s.server_type))];
        },

        get selectedIngressServer() {
            if (!this.selectedIngressServerKey) return null;
            return this.ingressServers.find(s => this.serverKey(s) === this.selectedIngressServerKey) || null;
        },

        emptyIngressForm() {
            const form = {};
            for (const tier of this.tierOptions) {
                form[tier.value] = { exposure_tier: tier.value, ingress_host: '', ingress_port: null, pool_id: null, remark: '' };
            }
            return form;
        },

        resetIngressForm() {
            this.ingressForm = this.emptyIngressForm();
        },

        selectedIngressMappingCount() {
            return Object.values(this.ingressForm).filter(m => m.pool_id || m.ingress_host).length;
        },

        async loadPools() {
            try {
                const res = await this.apiFetch('/smart-route/pool/list');
                this.poolList = res.data || [];
            } catch (e) { this.showToast('加载入口池失败: ' + e.message, 'error'); }
        },

        resetPoolForm() {
            this.poolForm = { id: null, name: '', host: '', port: null, remark: '' };
        },

        editPool(p) {
            this.poolForm = { id: p.id, name: p.name, host: p.host, port: p.port, remark: p.remark || '' };
        },

        async savePool() {
            if (!this.poolForm.name || !this.poolForm.host) { this.showToast('名称和 host 必填', 'error'); return; }
            this.saving = true;
            try {
                await this.apiFetch('/smart-route/pool/save', 'POST', this.poolForm);
                this.showToast(this.poolForm.id ? '入口已更新' : '入口已新增');
                this.resetPoolForm();
                await this.loadPools();
            } catch (e) { this.showToast('保存失败: ' + e.message, 'error'); }
            finally { this.saving = false; }
        },

        async deletePool(p) {
            if (!confirm('确认删除入口「' + p.name + '」？已配置到节点的映射不受影响。')) return;
            try {
                await this.apiFetch('/smart-route/pool/delete', 'POST', { id: p.id });
                this.showToast('入口已删除');
                await this.loadPools();
            } catch (e) { this.showToast('删除失败: ' + e.message, 'error'); }
        },

        onPoolSelect(tier) {
            if (!this.ingressForm[tier]) return;
            const poolId = this.ingressForm[tier].pool_id;
            if (poolId) {
                // 选了入口池：清空单 host（互斥），端口/备注可留作覆盖用
                this.ingressForm[tier].ingress_host = '';
                const p = this.poolList.find(x => String(x.id) === String(poolId));
                if (p && !this.ingressForm[tier].remark) this.ingressForm[tier].remark = p.name;
            }
        },

        async loadIngressServers() {
            try {
                const res = await this.apiFetch('/smart-route/server/list');
                this.ingressServers = res.data || [];
                if (!this.selectedIngressServerKey && this.ingressServers.length > 0) {
                    this.selectedIngressServerKey = this.serverKey(this.ingressServers[0]);
                    await this.loadSelectedIngressMappings();
                }
            } catch (e) { this.showToast('加载节点列表失败: ' + e.message, 'error'); }
        },

        selectFirstFilteredServer() {
            const list = this.filteredIngressServers();
            this.selectedIngressServerKey = list.length ? this.serverKey(list[0]) : '';
            this.loadSelectedIngressMappings();
        },

        async loadSelectedIngressMappings() {
            this.resetIngressForm();
            if (!this.selectedIngressServer) return;
            try {
                const params = new URLSearchParams({
                    server_type: this.selectedIngressServer.server_type,
                    server_id: this.selectedIngressServer.server_id
                });
                const res = await this.apiFetch('/smart-route/ingress/list?' + params.toString());
                for (const item of (res.data || [])) {
                    if (!this.ingressForm[item.exposure_tier]) continue;
                    this.ingressForm[item.exposure_tier] = {
                        exposure_tier: item.exposure_tier,
                        ingress_host: item.ingress_host || '',
                        ingress_port: item.ingress_port || null,
                        pool_id: item.pool_id || null,
                        remark: item.remark || ''
                    };
                }
            } catch (e) { this.showToast('加载入口映射失败: ' + e.message, 'error'); }
        },

        clearIngressForm() {
            if (!this.selectedIngressServer) return;
            if (!confirm('确认清空当前节点所有 L1-L4 单节点入口映射？保存后才会生效。')) return;
            this.resetIngressForm();
        },

        async saveIngressMappings() {
            if (!this.selectedIngressServer) return;
            this.saving = true;
            try {
                const mappings = this.tierOptions.map(tier => {
                    const item = this.ingressForm[tier.value] || {};
                    return {
                        exposure_tier: tier.value,
                        ingress_host: (item.ingress_host || '').trim(),
                        ingress_port: item.ingress_port ? Number(item.ingress_port) : null,
                        pool_id: item.pool_id ? Number(item.pool_id) : null,
                        remark: (item.remark || '').trim()
                    };
                });
                await this.apiFetch('/smart-route/ingress/batch-save', 'POST', {
                    server_type: this.selectedIngressServer.server_type,
                    server_id: this.selectedIngressServer.server_id,
                    mappings
                });
                this.showToast('单节点入口映射已保存');
                await this.loadSelectedIngressMappings();
                this.loadRuntimeStatus();
            } catch (e) {
                this.showToast('保存入口映射失败: ' + e.message, 'error');
            } finally {
                this.saving = false;
            }
        },

        // ===== 设备管理 =====
        visibleDevices(account) {
            const devices = (account && account.devices) ? account.devices : [];
            if (this.deviceShowUnbound) return devices;
            // 默认隐藏已解绑(status=0)设备；但若该设备命中了搜索，仍然展示
            return devices.filter(d => d.status === 1 || d.matched);
        },

        async loadDevices() {
            try {
                const params = new URLSearchParams({ page: this.devicePage, per_page: 20 });
                if (this.deviceSearch) params.set('search', this.deviceSearch);
                if (this.deviceFilterTier) params.set('exposure_tier', this.deviceFilterTier);
                if (this.deviceFilterTrust) params.set('trust_level', this.deviceFilterTrust);
                const res = await this.apiFetch('/smart-route/device/account-list?' + params.toString());
                if (res.data) {
                    this.deviceList = res.data.items || [];
                    this.deviceTotal = res.data.total || 0;
                    if (this.deviceSearch) {
                        const expanded = {};
                        for (const account of this.deviceList) expanded[account.user_id] = true;
                        this.expandedAccounts = expanded;
                    }
                }
            } catch (e) { this.showToast('加载设备列表失败: ' + e.message, 'error'); }
        },

        async showDeviceDetail(device) {
            this.detailModal = true;
            this.detailData = null;
            this.detailLoading = true;
            try {
                const res = await this.apiFetch('/smart-route/device/detail?device_id=' + encodeURIComponent(device.device_id));
                this.detailData = res.data;
            } catch (e) {
                this.showToast('加载设备详情失败: ' + e.message, 'error');
                this.detailModal = false;
            } finally {
                this.detailLoading = false;
            }
        },

        showAdjustModal(device) {
            this.adjustTarget = device;
            this.adjustForm = {
                trust_level: device.trust_level,
                exposure_tier: device.exposure_tier,
                reason: ''
            };
            this.adjustModal = true;
        },

        async submitAdjust() {
            try {
                await this.apiFetch('/smart-route/device/adjust-trust', 'POST', {
                    device_id: this.adjustTarget.device_id,
                    trust_level: this.adjustForm.trust_level,
                    exposure_tier: this.adjustForm.exposure_tier,
                    reason: this.adjustForm.reason
                });
                this.adjustModal = false;
                this.showToast('调级成功');
                this.loadDevices();
            } catch (e) { this.showToast('调级失败: ' + e.message, 'error'); }
        },

        async quickBlacklist(device) {
            if (!confirm('确认将此设备加入黑名单？')) return;
            try {
                await this.apiFetch('/smart-route/device/adjust-trust', 'POST', {
                    device_id: device.device_id,
                    trust_level: 'blacklisted',
                    exposure_tier: 'intl_only',
                    reason: '管理员手动拉黑'
                });
                this.showToast('已加入黑名单');
                this.loadDevices();
            } catch (e) { this.showToast('操作失败: ' + e.message, 'error'); }
        },

        async quickUnblacklist(device) {
            if (!confirm('确认解除此设备的黑名单？')) return;
            try {
                await this.apiFetch('/smart-route/blacklist/remove', 'POST', {
                    device_id: device.device_id,
                    reason: '管理员手动解除'
                });
                this.showToast('已解除黑名单');
                this.loadDevices();
            } catch (e) { this.showToast('操作失败: ' + e.message, 'error'); }
        },

        async forceUnbindDevice(device) {
            const reason = prompt('请输入管理员解绑原因（也会写入审计日志）：', '用户旧设备无法登入，管理员协助解绑');
            if (!reason) return;
            if (!confirm('确认解绑该设备？此操作会立即释放该用户的设备名额，并清除信任档案与凭证。')) return;
            try {
                await this.apiFetch('/smart-route/device/force-unbind', 'POST', {
                    device_id: device.device_id,
                    reason
                });
                this.showToast('设备已解绑');
                if (this.detailModal) await this.refreshDetail();
                this.loadDevices();
            } catch (e) { this.showToast('解绑失败: ' + e.message, 'error'); }
        },

        async resetAccountDevices(account) {
            const email = account.user_email || ('#' + account.user_id);
            const tip = '确认重置账号 ' + email + ' 的设备登录？\n\n'
                + '这会立即注销该账号现有 APP 登录、停用全部设备并释放名额；'
                + '不会影响其他账号。用户需彻底关闭 APP 后，用密码手动登录一次。';
            if (!confirm(tip)) return;
            const reason = prompt('请输入重置原因（写入审计日志）：', '同一手机产生重复设备，管理员协助恢复登录');
            if (!reason) return;
            try {
                const res = await this.apiFetch('/smart-route/device/reset-account', 'POST', {
                    user_id: account.user_id,
                    reason
                });
                const data = res.data || {};
                this.showToast('已重置：SR ' + (data.sr_devices_reset || 0)
                    + ' 台 / UserDevice ' + (data.user_devices_reset || 0) + ' 条');
                if (this.detailModal) this.detailModal = false;
                this.loadDevices();
            } catch (e) { this.showToast('重置失败: ' + e.message, 'error'); }
        },

        async purgeSingleDevice(device) {
            if (device.status !== 0) {
                this.showToast('该设备处于活跃状态，请先解绑', 'error');
                return;
            }
            if (!confirm('确认清理该设备的历史数据？将物理删除 SmartRoute 档案、信任、会话与行为聚合，不可恢复。')) return;
            try {
                const res = await this.apiFetch('/smart-route/device/purge-unbound', 'POST', {
                    device_id: device.device_id,
                    reason: '管理员手动清理已解绑设备'
                });
                this.showToast('已清理 ' + (res.data?.sr_purged || 0) + ' 台 SR 设备');
                if (this.detailModal) this.detailModal = false;
                this.loadDevices();
            } catch (e) { this.showToast('清理失败: ' + e.message, 'error'); }
        },

        async purgeAccountUnbound(account) {
            const sr = (account.total_device_count || 0) - (account.active_device_count || 0);
            const tip = '将清理账号 ' + (account.user_email || account.user_id) + ' 下所有已解绑设备的历史数据'
                + (sr > 0 ? '（约 ' + sr + ' 台 SR 设备）' : '')
                + '。\n仅清理 status=0 的记录，活跃设备不受影响。';
            if (!confirm(tip)) return;
            try {
                const res = await this.apiFetch('/smart-route/device/purge-unbound', 'POST', {
                    user_id: account.user_id,
                    reason: '管理员清理账号已解绑设备'
                });
                this.showToast('已清理 SR ' + (res.data?.sr_purged || 0) + ' 台 / UserDevice ' + (res.data?.user_devices_purged || 0) + ' 条');
                if (this.detailModal) await this.refreshDetail();
                this.loadDevices();
            } catch (e) { this.showToast('清理失败: ' + e.message, 'error'); }
        },

        // 带确认令牌执行批量清理；若令牌缺失/失效（后端 422 会回带新令牌），自动用新令牌重试一次。
        async purgeWithConfirm(body) {
            try {
                return await this.apiFetch('/smart-route/device/bulk-purge', 'POST', body);
            } catch (e) {
                const freshToken = e.data && e.data.confirm_token;
                if (e.status === 422 && freshToken && freshToken !== body.confirm_token) {
                    return await this.apiFetch('/smart-route/device/bulk-purge', 'POST', {
                        ...body,
                        confirm_token: freshToken
                    });
                }
                throw e;
            }
        },

        async bulkPurgeAllUnbound() {
            try {
                const preview = await this.apiFetch('/smart-route/device/bulk-purge', 'POST', {
                    mode: 'unbound', dry_run: true
                });
                const est = preview.data?.estimated_affected || 0;
                if (est === 0) { this.showToast('没有需要清理的已解绑设备'); return; }
                if (!confirm('⚠️ 一键清理全局所有已解绑设备（status=0）的 SmartRoute 档案及 UserDevice 记录。\n\n预计影响 ' + est + ' 条记录。活跃设备不受影响。此操作不可恢复，确认继续？')) return;
                const res = await this.purgeWithConfirm({
                    mode: 'unbound',
                    confirm_token: preview.data?.confirm_token,
                    reason: '管理员一键清理所有已解绑设备'
                });
                this.showToast('清理完成：SR ' + (res.data?.sr_purged || 0) + ' 台 / UserDevice ' + (res.data?.user_devices_purged || 0) + ' 条');
                this.loadDevices();
            } catch (e) { this.showToast('清理失败: ' + e.message, 'error'); }
        },

        async bulkPurgeInactive() {
            const days = prompt('清理多少天内未活跃的设备？（默认 15 天，会先解绑再清理）', '15');
            if (!days) return;
            const d = parseInt(days, 10);
            if (isNaN(d) || d < 1) { this.showToast('请输入有效天数', 'error'); return; }
            try {
                const preview = await this.apiFetch('/smart-route/device/bulk-purge', 'POST', {
                    mode: 'inactive', inactive_days: d, dry_run: true
                });
                const est = preview.data?.estimated_affected || 0;
                if (est === 0) { this.showToast('没有 ' + d + ' 天未活跃的设备需要清理'); return; }
                if (!confirm('⚠️ 将解绑并清理所有 ' + d + ' 天内未活跃的设备。\n\n预计影响 ' + est + ' 条记录。这些设备可能是用户重装系统后遗留的旧设备，清理后用户可重新绑定新设备。\n\n此操作不可恢复，确认继续？')) return;
                const res = await this.purgeWithConfirm({
                    mode: 'inactive',
                    inactive_days: d,
                    confirm_token: preview.data?.confirm_token,
                    reason: '管理员一键清理 ' + d + ' 天未活跃设备'
                });
                this.showToast('清理完成：解绑 ' + (res.data?.unbound_first || 0) + ' 台 → 清理 SR ' + (res.data?.sr_purged || 0) + ' 台 / UserDevice ' + (res.data?.user_devices_purged || 0) + ' 条');
                this.loadDevices();
            } catch (e) { this.showToast('清理失败: ' + e.message, 'error'); }
        },

        async refreshDetail() {
            if (!this.detailData?.device?.device_id) return;
            try {
                const res = await this.apiFetch('/smart-route/device/detail?device_id=' + encodeURIComponent(this.detailData.device.device_id));
                this.detailData = res.data;
            } catch (e) { /* 忽略，按钮回流即可 */ }
        },

        // ===== 审计日志 =====
        async loadAuditLogs() {
            try {
                const params = new URLSearchParams({ page: this.auditPage, per_page: 30 });
                if (this.auditFilterAction) params.set('action', this.auditFilterAction);
                if (this.auditFilterSearch && this.auditFilterSearch.trim()) params.set('search', this.auditFilterSearch.trim());
                if (this.auditFilterStart) params.set('start_date', this.auditFilterStart);
                if (this.auditFilterEnd) params.set('end_date', this.auditFilterEnd);
                const res = await this.apiFetch('/smart-route/audit-logs?' + params.toString());
                if (res.data) {
                    this.auditLogs = res.data.items || [];
                    this.auditTotal = res.data.total || 0;
                }
            } catch (e) { this.showToast('加载审计日志失败: ' + e.message, 'error'); }
        },

        resetAuditFilter() {
            this.auditFilterAction = '';
            this.auditFilterSearch = '';
            this.auditFilterStart = '';
            this.auditFilterEnd = '';
            this.auditPage = 1;
            this.loadAuditLogs();
        },

        openAuditPurge() {
            this.auditPurgeActions = ['device.register'];
            this.auditPurgeKeepUnbind = true;
            this.auditPurgeBeforeDays = '';
            this.auditPurgeModal = true;
        },

        buildAuditPurgeBody() {
            const body = {};
            if (this.auditPurgeActions.length) body.actions = this.auditPurgeActions;
            if (this.auditPurgeKeepUnbind) body.keep_actions = ['device.user_unbind', 'device.force_unbind'];
            const d = parseInt(this.auditPurgeBeforeDays, 10);
            if (!isNaN(d) && d >= 0) body.before_days = d;
            return body;
        },

        async confirmAuditPurge() {
            const base = this.buildAuditPurgeBody();
            if (!base.actions && !base.keep_actions && base.before_days === undefined) {
                this.showToast('请至少选择操作类型或填写保留天数', 'error');
                return;
            }
            this.auditPurging = true;
            try {
                const preview = await this.apiFetch('/smart-route/audit-logs/purge', 'POST', { ...base, dry_run: true });
                const est = preview.data?.estimated_affected || 0;
                if (est === 0) { this.showToast('没有符合条件的日志需要清理'); this.auditPurging = false; return; }
                if (!confirm('⚠️ 将物理删除 ' + est + ' 条审计日志，不可恢复。确认继续？')) { this.auditPurging = false; return; }
                let res;
                const body = { ...base, confirm_token: preview.data?.confirm_token, reason: '管理员清理审计日志' };
                try {
                    res = await this.apiFetch('/smart-route/audit-logs/purge', 'POST', body);
                } catch (e) {
                    const fresh = e.data && e.data.confirm_token;
                    if (e.status === 422 && fresh && fresh !== body.confirm_token) {
                        res = await this.apiFetch('/smart-route/audit-logs/purge', 'POST', { ...body, confirm_token: fresh });
                    } else { throw e; }
                }
                this.showToast('已清理 ' + (res.data?.purged || 0) + ' 条日志');
                this.auditPurgeModal = false;
                this.auditPage = 1;
                this.loadAuditLogs();
            } catch (e) {
                this.showToast('清理失败: ' + e.message, 'error');
            } finally {
                this.auditPurging = false;
            }
        },

        // ==================== 自定义规则（Provider Package）====================
        async loadPackages() {
            try {
                const params = new URLSearchParams();
                if (this.pkgFilterTier) params.set('exposure_tier', this.pkgFilterTier);
                const qs = params.toString();
                const res = await this.apiFetch('/smart-route/provider-package/list' + (qs ? '?' + qs : ''));
                this.pkgList = res.data || [];
            } catch (e) { this.showToast('加载自定义规则失败: ' + e.message, 'error'); }
        },

        resetPkgForm() {
            this.pkgForm = { id: null, name: '', exposure_tier: 'intl_only', provider_type: 'mihomo_proxy_provider', payload: '', enabled: 1 };
        },

        editPackage(p) {
            this.pkgForm = {
                id: p.id, name: p.name, exposure_tier: p.exposure_tier,
                provider_type: p.provider_type || 'mihomo_proxy_provider',
                payload: p.payload || '', enabled: Number(p.enabled)
            };
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        async savePackage() {
            if (!this.pkgForm.name || !this.pkgForm.payload || !this.pkgForm.payload.trim()) {
                this.showToast('名称和规则内容必填', 'error'); return;
            }
            this.pkgSaving = true;
            try {
                const res = await this.apiFetch('/smart-route/provider-package/save', 'POST', this.pkgForm);
                this.showToast(this.pkgForm.id ? ('规则已更新 (' + (res.data?.version || '') + ')') : '规则已新增');
                this.resetPkgForm();
                await this.loadPackages();
            } catch (e) { this.showToast('保存失败: ' + e.message, 'error'); }
            finally { this.pkgSaving = false; }
        },

        async deletePackage(p) {
            if (!confirm('确认删除规则「' + p.name + '」？删除后该 tier 将回落到其他 enabled 规则或空规则。')) return;
            try {
                await this.apiFetch('/smart-route/provider-package/delete', 'POST', { id: p.id });
                this.showToast('规则已删除');
                await this.loadPackages();
            } catch (e) { this.showToast('删除失败: ' + e.message, 'error'); }
        }
    };
}
</script>
</body>
</html>
