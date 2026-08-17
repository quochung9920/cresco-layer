import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const source = fs.readFileSync(new URL('../../assets/export-error-diagnostics.js', import.meta.url), 'utf8');
const bootstrap = fs.readFileSync(new URL('../../assets/editor-bootstrap.js', import.meta.url), 'utf8');

assert.match(source, /cresco-export-client-diagnostic\/v1/, 'Client diagnostic schema is missing.');
assert.match(source, /X-Cresco-Request-Id/, 'Export requests need a correlation ID.');
assert.match(source, /cresco_export_fatal/, 'Fatal PHP payload normalization is missing.');
assert.match(source, /isServerFailurePayload/, 'Successful HTTP responses carrying fatal payloads must be detected.');
assert.match(source, /getLastError/, 'Last export diagnostic must be inspectable from DevTools.');
assert.ok(
  bootstrap.indexOf("'export-error-diagnostics.js'") < bootstrap.indexOf("'exact-runtime-export.js'"),
  'Backend diagnostics must wrap fetch before Exact Runtime so fatal payloads are preserved.'
);

async function runWithFetch(fetchImpl) {
  const errors = [];
  const context = {
    window: {
      crescoLayerEditor: { restRoot: 'http://localhost/wp-json/cresco-layer/v1' },
      fetch: fetchImpl,
      crypto: { randomUUID: () => '12345678-1234-1234-1234-123456789abc' },
    },
    Response,
    Headers,
    Date,
    Math,
    JSON,
    Promise,
    String,
    console: { error: (...args) => errors.push(args) },
  };
  context.window.window = context.window;
  vm.createContext(context);
  vm.runInContext(source, context);
  return { window: context.window, errors };
}

{
  const fatal = {
    code: 'cresco_export_fatal',
    message: 'Cresco export failed at rest-response-serialization [CX-SERVER].',
    data: {
      status: 500,
      crescoDiagnostic: { errorId: 'CX-SERVER', stage: 'rest-response-serialization' },
    },
  };
  const runtime = await runWithFetch(async () => new Response(JSON.stringify(fatal), {
    status: 200,
    headers: { 'content-type': 'application/json' },
  }));
  const response = await runtime.window.fetch('http://localhost/wp-json/cresco-layer/v1/documents/22/export?scope=subtree');
  assert.equal(response.status, 500, 'A fatal body delivered with HTTP 200 must be normalized to 500.');
  assert.equal((await response.json()).code, 'cresco_export_fatal');
  assert.equal(runtime.window.CrescoLayerExportDiagnostics.getLastError().errorId, 'CX-SERVER');
}

{
  const runtime = await runWithFetch(async () => new Response('<html>PHP fatal page</html>', {
    status: 500,
    headers: { 'x-cresco-diagnostic-stage': 'context-capability-details' },
  }));
  const response = await runtime.window.fetch('http://localhost/wp-json/cresco-layer/v1/documents/22/export?scope=subtree');
  const body = await response.json();
  assert.equal(response.status, 500);
  assert.match(body.message, /context-capability-details/);
  assert.match(body.message, /CX-12345678123412341234/);
}

console.log('Cresco Layer export error diagnostics contract passed.');
