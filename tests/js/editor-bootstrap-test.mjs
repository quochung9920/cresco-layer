import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const source = fs.readFileSync(new URL('../../assets/editor.js', import.meta.url), 'utf8');
const elementsById = new Map();
const listeners = {};

class FakeElement {
	constructor(tag) {
		this.tagName = String(tag || '').toUpperCase();
		this.children = [];
		this.dataset = {};
		this.attributes = {};
		this.hidden = false;
		this.nodeType = 1;
		this._buttons = [];
		this._innerHTML = '';
	}
	appendChild(child) {
		this.children.push(child);
		if (child.id) elementsById.set(child.id, child);
		return child;
	}
	remove() {}
	click() {}
	addEventListener() {}
	setAttribute(name, value) { this.attributes[name] = String(value); }
	getAttribute(name) { return this.attributes[name] ?? null; }
	closest() { return null; }
	querySelector() { return null; }
	querySelectorAll(selector) {
		if (selector === 'button') return this._buttons;
		return [];
	}
	set innerHTML(value) {
		this._innerHTML = String(value);
		if (this._innerHTML.includes('data-cresco="widget"')) {
			this._buttons = [new FakeElement('button'), new FakeElement('button'), new FakeElement('button')];
		}
	}
	get innerHTML() { return this._innerHTML; }
}

const documentElement = new FakeElement('html');
const document = {
	body: new FakeElement('body'),
	head: new FakeElement('head'),
	documentElement,
	readyState: 'complete',
	hidden: false,
	createElement: (tag) => new FakeElement(tag),
	getElementById: (id) => elementsById.get(id) || null,
	querySelector: () => null,
	querySelectorAll: () => [],
	addEventListener: () => {},
};

const window = {
	crescoLayerEditor: {
		version: '0.2.1',
		postId: 42,
		restRoot: 'https://example.test/wp-json/cresco-layer/v1',
		nonce: 'nonce',
	},
	location: { search: '?post=42' },
	addEventListener(type, callback) { (listeners[type] ||= []).push(callback); },
	confirm: () => true,
	console,
};

let intervalCallback = null;
const context = {
	window,
	document,
	console,
	Promise,
	URL,
	URLSearchParams,
	Blob,
	JSON,
	Date,
	Object,
	Array,
	String,
	parseInt,
	setTimeout: () => 1,
	clearTimeout: () => {},
	setInterval(callback) { intervalCallback = callback; return 1; },
	clearInterval: () => {},
	fetch: () => Promise.reject(new Error('not used')),
};

vm.runInNewContext(source, context, { filename: 'assets/editor.js' });

assert.ok(window.CrescoLayerEditorBridge, 'Bridge should be exposed immediately.');
assert.equal(window.CrescoLayerEditorBridge.scriptLoaded, true, 'Bridge should mark the editor script as loaded.');
assert.ok(document.getElementById('cresco-layer-editor-tools'), 'Floating Cresco tools must render before Elementor is ready.');
assert.equal(window.CrescoLayerEditorBridge.state, 'waiting-elementor', 'Bridge should wait rather than silently stop when Elementor is late.');
assert.equal(typeof intervalCallback, 'function', 'Bridge should install a retry loop for late Elementor initialization.');

const registeredFilters = [];
const registeredActions = [];
const elementor = {
	hooks: {
		addFilter(name, callback) { registeredFilters.push([name, callback]); },
		addAction(name, callback) { registeredActions.push([name, callback]); },
	},
	channels: {
		editor: {
			request(name) {
				if (name === 'selectedElement') return { model: { id: 'abc123' } };
				return null;
			},
		},
	},
	config: { document: { id: 42 } },
};
window.elementor = elementor;
context.elementor = elementor;

window.CrescoLayerEditorBridge.boot('test-elementor-ready');
const diagnostics = window.CrescoLayerEditorBridge.getDiagnostics();
assert.equal(diagnostics.state, 'ready', 'Bridge should become ready when Elementor hooks arrive later.');
assert.equal(diagnostics.selectedElementId, 'abc123', 'Bridge should resolve the selected Elementor model.');
assert.ok(registeredFilters.some(([name]) => name === 'elements/context-menu/groups'), 'Current Elementor context-menu filter must be installed.');
assert.ok(registeredFilters.some(([name]) => name === 'elements/widget/contextMenuGroups'), 'Legacy/hybrid context-menu filter must remain installed.');
assert.ok(registeredActions.some(([name]) => name === 'panel/open_editor/e-flexbox'), 'Atomic layout editor hook must be installed.');

console.log('Cresco Layer editor bootstrap compatibility test passed.');
