(function () {
	'use strict';

	var config = window.crescoLayerEditor || {};
	var currentModel = null;
	var currentPreviewText = '';
	var currentImportMode = 'subtree';
	var hooksInstalled = false;

	function postId() {
		var value = parseInt(config.postId || 0, 10);
		if (!value && window.elementor && elementor.config && elementor.config.document && elementor.config.document.id) {
			value = parseInt(elementor.config.document.id, 10);
		}
		return value || 0;
	}

	function endpoint(path) {
		return String(config.restRoot || '').replace(/\/$/, '') + path;
	}

	function request(path, options) {
		options = options || {};
		options.headers = Object.assign({
			'X-WP-Nonce': config.nonce,
			'Content-Type': 'application/json'
		}, options.headers || {});
		return fetch(endpoint(path), options).then(function (response) {
			return response.json().catch(function () { return {}; }).then(function (body) {
				if (!response.ok) {
					throw new Error(body && body.message ? body.message : 'Cresco Layer request failed (' + response.status + ').');
				}
				return body;
			});
		});
	}

	function modelId(model) {
		if (!model) return '';
		if (model.id) return String(model.id);
		if (typeof model.get === 'function') return String(model.get('id') || '');
		return '';
	}

	function resolveModel() {
		try {
			if (window.elementor && elementor.channels && elementor.channels.editor) {
				var selected = elementor.channels.editor.request('selectedElement');
				if (selected) {
					if (selected.model && modelId(selected.model)) return selected.model;
					if (modelId(selected)) return selected;
				}
			}
		} catch (e) {}
		return currentModel;
	}

	function selectedId() {
		var id = modelId(resolveModel());
		if (!id) throw new Error('Select an Elementor widget or container first.');
		return id;
	}

	function downloadJson(filename, data) {
		var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
		var url = URL.createObjectURL(blob);
		var anchor = document.createElement('a');
		anchor.href = url;
		anchor.download = filename;
		document.body.appendChild(anchor);
		anchor.click();
		anchor.remove();
		setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
	}

	function notify(message, tone) {
		var toast = document.getElementById('cresco-layer-editor-toast');
		if (!toast) {
			toast = document.createElement('div');
			toast.id = 'cresco-layer-editor-toast';
			document.body.appendChild(toast);
		}
		toast.className = 'cresco-layer-editor-toast is-' + (tone || 'info');
		toast.textContent = message;
		toast.hidden = false;
		clearTimeout(toast._timer);
		toast._timer = setTimeout(function () { toast.hidden = true; }, 5000);
	}

	function exportScope(mode) {
		try {
			var id = selectedId();
			var pid = postId();
			if (!pid) throw new Error('Cresco Layer could not determine the current Elementor document ID.');
			notify('Building complete Elementor ' + mode + ' package…', 'busy');
			request('/documents/' + pid + '/export?scope=' + encodeURIComponent(mode) + '&selected=' + encodeURIComponent(id))
				.then(function (data) {
					downloadJson('cresco-layer-' + pid + '-' + id + '-' + mode + '.json', data);
					notify('Cresco AI package exported.', 'success');
				})
				.catch(function (error) { notify(error.message, 'error'); });
		} catch (error) {
			notify(error.message, 'error');
		}
	}

	function ensureModal() {
		var modal = document.getElementById('cresco-layer-editor-modal');
		if (modal) return modal;
		modal = document.createElement('div');
		modal.id = 'cresco-layer-editor-modal';
		modal.className = 'cresco-layer-editor-modal';
		modal.hidden = true;
		modal.innerHTML = '' +
			'<div class="cresco-layer-editor-modal__backdrop" data-cresco-close></div>' +
			'<section class="cresco-layer-editor-dialog" role="dialog" aria-modal="true" aria-labelledby="cresco-layer-dialog-title">' +
				'<header><div><span class="cresco-layer-editor-eyebrow">AI exchange</span><h2 id="cresco-layer-dialog-title">Import AI changes</h2></div><button type="button" class="cresco-layer-editor-close" data-cresco-close aria-label="Close">×</button></header>' +
				'<div class="cresco-layer-editor-scope"><label><input type="radio" name="cresco-import-scope" value="widget"> Widget only</label><label><input type="radio" name="cresco-import-scope" value="subtree" checked> Subtree</label></div>' +
				'<p class="cresco-layer-editor-help">Paste the <code>cresco-layer-patch/v1</code> returned from the package exported for this exact element. Cresco validates the target and scope checksum before applying.</p>' +
				'<textarea id="cresco-layer-editor-patch" rows="18" spellcheck="false" placeholder='{&quot;schema&quot;:&quot;cresco-layer-patch/v1&quot;,...}'></textarea>' +
				'<div id="cresco-layer-editor-preview" class="cresco-layer-editor-preview">No patch preview yet.</div>' +
				'<footer><button type="button" class="cresco-layer-secondary" data-cresco-close>Cancel</button><button type="button" class="cresco-layer-secondary" id="cresco-layer-editor-validate">Validate & Preview</button><button type="button" class="cresco-layer-primary" id="cresco-layer-editor-apply" disabled>Apply reviewed patch</button></footer>' +
			'</section>';
		document.body.appendChild(modal);

		Array.prototype.forEach.call(modal.querySelectorAll('[data-cresco-close]'), function (button) {
			button.addEventListener('click', closeModal);
		});
		Array.prototype.forEach.call(modal.querySelectorAll('input[name="cresco-import-scope"]'), function (input) {
			input.addEventListener('change', function () {
				currentImportMode = input.checked ? input.value : currentImportMode;
				currentPreviewText = '';
				modal.querySelector('#cresco-layer-editor-apply').disabled = true;
			});
		});
		modal.querySelector('#cresco-layer-editor-patch').addEventListener('input', function () {
			if (this.value.trim() !== currentPreviewText) modal.querySelector('#cresco-layer-editor-apply').disabled = true;
		});
		modal.querySelector('#cresco-layer-editor-validate').addEventListener('click', validatePatch);
		modal.querySelector('#cresco-layer-editor-apply').addEventListener('click', applyPatch);
		return modal;
	}

	function openImport(mode) {
		try {
			selectedId();
			currentImportMode = mode || 'subtree';
			currentPreviewText = '';
			var modal = ensureModal();
			var input = modal.querySelector('input[name="cresco-import-scope"][value="' + currentImportMode + '"]');
			if (input) input.checked = true;
			modal.querySelector('#cresco-layer-editor-apply').disabled = true;
			modal.querySelector('#cresco-layer-editor-preview').textContent = 'No patch preview yet.';
			modal.hidden = false;
			setTimeout(function () { modal.querySelector('#cresco-layer-editor-patch').focus(); }, 0);
		} catch (error) {
			notify(error.message, 'error');
		}
	}

	function closeModal() {
		var modal = document.getElementById('cresco-layer-editor-modal');
		if (modal) modal.hidden = true;
	}

	function parsePatch() {
		var modal = ensureModal();
		var text = modal.querySelector('#cresco-layer-editor-patch').value.trim();
		if (!text) throw new Error('Paste a Cresco Layer AI patch first.');
		try { return { text: text, patch: JSON.parse(text) }; }
		catch (e) { throw new Error('The AI patch is not valid JSON.'); }
	}

	function expectedScope() {
		return { mode: currentImportMode, rootElementId: selectedId() };
	}

	function renderPreview(data) {
		var box = ensureModal().querySelector('#cresco-layer-editor-preview');
		var diff = data.diff || {};
		box.innerHTML = '' +
			'<strong>Validated · ' + (diff.total || 0) + ' operations</strong>' +
			'<span>Updated ' + (diff.updated || 0) + '</span>' +
			'<span>Replaced ' + (diff.replaced || 0) + '</span>' +
			'<span>Inserted ' + (diff.inserted || 0) + '</span>' +
			'<span>Removed ' + (diff.removed || 0) + '</span>' +
			'<span>Moved ' + (diff.moved || 0) + '</span>' +
			(data.staleDocumentButScopeUnchanged ? '<em>The page changed elsewhere after export, but this exported scope is unchanged and remains safe to apply.</em>' : '');
	}

	function validatePatch() {
		try {
			var pid = postId();
			var item = parsePatch();
			var modal = ensureModal();
			modal.querySelector('#cresco-layer-editor-apply').disabled = true;
			currentPreviewText = '';
			notify('Validating scoped Elementor patch…', 'busy');
			request('/documents/' + pid + '/preview', {
				method: 'POST',
				body: JSON.stringify({ patch: item.patch, expectedScope: expectedScope() })
			}).then(function (data) {
				renderPreview(data);
				currentPreviewText = item.text;
				modal.querySelector('#cresco-layer-editor-apply').disabled = false;
				notify('Patch is valid for the selected Elementor scope.', 'success');
			}).catch(function (error) { notify(error.message, 'error'); });
		} catch (error) {
			notify(error.message, 'error');
		}
	}

	function applyPatch() {
		try {
			var pid = postId();
			var item = parsePatch();
			if (!currentPreviewText || currentPreviewText !== item.text) throw new Error('The patch changed after preview. Validate it again.');
			if (!window.confirm('Apply this reviewed AI patch to the selected Elementor ' + currentImportMode + '? It will not publish the page.')) return;
			var modal = ensureModal();
			modal.querySelector('#cresco-layer-editor-apply').disabled = true;
			notify('Applying through Elementor working data…', 'busy');
			request('/documents/' + pid + '/apply', {
				method: 'POST',
				body: JSON.stringify({ patch: item.patch, expectedScope: expectedScope() })
			}).then(function () {
				currentPreviewText = '';
				closeModal();
				notify('AI changes applied. Reload/reopen the Elementor document if the canvas does not refresh automatically, then review before Update/Publish.', 'success');
			}).catch(function (error) {
				modal.querySelector('#cresco-layer-editor-apply').disabled = false;
				notify(error.message, 'error');
			});
		} catch (error) {
			notify(error.message, 'error');
		}
	}

	function ensureFloatingTools() {
		var tools = document.getElementById('cresco-layer-editor-tools');
		if (tools) return tools;
		tools = document.createElement('div');
		tools.id = 'cresco-layer-editor-tools';
		tools.className = 'cresco-layer-editor-tools';
		tools.innerHTML = '<button type="button" title="Export selected widget configuration for AI">AI Widget</button><button type="button" title="Export selected container/widget and all descendants for AI">AI Subtree</button><button type="button" title="Import a reviewed AI patch for the selected subtree">Import AI</button>';
		var buttons = tools.querySelectorAll('button');
		buttons[0].addEventListener('click', function () { exportScope('widget'); });
		buttons[1].addEventListener('click', function () { exportScope('subtree'); });
		buttons[2].addEventListener('click', function () { openImport('subtree'); });
		document.body.appendChild(tools);
		return tools;
	}

	function captureModel(panel, model) {
		if (modelId(model)) currentModel = model;
		ensureFloatingTools();
	}

	function installHooks() {
		if (hooksInstalled || !window.elementor || !elementor.hooks) return;
		hooksInstalled = true;
		['widget', 'container', 'section', 'column'].forEach(function (type) {
			elementor.hooks.addAction('panel/open_editor/' + type, captureModel);
		});

		elementor.hooks.addFilter('elements/context-menu/groups', function (groups, elementType) {
			if (['widget', 'container', 'section', 'column'].indexOf(elementType) === -1) return groups;
			groups.push({
				name: 'cresco-layer-ai-exchange',
				actions: [
					{ name: 'cresco-export-widget', icon: 'eicon-export-kit', title: 'Cresco · Export element for AI', isEnabled: function () { return true; }, callback: function () { exportScope('widget'); } },
					{ name: 'cresco-export-subtree', icon: 'eicon-navigator', title: 'Cresco · Export subtree for AI', isEnabled: function () { return true; }, callback: function () { exportScope('subtree'); } },
					{ name: 'cresco-import-ai', icon: 'eicon-import-kit', title: 'Cresco · Import AI changes', isEnabled: function () { return true; }, callback: function () { openImport('subtree'); } }
				]
			});
			return groups;
		});
		ensureFloatingTools();
	}

	window.addEventListener('elementor/init', installHooks);
	if (window.elementor && elementor.hooks) installHooks();
}());
