import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const source = fs.readFileSync(new URL('../../assets/export-target-sync.js', import.meta.url), 'utf8');
const calls = { autosave: [], fetch: [], listeners: [] };
let statusCall = 0;
const qualitySmall = { textContent: '' };

const document = {
  querySelector(selector) {
    if (selector.includes('cresco-export-scope')) return { value: 'subtree' };
    if (selector.includes('data-cresco-ai-quality')) return { querySelector: () => qualitySmall };
    return null;
  },
  getElementById() { return null; },
  createElement() { return { className: '', textContent: '', hidden: true }; },
  body: { appendChild() {} },
  addEventListener(type, callback, capture) { calls.listeners.push({ type, callback, capture }); },
};

const window = {
  crescoLayerEditor: { postId: 22, restRoot: 'https://example.test/wp-json/cresco-layer/v1', nonce: 'nonce' },
  CrescoLayerEditorBridge: { getDiagnostics: () => ({ selectedElementId: 'f82af75' }) },
  $e: {
    run(command, args) {
      calls.autosave.push({ command, args });
      return Promise.resolve(true);
    },
  },
  fetch(url) {
    calls.fetch.push(url);
    statusCall += 1;
    const body = statusCall === 1
      ? { schema: 'cresco-export-target-status/v1', state: 'sync-required', ready: false }
      : { schema: 'cresco-export-target-status/v1', state: 'ready', ready: true };
    return Promise.resolve({ ok: true, json: () => Promise.resolve(body) });
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

assert.ok(window.CrescoLayerExportTargetSync, 'Target sync API must be exposed.');
assert.equal(calls.listeners.length, 1, 'Only one passive startup listener should be registered.');
assert.equal(calls.listeners[0].type, 'click');
assert.equal(calls.listeners[0].capture, true, 'Export guard must run in capture phase.');

const result = await window.CrescoLayerExportTargetSync.preflight();
assert.equal(result.ready, true, 'Preflight must wait until server-side target state becomes ready.');
assert.equal(calls.autosave.length, 1, 'Preflight should force exactly one Elementor autosave per export attempt.');
assert.equal(calls.autosave[0].command, 'document/save/auto');
assert.equal(calls.autosave[0].args.force, true);
assert.equal(calls.fetch.length, 2, 'A lagging target should be rechecked, but with bounded status requests.');
assert.match(calls.fetch[0], /documents\/22\/export-target-status/);
assert.match(calls.fetch[0], /scope=subtree/);
assert.match(calls.fetch[0], /selected=f82af75/);
assert.equal(window.CrescoLayerExportTargetSync.getState().lastAutosave, 'completed');
assert.equal(window.CrescoLayerExportTargetSync.getState().lastTarget, 'f82af75');
assert.match(qualitySmall.textContent, /Elementor synchronized/);

console.log('Cresco Layer export target sync behavior passed.');
