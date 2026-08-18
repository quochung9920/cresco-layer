import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const source = fs.readFileSync(new URL('../../assets/export-target-sync.js', import.meta.url), 'utf8');
const calls = { autosave: 0, fetch: 0, listeners: 0 };

const document = {
  querySelector(selector) {
    if (selector.includes('cresco-export-scope')) return { value: 'subtree' };
    return null;
  },
  getElementById() { return null; },
  createElement() { return { className: '', textContent: '', hidden: true }; },
  body: { appendChild() {} },
  addEventListener() { calls.listeners += 1; },
};

const window = {
  crescoLayerEditor: { postId: 3, restRoot: 'https://example.test/wp-json/cresco-layer/v1', nonce: 'nonce' },
  CrescoLayerEditorBridge: { getDiagnostics: () => ({ selectedElementId: 'b58d95b' }) },
  elementor: { getContainer() { return null; } },
  $e: {
    run() {
      calls.autosave += 1;
      return Promise.resolve(true);
    },
  },
  fetch() {
    calls.fetch += 1;
    return Promise.reject(new Error('stale target must be rejected before REST status polling'));
  },
};

const context = {
  window,
  document,
  console,
  Promise,
  String,
  parseInt,
  encodeURIComponent,
  Error,
  setTimeout(callback) { callback(); return 1; },
  clearTimeout() {},
};

vm.runInNewContext(source, context, { filename: 'assets/export-target-sync.js' });

await assert.rejects(
  window.CrescoLayerExportTargetSync.preflight(),
  /no longer exists in the live editor/,
  'A stale selected ID must stop before autosave/server export work begins.'
);

const state = window.CrescoLayerExportTargetSync.getState();
assert.equal(state.lastTarget, 'b58d95b');
assert.equal(state.lastStatus.state, 'stale-target');
assert.equal(state.lastStatus.retryable, false);
assert.equal(calls.autosave, 0, 'A confirmed stale target should not trigger an unnecessary autosave.');
assert.equal(calls.fetch, 0, 'A confirmed stale target should not reach the target-status endpoint.');

console.log('Cresco Layer stale export target behavior passed.');
