(() => {
    'use strict';
    const $ = id => document.getElementById(id);
    const base = document.querySelector('meta[name="api-base"]').content;
    let page = 1, total = 0, active = null, historyUser = null, historyPage = 1, historyTotal = 0;
    let filters = {q: '', event: 'all', marked: '0'}, sequence = 0, historySequence = 0;
    const text = (tag, value, cls) => {
        const node = document.createElement(tag);
        node.textContent = value == null || value === '' ? '—' : String(value);
        if (cls) node.className = cls;
        return node;
    };
    function authorization() {
        let value = localStorage.getItem('authorization') || localStorage.getItem('auth_data') || '';
        if (!value) {
            for (const part of document.cookie.split(';')) {
                const i = part.indexOf('=');
                if (['authorization', 'auth_data'].includes(part.slice(0, i).trim())) value = decodeURIComponent(part.slice(i + 1));
            }
        }
        return value;
    }
    async function api(path, body) {
        const auth = authorization();
        if (!auth) throw new Error('登录已失效，请返回管理后台重新登录。');
        const response = await fetch(base + path, {
            method: body ? 'POST' : 'GET', cache: 'no-store',
            headers: {authorization: auth, Accept: 'application/json', ...(body ? {'Content-Type': 'application/json'} : {})},
            ...(body ? {body: JSON.stringify(body)} : {})
        });
        if (!response.ok) {
            if (response.status === 401 || response.status === 403) throw new Error('登录已失效或没有管理员权限，请重新登录。');
            if (response.status === 422) throw new Error('输入内容不符合要求，请检查后重试。');
            throw new Error('请求失败（' + response.status + '），请稍后重试。');
        }
        return response.json();
    }
    const geo = row => [row.country, row.city, row.isp, row.as].filter(Boolean).join(' · ') || '归属地未知';
    const time = value => value ? new Date(Number(value) * 1000).toLocaleString('zh-CN', {hour12: false}) : '—';
    function renderRow(row) {
        const tr = document.createElement('tr');
        if (row.signals.length) tr.className = 'attention';
        const user = document.createElement('td');
        user.append(text('div', row.email, 'email'), text('div', 'UID ' + row.user_id, 'subtle'));
        if (row.is_marked) user.append(text('span', '已标记', 'tag mark'));
        user.append(text('div', '注册 ' + time(row.registered_at), 'subtle'));
        const latest = document.createElement('td'), info = row.latest || {};
        latest.append(text('div', info.ip, 'ip'), text('div', geo(info), 'subtle'), text('div', info.user_agent || 'UA 未记录', 'ua'), text('div', row.last_at, 'subtle'));
        const counts = document.createElement('td');
        [['2 分钟', row.count_2m, '次'], ['5 分钟', row.count_5m, '次'], ['1 小时', row.count_1h, '次'], ['24 小时', row.count_24h, '次'], ['10 分钟', row.ips_10m, 'IP'], ['24 小时', row.ips_24h, 'IP']].forEach(([label, value, unit]) => {
            const line = text('div', label + ' ', 'metric');
            line.append(text('strong', value), document.createTextNode(' ' + unit));
            counts.append(line);
        });
        counts.append(text('div', '均隔 ' + (row.average_interval == null ? '—' : row.average_interval + ' 秒'), 'subtle'));
        const notes = document.createElement('td');
        row.signals.forEach(signal => notes.append(text('span', signal === 'frequent' ? '频繁拉取' : '多 IP 拉取', 'tag')));
        if (!row.signals.length) notes.append(text('div', '暂无异常提示', 'subtle'));
        if (row.note) notes.append(text('div', row.note, 'note'));
        if (row.marked_at) notes.append(text('div', '标记更新 ' + time(row.marked_at), 'subtle'));
        const actions = document.createElement('td'); actions.className = 'actions';
        const mark = text('button', row.is_marked ? '编辑标记' : '标记', 'primary');
        mark.type = 'button'; mark.onclick = () => openMark(row);
        const detail = text('button', '拉取记录'); detail.type = 'button'; detail.onclick = () => openHistory(row);
        actions.append(mark, detail); tr.append(user, latest, counts, notes, actions);
        return tr;
    }
    async function load() {
        const seq = ++sequence;
        $('status').className = ''; $('status').textContent = '正在加载…';
        $('prev').disabled = $('next').disabled = true;
        // Clear stale results so a failed filter request cannot be mistaken for a successful one.
        $('rows').replaceChildren(); $('page-info').textContent = '';
        try {
            const data = await api('/subscription-analysis/fetch?' + new URLSearchParams({...filters, page, page_size: 20}));
            if (seq !== sequence) return;
            total = data.total;
            if (page > 1 && total <= (page - 1) * 20) { page = Math.max(1, Math.ceil(total / 20)); return load(); }
            $('rows').replaceChildren(...data.data.map(renderRow));
            Object.entries(data.summary).forEach(([key, value]) => { if ($(key)) $(key).textContent = Number(value).toLocaleString('zh-CN'); });
            $('scope').textContent = data.meta.scope;
            $('status').textContent = total ? '用户视图 · 共 ' + total + ' 位用户' : '没有符合条件的订阅记录';
            $('page-info').textContent = '第 ' + page + ' / ' + Math.max(1, Math.ceil(total / 20)) + ' 页';
            $('updated').textContent = '统计时间：' + data.meta.as_of + '（' + data.meta.timezone + '） · 现存最早记录：' + (data.meta.retained_from || '暂无');
            $('prev').disabled = page <= 1; $('next').disabled = page * 20 >= total;
        } catch (error) {
            if (seq !== sequence) return;
            $('status').className = 'error'; $('status').textContent = error.message;
            ['users', 'requests', 'frequent_users', 'multi_ip_users', 'marked_users'].forEach(key => $(key).textContent = '—');
            $('updated').textContent = '';
        }
    }
    function openMark(row) {
        active = row; $('mark-user').textContent = row.email + ' · UID ' + row.user_id;
        $('note').value = row.note || ''; $('mark-error').textContent = '';
        $('unmark').hidden = !row.is_marked; $('mark-dialog').showModal();
    }
    async function saveMark(marked) {
        const user = active;
        ['save-mark', 'unmark', 'close-mark'].forEach(id => $(id).disabled = true);
        try {
            await api('/subscription-analysis/mark', {user_id: user.user_id, marked, note: $('note').value.trim()});
            $('mark-dialog').close(); await load();
        } catch (error) { $('mark-error').textContent = error.message; }
        finally { ['save-mark', 'unmark', 'close-mark'].forEach(id => $(id).disabled = false); }
    }
    function openHistory(row) {
        historyUser = row; historyPage = 1;
        $('history-title').textContent = '拉取记录 · ' + row.email;
        $('history-dialog').showModal(); loadHistory();
    }
    async function loadHistory() {
        const seq = ++historySequence;
        $('history-status').textContent = '正在加载普通订阅历史…'; $('history-rows').replaceChildren();
        $('history-prev').disabled = $('history-next').disabled = true;
        $('history-page').textContent = '';
        try {
            const data = await api('/user/fetchSubscribeLogs?' + new URLSearchParams({user_id: historyUser.user_id, current: historyPage, pageSize: 20}));
            if (seq !== historySequence) return;
            historyTotal = data.total;
            $('history-rows').replaceChildren(...data.data.map(row => {
                const tr = document.createElement('tr'), ip = document.createElement('td');
                ip.append(text('div', row.ip, 'ip'), text('div', geo(row), 'subtle'));
                tr.append(text('td', row.created_at), ip, text('td', row.user_agent || 'UA 未记录')); return tr;
            }));
            $('history-status').textContent = '全部现存普通订阅记录 · 共 ' + historyTotal + ' 条（不限24小时）';
            $('history-page').textContent = historyPage + ' / ' + Math.max(1, Math.ceil(historyTotal / 20));
            $('history-prev').disabled = historyPage <= 1; $('history-next').disabled = historyPage * 20 >= historyTotal;
        } catch (error) { if (seq === historySequence) $('history-status').textContent = error.message; }
    }
    $('filters').onsubmit = event => { event.preventDefault(); filters = {q: $('q').value.trim(), event: $('event').value, marked: $('marked').checked ? '1' : '0'}; page = 1; load(); };
    $('refresh').onclick = () => load();
    $('prev').onclick = () => { if (page > 1) { page--; load(); } };
    $('next').onclick = () => { if (page * 20 < total) { page++; load(); } };
    $('mark-form').onsubmit = event => { event.preventDefault(); saveMark(true); };
    $('unmark').onclick = () => saveMark(false);
    $('close-mark').onclick = () => $('mark-dialog').close();
    $('mark-dialog').addEventListener('cancel', event => { if ($('save-mark').disabled) event.preventDefault(); });
    $('close-history').onclick = () => { historySequence++; $('history-dialog').close(); };
    $('history-prev').onclick = () => { if (historyPage > 1) { historyPage--; loadHistory(); } };
    $('history-next').onclick = () => { if (historyPage * 20 < historyTotal) { historyPage++; loadHistory(); } };
    load();
})();
