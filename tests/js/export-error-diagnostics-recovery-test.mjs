import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const source = fs.readFileSync(new URL('../../assets/export-error-diagnostics.js', import.meta.url), 'utf8');
const REST = 'https://example.test/wp-json/cresco-layer/v1';
const fullUrl = REST + '/documents/3/export?scope=subtree&selected=abc123&context=full';

function makeContext({ exactRuntimeFailure = false } = {}) {
  const calls = [];
  const previousFetch = async (input) => {
    const url = typeof input === 'string' ? input : input.url;
    calls.push(url);
    if (url.includes('context=smart')) {
      return new Response(JSON.stringify({ schema: 'cresco-layer-ai-package/v2', manifest: { contextProfile: 'exact-runtime' } }), {
        status: 200, headers: { 'content-type': 'application/json' }
      });
    }
    if (exactRuntimeFailure) {
      return new Response(JSON.stringify({ code: 'cresco_exact_runtime_export_failed', message: 'required capability failed', data: { status: 502 } }), {
        status: 502, headers: { 'content-type': 'application/json', 'x-cresco-diagnostic-stage': 'exact-runtime-enrich' }
      });
    }
    return new Response(JSON.stringify({
      code: 'cresco_export_fatal',
      message: 'memory exhausted',
      data: { status: 500, crescoDiagnostic: { schema: 'cresco-export-diagnostic/v1', errorId: 'CX-first', stage: 'context-capability-details', memory: { peakBytes: 130000000, limit: '128M' } } }
    }), { status: 500, headers: { 'content-type': 'application/json', 'x-cresco-request-id': 'CX-first', 'x-cresco-diagnostic-stage': 'context-capability-details' } });
  };

  const document = { getElementById() { return null; } };
  const window = {
    crescoLayerEditor: { restRoot: REST + '/' },
    fetch: previousFetch,
    document,
    navigator: {},
    dispatchEvent() {},
    crypto: { randomUUID() { return '12345678-1234-1234-1234-123456789abc'; } }
  };
  const context = vm.createContext({
    window, document, navigator: window.navigator, Response, Headers, Promise, console,
    CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init?.detail; } },
    Date, Math, JSON, String, Number, encodeURIComponent, decodeURIComponent, setTimeout, clearTimeout
  });
  vm.runInContext(source, context, { filename: 'export-error-diagnostics.js' });
  return { window, calls };
}

{
  const { window, calls } = makeContext();
  const response = await window.fetch(fullUrl);
  assert.equal(response.status, 200);
  const payload = await response.json();
  assert.equal(payload.manifest.exportRecovery.used, true);
  assert.equal(payload.manifest.exportRecovery.fromContext, 'full');
  assert.equal(payload.manifest.exportRecovery.toContext, 'smart');
  assert.equal(calls.length, 2);
  assert.ok(calls[1].includes('context=smart'));
  assert.ok(calls[1].includes('cresco_recovery=1'));
  const last = window.CrescoLayerExportDiagnostics.getLastError();
  assert.equal(last.recovered, true);
  assert.equal(last.stage, 'recovered-smart-context');
}

{
  const { window, calls } = makeContext({ exactRuntimeFailure: true });
  const response = await window.fetch(fullUrl);
  assert.equal(response.status, 502);
  assert.equal(calls.length, 1, 'Exact Runtime target failures must not be retried with a weaker server context');
  const payload = await response.json();
  assert.match(payload.message, /required capability failed/);
  const last = window.CrescoLayerExportDiagnostics.getLastError();
  assert.equal(last.stage, 'exact-runtime-enrich');
}

console.log('PASS: export diagnostics automatic recovery behavior');
