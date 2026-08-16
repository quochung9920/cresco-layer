(function () {
	'use strict';

	var bridge = window.CrescoLayerEditorBridge || {};
	var installedHooks = null;
	var installed = false;

	function panel() { return window.CrescoLayerAIPanel || null; }
	function currentId() {
		try { var d = bridge.getDiagnostics ? bridge.getDiagnostics() : null; return d && d.selectedElementId ? String(d.selectedElementId) : ''; } catch (e) { return ''; }
	}
	function setScope(box, name, scope) {
		if (!box || ['widget', 'subtree', 'document'].indexOf(scope) === -1) return false;
		var input = box.querySelector('input[name="' + name + '"][value="' + scope + '"]');
		if (!input) return false;
		input.checked = true;
		return true;
	}
	function syncNoSelectionDefault() {
		var box = document.getElementById('cresco-ai-panel');
		if (!box || box.dataset.scopeTouched || currentId()) return;
		setScope(box, 'cresco-export-scope', 'document');
		setScope(box, 'cresco-import-scope', 'document');
	}
	function open(tab) {
		var api = panel();
		if (api && typeof api.open === 'function') {
			api.open(tab);
			syncNoSelectionDefault();
			return true;
		}
		return false;
	}
	function stripFences(raw) {
		var value = String(raw || '').trim();
		var match = value.match(/```(?:json)?\s*([\s\S]*?)\s*```/i);
		return match ? match[1].trim() : value;
	}
	function unwrap(value) {
		var keys = ['result', 'data', 'output', 'response', 'payload', 'aiResult', 'ai_result', 'json', 'patch'];
		for (var depth = 0; value && typeof value === 'object' && !value.schema && depth < 6; depth++) {
			var next = null;
			for (var i = 0; i < keys.length; i++) {
				if (value[keys[i]] && typeof value[keys[i]] === 'object') { next = value[keys[i]]; break; }
			}
			if (!next) break;
			value = next;
		}
		return value;
	}
	function inferScope(raw) {
		try {
			var value = unwrap(JSON.parse(stripFences(raw)));
			if (!value || typeof value !== 'object') return '';
			var targetScope = value.target && typeof value.target === 'object' ? String(value.target.scope || '') : '';
			var patchScope = value.scope && typeof value.scope === 'object' ? String(value.scope.mode || '') : '';
			var scope = targetScope || patchScope;
			return ['widget', 'subtree', 'document'].indexOf(scope) >= 0 ? scope : '';
		} catch (e) { return ''; }
	}
	function syncScopeFromRaw(raw) {
		var box = document.getElementById('cresco-ai-panel');
		var scope = inferScope(raw);
		if (!box || !scope) return;
		if (setScope(box, 'cresco-import-scope', scope)) box.dataset.scopeTouched = '1';
	}
	function readFileText(file) {
		if (!file) return;
		if (typeof file.text === 'function') {
			file.text().then(syncScopeFromRaw).catch(function () {});
			return;
		}
		try {
			var reader = new FileReader();
			reader.onload = function () { syncScopeFromRaw(String(reader.result || '')); };
			reader.readAsText(file);
		} catch (e) {}
	}
	function bindPanelScope() {
		var box = document.getElementById('cresco-ai-panel');
		if (!box || box.dataset.externalScopeBound) { syncNoSelectionDefault(); return; }
		box.dataset.externalScopeBound = '1';
		var area = box.querySelector('[data-cresco-ai-import]');
		if (area) area.addEventListener('input', function () { syncScopeFromRaw(area.value); });
		var file = box.querySelector('[data-cresco-ai-import-file]');
		if (file) file.addEventListener('change', function () { readFileText(file.files && file.files[0]); });
		var drop = box.querySelector('.cresco-ai-import-drop');
		if (drop) drop.addEventListener('drop', function (event) { readFileText(event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0]); });
		syncNoSelectionDefault();
	}
	function group() {
		return {
			name: 'cresco-layer-ai-exchange',
			actions: [
				{
					name: 'cresco-export-external-ai',
					icon: 'eicon-export-kit',
					title: 'Cresco - Export to ChatGPT',
					isEnabled: function () { return true; },
					callback: function () { open('export'); }
				},
				{
					name: 'cresco-import-external-ai',
					icon: 'eicon-import-kit',
					title: 'Cresco - Import AI Result',
					isEnabled: function () { return true; },
					callback: function () { open('import'); }
				}
			]
		};
	}
	function replaceGroup(groups, type) {
		if (!Array.isArray(groups) || type === 'document') return groups;
		var out = [], replaced = false;
		groups.forEach(function (item) {
			if (item && item.name === 'cresco-layer-ai-exchange') {
				if (!replaced) { out.push(group()); replaced = true; }
				return;
			}
			out.push(item);
		});
		if (!replaced) out.push(group());
		return out;
	}
	function install() {
		if (!window.elementor || !elementor.hooks || typeof elementor.hooks.addFilter !== 'function') return false;
		if (installed && installedHooks === elementor.hooks) return true;
		installedHooks = elementor.hooks; installed = true;
		var types = ['widget', 'container', 'section', 'column', 'e-div-block', 'e-flexbox', 'e-grid'];
		elementor.hooks.addFilter('elements/context-menu/groups', function (groups, type) { return replaceGroup(groups, type); });
		types.forEach(function (type) {
			elementor.hooks.addFilter('elements/' + type + '/contextMenuGroups', function (groups) { return replaceGroup(groups, type); });
		});
		return true;
	}
	function redirectBridge() {
		bridge.openEdit = function () { open('export'); };
		bridge.exportWidget = function () { open('export'); };
		bridge.exportSubtree = function () { open('export'); };
		bridge.exportSelection = function () { open('export'); };
		bridge.openImport = function () { open('import'); };
		bridge.externalExchange = true;
	}
	function boot() { redirectBridge(); install(); bindPanelScope(); }

	window.CrescoLayerExternalAIEntrypoints = {
		version: '1.1.0',
		install: install,
		replaceGroup: replaceGroup,
		inferScope: inferScope,
		syncScopeFromRaw: syncScopeFromRaw,
		open: open,
		getDiagnostics: function () { return { installed: installed, selectedElementId: currentId(), externalPanel: !!panel() }; }
	};

	boot();
	window.addEventListener('elementor/init', boot);
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
}());