(function () {
	'use strict';

	var cfg = window.crescoLayerEditor || {};
	var bridge = window.CrescoLayerEditorBridge || {};
	var state = { prepared: null, preparedTarget: '', preparedScope: '', previewedText: '', previewedTarget: '', previewedScope: '', referenceImage: null };

	function esc(value) { return String(value == null ? '' : value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;'); }
	function restRoot() { return String(cfg.restRoot || '').replace(/\/$/, ''); }
	function postId() {
		var id = parseInt(cfg.postId || 0, 10); if (id) return id;
		try { return parseInt(window.elementor && elementor.config && elementor.config.document && elementor.config.document.id || 0, 10) || 0; } catch (e) { return 0; }
	}
	function request(path, options) {
		options = options || {};
		options.headers = Object.assign({ 'X-WP-Nonce': cfg.nonce || '', 'Content-Type': 'application/json' }, options.headers || {});
		return window.fetch(restRoot() + path, options).then(function (response) {
			return response.json().catch(function () { return {}; }).then(function (body) {
				if (!response.ok) { var error = new Error(body.message || ('Cresco Layer request failed (' + response.status + ').')); error.status = response.status; error.body = body; throw error; }
				return body;
			});
		});
	}
	function selectedId() {
		try { var diagnostics = bridge.getDiagnostics ? bridge.getDiagnostics() : null; if (diagnostics && diagnostics.selectedElementId) return String(diagnostics.selectedElementId); } catch (e) {}
		try {
			var selected = window.elementor && elementor.channels && elementor.channels.editor ? elementor.channels.editor.request('selectedElement') : null;
			var model = selected && (selected.model || selected); var id = model && (model.id || (typeof model.get === 'function' ? model.get('id') : ''));
			if (id) return String(id);
		} catch (e2) {}
		return '';
	}
	function selectedData(id) {
		try { var container = window.elementor && typeof elementor.getContainer === 'function' ? elementor.getContainer(id) : null; if (container && container.model && typeof container.model.toJSON === 'function') return container.model.toJSON() || {}; } catch (e) {}
		return { id: id };
	}
	function targetInfo() {
		var id = selectedId();
		if (!id) return { id: '', type: 'No selection', defaultScope: 'document', label: 'No Elementor element selected' };
		var data = selectedData(id), type = String(data.widgetType || data.elType || 'element');
		return { id: id, type: type, defaultScope: data.elType === 'widget' || !!data.widgetType ? 'widget' : 'subtree', label: type.replace(/[-_]+/g, ' ') + ' - ' + id };
	}
	function ensureExact() {
		try { if (window.CrescoLayerExactRuntimeExport && typeof window.CrescoLayerExactRuntimeExport.setProfile === 'function') window.CrescoLayerExactRuntimeExport.setProfile('exact'); } catch (e) {}
	}
	function referenceMetadata(file) {
		if (!file) return { provided: false, delivery: 'bundle-or-attach-separately' };
		return { provided: true, name: String(file.name || ''), type: String(file.type || ''), size: Number(file.size || 0), delivery: 'bundle-or-attach-separately' };
	}
	function toast(message, tone) {
		var el = document.getElementById('cresco-ai-panel-toast');
		if (!el) { el = document.createElement('div'); el.id = 'cresco-ai-panel-toast'; document.body.appendChild(el); }
		el.className = 'cresco-ai-panel-toast is-' + (tone || 'info'); el.textContent = message; el.hidden = false;
		clearTimeout(el._timer); el._timer = setTimeout(function () { el.hidden = true; }, 5200);
	}
	function setBusy(button, busy, label) {
		if (!button) return; button.disabled = !!busy;
		if (busy) { button.dataset.oldText = button.textContent; button.textContent = label || 'Working...'; }
		else if (button.dataset.oldText) { button.textContent = button.dataset.oldText; delete button.dataset.oldText; }
	}
	function selectedScope(box, name) {
		var checked = box.querySelector('input[name="' + name + '"]:checked'); return checked ? checked.value : 'subtree';
	}
	function setScope(box, name, scope) {
		var input = box.querySelector('input[name="' + name + '"][value="' + scope + '"]'); if (input) input.checked = true;
	}
	function panel() {
		var existing = document.getElementById('cresco-ai-panel'); if (existing) return existing;
		var box = document.createElement('aside'); box.id = 'cresco-ai-panel'; box.className = 'cresco-ai-panel'; box.hidden = true;
		box.innerHTML = '' +
			'<div class="cresco-ai-panel__head"><div><span class="cresco-ai-kicker">Cresco Layer</span><h2>External AI Exchange</h2></div><button type="button" class="cresco-ai-icon-button" data-cresco-ai-close aria-label="Close">x</button></div>' +
			'<div class="cresco-ai-tabs"><button type="button" class="is-active" data-cresco-ai-tab="export">Export to ChatGPT</button><button type="button" data-cresco-ai-tab="import">Import AI Result</button></div>' +
			'<div class="cresco-ai-panel__body">' +
				'<section data-cresco-ai-pane="export">' +
					'<div class="cresco-ai-target"><span>Current target</span><strong data-cresco-ai-target-label>No Elementor element selected</strong><small>Select an element for element/subtree export, or choose Entire page.</small></div>' +
					'<div class="cresco-ai-field"><span>Export scope</span><div class="cresco-ai-segmented">' +
						'<label><input type="radio" name="cresco-export-scope" value="widget"><span>Selected element</span></label>' +
						'<label><input type="radio" name="cresco-export-scope" value="subtree" checked><span>Selected subtree</span></label>' +
						'<label><input type="radio" name="cresco-export-scope" value="document"><span>Entire page</span></label>' +
					'</div><small>External export uses Full Context: runtime controls, globals, responsive context, layout graph and rendered evidence are included automatically.</small></div>' +
					'<label class="cresco-ai-reference"><input type="file" accept="image/*" data-cresco-ai-reference><span><strong>Reference image (optional)</strong><small>Included only in the full ZIP bundle. You can also attach a reference directly in ChatGPT.</small></span></label>' +
					'<div class="cresco-ai-quality" data-cresco-ai-quality><div><span>Export quality</span><strong>Not exported</strong></div><div class="cresco-ai-quality__bar"><i style="width:0%"></i></div><small>Exact Runtime, Full Context and Fidelity evidence are prepared automatically when you export.</small></div>' +
					'<div class="cresco-ai-actions"><button type="button" class="cresco-ai-primary" data-cresco-export-bundle>Export for ChatGPT</button><button type="button" class="cresco-ai-secondary" data-cresco-export-json>JSON only</button></div>' +
					'<div class="cresco-ai-ready" data-cresco-ai-ready hidden><div class="cresco-ai-ready__summary"></div><small>Upload the exported file to ChatGPT, describe the interface change in chat, then import the returned JSON here.</small></div>' +
				'</section>' +
				'<section data-cresco-ai-pane="import" hidden>' +
					'<div class="cresco-ai-target"><span>Import destination</span><strong data-cresco-ai-import-target>No Elementor element selected</strong><small>Select the same original target unless the result is for the entire page.</small></div>' +
					'<div class="cresco-ai-field"><span>Expected scope</span><div class="cresco-ai-segmented">' +
						'<label><input type="radio" name="cresco-import-scope" value="widget"><span>Selected element</span></label>' +
						'<label><input type="radio" name="cresco-import-scope" value="subtree" checked><span>Selected subtree</span></label>' +
						'<label><input type="radio" name="cresco-import-scope" value="document"><span>Entire page</span></label>' +
					'</div></div>' +
					'<label class="cresco-ai-import-drop"><input type="file" accept="application/json,.json" data-cresco-ai-import-file hidden><strong>Drop ChatGPT result JSON here</strong><span>or choose the file returned by ChatGPT</span><button type="button" class="cresco-ai-secondary" data-cresco-ai-choose>Choose JSON</button></label>' +
					'<label class="cresco-ai-field"><span>Raw JSON fallback</span><textarea data-cresco-ai-import rows="8" placeholder="Paste cresco-ai-mutation/v3, v2, cresco-layer-patch/v1 or cresco-layer-ai-result/v1"></textarea></label>' +
					'<div class="cresco-ai-preview" data-cresco-ai-preview><span>No AI result validated yet.</span></div>' +
					'<div class="cresco-ai-actions"><button type="button" class="cresco-ai-secondary" data-cresco-ai-preview-button>Preview Changes</button><button type="button" class="cresco-ai-primary" data-cresco-ai-apply disabled>Apply to Elementor</button></div>' +
				'</section>' +
			'</div>';
		document.body.appendChild(box); bindPanel(box); return box;
	}
	function launcher() {
		var existing = document.getElementById('cresco-ai-launcher'); if (existing) return existing;
		var button = document.createElement('button'); button.id = 'cresco-ai-launcher'; button.type = 'button'; button.className = 'cresco-ai-launcher'; button.innerHTML = '<span>&harr;</span> Cresco Export / Import';
		button.addEventListener('click', function () { openPanel('export'); }); document.body.appendChild(button); return button;
	}
	function openPanel(tab) { var box = panel(); refreshTargets(); switchTab(box, tab || 'export'); box.hidden = false; }
	function closePanel() { var box = document.getElementById('cresco-ai-panel'); if (box) box.hidden = true; }
	function switchTab(box, name) {
		Array.prototype.forEach.call(box.querySelectorAll('[data-cresco-ai-tab]'), function (button) { button.classList.toggle('is-active', button.getAttribute('data-cresco-ai-tab') === name); });
		Array.prototype.forEach.call(box.querySelectorAll('[data-cresco-ai-pane]'), function (pane) { pane.hidden = pane.getAttribute('data-cresco-ai-pane') !== name; }); refreshTargets();
	}
	function refreshTargets() {
		var info = targetInfo(), box = document.getElementById('cresco-ai-panel'); if (!box) return;
		Array.prototype.forEach.call(box.querySelectorAll('[data-cresco-ai-target-label],[data-cresco-ai-import-target]'), function (el) { el.textContent = info.label; });
		if (!box.dataset.scopeTouched && info.id) { setScope(box, 'cresco-export-scope', info.defaultScope); setScope(box, 'cresco-import-scope', info.defaultScope); }
	}
	function renderQuality(box, quality) {
		var wrap = box.querySelector('[data-cresco-ai-quality]'); if (!wrap) return;
		var score = Math.max(0, Math.min(100, parseInt(quality.score || 0, 10))), grade = quality.grade || 'Incomplete';
		var strong = wrap.querySelector('strong'), bar = wrap.querySelector('i'), small = wrap.querySelector('small');
		if (strong) strong.textContent = score + '/100 - ' + grade; if (bar) bar.style.width = score + '%';
		var failed = (quality.checks || []).filter(function (item) { return !item.ok; }).map(function (item) { return item.key; });
		if (small) small.textContent = failed.length ? ('Partial context: ' + failed.join(', ') + '.') : 'Runtime, globals, layout graph, visual metrics and exchange safety are ready.';
	}
	function renderExportReady(box, pkg, kind) {
		var wrap = box.querySelector('[data-cresco-ai-ready]'); if (!wrap) return; wrap.hidden = false;
		var summary = wrap.querySelector('.cresco-ai-ready__summary'), target = pkg.target || {};
		if (summary) summary.innerHTML = '<strong>' + esc(kind === 'json' ? 'JSON package exported' : 'ChatGPT bundle exported') + '</strong><span>' + esc(target.scope || state.preparedScope) + ' - ' + esc(target.id || 'entire page') + '</span><span>Preferred result: ' + esc((pkg.outputContract || {}).preferredSchema || 'cresco-ai-mutation/v3') + '</span>';
	}
	function prepare(scope, box) {
		var info = targetInfo(), pid = postId();
		if (!pid) return Promise.reject(new Error('Cannot determine the current Elementor document.'));
		if (scope !== 'document' && !info.id) return Promise.reject(new Error('Select the Elementor element you want to export, or choose Entire page.'));
		ensureExact();
		window.CrescoLayerAIIntent = { workflow: 'external-file-exchange', request: '', changeType: 'auto', referenceImage: referenceMetadata(state.referenceImage) };
		var selected = scope === 'document' ? '' : info.id;
		return request('/documents/' + pid + '/export?scope=' + encodeURIComponent(scope) + '&selected=' + encodeURIComponent(selected) + '&context=full').then(function (pkg) {
			if (!pkg || pkg.schema !== 'cresco-ai-context/v3') throw new Error('AI Context v3 was not produced. Reload Elementor and export again.');
			state.prepared = pkg; state.preparedTarget = selected; state.preparedScope = scope; renderQuality(box, pkg.contextQuality || {}); return pkg;
		});
	}
	function exportForChatGPT(box, kind) {
		var scope = selectedScope(box, 'cresco-export-scope'), button = box.querySelector(kind === 'json' ? '[data-cresco-export-json]' : '[data-cresco-export-bundle]');
		if (!window.CrescoLayerAIBundle) { toast('External AI package exporter is unavailable. Reload Elementor.', 'error'); return; }
		setBusy(button, true, kind === 'json' ? 'Exporting JSON...' : 'Building ChatGPT bundle...');
		prepare(scope, box).then(function (pkg) {
			return kind === 'json' ? window.CrescoLayerAIBundle.exportJson(pkg) : window.CrescoLayerAIBundle.export(pkg, state.referenceImage);
		}).then(function () { renderExportReady(box, state.prepared, kind); toast(kind === 'json' ? 'JSON package exported. Upload it to ChatGPT.' : 'ChatGPT bundle exported. Upload the ZIP to ChatGPT.', 'success'); })
			.catch(function (error) { toast(error.message, 'error'); }).finally(function () { setBusy(button, false); });
	}
	function readFile(file, callback) { var reader = new FileReader(); reader.onload = function () { callback(String(reader.result || '')); }; reader.onerror = function () { toast('Could not read that file.', 'error'); }; reader.readAsText(file); }
	function stripFences(raw) { var text = String(raw || '').trim(), match = text.match(/```(?:json)?\s*([\s\S]*?)\s*```/i); return match ? match[1].trim() : text; }
	function unwrap(value) {
		var keys = ['result', 'data', 'output', 'response', 'payload', 'aiResult', 'ai_result', 'json', 'patch'];
		for (var depth = 0; value && typeof value === 'object' && !value.schema && depth < 6; depth++) { var next = null; for (var i = 0; i < keys.length; i++) if (value[keys[i]] && typeof value[keys[i]] === 'object') { next = value[keys[i]]; break; } if (!next) break; value = next; }
		return value;
	}
	function inferResult(raw) {
		try { var value = unwrap(JSON.parse(stripFences(raw))); var target = value && value.target && typeof value.target === 'object' ? value.target : {}; return { id: String(target.id || ''), scope: ['widget', 'subtree', 'document'].indexOf(String(target.scope || '')) >= 0 ? String(target.scope) : '' }; } catch (e) { return { id: '', scope: '' }; }
	}
	function syncImportFromResult(box, raw) {
		var inferred = inferResult(raw); if (inferred.scope) { setScope(box, 'cresco-import-scope', inferred.scope); box.dataset.scopeTouched = '1'; }
		var wrap = box.querySelector('[data-cresco-ai-preview]'); if (wrap && inferred.id) wrap.innerHTML = '<span>Result target: <strong>' + esc(inferred.id) + '</strong>. Preview will verify it against the selected Elementor target.</span>';
	}
	function importBody(raw, box) {
		var scope = selectedScope(box, 'cresco-import-scope'), id = scope === 'document' ? '' : selectedId();
		return { aiResult: raw, selectedElementId: id, expectedScope: { mode: scope, rootElementId: id } };
	}
	function previewImport(box) {
		var area = box.querySelector('[data-cresco-ai-import]'), raw = String(area && area.value || '').trim(), scope = selectedScope(box, 'cresco-import-scope'), id = selectedId(), inferred = inferResult(raw);
		if (!raw) { toast('Choose or paste the ChatGPT result JSON first.', 'error'); return; }
		if (scope !== 'document' && !id) { toast('Select the original Elementor target before previewing this result.', 'error'); return; }
		if (scope !== 'document' && inferred.id && inferred.id !== id) { toast('This result targets ' + inferred.id + '. Select that original Elementor element before importing.', 'error'); return; }
		var button = box.querySelector('[data-cresco-ai-preview-button]'), apply = box.querySelector('[data-cresco-ai-apply]'); state.previewedText = ''; state.previewedTarget = ''; state.previewedScope = ''; if (apply) apply.disabled = true; setBusy(button, true, 'Validating...');
		request('/documents/' + postId() + '/preview', { method: 'POST', body: JSON.stringify(importBody(raw, box)) }).then(function (data) {
			state.previewedText = raw; state.previewedTarget = scope === 'document' ? '' : id; state.previewedScope = scope; renderPreview(box, data); if (apply) apply.disabled = false; toast('AI result validated. Review the changes before applying.', 'success');
		}).catch(function (error) { renderPreviewError(box, error.message); toast(error.message, 'error'); }).finally(function () { setBusy(button, false); });
	}
	function renderPreview(box, data) {
		var wrap = box.querySelector('[data-cresco-ai-preview]'); if (!wrap) return;
		var diff = data.diff || {}, semantic = data.semantic || {}, imported = data.aiImport || {}, destructive = Number(diff.replaced || 0) + Number(diff.removed || 0), repairCount = Number(imported.autoRepairCount || (imported.autoRepaired || []).length || 0);
		wrap.className = 'cresco-ai-preview is-ready';
		wrap.innerHTML = '<div class="cresco-ai-preview__head"><strong>Ready to apply</strong><span>' + (destructive ? 'Review carefully' : 'Scoped delta') + '</span></div>' +
			'<div class="cresco-ai-preview__grid"><span><b>' + Number(diff.inserted || 0) + '</b> added</span><span><b>' + Number(diff.updated || 0) + '</b> updated</span><span><b>' + Number(diff.moved || 0) + '</b> moved</span><span><b>' + Number(diff.replaced || 0) + '</b> replaced</span><span><b>' + Number(diff.removed || 0) + '</b> removed</span><span><b>' + Number((semantic.warnings || []).length || 0) + '</b> warnings</span><span><b>' + repairCount + '</b> auto-repaired</span></div>' +
			'<small>' + (imported.source ? ('Normalized as ' + esc(imported.source) + '. ') : '') + (destructive ? 'Existing elements will be replaced or removed only inside the approved scope.' : 'Existing UI is preserved by delta operations.') + '</small>';
	}
	function renderPreviewError(box, message) { var wrap = box.querySelector('[data-cresco-ai-preview]'); if (!wrap) return; wrap.className = 'cresco-ai-preview is-error'; wrap.innerHTML = '<strong>Cannot apply this result</strong><span>' + esc(message) + '</span>'; }
	function refreshPreview() {
		try { if (window.elementor && typeof elementor.reloadPreview === 'function') { elementor.reloadPreview(); return; } } catch (e) {}
		try { var frame = document.querySelector('#elementor-preview-iframe,iframe[name="elementor-preview-iframe"],iframe[src*="elementor-preview"]'); if (frame && frame.contentWindow) frame.contentWindow.location.reload(); } catch (e2) {}
	}
	function applyImport(box) {
		var area = box.querySelector('[data-cresco-ai-import]'), raw = String(area && area.value || '').trim(), scope = selectedScope(box, 'cresco-import-scope'), id = scope === 'document' ? '' : selectedId();
		if (!raw || raw !== state.previewedText || id !== state.previewedTarget || scope !== state.previewedScope) { toast('Preview this exact result and scope again before applying.', 'error'); return; }
		if (!window.confirm('Apply these reviewed external AI changes to the Elementor working document? This does not publish the page.')) return;
		var button = box.querySelector('[data-cresco-ai-apply]'); setBusy(button, true, 'Applying...');
		request('/documents/' + postId() + '/apply', { method: 'POST', body: JSON.stringify(importBody(raw, box)) }).then(function (data) {
			state.previewedText = ''; state.previewedTarget = ''; state.previewedScope = ''; button.disabled = true; refreshPreview(); var verified = !data.verification || data.verification.verified !== false;
			toast(verified ? 'AI result applied. Elementor preview is refreshing for fidelity verification.' : 'Changes were saved, but verification reported a mismatch. Review the refreshed target.', verified ? 'success' : 'error');
		}).catch(function (error) { toast(error.message, 'error'); button.disabled = false; }).finally(function () { setBusy(button, false); });
	}
	function bindPanel(box) {
		box.querySelector('[data-cresco-ai-close]').addEventListener('click', closePanel);
		Array.prototype.forEach.call(box.querySelectorAll('[data-cresco-ai-tab]'), function (button) { button.addEventListener('click', function () { switchTab(box, button.getAttribute('data-cresco-ai-tab')); }); });
		Array.prototype.forEach.call(box.querySelectorAll('input[name="cresco-export-scope"],input[name="cresco-import-scope"]'), function (input) { input.addEventListener('change', function () { box.dataset.scopeTouched = '1'; }); });
		box.querySelector('[data-cresco-ai-reference]').addEventListener('change', function (event) { state.referenceImage = event.target.files && event.target.files[0] ? event.target.files[0] : null; });
		box.querySelector('[data-cresco-export-bundle]').addEventListener('click', function () { exportForChatGPT(box, 'bundle'); });
		box.querySelector('[data-cresco-export-json]').addEventListener('click', function () { exportForChatGPT(box, 'json'); });
		var importFile = box.querySelector('[data-cresco-ai-import-file]'), choose = box.querySelector('[data-cresco-ai-choose]'), drop = box.querySelector('.cresco-ai-import-drop'), area = box.querySelector('[data-cresco-ai-import]');
		choose.addEventListener('click', function (event) { event.preventDefault(); importFile.click(); });
		function loadText(text) { area.value = text; state.previewedText = ''; state.previewedTarget = ''; state.previewedScope = ''; box.querySelector('[data-cresco-ai-apply]').disabled = true; syncImportFromResult(box, text); }
		importFile.addEventListener('change', function () { if (importFile.files && importFile.files[0]) readFile(importFile.files[0], loadText); });
		['dragenter', 'dragover'].forEach(function (name) { drop.addEventListener(name, function (event) { event.preventDefault(); drop.classList.add('is-dragging'); }); });
		['dragleave', 'drop'].forEach(function (name) { drop.addEventListener(name, function (event) { event.preventDefault(); drop.classList.remove('is-dragging'); }); });
		drop.addEventListener('drop', function (event) { var file = event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0]; if (file) readFile(file, loadText); });
		area.addEventListener('input', function () { state.previewedText = ''; state.previewedTarget = ''; state.previewedScope = ''; box.querySelector('[data-cresco-ai-apply]').disabled = true; syncImportFromResult(box, area.value); });
		box.querySelector('[data-cresco-ai-preview-button]').addEventListener('click', function () { previewImport(box); });
		box.querySelector('[data-cresco-ai-apply]').addEventListener('click', function () { applyImport(box); });
	}
	function hideLegacyToolbar() { var legacy = document.getElementById('cresco-layer-editor-tools'); if (legacy) legacy.setAttribute('data-cresco-ai-legacy-hidden', 'true'); }
	function boot() { launcher(); panel(); hideLegacyToolbar(); refreshTargets(); ensureExact(); if (window.MutationObserver && document.documentElement) new MutationObserver(function () { hideLegacyToolbar(); }).observe(document.documentElement, { childList: true, subtree: true }); }

	window.CrescoLayerAIPanel = { version: '2.0.0', open: openPanel, close: closePanel, getState: function () { return { prepared: !!state.prepared, preparedTarget: state.preparedTarget, preparedScope: state.preparedScope, previewedTarget: state.previewedTarget, previewedScope: state.previewedScope, referenceImage: referenceMetadata(state.referenceImage) }; } };
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true }); else boot();
}());