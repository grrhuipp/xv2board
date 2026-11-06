const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');
const bundle = fs.readFileSync(path.join(__dirname, '../../public/assets/admin/umi.js'), 'utf8');

function harness() {
  const requests = [];
  const cache = {};
  class Component {
    constructor(props) { this.props = props; this.refs = {}; }
    setState(next) { this.state = { ...this.state, ...next }; }
  }
  const React = { Component, Fragment: 'fragment', createElement: (type, props, ...children) => ({ type, props: props || {}, children }), cloneElement: (element, props) => ({ ...element, props }) };
  const widget = Object.assign(() => null, { Option: 'option', Item: Object.assign(() => null, { Meta: 'meta' }), TextArea: 'textarea' });
  const stub = { a: widget, c: () => value => value, e: () => undefined, f: () => false };
  function load(id) {
    if (cache[id]) return cache[id];
    if (id === 'q1tI') return React;
    if (id === 'p0pE') return Object.assign;
    if (id === 't3Un') return {
      a: async url => ({ code: 200, data: [{ id: 1, name: 'Clash', keywords: ['Clash'] }] }),
      b: async (url, data, json) => { requests.push({ url, data, json }); return { code: 200, data: true }; }
    };
    if (!['clientUaGroups', '3XVG', 'H9LU', 'uzXD', 'ykC2'].includes(id)) return stub;
    const marker = bundle.includes('    "' + id + '": function') ? '    "' + id + '": ' : '    ' + id + ': ';
    const start = bundle.indexOf(marker) + marker.length;
    assert(start >= marker.length, id + ' module exists');
    const end = bundle.indexOf('\n    },\n    ', start) + '\n    }'.length;
    let source = bundle.slice(start, end);
    if (id === 'uzXD') source = source.slice(0, -1) + 't.forms = [V, z, wTuic, wAnyTLS];}';
    const exports = cache[id] = {};
    vm.runInNewContext('(' + source + ')', { window: { settings: { secure_path: 'admin' } } })({}, exports, load);
    return exports;
  }
  load.r = () => {};
  load.d = (exports, key, getter) => Object.defineProperty(exports, key, { get: getter });
  load.n = value => { const getter = () => value; getter.a = value; return getter; };
  return { load, requests };
}
function elements(tree) {
  if (!tree || typeof tree !== 'object') return [];
  if (Array.isArray(tree)) return tree.flatMap(elements);
  return [tree, ...(tree.children || []).flatMap(elements)];
}

test('UA groups use the existing API and a multiple-choice selector', async () => {
  const { load, requests } = harness();
  const Selector = load('clientUaGroups').a;
  const selector = new Selector({ value: [1], onChange() {} });
  await selector.load();
  assert.equal(selector.state.groups[0].name, 'Clash');
  const selection = elements(selector.render()).find(el => el.props.mode === 'multiple');
  assert(selection);
  assert.equal(selection.props.allowClear, true);
  await selector.mutate('save', { name: 'Mobile', keywords: ['Loon'] });
  assert.equal(requests[0].url, '/admin/server/client-ua-group/save');
  assert.equal(requests[0].json, true);
});

test('all seven native node editors render UA groups and clear to an explicit null', () => {
  const { load } = harness();
  const Selector = load('clientUaGroups').a;
  const forms = [load('3XVG').a, load('H9LU').a, load('ykC2').a, ...load('uzXD').forms];
  assert.equal(forms.length, 7);
  for (const Form of forms) {
    const props = { record: { group_id: [], tags: [], client_ua_group_ids: [1], network: 'tcp', tls: 0, version: 2 }, children: {}, serverGroup: { groups: [] }, serverManage: { servers: [] } };
    for (const type of ['Vmess', 'Shadowsocks', 'Trojan', 'Vless', 'Hysteria', 'Tuic', 'AnyTLS']) props['server' + type] = { saveLoading: false };
    const form = new Form(props);
    const field = elements(form.render()).find(el => el.type === Selector);
    assert(field, Form.name + ' exposes UA group choices');
    field.props.onChange([2]);
    assert.equal(form.state.server.client_ua_group_ids[0], 2);
    field.props.onChange([]);
    assert.equal(form.state.server.client_ua_group_ids, null);
  }
  assert(!bundle.includes('server/v2node/'));
  assert(!bundle.includes('serverV2node'));
});

test('saving captures pending keywords and normalizes duplicates', async () => {
  const { load, requests } = harness();
  const selector = new (load('clientUaGroups').a)({ managerOnly: true });
  selector.state.pending = ' Mihomo ';
  await selector.mutate('save', { name: ' Desktop ', keywords: ['Clash', ' clash ', ''] });
  assert.equal(requests[0].data.name, 'Desktop');
  assert.equal(JSON.stringify(requests[0].data.keywords), JSON.stringify(['Clash', 'Mihomo']));
  assert.equal(selector.state.notice, '分组已保存');
  assert.equal(selector.state.busy, false);
});

test('empty groups are rejected before sending and busy actions do not duplicate', async () => {
  const { load, requests } = harness();
  const selector = new (load('clientUaGroups').a)({ managerOnly: true });
  await selector.mutate('save', { name: ' ', keywords: ['Clash'] });
  assert.equal(requests.length, 0);
  assert(selector.state.error);
  selector.state.busy = true;
  await selector.mutate('save', { name: 'Clash', keywords: ['Clash'] });
  assert.equal(requests.length, 0);
});
