(function () {
	'use strict';

	var cfg = window.crescoLayerEditor || {};
	var bridge = window.CrescoLayerEditorBridge = window.CrescoLayerEditorBridge || {};
	var selectedModel = null;
	var selectedElementId = '';
	var selectionIds = [];
	var hooksObject = null;
	var hooksInstalled = false;
	var exportMode = 'widget';
	var importMode = 'subtree';
	var importSourceName = '';
	var previewedPatch = '';
	var retries = 0;
	var timer = null;

	bridge.version = cfg.version || '0.5.1';
	bridge.scriptLoaded = true;
	bridge.state = 'loading';

	function validId(value) {
		return /^[A-Za-z0-9_-]{1,64}$/.test(String(value || ''));
	}

	function escapeHtml(value) {
		return String(value == null ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
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
			'.elementor-element.elementor-element-editable[data-id]',
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

	/**
	 * The selection as the editor reports it right now, or '' when it cannot be read.
	 *
	 * Only live sources: Elementor's own channel, then the selected node in the top document, then
	 * inside each preview iframe. Remembered state is deliberately excluded — see liveSelectedId.
	 */
	function liveSelectedId() {
		/*
		 * elementor.selection is the selection API the editor itself uses — document/elements/copy
		 * reads it to know what to act on. It answers for containers and for selections made through
		 * the Navigator, both of which the legacy Marionette channel below can miss entirely.
		 */
		try {
			if (window.elementor && elementor.selection && typeof elementor.selection.getElements === 'function') {
				var containers = elementor.selection.getElements();
				if (containers && containers.length) {
					var container = containers[containers.length - 1];
					var containerId = modelId(container) || modelId(container && container.model);
					if (containerId) {
						if (container && container.model) selectedModel = container.model;
						return rememberId(containerId);
					}
				}
			}
		} catch (e) {}

		try {
			if (window.elementor && elementor.channels && elementor.channels.editor) {
				var selected = elementor.channels.editor.request('selectedElement');
				var model = selected && (selected.model || selected);
				if (modelId(model)) {
					selectedModel = model;
					return rememberId(modelId(model));
				}
			}
		} catch (e2) {}

		var id = selectedFromDom(document);
		if (id) return id;
		try {
			var frames = document.querySelectorAll('iframe');
			for (var i = 0; i < frames.length; i++) {
				id = selectedFromDom(frames[i].contentDocument);
				if (id) return id;
			}
		} catch (e2) {}
		return '';
	}

	/**
	 * selectedElementId and selectedModel remember the last element the user touched, which is not
	 * the same as the element selected now: changing selection through the Navigator or the keyboard
	 * fires no canvas pointer event. Falling back to that memory silently pointed an import at the
	 * container the user had selected earlier, rewriting the wrong one. Answering with an error
	 * instead costs one click; answering with a stale ID costs the user their layout.
	 */
	function selectedId() {
		var id = liveSelectedId();
		if (id) return id;
		throw new Error('Cresco could not read the current Elementor selection. Click the element on the canvas, then try again.');
	}

	function selectedIdSafe() {
		try { return selectedId(); }
		catch (e) { return ''; }
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
				if (!response.ok) {
					var error = new Error(body.message || ('Cresco Layer request failed (' + response.status + ').'));
					error.status = response.status;
					error.body = body;
					throw error;
				}
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

	function elementData(id) {
		var container = getContainer(id);
		try {
			if (container && container.model && typeof container.model.toJSON === 'function') return container.model.toJSON() || {};
		} catch (e) {}
		if (selectedModel && modelId(selectedModel) === id) {
			try {
				if (typeof selectedModel.toJSON === 'function') return selectedModel.toJSON() || {};
				if (selectedModel.attributes) return selectedModel.attributes;
			} catch (e2) {}
		}
		return { id: id };
	}

	function elementLabel(id) {
		var data = elementData(id);
		var type = data.widgetType || data.elType || 'element';
		var title = type.replace(/[-_]+/g, ' ');
		return title.charAt(0).toUpperCase() + title.slice(1) + ' · ' + id;
	}

	function uniqueIds(ids) {
		var out = [];
		(ids || []).forEach(function (id) {
			id = String(id || '');
			if (validId(id) && out.indexOf(id) === -1) out.push(id);
		});
		return out;
	}

	function hasSelection(id) {
		return selectionIds.indexOf(String(id || '')) !== -1;
	}

	function addSelection(id) {
		if (!validId(id)) return false;
		if (!hasSelection(id)) selectionIds.push(String(id));
		renderSelectionCount();
		renderExportSelection();
		return true;
	}

	function removeSelection(id) {
		selectionIds = selectionIds.filter(function (item) { return item !== String(id || ''); });
		renderSelectionCount();
		renderExportSelection();
	}

	function toggleSelection(id) {
		if (!validId(id)) return false;
		if (hasSelection(id)) removeSelection(id);
		else addSelection(id);
		return hasSelection(id);
	}

	function clearSelection() {
		selectionIds = [];
		renderSelectionCount();
		renderExportSelection();
	}

	function renderSelectionCount() {
		var count = document.querySelector ? document.querySelector('[data-cresco-selection-count]') : null;
		if (count) count.textContent = String(selectionIds.length);
		var button = document.querySelector ? document.querySelector('[data-cresco="selection"]') : null;
		if (button) button.setAttribute('aria-label', 'AI selection: ' + selectionIds.length + ' elements');
	}

	function selectedIdsForMode(mode) {
		var current = selectedId();
		if ('selection' === mode) {
			if (!selectionIds.length) addSelection(current);
			return uniqueIds(selectionIds);
		}
		return [current];
	}

	function exportFilename(pid, mode, ids) {
		var target = ids.length === 1 ? ids[0] : (ids.length + '-elements');
		return 'cresco-ai-input-post' + pid + '-' + target + '-' + mode + '.json';
	}

	function copyToClipboard(text) {
		if (window.navigator && window.navigator.clipboard && typeof window.navigator.clipboard.writeText === 'function') {
			return window.navigator.clipboard.writeText(text);
		}
		return new Promise(function (resolve, reject) {
			var area = document.createElement('textarea');
			area.value = text;
			area.setAttribute('readonly', '');
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild(area);
			area.select();
			var ok = false;
			try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
			area.remove();
			ok ? resolve() : reject(new Error('This browser blocked clipboard access. Use "Download file" instead.'));
		});
	}

	/**
	 * delivery: 'download' writes a .json file, 'clipboard' copies the package, 'instructions' copies
	 * only the scope-aware AI briefing so it can be pasted ahead of the package.
	 */
	function exportScope(mode, delivery) {
		delivery = delivery || 'download';
		try {
			var ids = selectedIdsForMode(mode);
			var pid = postId();
			if (!pid) throw new Error('Cannot determine the current Elementor document ID.');
			if (!ids.length) throw new Error('Select at least one Elementor element first.');
			toast('Building AI package for ' + (mode === 'widget' ? 'this element' : mode === 'subtree' ? 'this section' : (ids.length + ' selected elements')) + '…', 'busy');
			request('/documents/' + pid + '/export?scope=' + encodeURIComponent(mode) + '&selected=' + encodeURIComponent(ids.join(',')))
				.then(function (data) {
					if (delivery === 'download') {
						download(exportFilename(pid, mode, ids), data);
						closeExportModal();
						toast('AI package exported. Send this input file to your AI, then import the returned Cresco patch.', 'success');
						return;
					}
					if (delivery === 'instructions') {
						var briefing = typeof data.instructions === 'string' ? data.instructions : '';
						if (!briefing) throw new Error('This package did not include AI instructions.');
						return copyToClipboard(briefing).then(function () {
							toast('AI instructions copied. Paste them into your AI chat, then paste or attach the package.', 'success');
						});
					}
					return copyToClipboard(JSON.stringify(data, null, 2)).then(function () {
						closeExportModal();
						toast('AI package copied to clipboard. Paste it into your AI chat, then import the returned Cresco patch.', 'success');
					});
				})
				.catch(function (error) { showExportError(error.message); toast(error.message, 'error'); });
		} catch (error) { showExportError(error.message); toast(error.message, 'error'); }
	}

	/** Clipboard reads need permission and are blocked in some contexts; fall back to a visible textarea. */
	function openPasteFallback(message) {
		var details = document.querySelector('#cresco-layer-editor-modal .cresco-layer-paste-fallback');
		if (details) details.open = true;
		var textarea = document.getElementById('cresco-layer-editor-patch');
		if (textarea) textarea.focus();
		toast(message, 'info');
	}

	function closeExportModal() {
		var box = document.getElementById('cresco-layer-export-modal');
		if (box) box.hidden = true;
	}

	function exportModal() {
		var box = document.getElementById('cresco-layer-export-modal');
		if (box) return box;
		box = document.createElement('div');
		box.id = 'cresco-layer-export-modal';
		box.className = 'cresco-layer-editor-modal';
		box.hidden = true;
		box.innerHTML = '<div class="cresco-layer-editor-modal__backdrop" data-cresco-export-close></div>' +
			'<section class="cresco-layer-editor-dialog cresco-layer-editor-dialog--export" role="dialog" aria-modal="true" aria-labelledby="cresco-layer-export-title">' +
			'<header><div><span class="cresco-layer-editor-eyebrow">Cresco AI</span><h2 id="cresco-layer-export-title">Edit with AI</h2><p class="cresco-layer-editor-subtitle">Choose exactly how much of the current design the AI may edit.</p></div><button type="button" class="cresco-layer-editor-close" data-cresco-export-close aria-label="Close">×</button></header>' +
			'<div class="cresco-layer-editor-target" id="cresco-layer-export-target"></div>' +
			'<div class="cresco-layer-scope-cards">' +
			'<label class="cresco-layer-scope-card"><input type="radio" name="cresco-export-scope" value="widget" checked><span><strong>This element only</strong><small>Change only the selected widget/container settings. Children stay untouched.</small></span></label>' +
			'<label class="cresco-layer-scope-card"><input type="radio" name="cresco-export-scope" value="subtree"><span><strong>This section + children</strong><small>Redesign the selected container and everything inside it.</small></span></label>' +
			'<label class="cresco-layer-scope-card"><input type="radio" name="cresco-export-scope" value="selection"><span><strong>Selected elements</strong><small>Edit only the elements collected in your AI selection.</small></span></label>' +
			'</div>' +
			'<div class="cresco-layer-selection-panel" id="cresco-layer-selection-panel"><div class="cresco-layer-selection-panel__head"><strong>AI selection</strong><span id="cresco-layer-selection-summary">0 elements</span></div><div id="cresco-layer-selection-chips" class="cresco-layer-selection-chips"></div><div class="cresco-layer-selection-actions"><button type="button" class="cresco-layer-secondary" id="cresco-layer-selection-add">Add current element</button><button type="button" class="cresco-layer-link-button" id="cresco-layer-selection-clear">Clear</button></div><p class="cresco-layer-editor-help">Tip: right-click any Elementor element and use <strong>Cresco · Add/remove AI selection</strong> to build a non-contiguous selection quickly.</p></div>' +
			'<div id="cresco-layer-export-error" class="cresco-layer-editor-error" hidden></div>' +
			'<footer><button type="button" class="cresco-layer-secondary" data-cresco-export-close>Cancel</button><button type="button" class="cresco-layer-secondary" id="cresco-layer-export-instructions">Copy instructions</button><button type="button" class="cresco-layer-secondary" id="cresco-layer-export-copy">Copy package</button><button type="button" class="cresco-layer-primary" id="cresco-layer-export-run">Download file</button></footer></section>';
		document.body.appendChild(box);
		Array.prototype.forEach.call(box.querySelectorAll('[data-cresco-export-close]'), function (button) { button.addEventListener('click', closeExportModal); });
		Array.prototype.forEach.call(box.querySelectorAll('input[name="cresco-export-scope"]'), function (input) {
			input.addEventListener('change', function () {
				if (input.checked) exportMode = input.value;
				renderExportSelection();
				showExportError('');
			});
		});
		var add = box.querySelector('#cresco-layer-selection-add');
		if (add) add.addEventListener('click', function () {
			try { addSelection(selectedId()); showExportError(''); }
			catch (error) { showExportError(error.message); }
		});
		var clear = box.querySelector('#cresco-layer-selection-clear');
		if (clear) clear.addEventListener('click', clearSelection);
		var run = box.querySelector('#cresco-layer-export-run');
		if (run) run.addEventListener('click', function () { exportScope(exportMode, 'download'); });
		var copy = box.querySelector('#cresco-layer-export-copy');
		if (copy) copy.addEventListener('click', function () { exportScope(exportMode, 'clipboard'); });
		var brief = box.querySelector('#cresco-layer-export-instructions');
		if (brief) brief.addEventListener('click', function () { exportScope(exportMode, 'instructions'); });
		return box;
	}

	function showExportError(message) {
		var box = document.getElementById('cresco-layer-export-error');
		if (!box) return;
		box.textContent = message || '';
		box.hidden = !message;
	}

	function renderExportSelection() {
		var box = document.getElementById('cresco-layer-export-modal');
		if (!box) return;
		var panel = box.querySelector('#cresco-layer-selection-panel');
		if (panel) panel.hidden = exportMode !== 'selection';
		var summary = box.querySelector('#cresco-layer-selection-summary');
		if (summary) summary.textContent = selectionIds.length + (selectionIds.length === 1 ? ' element' : ' elements');
		var chips = box.querySelector('#cresco-layer-selection-chips');
		if (chips) {
			chips.innerHTML = selectionIds.length ? selectionIds.map(function (id) {
				return '<button type="button" class="cresco-layer-selection-chip" data-cresco-remove-selection="' + escapeHtml(id) + '" title="Remove from AI selection"><span>' + escapeHtml(elementLabel(id)) + '</span><b>×</b></button>';
			}).join('') : '<span class="cresco-layer-selection-empty">No elements added yet.</span>';
			Array.prototype.forEach.call(chips.querySelectorAll('[data-cresco-remove-selection]'), function (button) {
				button.addEventListener('click', function () { removeSelection(button.getAttribute('data-cresco-remove-selection')); });
			});
		}
		var run = box.querySelector('#cresco-layer-export-run');
		if (run) run.disabled = exportMode === 'selection' && !selectionIds.length;
	}

	function openExport(mode) {
		try {
			var id = selectedId();
			exportMode = mode || 'widget';
			var box = exportModal();
			var target = box.querySelector('#cresco-layer-export-target');
			if (target) target.innerHTML = '<span>Current target</span><strong>' + escapeHtml(elementLabel(id)) + '</strong>';
			var radio = box.querySelector('input[name="cresco-export-scope"][value="' + exportMode + '"]');
			if (radio) radio.checked = true;
			showExportError('');
			renderExportSelection();
			box.hidden = false;
		} catch (error) { toast(error.message, 'error'); }
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
			return api.internal('document/history/start-log', { type: 'change', title: label || 'Cresco AI Import' });
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
				api.run('document/elements/create', { container: parent, model: op.element || {}, options: { at: Math.max(0, parseInt(op.position || 0, 10)) } });
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
				api.run('document/elements/move', { container: container, target: parent, options: { at: Math.max(0, parseInt(op.position || 0, 10)) } });
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
		var result = { live: false, appliedOperations: 0, unsupportedOperations: 0, requiresReload: false, error: '' };
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
		} finally { endHistory(historyId); }
		try {
			var rootId = patch.scope && patch.scope.rootElementId ? patch.scope.rootElementId : selectedElementId;
			var root = getContainer(rootId);
			if (root && root.model) { selectedModel = root.model; rememberId(rootId); }
		} catch (e) {}
		return result;
	}

	function closeImportModal() {
		var box = document.getElementById('cresco-layer-editor-modal');
		if (box) box.hidden = true;
	}

	function detectPayload(text) {
		var clean = String(text || '').trim();
		if (!clean) return { kind: 'empty', message: 'Choose a Cresco AI result file or paste a patch.' };
		var payload;
		try { payload = JSON.parse(clean); }
		catch (e) { return { kind: 'invalid-json', message: 'This file is not valid JSON.' }; }
		if (payload && payload.schema === 'cresco-layer-patch/v1') return { kind: 'patch', payload: payload, text: clean };
		if (payload && payload.schema === 'cresco-layer-ai-package/v2') return { kind: 'ai-package', payload: payload, message: 'This is a Cresco AI input package. Send it to the AI first, then import the returned cresco-layer-patch/v1 result.' };
		if (payload && payload.type === 'elementor') return { kind: 'elementor-clipboard', payload: payload, message: 'This is Elementor clipboard/export data, not a Cresco AI result. Import AI only accepts cresco-layer-patch/v1.' };
		return { kind: 'unsupported', payload: payload, message: 'Unsupported JSON. Expected schema cresco-layer-patch/v1.' };
	}

	function scopeLabel(mode) {
		if (mode === 'widget') return 'This element only';
		if (mode === 'subtree') return 'This section + children';
		if (mode === 'selection') return 'Selected elements';
		if (mode === 'document') return 'Whole document';
		return mode || 'Unknown';
	}

	function patchTargetCheck(patch) {
		var scope = patch && patch.scope ? patch.scope : null;
		var pid = patch && patch.base ? parseInt(patch.base.postId || 0, 10) : 0;
		if (pid && postId() && pid !== postId()) return { ok: false, message: 'This patch belongs to Elementor document #' + pid + ', but the current editor is document #' + postId() + '.' };
		if (!scope || scope.mode === 'document') return { ok: true, current: selectedIdSafe(), target: '', mode: 'document' };
		var current = selectedIdSafe();
		if ((scope.mode === 'widget' || scope.mode === 'subtree') && validId(scope.rootElementId || '')) {
			if (!current) return { ok: false, message: 'Select the patch target ' + scope.rootElementId + ' in Elementor before validating.', current: '', target: scope.rootElementId, mode: scope.mode };
			if (current !== scope.rootElementId) return { ok: false, message: 'Patch target is ' + scope.rootElementId + ', but you currently selected ' + current + '. Select the correct Elementor element first.', current: current, target: scope.rootElementId, mode: scope.mode };
		}
		if (scope.mode === 'selection') {
			var ids = uniqueIds(scope.elementIds || []);
			if (!ids.length) return { ok: false, message: 'Selection patch does not contain elementIds.', current: current, target: '', mode: scope.mode };
			return { ok: true, current: current, target: ids.join(', '), mode: scope.mode, count: ids.length };
		}
		return { ok: true, current: current, target: scope.rootElementId || '', mode: scope.mode };
	}

	function importModal() {
		var box = document.getElementById('cresco-layer-editor-modal');
		if (box) return box;
		box = document.createElement('div');
		box.id = 'cresco-layer-editor-modal';
		box.className = 'cresco-layer-editor-modal';
		box.hidden = true;
		box.innerHTML = '<div class="cresco-layer-editor-modal__backdrop" data-cresco-close></div>' +
			'<section class="cresco-layer-editor-dialog" role="dialog" aria-modal="true" aria-labelledby="cresco-layer-dialog-title">' +
			'<header><div><span class="cresco-layer-editor-eyebrow">Cresco AI</span><h2 id="cresco-layer-dialog-title">Import AI result</h2><p class="cresco-layer-editor-subtitle">Drop the JSON returned by the AI. Cresco will detect the scope and target automatically.</p></div><button type="button" class="cresco-layer-editor-close" data-cresco-close aria-label="Close">×</button></header>' +
			'<div class="cresco-layer-import-body">' +
			'<div id="cresco-layer-drop-zone" class="cresco-layer-drop-zone" tabindex="0" role="button" aria-label="Choose Cresco AI result JSON"><input id="cresco-layer-patch-file" type="file" accept="application/json,.json" hidden><strong>Drop Cresco AI result here</strong><span>or</span><span class="cresco-layer-drop-actions"><button type="button" class="cresco-layer-secondary" id="cresco-layer-choose-file">Choose JSON file</button><button type="button" class="cresco-layer-secondary" id="cresco-layer-paste-clipboard">Paste from clipboard</button></span><small>Expected: <code>cresco-layer-patch/v1</code></small></div>' +
			'<div id="cresco-layer-import-detection" class="cresco-layer-import-detection is-neutral"><strong>No AI result loaded yet.</strong><span>Choose the patch file downloaded from your AI conversation.</span></div>' +
			'<details class="cresco-layer-paste-fallback"><summary>Paste JSON manually</summary><textarea id="cresco-layer-editor-patch" rows="12" spellcheck="false" placeholder="{&quot;schema&quot;:&quot;cresco-layer-patch/v1&quot;,...}"></textarea></details>' +
			'<div id="cresco-layer-editor-error" class="cresco-layer-editor-error" hidden><strong>Validation failed</strong><p></p><button type="button" class="cresco-layer-link-button" id="cresco-layer-copy-diagnostics">Copy diagnostics</button></div>' +
			'<div id="cresco-layer-editor-preview" class="cresco-layer-editor-preview">No patch preview yet.</div>' +
			'</div>' +
			'<footer><button type="button" class="cresco-layer-secondary" data-cresco-close>Cancel</button><button type="button" class="cresco-layer-secondary" id="cresco-layer-editor-validate" disabled>Validate & Preview</button><button type="button" class="cresco-layer-primary" id="cresco-layer-editor-apply" disabled>Apply reviewed patch</button></footer></section>';
		document.body.appendChild(box);
		Array.prototype.forEach.call(box.querySelectorAll('[data-cresco-close]'), function (button) { button.addEventListener('click', closeImportModal); });
		var choose = box.querySelector('#cresco-layer-choose-file');
		var input = box.querySelector('#cresco-layer-patch-file');
		var drop = box.querySelector('#cresco-layer-drop-zone');
		var pasteButton = box.querySelector('#cresco-layer-paste-clipboard');
		if (choose && input) choose.addEventListener('click', function () { input.click(); });
		if (pasteButton) pasteButton.addEventListener('click', function (event) {
			event.stopPropagation();
			if (!window.navigator || !navigator.clipboard || typeof navigator.clipboard.readText !== 'function') {
				openPasteFallback('This browser cannot read the clipboard directly. Paste the JSON here instead.');
				return;
			}
			navigator.clipboard.readText().then(function (text) {
				if (!String(text || '').trim()) { openPasteFallback('The clipboard is empty. Paste the JSON here instead.'); return; }
				setPatchText(text, 'Clipboard', true);
			}).catch(function () {
				openPasteFallback('Clipboard access was blocked. Paste the JSON here instead.');
			});
		});
		if (drop && input) drop.addEventListener('click', function (event) { if (event.target !== choose && event.target !== input && event.target !== pasteButton) input.click(); });
		if (drop) drop.addEventListener('keydown', function (event) { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); if (input) input.click(); } });
		if (input) input.addEventListener('change', function () { if (input.files && input.files[0]) readPatchFile(input.files[0]); });
		if (drop) {
			['dragenter', 'dragover'].forEach(function (name) { drop.addEventListener(name, function (event) { event.preventDefault(); drop.classList.add('is-dragging'); }); });
			['dragleave', 'drop'].forEach(function (name) { drop.addEventListener(name, function (event) { event.preventDefault(); drop.classList.remove('is-dragging'); }); });
			drop.addEventListener('drop', function (event) { var files = event.dataTransfer && event.dataTransfer.files; if (files && files[0]) readPatchFile(files[0]); });
		}
		var textarea = box.querySelector('#cresco-layer-editor-patch');
		if (textarea) textarea.addEventListener('input', function () { setPatchText(textarea.value, 'Pasted JSON', true); });
		var validate = box.querySelector('#cresco-layer-editor-validate');
		if (validate) validate.addEventListener('click', previewPatch);
		var apply = box.querySelector('#cresco-layer-editor-apply');
		if (apply) apply.addEventListener('click', applyPatch);
		var copy = box.querySelector('#cresco-layer-copy-diagnostics');
		if (copy) copy.addEventListener('click', copyDiagnostics);
		return box;
	}

	function readPatchFile(file) {
		if (!file) return;
		if (file.name && !/\.json$/i.test(file.name)) {
			showImportError('Choose a .json file.');
			return;
		}
		var Reader = window.FileReader || (typeof FileReader !== 'undefined' ? FileReader : null);
		if (!Reader) { showImportError('This browser cannot read local files here. Use “Paste JSON manually” instead.'); return; }
		var reader = new Reader();
		reader.onload = function () { setPatchText(String(reader.result || ''), file.name || 'JSON file'); };
		reader.onerror = function () { showImportError('Cresco could not read that JSON file.'); };
		reader.readAsText(file);
	}

	function setPatchText(text, sourceName, fromTextarea) {
		var box = importModal();
		var textarea = box.querySelector('#cresco-layer-editor-patch');
		if (!fromTextarea && textarea) textarea.value = String(text || '');
		importSourceName = sourceName || '';
		previewedPatch = '';
		var apply = box.querySelector('#cresco-layer-editor-apply');
		if (apply) apply.disabled = true;
		showImportError('');
		renderImportDetection(detectPayload(text));
	}

	function renderImportDetection(result) {
		var box = importModal();
		var detection = box.querySelector('#cresco-layer-import-detection');
		var validate = box.querySelector('#cresco-layer-editor-validate');
		if (!detection || !validate) return;
		validate.disabled = true;
		detection.className = 'cresco-layer-import-detection is-neutral';
		if (result.kind !== 'patch') {
			detection.className = 'cresco-layer-import-detection ' + (result.kind === 'empty' ? 'is-neutral' : 'is-error');
			detection.innerHTML = '<strong>' + escapeHtml(result.kind === 'empty' ? 'No AI result loaded yet.' : 'Wrong JSON type') + '</strong><span>' + escapeHtml(result.message || '') + '</span>';
			if (result.kind !== 'empty') showImportError(result.message || 'Unsupported JSON.');
			return;
		}
		var patch = result.payload;
		var scope = patch.scope || { mode: 'document', rootElementId: '', elementIds: [] };
		importMode = scope.mode || 'document';
		var target = patchTargetCheck(patch);
		var ops = Array.isArray(patch.operations) ? patch.operations.length : 0;
		var source = importSourceName ? '<span class="cresco-layer-file-name">' + escapeHtml(importSourceName) + '</span>' : '';
		var targetText = scope.mode === 'selection' ? ((scope.elementIds || []).length + ' selected elements') : (scope.rootElementId || 'Document');
		detection.className = 'cresco-layer-import-detection ' + (target.ok ? 'is-success' : 'is-warning');
		detection.innerHTML = '<div><strong>✓ Cresco AI Patch v1</strong>' + source + '</div><dl><div><dt>Scope</dt><dd>' + escapeHtml(scopeLabel(importMode)) + '</dd></div><div><dt>Target</dt><dd>' + escapeHtml(targetText) + '</dd></div><div><dt>Operations</dt><dd>' + ops + '</dd></div></dl>' + (target.ok ? '<span class="cresco-layer-target-ok">✓ Target check passed</span>' : '<span class="cresco-layer-target-warning">⚠ ' + escapeHtml(target.message) + '</span>');
		if (target.ok) validate.disabled = false;
		else showImportError(target.message);
	}

	function openImport() {
		var box = importModal();
		previewedPatch = '';
		var apply = box.querySelector('#cresco-layer-editor-apply');
		if (apply) apply.disabled = true;
		var preview = box.querySelector('#cresco-layer-editor-preview');
		if (preview) preview.textContent = 'No patch preview yet.';
		showImportError('');
		var textarea = box.querySelector('#cresco-layer-editor-patch');
		renderImportDetection(detectPayload(textarea ? textarea.value : ''));
		box.hidden = false;
	}

	function parsePatch() {
		var textarea = importModal().querySelector('#cresco-layer-editor-patch');
		var text = textarea ? textarea.value.trim() : '';
		var detected = detectPayload(text);
		if (detected.kind !== 'patch') throw new Error(detected.message || 'Choose a cresco-layer-patch/v1 JSON result first.');
		var target = patchTargetCheck(detected.payload);
		if (!target.ok) throw new Error(target.message);
		return { text: detected.text, patch: detected.payload };
	}

	function expectedScope(patch) {
		var scope = patch && patch.scope ? patch.scope : { mode: 'document', rootElementId: '' };
		if (scope.mode === 'widget' || scope.mode === 'subtree') return { mode: scope.mode, rootElementId: selectedId() };
		if (scope.mode === 'selection') return null;
		return { mode: 'document', rootElementId: '' };
	}

	function showImportError(message) {
		var box = document.getElementById('cresco-layer-editor-error');
		if (!box) return;
		var paragraph = box.querySelector('p');
		if (paragraph) paragraph.textContent = message || '';
		box.hidden = !message;
		if (message) box.dataset.diagnostics = message;
		else delete box.dataset.diagnostics;
	}

	function copyDiagnostics() {
		var box = document.getElementById('cresco-layer-editor-error');
		var message = box && box.dataset ? (box.dataset.diagnostics || '') : '';
		var diagnostics = 'Cresco Layer ' + bridge.version + '\nPost: ' + postId() + '\nSelected: ' + selectedIdSafe() + '\nSource: ' + (importSourceName || 'pasted JSON') + '\nError: ' + message;
		try {
			if (window.navigator && window.navigator.clipboard && typeof window.navigator.clipboard.writeText === 'function') {
				window.navigator.clipboard.writeText(diagnostics).then(function () { toast('Diagnostics copied.', 'success'); });
				return;
			}
		} catch (e) {}
		toast(diagnostics, 'info');
	}

	function showPreview(data) {
		var diff = data.diff || {};
		var semantic = data.semantic || {};
		var warnings = Array.isArray(semantic.warnings) ? semantic.warnings.length : 0;
		var target = data.scope && data.scope.mode === 'selection' ? ((data.scope.elementIds || []).length + ' elements') : (data.scope && data.scope.rootElementId ? data.scope.rootElementId : 'Document');
		var html = '<div class="cresco-layer-preview-head"><strong>Validated · ready to apply</strong><span>' + escapeHtml(scopeLabel(data.scope && data.scope.mode ? data.scope.mode : importMode)) + ' · ' + escapeHtml(target) + '</span></div>' +
			'<div class="cresco-layer-preview-grid"><span><b>' + (diff.total || 0) + '</b> operations</span><span><b>' + (diff.updated || 0) + '</b> updated</span><span><b>' + (diff.replaced || 0) + '</b> replaced</span><span><b>' + (diff.inserted || 0) + '</b> inserted</span><span><b>' + (diff.removed || 0) + '</b> removed</span><span><b>' + (diff.moved || 0) + '</b> moved</span></div>';
		if (semantic.nativeControlOperations || semantic.structuralOperations) html += '<p>Native controls: ' + (semantic.nativeControlOperations || 0) + ' · Structural changes: ' + (semantic.structuralOperations || 0) + '</p>';
		if (warnings) html += '<em>⚠ ' + warnings + ' semantic warning' + (warnings === 1 ? '' : 's') + ' to review.</em>';
		if (data.staleDocumentButScopeUnchanged) html += '<em>The page changed elsewhere, but this exported scope is still unchanged.</em>';
		importModal().querySelector('#cresco-layer-editor-preview').innerHTML = html;
	}

	function previewPatch() {
		try {
			var item = parsePatch();
			var box = importModal();
			var apply = box.querySelector('#cresco-layer-editor-apply');
			if (apply) apply.disabled = true;
			previewedPatch = '';
			showImportError('');
			request('/documents/' + postId() + '/preview', { method: 'POST', body: JSON.stringify({ patch: item.patch, expectedScope: expectedScope(item.patch) }) })
				.then(function (data) {
					showPreview(data);
					previewedPatch = item.text;
					if (apply) apply.disabled = false;
					toast('Patch is valid for this Elementor scope.', 'success');
				})
				.catch(function (error) { showImportError(error.message); toast(error.message, 'error'); });
		} catch (error) { showImportError(error.message); toast(error.message, 'error'); }
	}

	function applyPatch() {
		try {
			var item = parsePatch();
			if (!previewedPatch || previewedPatch !== item.text) throw new Error('Validate the patch again before applying.');
			if (!window.confirm('Apply this reviewed AI patch? It will not publish the page.')) return;
			var box = importModal();
			var apply = box.querySelector('#cresco-layer-editor-apply');
			if (apply) apply.disabled = true;
			showImportError('');
			request('/documents/' + postId() + '/apply', { method: 'POST', body: JSON.stringify({ patch: item.patch, expectedScope: expectedScope(item.patch) }) })
				.then(function (data) {
					var liveResult = applyPatchToEditor(item.patch);
					previewedPatch = '';
					closeImportModal();
					var verified = data && data.verification ? data.verification.verified : true;
					if (!verified) toast('AI changes were saved, but post-save verification reported a mismatch. Reopen Elementor and review the target.', 'error');
					else if (liveResult.requiresReload) {
						var details = liveResult.error ? ' ' + liveResult.error : '';
						toast('AI changes were saved. ' + liveResult.appliedOperations + ' operations synced to the open canvas; some changes need an Elementor reopen.' + details, 'success');
					} else toast('AI changes applied live in Elementor. Review the canvas, then Update/Publish when ready.', 'success');
				})
				.catch(function (error) { if (apply) apply.disabled = false; showImportError(error.message); toast(error.message, 'error'); });
		} catch (error) { showImportError(error.message); toast(error.message, 'error'); }
	}

	function toolbar() {
		if (!document.body) return null;
		var tools = document.getElementById('cresco-layer-editor-tools');
		if (tools) return tools;
		tools = document.createElement('div');
		tools.id = 'cresco-layer-editor-tools';
		tools.className = 'cresco-layer-editor-tools';
		tools.innerHTML = '<button type="button" class="cresco-layer-tool-primary" data-cresco="edit">✦ Edit with AI</button><button type="button" data-cresco="import">Import AI</button><button type="button" class="cresco-layer-selection-tool" data-cresco="selection">Selection <span data-cresco-selection-count>0</span></button>';
		Array.prototype.forEach.call(tools.querySelectorAll('button'), function (button) {
			button.addEventListener('click', function () {
				var action = button.getAttribute('data-cresco');
				if (action === 'edit') openExport('widget');
				else if (action === 'import') openImport();
				else if (action === 'selection') {
					try { if (!selectionIds.length) addSelection(selectedId()); openExport('selection'); }
					catch (error) { toast(error.message, 'error'); }
				}
			});
		});
		document.body.appendChild(tools);
		renderSelectionCount();
		return tools;
	}

	function contextGroup(view) {
		function selectView() { if (view) rememberModel(null, view.model, view); }
		return { name: 'cresco-layer-ai-exchange', actions: [
			{ name: 'cresco-edit-element-ai', icon: 'eicon-ai', title: 'Cresco · Edit this element with AI', isEnabled: function () { return true; }, callback: function () { selectView(); openExport('widget'); } },
			{ name: 'cresco-edit-subtree-ai', icon: 'eicon-navigator', title: 'Cresco · Edit this section + children', isEnabled: function () { return true; }, callback: function () { selectView(); openExport('subtree'); } },
			{ name: 'cresco-toggle-ai-selection', icon: 'eicon-checkbox', title: 'Cresco · Add/remove AI selection', isEnabled: function () { return true; }, callback: function () { selectView(); var id = selectedId(); var added = toggleSelection(id); toast((added ? 'Added ' : 'Removed ') + id + (added ? ' to' : ' from') + ' AI selection.', 'success'); } },
			{ name: 'cresco-import-ai', icon: 'eicon-import-kit', title: 'Cresco · Import AI result', isEnabled: function () { return true; }, callback: function () { selectView(); openImport(); } }
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
		types.forEach(function (type) { elementor.hooks.addFilter('elements/' + type + '/contextMenuGroups', function (groups, view) { return addContext(groups, type, view); }); });
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
	bridge.openEdit = function (mode) { openExport(mode || 'widget'); };
	bridge.exportWidget = function () { exportScope('widget'); };
	bridge.exportSubtree = function () { exportScope('subtree'); };
	bridge.exportSelection = function () { exportScope('selection'); };
	bridge.addCurrentToSelection = function () { return addSelection(selectedId()); };
	bridge.clearSelection = clearSelection;
	bridge.openImport = openImport;
	bridge.applyPatchToEditor = applyPatchToEditor;
	bridge.detectPayload = detectPayload;
	bridge.getDiagnostics = function () {
		var id = selectedIdSafe();
		return {
			version: bridge.version,
			state: bridge.state,
			postId: postId(),
			elementorPresent: !!window.elementor,
			hooksReady: ready(),
			hooksInstalled: hooksInstalled,
			liveEditorReady: liveEditorReady(),
			selectedElementId: id,
			selectionElementIds: selectionIds.slice(),
			elementorVersion: cfg.elementorVersion || null,
			elementorProVersion: cfg.elementorProVersion || null
		};
	};

	start();
}());
