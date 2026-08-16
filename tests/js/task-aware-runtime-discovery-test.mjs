import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../assets/exact-runtime-export.js', import.meta.url), 'utf8');

const sandbox = {
  window: {
    crescoLayerEditor: { restRoot: '/wp-json/cresco-layer/v1', nonce: 'x' },
    CrescoLayerAIIntent: { request: 'Create an FAQ accordion below this section' },
    localStorage: { getItem() { return null; }, setItem() {} },
    fetch: async () => ({ ok: true, clone() { return this; }, json: async () => ({}) }),
  },
  document: {
    readyState: 'loading',
    addEventListener() {},
    getElementById() { return null; },
    documentElement: null,
  },
  console,
  Set,
  Array,
  Object,
  String,
  Number,
  Math,
  JSON,
  Promise,
  Response: class {},
  Headers: class {},
};
sandbox.window.window = sandbox.window;
vm.createContext(sandbox);
vm.runInContext(source, sandbox);

const pkg = {
  registryIndex: {
    widgets: {
      heading: { title: 'Heading', categories: ['basic'], keywords: ['title'] },
      accordion: { title: 'Accordion', categories: ['general'], keywords: ['faq', 'toggle'] },
      button: { title: 'Button', categories: ['basic'], keywords: ['cta'] },
    },
    elements: { container: { title: 'Container' } },
  },
};

const discovered = sandbox.window.CrescoLayerExactRuntimeExport.discoverTaskWidgets(pkg);
if (!discovered.includes('accordion')) throw new Error('task-aware runtime discovery failed to include runtime accordion');
if (discovered.includes('invented-accordion')) throw new Error('task-aware runtime discovery invented a widget');
console.log('task-aware runtime discovery passed');
