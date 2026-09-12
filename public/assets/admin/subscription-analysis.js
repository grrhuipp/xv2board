/* Reuse the admin bundle's React, Ant Design and layout components. */
window.createSubscriptionAnalysisPage = function (n) {
    'use strict';
    const React = n('q1tI'), h = React.createElement;
    const Layout = n('Bl7J').a, Table = n('wCAj').a, Button = n('2/Rp').a;
    const Input = n('5rEg').a, Select = n('2fM7').a, Modal = n('kLXV').a, Logs = n('UserSubscribeLogs').a;
    const names = {multi_ip: '多 IP', geo: '异地拉取', frequent: '频繁拉取', multi_ua: '多 UA'};
    const thresholdLabels = {frequent_2m: '2 分钟拉取次数', frequent_5m: '5 分钟拉取次数', frequent_1h: '1 小时拉取次数', ip_10m: '10 分钟不同 IP 数', ip_3d: '3 天不同 IP 数', ua_3d: '3 天不同 UA 数', countries_3d: '3 天不同国家数', cities_3d: '3 天不同城市数'};
    const tag = (value, color, key) => h('span', {key, className: 'ant-tag ant-tag-' + color}, value);
    const muted = value => h('div', {className: 'text-muted sa-small'}, value || '—');
    const date = value => value ? new Date(value * 1000).toLocaleString('zh-CN', {hour12: false}) : '—';
    async function api(path, body) {
        let auth = localStorage.getItem('authorization') || localStorage.getItem('auth_data') || '';
        if (!auth) for (const part of document.cookie.split(';')) {
            const i = part.indexOf('=');
            if (['authorization', 'auth_data'].includes(part.slice(0, i).trim())) auth = decodeURIComponent(part.slice(i + 1));
        }
        if (!auth) throw new Error('登录已失效，请重新登录。');
        const response = await fetch('/api/v1/' + window.settings.secure_path + path, {
            method: body ? 'POST' : 'GET', cache: 'no-store',
            headers: {authorization: auth, Accept: 'application/json', ...(body ? {'Content-Type': 'application/json'} : {})},
            ...(body ? {body: JSON.stringify(body)} : {})
        });
        if (!response.ok) throw new Error(response.status === 403 || response.status === 401 ? '登录已失效或没有管理员权限，请重新登录。' : '请求失败（' + response.status + '），请检查输入或稍后重试。');
        return response.json();
    }
    return class SubscriptionAnalysis extends React.Component {
        constructor(props) {
            super(props);
            this.state = {q: '', event: 'all', marked: false, rows: [], summary: null, meta: null, loading: false, error: '', page: 1, pageSize: 20, total: 0, active: null, note: '', saving: false, markError: '', rules: false, thresholds: null, settingsOpen: false, draft: {}, settingsSaving: false, settingsError: ''};
            this.filters = {q: '', event: 'all', marked: '0'};
            this.sequence = 0;
            this.alive = true;
        }
        componentDidMount() { this.load(1, 20); }
        componentWillUnmount() { this.alive = false; this.sequence++; }
        async load(page = this.state.page, pageSize = this.state.pageSize) {
            const seq = ++this.sequence;
            this.setState({loading: true, error: '', rows: [], page, pageSize});
            try {
                const data = await api('/subscription-analysis/fetch?' + new URLSearchParams({...this.filters, page, page_size: pageSize}));
                if (!this.alive || seq !== this.sequence) return;
                if (page > 1 && data.total <= (page - 1) * pageSize) return this.load(Math.max(1, Math.ceil(data.total / pageSize)), pageSize);
                this.setState({rows: data.data, total: data.total, summary: data.summary, meta: data.meta, thresholds: data.thresholds, loading: false});
            } catch (error) {
                if (this.alive && seq === this.sequence) this.setState({loading: false, error: error.message, summary: null, meta: null, total: 0});
            }
        }
        search(event) {
            if (event) event.preventDefault();
            this.filters = {q: this.state.q.trim(), event: this.state.event, marked: this.state.marked ? '1' : '0'};
            this.load(1);
        }
        async save(marked) {
            const active = this.state.active;
            this.setState({saving: true, markError: ''});
            try {
                await api('/subscription-analysis/mark', {user_id: active.user_id, marked, note: this.state.note.trim()});
                if (!this.alive) return;
                this.setState({active: null, saving: false}); this.load();
            } catch (error) { if (this.alive) this.setState({saving: false, markError: error.message}); }
        }
        async saveSettings() {
            const values = {};
            for (const key of Object.keys(thresholdLabels)) {
                const value = Number(this.state.draft[key]);
                const minimum = key.startsWith('frequent_') ? 1 : 2;
                if (!Number.isInteger(value) || value < minimum || value > 100000) {
                    this.setState({settingsError: thresholdLabels[key] + '必须为 ' + minimum + '–100000 的整数。'}); return;
                }
                values[key] = value;
            }
            this.setState({settingsSaving: true, settingsError: ''});
            try {
                await api('/subscription-analysis/settings', values);
                if (!this.alive) return;
                this.setState({settingsOpen: false, settingsSaving: false, thresholds: values}); this.load(1);
            } catch (error) { if (this.alive) this.setState({settingsSaving: false, settingsError: error.message}); }
        }
        columns() {
            return [
                {title: '用户', key: 'user', width: 230, render: (_, row) => h('div', null, row.email, muted('UID ' + row.user_id), row.is_marked && tag('已标记', 'blue'), muted('注册 ' + date(row.registered_at)))},
                {title: '最近订阅 IP / 归属地 / UA', key: 'latest', width: 340, render: (_, row) => {
                    const info = row.latest || {};
                    return h('div', null, info.ip || '—', muted([info.country, info.city, info.isp, info.as].filter(Boolean).join(' · ')), h('div', {className: 'sa-ua text-muted'}, info.user_agent || 'UA 未记录'), muted(row.last_at));
                }},
                {title: '拉取次数', key: 'counts', width: 160, render: (_, row) => h('div', null,
                    ...[['2 分钟', row.count_2m], ['5 分钟', row.count_5m], ['1 小时', row.count_1h], ['3 天', row.count_3d]].map(([label, count]) => h('div', {key: label}, label + '：' + count + ' 次')),
                    muted('均隔 ' + (row.average_interval == null ? '—' : row.average_interval + ' 秒')))},
                {title: 'IP / 异地 / UA', key: 'risk_counts', width: 175, render: (_, row) => h('div', null,
                    h('div', null, '10 分钟 IP：' + row.ips_10m), h('div', null, '3 天 IP：' + row.ips_3d),
                    h('div', null, '3 天 UA：' + row.uas_3d), muted('国家 ' + row.countries_3d + ' / 城市 ' + row.cities_3d))},
                {title: '高危提示 / 标记', key: 'signals', width: 235, render: (_, row) => h('div', null,
                    row.signals.length ? row.signals.map(signal => tag(names[signal], 'red', signal)) : muted('暂无高危提示'),
                    row.note && h('div', {className: 'sa-note'}, row.note), row.marked_at && muted('标记更新 ' + date(row.marked_at)))},
                {title: '操作', key: 'actions', width: 110, fixed: 'right', render: (_, row) => h('div', {className: 'sa-actions'},
                    h('a', {onClick: () => this.setState({active: row, note: row.note || '', markError: ''})}, row.is_marked ? '编辑标记' : '标记'),
                    h(Logs, {userId: row.user_id, email: row.email, modalClassName: 'sa-scroll-modal'}, h('a', null, '订阅记录')))}
            ];
        }
        render() {
            const s = this.state;
            const summary = s.summary;
            const limits = s.thresholds || {};
            const close = () => { if (!s.saving) this.setState({active: null}); };
            return h(Layout, {...this.props, title: '订阅分析'}, h('div', {id: 'subscription-analysis', className: 'block border-bottom'},
                h('form', {className: 'sa-toolbar bg-white', onSubmit: event => this.search(event)},
                    h(Input, {value: s.q, maxLength: 100, placeholder: '邮箱 / UID / IP / UA / 标记备注', 'aria-label': '搜索用户', className: 'sa-search', onChange: event => this.setState({q: event.target.value})}),
                    h(Select, {value: s.event, style: {width: 145}, 'aria-label': '事件筛选', onChange: event => this.setState({event})},
                        ...[['all', '全部事件'], ['attention', '高危提示'], ...Object.entries(names)].map(([value, label]) => h(Select.Option, {key: value, value}, label))),
                    h('label', {className: 'ant-checkbox-wrapper'}, h('span', {className: 'ant-checkbox' + (s.marked ? ' ant-checkbox-checked' : '')}, h('input', {className: 'ant-checkbox-input', type: 'checkbox', checked: s.marked, onChange: event => this.setState({marked: event.target.checked})}), h('span', {className: 'ant-checkbox-inner'})), h('span', null, '只看已标记')),
                    h(Button, {htmlType: 'submit', type: 'primary', icon: 'search'}, '查询'),
                    h(Button, {icon: 'reload', onClick: () => this.load()}, '刷新'),
                    h(Button, {icon: 'setting', disabled: !s.thresholds, onClick: () => this.setState({settingsOpen: true, draft: {...s.thresholds}, settingsError: ''})}, '阈值设置'),
                    h(Button, {icon: 'question-circle', disabled: !s.thresholds, onClick: () => this.setState({rules: true})}, '提示规则')),
                h('div', {className: 'sa-summary text-muted'},
                    '最近 3 天 · 普通订阅',
                    summary && h(React.Fragment, null, h('span', null, '用户 ' + summary.users), h('span', null, '请求 ' + summary.requests), tag('高危提示 ' + summary.attention_users, 'red'),
                        ...[['multi_ip_users', '多 IP'], ['geo_users', '异地'], ['frequent_users', '频繁'], ['multi_ua_users', '多 UA'], ['marked_users', '已标记']].map(([key, label]) => h('span', {key}, label + ' ' + summary[key])))),
                s.error && h('div', {className: 'alert alert-danger', role: 'alert'}, s.error),
                h(Table, {rowKey: 'user_id', columns: this.columns(), dataSource: s.rows, loading: s.loading, scroll: {x: 1250},
                    locale: {emptyText: s.error ? '加载失败，请重试' : '没有符合条件的订阅记录'},
                    pagination: {current: s.page, pageSize: s.pageSize, total: s.total, size: 'small', showSizeChanger: true, pageSizeOptions: ['10', '20', '50'], showTotal: total => '共 ' + total + ' 位用户'},
                    onChange: page => this.load(page.pageSize !== s.pageSize ? 1 : page.current, page.pageSize)}),
                s.meta && h('div', {className: 'sa-footnote text-muted'}, '统计时间：' + s.meta.as_of + '（' + s.meta.timezone + '） · 不包含 APP 订阅；提示仅供排查，不自动封禁。'),
                h(Modal, {className: 'sa-scroll-modal', title: '用户标记', visible: !!s.active, onCancel: close, confirmLoading: s.saving, maskClosable: !s.saving, closable: !s.saving, keyboard: !s.saving,
                    footer: [s.active && s.active.is_marked && h(Button, {key: 'unmark', disabled: s.saving, onClick: () => this.save(false)}, '取消标记'), h(Button, {key: 'cancel', disabled: s.saving, onClick: close}, '关闭'), h(Button, {key: 'save', type: 'primary', loading: s.saving, onClick: () => this.save(true)}, '保存标记')]},
                    s.active && h('p', null, s.active.email + ' · UID ' + s.active.user_id),
                    h(Input.TextArea, {rows: 5, maxLength: 500, value: s.note, 'aria-label': '排查备注', placeholder: '排查备注（最多500字）', onChange: event => this.setState({note: event.target.value})}),
                    s.markError && h('p', {className: 'text-danger', role: 'alert'}, s.markError)),
                h(Modal, {className: 'sa-scroll-modal', title: '阈值设置', visible: s.settingsOpen, okText: '保存并应用', cancelText: '取消', confirmLoading: s.settingsSaving, closable: !s.settingsSaving, maskClosable: !s.settingsSaving, keyboard: !s.settingsSaving, cancelButtonProps: {disabled: s.settingsSaving}, onOk: () => this.saveSettings(), onCancel: () => { if (!s.settingsSaving) this.setState({settingsOpen: false}); }},
                    h('p', {className: 'text-muted'}, '分析范围固定为最近 3 天。达到任一阈值即提示；保存后对所有管理员生效，不修改原始日志。'),
                    ...Object.entries(thresholdLabels).map(([key, label]) => h('div', {className: 'form-group', key}, h('label', {htmlFor: 'sa-' + key}, label + ' ≥'), h(Input, {id: 'sa-' + key, type: 'number', min: key.startsWith('frequent_') ? 1 : 2, max: 100000, step: 1, value: s.draft[key], onChange: event => this.setState({draft: {...s.draft, [key]: event.target.value}})}))),
                    s.settingsError && h('p', {className: 'text-danger', role: 'alert'}, s.settingsError)),
                h(Modal, {className: 'sa-scroll-modal', title: '高危提示规则', visible: s.rules, footer: null, onCancel: () => this.setState({rules: false})},
                    h('p', null, '多 IP：10 分钟 ≥ ' + limits.ip_10m + ' 个，或 3 天 ≥ ' + limits.ip_3d + ' 个不同 IP。'),
                    h('p', null, '异地拉取：3 天内至少两个不同 IP，且已知国家数 ≥ ' + limits.countries_3d + ' 或城市数 ≥ ' + limits.cities_3d + '；未知归属地不计入。'),
                    h('p', null, '频繁拉取：2 分钟 ≥ ' + limits.frequent_2m + ' 次，或 5 分钟 ≥ ' + limits.frequent_5m + ' 次，或 1 小时 ≥ ' + limits.frequent_1h + ' 次。'),
                    h('p', null, '多 UA：3 天 ≥ ' + limits.ua_3d + ' 种不同的非空 UA 字符串。UA 不等于设备数，版本变化也可能产生不同 UA。'),
                    h('p', null, '满足任一规则即提示。异地以日志中的 IP 归属地为准，代理、移动网络及数据库误差可能影响结果；请结合订阅记录人工判断。'),
                    h('p', null, '上方统计栏展示最近3天总体数据，不随列表筛选变化。次数为鉴权后的普通订阅请求，不代表下载成功；历史弹窗展示全部现存普通订阅记录。'),
                    s.meta && h('p', null, '现存最早记录：' + (s.meta.retained_from || '暂无')))));
        }
    };
};
