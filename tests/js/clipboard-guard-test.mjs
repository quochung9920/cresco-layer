import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const source = fs.readFileSync(new URL('../../assets/clipboard-guard.js', import.meta.url), 'utf8');

let focused = false;
let nativeCalls = 0;
let execCalls = 0;
let restoredFocus = 0;
let selectedValue = '';

const activeElement = {
	focus() { restoredFocus += 1; },
};

const body = {
	appendChild() {},
};

const area = {
	value: '',
	style: {},
	setAttribute() {},
	focus() { focused = true; },
	select() { selectedValue = this.value; },
	setSelectionRange() {},
	remove() {},
};

const document = {
	body,
	activeElement,
	hasFocus() { return focused; },
	createElement(tag) {
		assert.equal(tag, 'textarea');
		return area;
	},
	execCommand(command) {
		execCalls += 1;
		assert.equal(command, 'copy');
		return true;
	},
};

const clipboard = {
	writeText(value) {
		nativeCalls += 1;
		assert.equal(value, 'Exact Runtime package');
		const error = new Error('Document is not focused.');
		error.name = 'NotAllowedError';
		return Promise.reject(error);
	},
};

const window = {
	navigator: { clipboard },
	focus() { focused = true; },
};

vm.runInNewContext(source, {
	window,
	document,
	navigator: window.navigator,
	Promise,
	Object,
	String,
	Error,
	setTimeout,
	console,
}, { filename: 'assets/clipboard-guard.js' });

assert.ok(window.CrescoLayerClipboardGuard, 'Clipboard guard should expose diagnostics.');
assert.equal(window.CrescoLayerClipboardGuard.getDiagnostics().installed, true, 'Clipboard guard should install over the async clipboard writer.');

await window.navigator.clipboard.writeText('Exact Runtime package');

assert.equal(nativeCalls, 1, 'Guard should still prefer the native Clipboard API after restoring focus.');
assert.equal(execCalls, 1, 'A rejected native write should fall back to a focused textarea + execCommand copy.');
assert.equal(selectedValue, 'Exact Runtime package', 'Fallback must select the full requested payload.');
assert.ok(restoredFocus >= 1, 'Fallback should restore the previously active Elementor control.');
assert.equal(window.CrescoLayerClipboardGuard.getDiagnostics().lastMethod, 'execCommand');
assert.equal(window.CrescoLayerClipboardGuard.getDiagnostics().lastError, '');

assert.match(source, /document\.hasFocus/, 'Guard must detect focus loss before Clipboard API writes.');
assert.match(source, /window\.focus\(\)/, 'Guard must attempt to restore the Elementor window focus.');
assert.match(source, /document\.execCommand\('copy'\)/, 'Guard must retain a synchronous clipboard fallback.');
assert.match(source, /Click once inside Elementor and press Copy again/, 'Final failure should give an actionable message instead of the raw browser exception.');

console.log('Cresco Layer clipboard focus fallback test passed.');
