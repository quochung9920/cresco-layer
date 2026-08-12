(function () {
	'use strict';

	var cfg = window.crescoLayerEditor || {};
	var bridge = window.CrescoLayerEditorBridge = window.CrescoLayerEditorBridge || {};
	var selectedModel = null;
	var selectedElementId = '';
	var hooksObject = null;
	var hooksInstalled = false;
	var importMode = 'subtree';
	var previewedPatch = '';
	var retries = 0;
	var timer = null;

	bridge.version = cfg.version || '0.2.2';
	bridge.scriptLoaded = true;
	bridge.state = 'loading';

	function validId(value) {
		return /^[A-Za-z0-9_-]{1,64}$/.test(String(value || ''));
	}

	function modelId(model) {
		if (!model) return '';
		var id = model.id || (model.attributes && model.attributes.id) || '';
		if (!id && typeof model.get === 'function') id = model.get('id') || '';
		return validId(id) ? String(id) : '';
	}

	function rememberId(id) {
		if (validId(id)) selectedElementId = String(id);
		return selectedElementId;
	}

	function rememberModel(panel, model, view) {
		if (modelId(model)) {
			selectedModel = model;
			rememberId(modelId(model));
		}
		if (view && view.model && modelId(view.model)) {
			selectedModel = view.model;
			rememberId(modelId(view.model));
		}
	}

	function idFromNode(node) {
		if (!node || node.nodeType === 3) return '';
		var target = node.closest ? (node.closest('[data-id],[data-e-id],[data-element-id]') || node) : node;
		if (!target || !target.getAttribute) return '';
		for (var i = 0; i < 3; i++) {
			var key = ['data-id', 'data-e-id', 'data-element-id'][i];
			var value = target.getAttribute(key);
			if (validId(value)) return rememberId(value);
		}
		return '';
	}

	function selectedFromDom(doc) {
		if (!doc || !doc.querySelector) return '';
		var selectors = [
			'.elementor-element.elementor-selected[data-id]',
			'.elementor-element.elementor-element-edit-mode[data-id]',
			'[data-id][aria-selected="true"]',
			'[data-e-id][aria-selected="true"]',
			'[data-element-id][aria-selected="true"]'
		];
		for (var i = 0; i < selectors.length; i++) {
			try {
				var id = idFromNode(doc.querySelector(selectors[i]));
				if (id) return id;
			} catch (e) {}
		}
		return '';
	}

	function instrument(doc) {
		if (!doc || doc.__crescoLayerInstrumented) return;
		doc.__crescoLayerInstrumented = true;
		var capture = function (event) { idFromNode(event.target); };
		try {
			doc.addEventListener('pointerdown', capture, true);
			doc.addEventListener('click', capture, true);
			doc.addEventListener('contextmenu', capture, true);
		} catch (e) {}
	}

	function instrumentPreview() {
		if (!document.querySelectorAll) return;
		var frames = document.querySelectorAll('#elementor-preview-iframe,iframe[name="elementor-preview-iframe"],iframe[src*="elementor-preview"]');
		Array.prototype.forEach.call(frames, function (frame) {
			function attach() {
				try { instrument(frame.contentDocument); selectedFromDom(frame.contentDocument); } catch (e) {}
			}
			attach();
			if (!frame.__crescoLayerHooked) {
				frame.__crescoLayerHooked = true;
				frame.addEventListener('load', attach);
			}
		});
	}

	function selectedId() {
		try {
			if (window.elementor && elementor.channels && elementor.channels.editor) {
				var selected = elementor.channels.editor.request('selectedElement');
				var model = selected && (selected.model || selected);
				if (modelId(model)) {
					selectedModel = model;
					return rememberId(modelId(model));
				}
			}
		} catch (e) {}

		var id = selectedFromDom(document);
		if (id) return id;
		try {
			var frames = document.querySelectorAll('iframe');
			for (var i = 0; i < frames.length; i++) {
				id = selectedFromDom(frames[i].contentDocument);
				if (id) return id;
			}
		} catch (e2) {}
		id = modelId(selectedModel);
		if (id) return rememberId(id);
		if (validId(selectedElementId)) return selectedElementId;
		throw new Error('Select an Elementor widget or container first.');
	}

	function postId() {
		var id = parseInt(cfg.postId || 0, 10);
		if (id) return id;
		try {
			id = parseInt(elementor.config.document.id || 0, 10);
			if (id) return id;
		} catch (e) {}
		try {
			var params = new URLSearchParams(window.location.search || '');
			return parseInt(params.get('post') || params.get('post_id') || 0, 10) || 0;
		} catch (e2) { return 0; }
	}

	function endpoint(path) {
		return String(cfg.restRoot || '').replace(/\/$/, '') + path;
	}

	function request(path, options) {
		if (!cfg.restRoot) return Promise.reject(new Error('Cresco Layer REST configuration is missing. Reload Elementor.'));
		options = options || {};
		options.headers = Object.assign({ 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' }, options.headers || {});
		return fetch(endpoint(path), options).then(function (response) {
			return response.json().catch(function () { return {}; }).then(function (body) {
				if (!response.ok) throw new Error(body.message || ('Cresco Layer request failed (' + response.status + ').'));
				return body;
			});
		});
	}

	function toast(message, tone) {
		if (!document.body) return;
		var el = document.getElementById('cresco-layer-editor-toast');
		if (!el) {
			el = document.createElement('div');
			el.id = 'cresco-layer-editor-toast';
			document.body.appendChild(el);
		}
		el.className = 'cresco-layer-editor-toast is-' + (tone || 'info');
		el.textContent = message;
		el.hidden = false;
		clearTimeout(el._timer);
		el._timer = setTimeout(function () { el.hidden = true; }, 6500);
	}

	function download(filename, data) {
		var url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }));
		var a = document.createElement('a');
		a.href = url;
		a.download = filename;
		document.body.appendChild(a);
		a.click();
		a.remove();
		setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
	}

	function exportScope(mode) {
		try {
			var id = selectedId();
			var pid = postId();
			if (!pid) throw new Error('Cannot determine the current Elementor document ID.');
			toast('Building Elementor ' + mode + ' AI package…', 'busy');
			request('/documents/' + pid + '/export?scope=' + encodeURIComponent(mode) + '&selected=' + encodeURIComponent(id))
				.then(function (data) {
					download('cresco-layer-' + pid + '-' + id + '-' + mode + '.json', data);
					toast('Cresco AI package exported.', 'success');
				})
				.catch(function (error) { toast(error.message, 'error'); });
		} catch (error) { toast(error.message, 'error'); }
	}

	function editorApi() {
		return window.$e && typeof window.$e.run === 'function' ? window.$e : null;
	}

	function liveEditorReady() {
		return !!(editorApi() && window.elementor && typeof elementor.getContainer === 'function');
	}

	function getContainer(id) {
		if (!validId(id) || !window.elementor) return null;
		try {
			var container = elementor.getContainer(String(id));
			if (container) return container;
		} catch (e) {}
		try {
			var api = editorApi();
			var documentComponent = api && api.components && typeof api.components.get === 'function' ? api.components.get('document') : null;
			if (documentComponent && documentComponent.utils && typeof documentComponent.utils.findContainerById === 'function') {
				return documentComponent.utils.findContainerById(String(id)) || null;
			}
		} catch (e2) {}
		return null;
	}

	function settingsJson(container) {
		try {
			if (container && container.settings && typeof container.settings.toJSON === 'function') return container.settings.toJSON() || {};
		} catch (e) {}
		return {};
	}

	function runElementSettings(container, settings) {
		var api = editorApi();
		if (!api || !container) throw new Error('Elementor live settings API is unavailable.');
		return api.run('document/elements/settings', {
			container: container,
			settings: settings,
			options: { external: true }
		});
	}

	function startHistory(label) {
		var api = editorApi();
		if (!api || typeof api.internal !== 'function') return null;
		try {
			return api.internal('document/history/start-log', {
				type: 'change',
				title: label || 'Cresco AI Import'
			});
		} catch (e) { return null; }
	}

	function endHistory(id) {
		var api = editorApi();
		if (!api || typeof api.internal !== 'function' || id === null || typeof id === 'undefined') return;
		try { api.internal('document/history/end-log', { id: id }); } catch (e) {}
	}

	function replaceSettingsLive(container, nextSettings) {
		var current = settingsJson(container);
		var changes = {};
		Object.keys(current).forEach(function (key) { changes[key] = undefined; });
		Object.keys(nextSettings || {}).forEach(function (key) { changes[key] = nextSettings[key]; });
		runElementSettings(container, changes);
	}

	function containerIndex(container) {
		if (!container) return 0;
		if (container.view && Number.isInteger(container.view._index)) return container.view._index;
		try {
			var parentModel = container.parent && container.parent.model;
			var collection = parentModel && typeof parentModel.get === 'function' ? parentModel.get('elements') : null;
			if (collection && typeof collection.indexOf === 'function') {
				var index = collection.indexOf(container.model);
				if (index >= 0) return index;
			}
		} catch (e) {}
		return 0;
	}

	function replacementNeedsReload(container, replacement) {
		if (!container || !container.model || !replacement) return false;
		var current = typeof container.model.toJSON === 'function' ? container.model.toJSON() : {};
		var ignored = { id: true, settings: true, elements: true };
		var keys = {};
		Object.keys(current || {}).forEach(function (key) { keys[key] = true; });
		Object.keys(replacement || {}).forEach(function (key) { keys[key] = true; });
		return Object.keys(keys).some(function (key) {
			if (ignored[key]) return false;
			return JSON.stringify(current[key]) !== JSON.stringify(replacement[key]);
		});
	}

	function applyLiveOperation(op, scopeMode, state) {
		var api = editorApi();
		var container;
		var parent;
		var replacement;
		var position;

		switch (op.operation) {
			case 'update-setting':
				container = getContainer(op.elementId);
				if (!container) throw new Error('Elementor element is not available in the live canvas: ' + op.elementId);
				var settingChange = {};
				settingChange[op.setting] = op.value;
				runElementSettings(container, settingChange);
				return true;

			case 'remove-setting':
				container = getContainer(op.elementId);
				if (!container) throw new Error('Elementor element is not available in the live canvas: ' + op.elementId);
				var removeChange = {};
				removeChange[op.setting] = undefined;
				runElementSettings(container, removeChange);
				return true;

			case 'replace-settings':
				container = getContainer(op.elementId);
				if (!container) throw new Error('Elementor element is not available in the live canvas: ' + op.elementId);
				replaceSettingsLive(container, op.settings || {});
				return true;

			case 'replace-element':
				container = getContainer(op.elementId);
				if (!container) throw new Error('Elementor element is not available in the live canvas: ' + op.elementId);
				replacement = op.element || {};
				if ('widget' === scopeMode || op.preserveChildren) {
					replaceSettingsLive(container, replacement.settings || {});
					if (replacementNeedsReload(container, replacement)) state.requiresReload = true;
					return true;
				}
				parent = container.parent || (window.elementor && typeof elementor.getPreviewContainer === 'function' ? elementor.getPreviewContainer() : null);
				if (!parent) { state.requiresReload = true; return false; }
				position = containerIndex(container);
				api.run('document/elements/delete', { container: container });
				api.run('document/elements/create', { container: parent, model: replacement, options: { at: position } });
				return true;

			case 'insert-element':
				parent = getContainer(op.parentId);
				if (!parent) throw new Error('Elementor parent is not available in the live canvas: ' + op.parentId);
				api.run('document/elements/create', {
					container: parent,
					model: op.element || {},
					options: { at: Math.max(0, parseInt(op.position || 0, 10)) }
				});
				return true;

			case 'remove-element':
				container = getContainer(op.elementId);
				if (!container) return true;
				api.run('document/elements/delete', { container: container });
				return true;

			case 'move-element':
				container = getContainer(op.elementId);
				parent = getContainer(op.parentId);
				if (!container || !parent) throw new Error('Elementor move target is not available in the live canvas.');
				api.run('document/elements/move', {
					container: container,
					target: parent,
					options: { at: Math.max(0, parseInt(op.position || 0, 10)) }
				});
				return true;

			case 'update-page-setting':
			case 'remove-page-setting':
			case 'replace-document':
				state.requiresReload = true;
				return false;
		}

		state.requiresReload = true;
		return false;
	}

	function applyPatchToEditor(patch) {
		var result = {
			live: false,
			appliedOperations: 0,
			unsupportedOperations: 0,
			requiresReload: false,
			error: ''
		};
		if (!patch || !Array.isArray(patch.operations) || !patch.operations.length) return result;
		if (!liveEditorReady()) {
			result.requiresReload = true;
			result.error = 'Elementor command API is not ready.';
			return result;
		}

		var scopeMode = patch.scope && patch.scope.mode ? patch.scope.mode : 'document';
		var historyId = startHistory('Cresco AI · ' + (patch.label || 'Import'));
		try {
			patch.operations.forEach(function (op) {
				if (applyLiveOperation(op, scopeMode, result)) result.appliedOperations += 1;
				else result.unsupportedOperations += 1;
			});
			result.live = result.appliedOperations > 0 && !result.requiresReload;
		} catch (error) {
			result.requiresReload = true;
			result.error = error && error.message ? error.message : String(error);
		} finally {
			endHistory(historyId);
		}

		try {
			var rootId = patch.scope && patch.scope.rootElementId ? patch.scope.rootElementId : selectedElementId;
			var root = getContainer(rootId);
			if (root && root.model) {
				selectedModel = root.model;
				rememberId(rootId);
			}
		} catch (e) {}
		return result;
	}

	function closeModal() {
		var modal = document.getElementById('cresco-layer-editor-modal');
		if (modal) modal.hidden = true;
	}

	function modal() {
		var box = document.getElementById('cresco-layer-editor-modal');
		if (box) return box;
		box = document.createElement('div');
		box.id = 'cresco-layer-editor-modal';
		box.className = 'cresco-layer-editor-modal';
		box.hidden = true;
		box.innerHTML = '<div class="cresco-layer-editor-modal__backdrop" data-cresco-close></div>' +
			'<section class="cresco-layer-editor-dialog" role="dialog" aria-modal="true" aria-labelledby="cresco-layer-dialog-title">' +
			'<header><div><span class="cresco-layer-editor-eyebrow">AI exchange</span><h2 id="cresco-layer-dialog-title">Import AI changes</h2></div><button type="button" class="cresco-layer-editor-close" data-cresco-close aria-label="Close">×</button></header>' +
			'<div class="cresco-layer-editor-scope"><label><input type="radio" name="cresco-import-scope" value="widget"> Widget only</label><label><input type="radio" name="cresco-import-scope" value="subtree" checked> Subtree</label></div>' +
			'<p class="cresco-layer-editor-help">Paste the <code>cresco-layer-patch/v1</code> returned for this exact exported scope.</p>' +
			'<textarea id="cresco-layer-editor-patch" rows="18" spellcheck="false" placeholder="{&quot;schema&quot;:&quot;cresco-layer-patch/v1&quot;,...}"></textarea>' +
			'<div id="cresco-layer-editor-preview" class="cresco-layer-editor-preview">No patch preview yet.</div>' +
			'<footer><button type="button" class="cresco-layer-secondary" data-cresco-close>Cancel</button><button type="button" class="cresco-layer-secondary" id="cresco-layer-editor-validate">Validate & Preview</button><button type="button" class="cresco-layer-primary" id="cresco-layer-editor-apply" disabled>Apply reviewed patch</button></footer></section>';
		document.body.appendChild(box);
		Array.prototype.forEach.call(box.querySelectorAll('[data-cresco-close]'), function (button) { button.addEventListener('click', closeModal); });
		Array.prototype.forEach.call(box.querySelectorAll('input[name="cresco-import-scope"]'), function (input) {
			input.addEventListener('change', function () {
				if (input.checked) importMode = input.value;
				previewedPatch = '';
				box.querySelector('#cresco-layer-editor-apply').disabled = true;
			});
		});
		box.querySelector('#cresco-layer-editor-patch').addEventListener('input', function () {
			if (this.value.trim() !== previewedPatch) box.querySelector('#cresco-layer-editor-apply').disabled = true;
		});
		box.querySelector('#cresco-layer-editor-validate').addEventListener('click', previewPatch);
		box.querySelector('#cresco-layer-editor-apply').addEventListener('click', applyPatch);
		return box;
	}

	function openImport(mode) {
		try {
			selectedId();
			importMode = mode || 'subtree';
			previewedPatch = '';
			var box = modal();
			var radio = box.querySelector('input[name="cresco-import-scope"][value="' + importMode + '"]');
			if (radio) radio.checked = true;
			box.querySelector('#cresco-layer-editor-preview').textContent = 'No patch preview yet.';
			box.querySelector('#cresco-layer-editor-apply').disabled = true;
			box.hidden = false;
		} catch (error) { toast(error.message, 'error'); }
	}

	function parsePatch() {
		var text = modal().querySelector('#cresco-layer-editor-patch').value.trim();
		if (!text) throw new Error('Paste a Cresco Layer AI patch first.');
		try { return { text: text, patch: JSON.parse(text) }; }
		catch (e) { throw new Error('The AI patch is not valid JSON.'); }
	}

	function expectedScope() {
		return { mode: importMode, rootElementId: selectedId() };
	}

	function showPreview(data) {
		var diff = data.diff || {};
		modal().querySelector('#cresco-layer-editor-preview').innerHTML =
			'<strong>Validated · ' + (diff.total || 0) + ' operations</strong>' +
			'<span>Updated ' + (diff.updated || 0) + '</span><span>Replaced ' + (diff.replaced || 0) + '</span>' +
			'<span>Inserted ' + (diff.inserted || 0) + '</span><span>Removed ' + (diff.removed || 0) + '</span><span>Moved ' + (diff.moved || 0) + '</span>' +
			(data.staleDocumentButScopeUnchanged ? '<em>The page changed elsewhere, but this exported scope is unchanged.</em>' : '');
	}

	function previewPatch() {
		try {
			var item = parsePatch();
			var box = modal();
			box.querySelector('#cresco-layer-editor-apply').disabled = true;
			previewedPatch = '';
			request('/documents/' + postId() + '/preview', { method: 'POST', body: JSON.stringify({ patch: item.patch, expectedScope: expectedScope() }) })
				.then(function (data) {
					showPreview(data);
					previewedPatch = item.text;
					box.querySelector('#cresco-layer-editor-apply').disabled = false;
					toast('Patch is valid for the selected Elementor scope.', 'success');
				})
				.catch(function (error) { toast(error.message, 'error'); });
		} catch (error) { toast(error.message, 'error'); }
	}

	function applyPatch() {
		try {
			var item = parsePatch();
			if (!previewedPatch || previewedPatch !== item.text) throw new Error('Validate the patch again before applying.');
			if (!window.confirm('Apply this reviewed AI patch? It will not publish the page.')) return;
			var box = modal();
			box.querySelector('#cresco-layer-editor-apply').disabled = true;
			request('/documents/' + postId() + '/apply', { method: 'POST', body: JSON.stringify({ patch: item.patch, expectedScope: expectedScope() }) })
				.then(function () {
					var liveResult = applyPatchToEditor(item.patch);
					previewedPatch = '';
					closeModal();
					if (liveResult.requiresReload) {
						var details = liveResult.error ? ' ' + liveResult.error : '';
						toast('AI changes were saved. ' + liveResult.appliedOperations + ' operations synced to the open canvas; some changes need an Elementor reopen.' + details, 'success');
					} else {
						toast('AI changes applied live in Elementor. Review the canvas, then Update/Publish when ready.', 'success');
					}
				})
				.catch(function (error) { box.querySelector('#cresco-layer-editor-apply').disabled = false; toast(error.message, 'error'); });
		} catch (error) { toast(error.message, 'error'); }
	}

	function toolbar() {
		if (!document.body) return null;
		var tools = document.getElementById('cresco-layer-editor-tools');
		if (tools) return tools;
		tools = document.createElement('div');
		tools.id = 'cresco-layer-editor-tools';
		tools.className = 'cresco-layer-editor-tools';
		tools.innerHTML = '<button type="button" data-cresco="widget">AI Widget</button><button type="button" data-cresco="subtree">AI Subtree</button><button type="button" data-cresco="import">Import AI</button>';
		var buttons = tools.querySelectorAll('button');
		buttons[0].addEventListener('click', function () { exportScope('widget'); });
		buttons[1].addEventListener('click', function () { exportScope('subtree'); });
		buttons[2].addEventListener('click', function () { openImport('subtree'); });
		document.body.appendChild(tools);
		return tools;
	}

	function contextGroup(view) {
		function selectView() { if (view) rememberModel(null, view.model, view); }
		return { name: 'cresco-layer-ai-exchange', actions: [
			{ name: 'cresco-export-widget', icon: 'eicon-export-kit', title: 'Cresco · Export element for AI', isEnabled: function () { return true; }, callback: function () { selectView(); exportScope('widget'); } },
			{ name: 'cresco-export-subtree', icon: 'eicon-navigator', title: 'Cresco · Export subtree for AI', isEnabled: function () { return true; }, callback: function () { selectView(); exportScope('subtree'); } },
			{ name: 'cresco-import-ai', icon: 'eicon-import-kit', title: 'Cresco · Import AI changes', isEnabled: function () { return true; }, callback: function () { selectView(); openImport('subtree'); } }
		] };
	}

	function addContext(groups, type, view) {
		if (!Array.isArray(groups) || !type || type === 'document') return groups;
		if (view) rememberModel(null, view.model, view);
		if (!groups.some(function (group) { return group && group.name === 'cresco-layer-ai-exchange'; })) groups.push(contextGroup(view));
		return groups;
	}

	function ready() {
		return !!(window.elementor && elementor.hooks && typeof elementor.hooks.addAction === 'function' && typeof elementor.hooks.addFilter === 'function');
	}

	function installHooks() {
		if (!ready()) return false;
		if (hooksObject !== elementor.hooks) { hooksObject = elementor.hooks; hooksInstalled = false; }
		if (hooksInstalled) return true;
		hooksInstalled = true;
		var types = ['widget', 'container', 'section', 'column', 'e-div-block', 'e-flexbox', 'e-grid'];
		types.forEach(function (type) { elementor.hooks.addAction('panel/open_editor/' + type, rememberModel); });
		elementor.hooks.addFilter('elements/context-menu/groups', function (groups, type, view) { return addContext(groups, type, view); });
		types.forEach(function (type) {
			elementor.hooks.addFilter('elements/' + type + '/contextMenuGroups', function (groups, view) { return addContext(groups, type, view); });
		});
		return true;
	}

	function setState(state) {
		bridge.state = state;
		var tools = toolbar();
		if (tools) { tools.dataset.bridgeState = state; tools.title = 'Cresco Layer ' + bridge.version + ' · ' + state; }
		if (document.documentElement) document.documentElement.setAttribute('data-cresco-layer-editor-bridge', state);
	}

	function boot(reason) {
		bridge.lastReason = reason || 'manual';
		toolbar();
		instrument(document);
		instrumentPreview();
		var ok = installHooks();
		setState(ok ? 'ready' : 'waiting-elementor');
		return ok;
	}

	function start() {
		if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { boot('dom-ready'); }, { once: true });
		else boot('script-ready');
		window.addEventListener('elementor/init', function () { boot('elementor/init'); });
		window.addEventListener('load', function () { boot('window-load'); }, { once: true });
		timer = setInterval(function () {
			retries += 1;
			var ok = boot('retry-' + retries);
			if ((ok && retries >= 4) || retries >= 240) { clearInterval(timer); timer = null; }
		}, 250);
	}

	bridge.boot = boot;
	bridge.exportWidget = function () { exportScope('widget'); };
	bridge.exportSubtree = function () { exportScope('subtree'); };
	bridge.openImport = function () { openImport('subtree'); };
	bridge.applyPatchToEditor = applyPatchToEditor;
	bridge.getDiagnostics = function () {
		var id = '';
		try { id = selectedId(); } catch (e) {}
		return {
			version: bridge.version,
			state: bridge.state,
			postId: postId(),
			elementorPresent: !!window.elementor,
			hooksReady: ready(),
			hooksInstalled: hooksInstalled,
			liveEditorReady: liveEditorReady(),
			selectedElementId: id,
			elementorVersion: cfg.elementorVersion || null,
			elementorProVersion: cfg.elementorProVersion || null
		};
	};

	start();
}());
