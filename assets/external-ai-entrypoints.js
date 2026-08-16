(function () {
	'use strict';

	var bridge = window.CrescoLayerEditorBridge || {};
	var installedHooks = null;
	var installed = false;

	function panel() { return window.CrescoLayerAIPanel || null; }
	function open(tab) {
		var api = panel();
		if (api && typeof api.open === 'function') { api.open(tab); return true; }
		return false;
	}
	function currentId() {
		try { var d = bridge.getDiagnostics ? bridge.getDiagnostics() : null; return d && d.selectedElementId ? String(d.selectedElementId) : ''; } catch (e) { return ''; }
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
	function boot() { redirectBridge(); install(); }

	window.CrescoLayerExternalAIEntrypoints = {
		version: '1.0.0',
		install: install,
		replaceGroup: replaceGroup,
		open: open,
		getDiagnostics: function () { return { installed: installed, selectedElementId: currentId(), externalPanel: !!panel() }; }
	};

	boot();
	window.addEventListener('elementor/init', boot);
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
}());