/* Use the same admin components and compact layout as subscription analysis. */
window.createConnectionAnalysisPage = function (n) {
    'use strict';
    const {React, h, Layout, Table, Button, Input, Select, muted, known, asnLabel, traffic, api} = window.createAnalysisUi(n);
    return class ConnectionAnalysis extends React.Component {
        constructor(props) {
            super(props);
            this.state = {q: '', days: 3, event: 'all', rows: [], summary: null, meta: null, total: 0, page: 1, pageSize: 20, loading: false, error: ''};
            this.filters = {q: '', days: 3, event: 'all'};
            this.sequence = 0;
            this.alive = true;
        }
        componentDidMount() { this.load(); }
        componentWillUnmount() { this.alive = false; this.sequence++; }
        async load(page = this.state.page, pageSize = this.state.pageSize) {
            const seq = ++this.sequence;
            this.setState({loading: true, rows: [], error: '', page, pageSize});
            try {
                const result = await api('/connection-analysis/fetch?' + new URLSearchParams({...this.filters, page, page_size: pageSize}));
                if (!this.alive || seq !== this.sequence) return;
                if (page > 1 && result.total <= (page - 1) * pageSize) return this.load(Math.max(1, Math.ceil(result.total / pageSize)), pageSize);
                this.setState({rows: result.data, total: result.total, summary: result.summary, meta: result.meta, loading: false});
            } catch (error) {
                if (this.alive && seq === this.sequence) this.setState({loading: false, error: error.message, summary: null, meta: null, total: 0});
            }
        }
        search(event) {
            if (event) event.preventDefault();
            this.filters = {q: this.state.q.trim(), days: this.state.days, event: this.state.event};
            this.load(1);
        }
        columns() {
            return [
                {title: '用户 / 流量', key: 'user', width: 230, render: (_, row) => h('div', null, row.email, muted('UID ' + row.user_id), traffic(row.traffic, this.state.meta && this.state.meta.traffic_scope))},
                {title: '连接 IP / 位置 / ASN', key: 'ips', width: 480, render: (_, row) => h('ol', {className: 'sa-ranking-list', tabIndex: 0, 'aria-label': '连接 IP 列表'},
                    ...(row.ips || []).map((item, index) => h('li', {className: 'sa-connection-item', key: item.ip},
                        h('span', {className: 'sa-ranking-position'}, index + 1),
                        h('div', {className: 'sa-ranking-value'}, item.ip,
                            h('div', {className: 'sa-ranking-location text-muted'}, [item.country, item.region].map(known).filter(Boolean).join(' · ') || '位置未知'),
                            h('div', {className: 'sa-ranking-asn text-muted'}, asnLabel(item.as_number))),
                        h('div', {className: 'sa-connection-seen text-muted'}, item.updated_at))))},
                {title: '连接概况', key: 'counts', width: 180, render: (_, row) => h('div', null,
                    h('div', null, 'IP ' + row.ip_count + ' 个 · 近24小时 ' + row.ips_1d + ' 个'),
                    muted('国家 ' + row.countries + ' / 地区 ' + row.regions + ' / ASN ' + row.asns))},
                {title: '首次记录 / 最近上报', key: 'times', width: 190, render: (_, row) => h('div', {className: 'sa-small'}, row.first_at || '—', h('br'), row.last_at || '—')}
            ];
        }
        render() {
            const s = this.state, summary = s.summary;
            return h(Layout, {...this.props, title: '连接 IP 分析'}, h('div', {id: 'connection-analysis', className: 'sa-analysis block border-bottom'},
                h('form', {className: 'sa-toolbar bg-white', onSubmit: event => this.search(event)},
                    h(Input, {size: 'small', className: 'sa-search', 'aria-label': '搜索连接用户', placeholder: '邮箱 / UID / IP / ASN / 国家或地区', maxLength: 100, value: s.q, onChange: event => this.setState({q: event.target.value})}),
                    h(Select, {size: 'small', className: 'sa-event-select', 'aria-label': '连接时间范围', value: s.days, onChange: days => this.setState({days})},
                        ...[[1, '最近 1 天'], [3, '最近 3 天'], [7, '最近 7 天'], [30, '最近 30 天'], [0, '全部记录']].map(([value, label]) => h(Select.Option, {key: value, value}, label))),
                    h(Select, {size: 'small', className: 'sa-event-select', 'aria-label': '连接筛选', value: s.event, onChange: event => this.setState({event})},
                        ...[['all', '全部用户'], ['multi_ip', '多个 IP（≥2）'], ['geo', '跨地区 IP']].map(([value, label]) => h(Select.Option, {key: value, value}, label))),
                    h(Button, {size: 'small', type: 'primary', htmlType: 'submit', icon: 'search'}, '查询'),
                    h(Button, {size: 'small', icon: 'reload', onClick: () => this.load()}, '刷新')),
                h('div', {className: 'sa-summary text-muted'},
                    (this.filters.days ? '最近 ' + this.filters.days + ' 天' : '全部记录') + ' · 节点连接 IP',
                    summary && h(React.Fragment, null,
                        h('span', null, '用户 ' + summary.users), h('span', null, '用户/IP 记录 ' + summary.ip_records),
                        h('span', null, '多个 IP 用户 ' + summary.multi_ip_users), h('span', null, '跨地区用户 ' + summary.geo_users))),
                s.error && h('div', {className: 'alert alert-danger', role: 'alert'}, s.error),
                h(Table, {size: 'small', rowKey: 'user_id', columns: this.columns(), dataSource: s.rows, loading: s.loading, scroll: {x: 1080},
                    locale: {emptyText: s.error ? '加载失败，请重试' : '暂无符合条件的连接 IP 上报'},
                    pagination: {size: 'small', current: s.page, pageSize: s.pageSize, total: s.total, showSizeChanger: true, pageSizeOptions: ['10', '20', '50'], showTotal: total => '共 ' + total + ' 位用户'},
                    onChange: page => this.load(page.pageSize !== s.pageSize ? 1 : page.current, page.pageSize)}),
                s.meta && h('div', {className: 'sa-footnote text-muted'},
                    s.meta.scope, h('div', null, s.meta.traffic_scope), h('div', null, '统计时间：' + s.meta.as_of + '（' + s.meta.timezone + '）'))));
        }
    };
};
