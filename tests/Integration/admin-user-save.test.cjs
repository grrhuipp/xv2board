const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');
const root = path.resolve(__dirname, '../..');
const api = require(path.join(root, 'public/assets/admin/account-update.js'));

function browser() {
  const local = new Map([['authorization', 'old-session']]);
  const session = new Map();
  const storage = values => ({ getItem: key => values.get(key), setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key) });
  const state = { local, session, redirects: [], notices: [] };
  state.localStorage = storage(local);
  state.sessionStorage = storage(session);
  state.settings = { secure_path: 'fixture' };
  state.location = { origin: 'https://example.test', pathname: '/fixture', replace: url => state.redirects.push(url) };
  state.document = { createElement: () => ({ style: {}, setAttribute() {} }), body: { appendChild: element => state.notices.push(element) } };
  state.setTimeout = () => {};
  state.V2boardAccountUpdate = { handle: result => api.handle(result, state) };
  return state;
}

async function runActualSaveEffect(result, state) {
  const bundle = fs.readFileSync(path.join(root, 'public/assets/admin/umi.js'), 'utf8');
  const start = bundle.indexOf('hlQx: function(e, t, n) {') + 'hlQx: '.length;
  assert(start > 10);
  const end = bundle.indexOf('\n    },\n    ', start) + '\n    }'.length;
  const exports = {};
  const actions = [];
  const requests = [];
  function load(name) {
    if (name === 'p0pE') return Object.assign;
    if (name === 'yWgo') return { e: () => undefined };
    if (name === 't3Un') return { b: (url, data) => { requests.push({ url, data }); return Promise.resolve(result); } };
    return {};
  }
  load.r = () => {};
  load.n = value => { const getter = () => value; getter.a = getter; return getter; };
  const factory = vm.runInNewContext('(' + bundle.slice(start, end) + ')', { window: state });
  factory({}, exports, load);
  const generator = exports.default.effects.update({ params: { transfer_enable: 1, u: 0, d: 0, balance: 0, commission_balance: 0 }, callback: () => actions.push({ type: 'closed' }) }, { put: action => { actions.push(action); return action; } });
  let step = generator.next();
  while (!step.done) step = generator.next(await step.value);
  return { actions, requests };
}

test('self password save redirects after success without an expired-session list request', async () => {
  const state = browser();
  const result = await runActualSaveEffect({ code: 200, data: true, reauthentication_required: true }, state);
  assert.equal(result.requests.length, 1);
  assert.equal(result.actions.some(action => action.type === 'fetch'), false);
  assert.equal(state.local.has('authorization'), false);
  assert.deepEqual(state.redirects, ['https://example.test/fixture']);
  assert.equal(result.actions.filter(action => action.type === 'setState').at(-1).payload.updateLoading, false);
  api.showNotice(state);
  assert.equal(state.notices.length, 1);
  assert.match(state.notices[0].textContent, /已保存.*重新登录/);
  api.showNotice(state);
  assert.equal(state.notices.length, 1);
});

test('saving another account refreshes the list and preserves the current login', async () => {
  const state = browser();
  const result = await runActualSaveEffect({ code: 200, data: true }, state);
  assert.equal(result.actions.some(action => action.type === 'fetch'), true);
  assert.equal(result.actions.some(action => action.type === 'closed'), true);
  assert.equal(state.local.get('authorization'), 'old-session');
  assert.deepEqual(state.redirects, []);
});

test('failed saves do not clear authentication, redirect, refresh or display success', async () => {
  const state = browser();
  const result = await runActualSaveEffect({ code: 500, reauthentication_required: true }, state);
  assert.equal(result.actions.some(action => action.type === 'fetch'), false);
  assert.equal(state.local.get('authorization'), 'old-session');
  assert.deepEqual(state.redirects, []);
  api.showNotice(state);
  assert.equal(state.notices.length, 0);
});
