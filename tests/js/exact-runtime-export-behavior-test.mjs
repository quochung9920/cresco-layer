import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const source = fs.readFileSync(new URL('../../assets/exact-runtime-export.js', import.meta.url), 'utf8');
const REST = 'https://example.test/wp-json/cresco-layer/v1';
const exportUrl = REST + '/documents/3/export?scope=subtree&selected=abc123';

function basePackage() {
  return {
    schema: 'cresco-layer-ai-package/v2',
    manifest: { postId: 3, contextProfile: 'smart' },
    document: {
      content: [ { id: 'abc123', elType: 'container', settings: {}, elements: [] } ]
    },
    elementContext: [],
    designSystem: {
      system_colors: [ { _id: 'primary', color: '#0F172A' } ],
      custom_colors: [],
      system_typography: [],
      custom_typography: [],
      container_width: { unit: '%', size: 100 },
      container_padding: { unit: 'custom', left: 'clamp(32px, 2.5vw, 48px)', right: 'clamp(32px, 2.5vw, 48px)' },
      button_background_color: '#2563EB',
      form_field_background_color: '#FFFFFF'
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
      elements: {
        container: { detailLoaded: false }
      }
    },
    widgetCatalog: { heading: { detailLoaded: true } },
    elementCatalog: { container: { detailLoaded: true } },
    relevantCapabilities: {},
    contextResolver: { profile: 'smart' },
    capabilities: {},
    instructions: 'Return JSON only.'
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

function makeContext({ failDetail = false, storedProfile = null } = {}) {
  const calls = [];
  const fetchImpl = async (input) => {
    const url = typeof input === 'string' ? input : input.url;
    calls.push(url);
    if (url === exportUrl) {
      return new Response(JSON.stringify(basePackage()), {
        status: 200,
        headers: { 'content-type': 'application/json' }
      });
    }
    if (url.startsWith(REST + '/elementor-catalog/')) {
      if (failDetail) {
        return new Response(JSON.stringify({ message: 'catalog unavailable' }), {
          status: 500,
          headers: { 'content-type': 'application/json' }
        });
      }
      const name = decodeURIComponent(url.split('/').pop());
      return new Response(JSON.stringify({ entry: detailEntry(name) }), {
        status: 200,
        headers: { 'content-type': 'application/json' }
      });
    }
    throw new Error('Unexpected fetch ' + url);
  };

  const storage = new Map();
  if (storedProfile) storage.set('cresco-layer-ai-context-profile', storedProfile);
  const document = {
    readyState: 'loading',
    documentElement: {},
    addEventListener() {},
    getElementById() { return null; }
  };
  const window = {
    crescoLayerEditor: { restRoot: REST + '/', nonce: 'nonce' },
    fetch: fetchImpl,
    localStorage: {
      getItem(key) { return storage.has(key) ? storage.get(key) : null; },
      setItem(key, value) { storage.set(key, String(value)); }
    },
    MutationObserver: null
  };
  const context = vm.createContext({
    window,
    document,
    MutationObserver: undefined,
    Response,
    Headers,
    URL,
    Promise,
    console,
    setTimeout,
    clearTimeout,
    encodeURIComponent,
    decodeURIComponent
  });
  vm.runInContext(source, context, { filename: 'exact-runtime-export.js' });
  return { window, calls };
}

{
  const { window, calls } = makeContext();
  assert.equal(window.CrescoLayerExactRuntimeExport.getProfile(), 'exact');
  const response = await window.fetch(exportUrl);
  assert.equal(response.status, 200);
  const payload = await response.json();
  assert.equal(payload.manifest.contextProfile, 'exact-runtime');
  assert.equal(payload.contextResolver.profile, 'exact-runtime');
  assert.equal(payload.capabilityLock.mode, 'runtime-exact');
  assert.equal(payload.capabilityLock.inventControls, false);
  assert.equal(payload.capabilityLock.inventResponsiveSuffixes, false);
  assert.equal(payload.runtimeCapabilities.mode, 'exact-runtime');
  assert.ok(payload.runtimeCapabilities.widgets.heading.detailLoaded);
  assert.ok(payload.runtimeCapabilities.widgets.button.detailLoaded);
  assert.ok(payload.runtimeCapabilities.widgets.form.detailLoaded);
  assert.ok(payload.runtimeCapabilities.elements.container.detailLoaded);
  assert.equal(payload.siteDesignContext.source, 'active-elementor-kit');
  assert.match(payload.instructions, /Never invent or infer an Elementor control key/);
  assert.ok(calls.some((url) => url.includes('/elementor-catalog/widget/button')));
  assert.ok(calls.some((url) => url.includes('/elementor-catalog/element/container')));
}

{
  const { window, calls } = makeContext({ storedProfile: 'smart' });
  assert.equal(window.CrescoLayerExactRuntimeExport.getProfile(), 'smart');
  const response = await window.fetch(exportUrl);
  const payload = await response.json();
  assert.equal(payload.manifest.contextProfile, 'smart');
  assert.equal(payload.runtimeCapabilities, undefined);
  assert.equal(calls.filter((url) => url.includes('/elementor-catalog/')).length, 0);
}

{
  const { window } = makeContext({ failDetail: true });
  const response = await window.fetch(exportUrl);
  assert.equal(response.status, 502);
  const payload = await response.json();
  assert.equal(payload.code, 'cresco_exact_runtime_export_failed');
  assert.match(payload.message, /catalog unavailable/);
}

console.log('PASS: exact runtime AI export behavior');
