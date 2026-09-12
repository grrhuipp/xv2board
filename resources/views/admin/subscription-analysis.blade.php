<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="api-base" content="/api/v1/{{$secure_path}}">
    <title>订阅分析</title>
    <link rel="stylesheet" href="/assets/admin/subscription-analysis.css?v={{$version}}">
    <script defer src="/assets/admin/subscription-analysis.js?v={{$version}}"></script>
</head>
<body>
<main>
    <header><div><h1>订阅分析</h1><p>普通订阅的用户拉取行为 · 最近 24 小时</p></div><span class="tag neutral">不包含 APP 订阅</span></header>
    <section class="stats" aria-label="最近24小时总体统计">
        <div><span>活跃用户</span><strong id="users">—</strong></div>
        <div><span>订阅请求</span><strong id="requests">—</strong></div>
        <div><span>频繁拉取</span><strong id="frequent_users">—</strong></div>
        <div><span>多 IP 拉取</span><strong id="multi_ip_users">—</strong></div>
        <div><span>已标记用户</span><strong id="marked_users">—</strong></div>
    </section>
    <section class="panel">
        <form id="filters">
            <input id="q" aria-label="搜索用户" maxlength="100" placeholder="邮箱 / UID / IP / UA / 标记备注">
            <select id="event" aria-label="事件筛选"><option value="all">全部事件</option><option value="attention">有异常提示</option><option value="frequent">频繁拉取</option><option value="multi_ip">多 IP 拉取</option></select>
            <label><input type="checkbox" id="marked"> 只看已标记</label>
            <button type="submit" class="primary">查询</button><button type="button" id="refresh">刷新</button>
        </form>
        <div class="description"><p id="scope">仅统计普通订阅；次数表示鉴权后的请求，不代表下载成功。</p><details><summary>查看提示规则与数据说明</summary><p>频繁拉取：2 分钟 ≥ 5 次，或 5 分钟 ≥ 10 次，或 1 小时 ≥ 30 次。多 IP：10 分钟 ≥ 3 个，或 24 小时 ≥ 5 个不同 IP。提示仅供人工排查，不会封禁用户。空 IP 不计入 IP 数；均隔按 24 小时内首末请求间隔计算。注册 IP、登录 IP 未采集，故不展示。</p><p>上方统计卡片始终展示最近 24 小时总体数据；筛选仅作用于下方列表。</p></details></div>
        <p id="status" role="status" aria-live="polite">正在加载…</p>
        <div class="table-scroll"><table><thead><tr><th>用户</th><th>最近订阅 IP / 地区 / UA</th><th>拉取频率</th><th>提示与标记</th><th>操作</th></tr></thead><tbody id="rows"></tbody></table></div>
        <footer><span id="page-info"></span><div><button id="prev" type="button">上一页</button><button id="next" type="button">下一页</button></div></footer>
    </section>
    <p class="footnote" id="updated"></p>
</main>
<dialog id="mark-dialog"><form id="mark-form"><h2>用户标记</h2><p id="mark-user"></p><label for="note">排查备注</label><textarea id="note" maxlength="500" rows="5" placeholder="填写需要关注的情况（最多500字）"></textarea><p id="mark-error" role="alert"></p><div class="dialog-actions"><button type="button" id="unmark">取消标记</button><button type="button" id="close-mark">关闭</button><button class="primary" type="submit" id="save-mark">保存标记</button></div></form></dialog>
<dialog id="history-dialog"><div class="dialog-heading"><h2 id="history-title">订阅记录</h2><button id="close-history" type="button">关闭</button></div><p id="history-status" role="status"></p><div class="table-scroll"><table><thead><tr><th>时间</th><th>IP / 地区</th><th>User-Agent</th></tr></thead><tbody id="history-rows"></tbody></table></div><div class="dialog-actions"><button id="history-prev">上一页</button><span id="history-page"></span><button id="history-next">下一页</button></div></dialog>
</body>
</html>
