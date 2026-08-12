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

	bridge.version = cfg.version || '0.2.1';
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
		el._timer = setTimeout(function () { el.hidden = true; }, 5000);
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
					previewedPatch = '';
					closeModal();
					toast('AI changes applied. Reload Elementor if the canvas has not refreshed, then review before Publish.', 'success');
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
	bridge.getDiagnostics = function () {
		var id = '';
		try { id = selectedId(); } catch (e) {}
		return { version: bridge.version, state: bridge.state, postId: postId(), elementorPresent: !!window.elementor, hooksReady: ready(), hooksInstalled: hooksInstalled, selectedElementId: id, elementorVersion: cfg.elementorVersion || null, elementorProVersion: cfg.elementorProVersion || null };
	};

	start();
}());
