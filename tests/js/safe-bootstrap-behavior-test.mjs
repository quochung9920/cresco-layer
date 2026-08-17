import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const source = fs.readFileSync(new URL('../../assets/editor-bootstrap.js', import.meta.url), 'utf8');

function environment(options = {}) {
  const windowListeners = {};
  const scripts = [];
  const elementsById = new Map();
  let timeoutCallback = null;

  class FakeElement {
    constructor(tag) {
      this.tagName = String(tag || '').toLowerCase();
      this.dataset = {};
      this.listeners = {};
      this.hidden = false;
      this.innerHTML = '';
      this.textContent = '';
      this.disabled = false;
      this.id = '';
      this.src = '';
    }
    addEventListener(name, callback) { (this.listeners[name] ||= []).push(callback); }
    fire(name, ...args) { for (const callback of this.listeners[name] || []) callback(...args); }
    appendChild(child) {
      if (child.id) elementsById.set(child.id, child);
      if (child.tagName === 'script') {
        scripts.push(child);
        if (String(child.src).includes('ai-panel.js')) {
          window.CrescoLayerAIPanel = { open() {} };
        }
        queueMicrotask(() => child.fire('load'));
      }
      return child;
    }
  }

  const head = new FakeElement('head');
  const body = new FakeElement('body');
  const documentElement = new FakeElement('html');
  const document = {
    head,
    body,
    documentElement,
    createElement: (tag) => new FakeElement(tag),
    getElementById: (id) => elementsById.get(id) || null,
    querySelector(selector) {
      if (selector.startsWith('script[data-cresco-lazy=')) {
        const key = selector.match(/"([^"]+)"/)?.[1];
        return scripts.find((script) => script.dataset.crescoLazy === key) || null;
      }
      return null;
    },
  };

  const filters = [];
  const makeElementor = () => ({
    hooks: { addFilter(name, callback) { filters.push([name, callback]); } },
    selection: { getElements() { return []; } },
    channels: { editor: { request() { return null; } } },
  });

  const window = {
    crescoLayerEditor: {
      assetBaseUrl: '/assets/',
      version: '0.24.0',
      safeMode: !!options.safeMode,
      bootstrap: { elementorReadyTimeoutMs: 8000 },
    },
    elementor: options.readyAtStart ? makeElementor() : undefined,
    addEventListener(name, callback) { (windowListeners[name] ||= []).push(callback); },
    dispatchEvent() {},
    console,
  };

  const context = {
    window,
    document,
    console,
    Promise,
    Array,
    Object,
    String,
    Number,
    Math,
    RegExp,
    Error,
    encodeURIComponent,
    CustomEvent: class {},
    queueMicrotask,
    setTimeout(callback) { timeoutCallback = callback; return 1; },
    clearTimeout() {},
  };
  if (window.elementor) context.elementor = window.elementor;

  vm.createContext(context);
  vm.runInContext(source, context, { filename: 'assets/editor-bootstrap.js' });

  return {
    window,
    document,
    scripts,
    filters,
    windowListeners,
    getTimeout: () => timeoutCallback,
    makeElementor,
    setElementor(elementor) { window.elementor = elementor; context.elementor = elementor; },
  };
}

const startup = environment();
assert.equal(startup.scripts.length, 0, 'No heavy script may load before Elementor is ready.');
assert.equal(startup.document.getElementById('cresco-safe-launcher'), null, 'Launcher must not be injected before Elementor is ready.');

startup.setElementor(startup.makeElementor());
for (const callback of startup.windowListeners['elementor/init'] || []) callback();
assert.ok(startup.document.getElementById('cresco-safe-launcher'), 'Safe launcher should appear after Elementor becomes ready.');
assert.equal(startup.scripts.length, 0, 'Elementor ready alone must not load the external exchange pipeline.');
assert.equal(startup.window.CrescoLayerSafeBootstrap.getState().status, 'ready');

await startup.window.CrescoLayerSafeBootstrap.open('export', '');
assert.ok(startup.scripts.length >= 10, 'External exchange scripts should load only after explicit user action.');
assert.equal(startup.window.CrescoLayerSafeBootstrap.getState().loaded, true);

const timeout = environment();
timeout.getTimeout()();
assert.equal(timeout.window.CrescoLayerSafeBootstrap.getState().status, 'passive-timeout');
timeout.setElementor(timeout.makeElementor());
for (const callback of timeout.windowListeners['elementor/init'] || []) callback();
assert.equal(timeout.window.CrescoLayerSafeBootstrap.getState().activated, false, 'A timed-out bootstrap must not wake up later.');
assert.equal(timeout.window.CrescoLayerSafeBootstrap.getState().passive, true);
assert.equal(timeout.scripts.length, 0);

const safe = environment({ safeMode: true });
assert.equal(safe.window.CrescoLayerSafeBootstrap.getState().status, 'safe-mode');
assert.equal(safe.window.CrescoLayerSafeBootstrap.getState().passive, true);
assert.equal(safe.document.getElementById('cresco-safe-launcher'), null);
assert.equal(safe.scripts.length, 0);

console.log('Cresco Layer safe bootstrap behavior passed.');
