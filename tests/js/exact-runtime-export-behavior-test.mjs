import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const source = fs.readFileSync(new URL('../../assets/exact-runtime-export.js', import.meta.url), 'utf8');
const REST = 'https://example.test/wp-json/cresco-layer/v1';
const exportUrl = REST + '/documents/3/export?scope=subtree&selected=abc123';
const targetStatusUrl = REST + '/documents/3/export-target-status?scope=subtree&selected=abc123';

function basePackage({ requiredWidget = '' } = {}) {
  const content = requiredWidget
    ? [ { id: 'abc123', elType: 'widget', widgetType: requiredWidget, settings: {}, elements: [] } ]
    : [ { id: 'abc123', elType: 'container', settings: {}, elements: [] } ];
  return {
    schema: 'cresco-layer-ai-package/v2',
    manifest: { postId: 3, contextProfile: 'full' },
    document: { content },
    elementContext: [],
    designSystem: {
      system_colors: [ { _id: 'primary', color: '#0F172A' } ],
      custom_colors: [], system_typography: [], custom_typography: [],
      container_width: { unit: '%', size: 100 },
      container_padding: { unit: 'px', left: 32, right: 32 }
    },
    siteContext: { breakpoints: { mobile: { value: 767 } } },
    layoutContext: { responsiveFoundation: { policy: 'cresco-responsive-foundation/v2' } },
    registryIndex: {
      controlMetadataVersion: 5,
      widgets: {
        heading: { detailLoaded: false },
        button: { detailLoaded: false },
        form: { detailLoaded: false }
      },
      elements: { container: { detailLoaded: false } }
    },
    widgetCatalog: { heading: detailEntry('heading') },
    elementCatalog: requiredWidget ? {} : { container: detailEntry('container') },
    relevantCapabilities: {}, contextResolver: { profile: 'full' }, capabilities: {}, instructions: 'Return JSON only.'
  };
}

function detailEntry(name) {
  return {
    name,
    detailLoaded: true,
    controls: {
      width: { type: 'slider', responsive: true, size_units: ['px', '%'], range: { px: { min: 0, max: 2000 } } },
      custom_css: { type: 'code', responsive: false }
    },
    defaultSettings: {}
  };
}

function makeContext({ failNames = [], storedProfile = null, requiredWidget = '' } = {}) {
  const calls = [];
  const fetchImpl = async (input) => {
    const url = typeof input === 'string' ? input : input.url;
    calls.push(url);
    if (url === exportUrl) {
      return new Response(JSON.stringify(basePackage({ requiredWidget })), { status: 200, headers: { 'content-type': 'application/json' } });
    }
    if (url === targetStatusUrl) {
      return new Response(JSON.stringify({ schema: 'cresco-export-target-status/v1', ready: true }), { status: 200, headers: { 'content-type': 'application/json' } });
    }
    if (url.startsWith(REST + '/elementor-catalog/')) {
      const name = decodeURIComponent(url.split('/').pop());
      if (failNames.includes(name)) {
        return new Response(JSON.stringify({ message: 'catalog unavailable for ' + name }), { status: 500, headers: { 'content-type': 'application/json' } });
      }
      return new Response(JSON.stringify({ entry: detailEntry(name) }), { status: 200, headers: { 'content-type': 'application/json' } });
    }
    throw new Error('Unexpected fetch ' + url);
  };

  const storage = new Map();
  if (storedProfile) storage.set('cresco-layer-ai-context-profile', storedProfile);
  const document = { readyState: 'loading', documentElement: {}, addEventListener() {}, getElementById() { return null; } };
  const window = {
    crescoLayerEditor: { restRoot: REST + '/', nonce: 'nonce' },
    fetch: fetchImpl,
    localStorage: { getItem(key) { return storage.has(key) ? storage.get(key) : null; }, setItem(key, value) { storage.set(key, String(value)); } },
    MutationObserver: null
  };
  const context = vm.createContext({
    window, document, MutationObserver: undefined, Response, Headers, URL, Promise, console,
    setTimeout, clearTimeout, encodeURIComponent, decodeURIComponent
  });
  vm.runInContext(source, context, { filename: 'exact-runtime-export.js' });
  return { window, calls };
}

{
  const { window, calls } = makeContext();
  const response = await window.fetch(exportUrl);
  assert.equal(response.status, 200);
  const payload = await response.json();
  assert.equal(payload.runtimeCapabilities.coverage.requiredComplete, true);
  assert.equal(payload.runtimeCapabilities.source, 'server-reuse-plus-live-elementor-catalog');
  assert.ok(payload.runtimeCapabilities.widgets.heading.detailLoaded);
  assert.ok(payload.runtimeCapabilities.elements.container.detailLoaded);
  assert.ok(payload.runtimeCapabilities.widgets.button.detailLoaded);
  assert.ok(payload.runtimeCapabilities.widgets.form.detailLoaded);
  assert.ok(!calls.some((url) => url.includes('/elementor-catalog/widget/heading')), 'preloaded heading must be reused');
  assert.ok(!calls.some((url) => url.includes('/elementor-catalog/element/container')), 'preloaded container must be reused');
  assert.ok(calls.some((url) => url.includes('/elementor-catalog/widget/button')));
}

{
  const { window } = makeContext({ failNames: ['button', 'form'] });
  const response = await window.fetch(exportUrl);
  assert.equal(response.status, 200, 'optional construction failures must not kill an otherwise safe export');
  const payload = await response.json();
  assert.equal(payload.runtimeCapabilities.coverage.requiredComplete, true);
  assert.equal(payload.runtimeCapabilities.coverage.optionalPartial, true);
  assert.ok(payload.runtimeCapabilities.coverage.fetch.widgets.failedOptional.length >= 1);
  assert.equal(payload.capabilityLock.optionalConstructionPartial, true);
}

{
  const { window } = makeContext({ failNames: ['button'], requiredWidget: 'button' });
  const response = await window.fetch(exportUrl);
  assert.equal(response.status, 502, 'required target capability failure must remain fail-closed');
  const payload = await response.json();
  assert.equal(payload.code, 'cresco_exact_runtime_export_failed');
  assert.match(payload.message, /catalog unavailable for button/);
}

{
  const { window, calls } = makeContext();
  const response = await window.fetch(targetStatusUrl);
  assert.equal(response.status, 200, 'target preflight endpoint must never be treated as an export package');
  const payload = await response.json();
  assert.equal(payload.schema, 'cresco-export-target-status/v1');
  assert.equal(calls.filter((url) => url.includes('/elementor-catalog/')).length, 0);
}

{
  const { window, calls } = makeContext({ storedProfile: 'smart' });
  const response = await window.fetch(exportUrl);
  const payload = await response.json();
  assert.equal(payload.manifest.contextProfile, 'full');
  assert.equal(payload.runtimeCapabilities, undefined);
  assert.equal(calls.filter((url) => url.includes('/elementor-catalog/')).length, 0);
}

console.log('PASS: exact runtime AI export resilience behavior');
