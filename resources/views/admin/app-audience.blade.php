<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>用户画像统计 - {{$title}}</title>
    <link rel="icon" href="{{$logo}}">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js"></script>
    <style>
        [x-cloak]{display:none!important}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f2f5}
        .card{background:#fff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1);padding:24px;margin-bottom:20px}
        .btn-back{background:#f3f4f6;color:#374151;padding:8px 16px;border-radius:6px;font-size:14px;cursor:pointer;border:1px solid #d1d5db;text-decoration:none;transition:background .2s;display:inline-block}
        .btn-back:hover{background:#e5e7eb}
        .input-field{padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px}
        .stat-card{border-radius:12px;padding:20px;color:#fff}
        .stat-card.purple{background:linear-gradient(135deg,#667eea,#764ba2)}
        .stat-card.blue{background:linear-gradient(135deg,#3b82f6,#1d4ed8)}
        .stat-card.green{background:linear-gradient(135deg,#10b981,#059669)}
        .section-title{font-size:15px;font-weight:600;color:#111827;margin-bottom:14px}
        .bar-row{display:flex;align-items:center;gap:8px;margin-bottom:8px;font-size:13px}
        .bar-name{width:38%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#374151}
        .bar-track{flex:1;background:#f0f2f5;border-radius:5px;height:18px;overflow:hidden}
        .bar-fill{height:100%;background:linear-gradient(90deg,#60a5fa,#3b82f6);border-radius:5px;min-width:2px}
        .bar-val{width:130px;text-align:right;color:#6b7280;font-variant-numeric:tabular-nums}
        .bar-val b{color:#111827}
        .toast{position:fixed;top:20px;right:20px;padding:12px 24px;border-radius:8px;color:#fff;font-size:14px;z-index:9999}
        .toast-error{background:#ef4444}
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
<div x-data="appAudience()" x-init="init()" x-cloak>
    <div x-show="toast.show" x-transition class="toast toast-error" x-text="toast.msg"></div>

    <div class="bg-white shadow-sm border-b">
        <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a :href="'/' + securePath" class="btn-back">&larr; 返回后台</a>
                <h1 class="text-xl font-bold text-gray-800">用户画像统计</h1>
            </div>
            <div class="flex items-center gap-3">
                <label class="text-sm text-gray-500">统计范围</label>
                <select class="input-field" x-model.number="days" @change="load()">
                    <option value="0">全部活跃设备</option>
                    <option value="7">近 7 天</option>
                    <option value="30">近 30 天</option>
                    <option value="90">近 90 天</option>
                </select>
                <button class="btn-back" @click="load()">刷新</button>
            </div>
        </div>
    </div>

    <div class="max-w-7xl mx-auto px-6 py-6">
        <!-- 概览卡片 -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="stat-card purple">
                <div class="text-sm opacity-80">统计设备总数</div>
                <div class="text-3xl font-bold mt-1" x-text="data.total_devices || 0"></div>
            </div>
            <div class="stat-card blue">
                <div class="text-sm opacity-80">设备品牌数</div>
                <div class="text-3xl font-bold mt-1" x-text="(data.dimensions?.brands||[]).length"></div>
            </div>
            <div class="stat-card green">
                <div class="text-sm opacity-80">APP 版本数</div>
                <div class="text-3xl font-bold mt-1" x-text="(data.dimensions?.versions||[]).length"></div>
            </div>
        </div>

        <div x-show="!data.geo_available" class="card" style="border-left:4px solid #f59e0b">
            <div class="text-sm text-yellow-700">
                未检测到 IP 归属地库（storage/app/ip2region/v4.xdb、v6.xdb），
                「真实IP归属地」和「运营商」维度将为空，其余维度正常。
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <template x-for="panel in panels" :key="panel.key">
                <div class="card">
                    <div class="section-title" x-text="panel.title"></div>
                    <template x-if="(data.dimensions?.[panel.key]||[]).length===0">
                        <div class="text-sm text-gray-400">暂无数据</div>
                    </template>
                    <template x-for="row in (data.dimensions?.[panel.key]||[])" :key="row.name">
                        <div class="bar-row">
                            <span class="bar-name" :title="row.name" x-text="row.name"></span>
                            <span class="bar-track">
                                <span class="bar-fill" :style="'width:'+barPct(panel.key,row.count)+'%'"></span>
                            </span>
                            <span class="bar-val"><b x-text="row.count"></b> 台 · <span x-text="pct(panel.key,row.count)"></span></span>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>
</div>

<script>
function appAudience() {
    return {
        securePath: '{{$secure_path}}',
        days: 0,
        data: { total_devices: 0, geo_available: true, dimensions: {} },
        toast: { show: false, msg: '' },
        panels: [
            { key: 'brands', title: '设备品牌' },
            { key: 'versions', title: 'APP 版本（看多少人没升级）' },
            { key: 'regions', title: '真实IP归属地' },
            { key: 'isps', title: '运营商' },
            { key: 'os_types', title: '操作系统' },
            { key: 'os_versions', title: '系统版本' },
        ],

        init() { this.load(); },

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

        apiBase() { return '/api/v1/' + this.securePath; },

        async load() {
            const auth = this.getAuthData();
            if (!auth) { this.showToast('未获取到后台登录凭证，请先登录后台再进入本页'); return; }
            try {
                const res = await fetch(this.apiBase() + '/stat/getAppAudience?days=' + this.days + '&limit=15', {
                    headers: { 'authorization': auth }
                });
                if (!res.ok) {
                    const err = await res.json().catch(() => ({}));
                    throw new Error(err.message || 'HTTP ' + res.status);
                }
                const json = await res.json();
                this.data = json.data || { dimensions: {} };
            } catch (e) {
                this.showToast('加载失败: ' + e.message);
            }
        },

        maxOf(key) {
            const list = this.data.dimensions?.[key] || [];
            let max = 0;
            list.forEach(r => { if (r.count > max) max = r.count; });
            return max || 1;
        },
        barPct(key, count) { return Math.round(count / this.maxOf(key) * 100); },
        pct(key, count) {
            const total = this.data.total_devices || 0;
            if (!total) return '0%';
            return (count / total * 100).toFixed(1) + '%';
        },

        showToast(msg) {
            this.toast.msg = msg; this.toast.show = true;
            setTimeout(() => { this.toast.show = false; }, 4000);
        },
    };
}
</script>
</body>
</html>
