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

function settingsStore(initial = {}) {
	const data = { ...initial };
	return {
		toJSON: () => ({ ...data }),
		set(values) {
			Object.entries(values).forEach(([key, value]) => {
				if (typeof value === 'undefined') delete data[key];
				else data[key] = value;
			});
		},
		setExternalChange(values) { this.set(values); },
	};
}

function makeContainer(id, settings = {}) {
	return {
		id,
		settings: settingsStore(settings),
		lookup() { return this; },
		renderCount: 0,
		render() { this.renderCount += 1; },
		model: {
			id,
			toJSON: () => ({ id, elType: 'container', settings: settingsStore(settings).toJSON(), elements: [] }),
		},
	};
}

const containers = new Map();
const root = makeContainer('root1', { content_width: 'boxed', gap: 20, obsolete: 'yes' });
const child = makeContainer('child1', { color: '#111111' });
child.parent = root;
containers.set(root.id, root);
containers.set(child.id, child);

const commandCalls = [];
const historyCalls = [];
const $e = {
	run(name, args) {
		commandCalls.push([name, args]);
		if (name === 'document/elements/settings') {
			const target = args.container.lookup ? args.container.lookup() : args.container;
			target.settings.setExternalChange(args.settings || {});
			target.render();
			return target;
		}
		if (name === 'document/elements/create') {
			const model = args.model || {};
			const created = makeContainer(model.id, model.settings || {});
			created.parent = args.container;
			containers.set(created.id, created);
			return created;
		}
		if (name === 'document/elements/delete') {
			containers.delete(args.container.id);
			return args.container;
		}
		if (name === 'document/elements/move') {
			args.container.parent = args.target;
			return args.container;
		}
		return null;
	},
	internal(name, args) {
		historyCalls.push([name, args]);
		return name === 'document/history/start-log' ? 77 : null;
	},
	components: {
		get() {
			return {
				utils: {
					findContainerById: (id) => containers.get(id) || null,
				},
			};
		},
	},
};

const document = {
	body: new FakeElement('body'),
	documentElement: new FakeElement('html'),
	readyState: 'complete',
	createElement: (tag) => new FakeElement(tag),
	getElementById: (id) => elementsById.get(id) || null,
	querySelector: () => null,
	querySelectorAll: () => [],
	addEventListener: () => {},
};

const elementor = {
	getContainer: (id) => containers.get(String(id)) || null,
	hooks: { addAction() {}, addFilter() {} },
	channels: {
		editor: {
			request(name) {
				if (name === 'selectedElement') return { model: { id: 'root1' } };
				return null;
			},
		},
	},
	config: { document: { id: 42 } },
};

const window = {
	crescoLayerEditor: {
		version: '0.2.2',
		postId: 42,
		restRoot: 'https://example.test/wp-json/cresco-layer/v1',
		nonce: 'nonce',
	},
	$e,
	elementor,
	location: { search: '?post=42' },
	addEventListener(type, callback) { (listeners[type] ||= []).push(callback); },
	confirm: () => true,
	console,
};

let intervalCallback = null;
const context = {
	window,
	document,
	elementor,
	$e,
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
	Number,
	parseInt,
	setTimeout: () => 1,
	clearTimeout: () => {},
	setInterval(callback) { intervalCallback = callback; return 1; },
	clearInterval: () => {},
	fetch: () => Promise.reject(new Error('not used')),
};

vm.runInNewContext(source, context, { filename: 'assets/editor.js' });
assert.equal(typeof window.CrescoLayerEditorBridge.applyPatchToEditor, 'function');
assert.equal(window.CrescoLayerEditorBridge.getDiagnostics().liveEditorReady, true);

const result = window.CrescoLayerEditorBridge.applyPatchToEditor({
	label: 'Live sync test',
	scope: { mode: 'subtree', rootElementId: 'root1' },
	operations: [
		{ operation: 'update-setting', elementId: 'root1', setting: 'gap', value: 32 },
		{ operation: 'remove-setting', elementId: 'root1', setting: 'obsolete' },
		{ operation: 'replace-settings', elementId: 'child1', settings: { color: '#ffffff', align: 'center' } },
		{ operation: 'insert-element', parentId: 'root1', position: 0, element: { id: 'new1', elType: 'widget', widgetType: 'heading', settings: { title: 'Hello' }, elements: [] } },
		{ operation: 'move-element', elementId: 'child1', parentId: 'root1', position: 1 },
		{ operation: 'remove-element', elementId: 'new1' },
	],
});

assert.equal(result.live, true);
assert.equal(result.requiresReload, false);
assert.equal(result.appliedOperations, 6);
assert.equal(root.settings.toJSON().gap, 32);
assert.equal(Object.hasOwn(root.settings.toJSON(), 'obsolete'), false);
assert.deepEqual(child.settings.toJSON(), { color: '#ffffff', align: 'center' });
assert.equal(containers.has('new1'), false);
assert.ok(commandCalls.some(([name]) => name === 'document/elements/settings'));
assert.ok(commandCalls.some(([name]) => name === 'document/elements/create'));
assert.ok(commandCalls.some(([name]) => name === 'document/elements/move'));
assert.ok(commandCalls.some(([name]) => name === 'document/elements/delete'));
assert.deepEqual(historyCalls.map(([name]) => name), ['document/history/start-log', 'document/history/end-log']);

const documentLevel = window.CrescoLayerEditorBridge.applyPatchToEditor({
	label: 'Page setting test',
	scope: { mode: 'document', rootElementId: '' },
	operations: [{ operation: 'update-page-setting', setting: 'hide_title', value: 'yes' }],
});
assert.equal(documentLevel.live, false);
assert.equal(documentLevel.requiresReload, true);
assert.equal(documentLevel.unsupportedOperations, 1);

console.log('Cresco Layer live Elementor apply test passed.');
