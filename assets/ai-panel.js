(function () {
	'use strict';

	var cfg = window.crescoLayerEditor || {};
	var bridge = window.CrescoLayerEditorBridge || {};
	var state = {
		prepared: null,
		preparedTarget: '',
		importText: '',
		previewedText: '',
		previewedTarget: '',
		referenceImage: null
	};

	function esc(value) {
		return String(value == null ? '' : value)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#039;');
	}
	function restRoot() { return String(cfg.restRoot || '').replace(/\/$/, ''); }
	function postId() {
		var id = parseInt(cfg.postId || 0, 10);
		if (id) return id;
		try { return parseInt(window.elementor && elementor.config && elementor.config.document && elementor.config.document.id || 0, 10) || 0; } catch (e) { return 0; }
	}
	function request(path, options) {
		options = options || {};
		options.headers = Object.assign({ 'X-WP-Nonce': cfg.nonce || '', 'Content-Type': 'application/json' }, options.headers || {});
		return window.fetch(restRoot() + path, options).then(function (response) {
			return response.json().catch(function () { return {}; }).then(function (body) {
				if (!response.ok) {
					var error = new Error(body.message || ('Cresco Layer request failed (' + response.status + ').'));
					error.status = response.status; error.body = body; throw error;
				}
				return body;
			});
		});
	}
	function selectedId() {
		try {
			var diagnostics = bridge.getDiagnostics ? bridge.getDiagnostics() : null;
			if (diagnostics && diagnostics.selectedElementId) return String(diagnostics.selectedElementId);
		} catch (e) {}
		try {
			if (window.elementor && typeof elementor.getContainer === 'function') {
				var selected = elementor.channels && elementor.channels.editor ? elementor.channels.editor.request('selectedElement') : null;
				var model = selected && (selected.model || selected);
				var id = model && (model.id || (typeof model.get === 'function' ? model.get('id') : ''));
				if (id) return String(id);
			}
		} catch (e2) {}
		return '';
	}
	function selectedData(id) {
		try {
			var container = window.elementor && typeof elementor.getContainer === 'function' ? elementor.getContainer(id) : null;
			if (container && container.model && typeof container.model.toJSON === 'function') return container.model.toJSON() || {};
		} catch (e) {}
		return { id: id };
	}
	function targetInfo() {
		var id = selectedId();
		if (!id) return { id: '', scope: 'subtree', type: 'No selection', label: 'Select an Elementor widget or container first.' };
		var data = selectedData(id);
		var type = String(data.widgetType || data.elType || 'element');
		var scope = data.elType === 'widget' || !!data.widgetType ? 'widget' : 'subtree';
		return { id: id, scope: scope, type: type, label: type.replace(/[-_]+/g, ' ') + ' · ' + id };
	}
	function ensureExact() {
		try {
			if (window.CrescoLayerExactRuntimeExport && typeof window.CrescoLayerExactRuntimeExport.setProfile === 'function') {
				window.CrescoLayerExactRuntimeExport.setProfile('exact');
			}
		} catch (e) {}
	}
	function copyText(text) {
		if (window.navigator && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') return navigator.clipboard.writeText(text);
		return new Promise(function (resolve, reject) {
			var area = document.createElement('textarea'); area.value = text; area.setAttribute('readonly', '');
			area.style.position = 'fixed'; area.style.opacity = '0'; document.body.appendChild(area); area.select();
			var ok = false; try { ok = document.execCommand('copy'); } catch (e) {} area.remove();
			ok ? resolve() : reject(new Error('Clipboard access is blocked. Use Download JSON instead.'));
		});
	}
	function download(name, data) {
		var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
		var url = URL.createObjectURL(blob); var a = document.createElement('a');
		a.href = url; a.download = name; document.body.appendChild(a); a.click(); a.remove();
		setTimeout(function () { URL.revokeObjectURL(url); }, 1200);
	}
	function toast(message, tone) {
		var el = document.getElementById('cresco-ai-panel-toast');
		if (!el) { el = document.createElement('div'); el.id = 'cresco-ai-panel-toast'; document.body.appendChild(el); }
		el.className = 'cresco-ai-panel-toast is-' + (tone || 'info'); el.textContent = message; el.hidden = false;
		clearTimeout(el._timer); el._timer = setTimeout(function () { el.hidden = true; }, 5200);
	}
	function setBusy(button, busy, label) {
		if (!button) return; button.disabled = !!busy;
		if (busy) { button.dataset.oldText = button.textContent; button.textContent = label || 'Working…'; }
		else if (button.dataset.oldText) { button.textContent = button.dataset.oldText; delete button.dataset.oldText; }
	}
	function panel() {
		var existing = document.getElementById('cresco-ai-panel');
		if (existing) return existing;
		var box = document.createElement('aside');
		box.id = 'cresco-ai-panel'; box.className = 'cresco-ai-panel'; box.hidden = true;
		box.innerHTML = '' +
			'<div class="cresco-ai-panel__head"><div><span class="cresco-ai-kicker">Cresco Layer</span><h2>AI Design</h2></div><button type="button" class="cresco-ai-icon-button" data-cresco-ai-close aria-label="Close">×</button></div>' +
			'<div class="cresco-ai-tabs"><button type="button" class="is-active" data-cresco-ai-tab="prepare">Create / Edit</button><button type="button" data-cresco-ai-tab="import">Import Result</button></div>' +
			'<div class="cresco-ai-panel__body">' +
				'<section data-cresco-ai-pane="prepare">' +
					'<div class="cresco-ai-target"><span>Selected target</span><strong data-cresco-ai-target-label>Select an Elementor element</strong><small>Exact Runtime is automatic. Cresco chooses widget/subtree scope for you.</small></div>' +
					'<label class="cresco-ai-field"><span>What do you want AI to do?</span><textarea data-cresco-ai-request rows="5" placeholder="Example: Add a continuous service ticker below this hero. Preserve everything that already exists."></textarea></label>' +
					'<div class="cresco-ai-field"><span>Change type</span><div class="cresco-ai-segmented">' +
						'<label><input type="radio" name="cresco-ai-change" value="auto" checked><span>Auto</span></label>' +
						'<label><input type="radio" name="cresco-ai-change" value="edit"><span>Edit</span></label>' +
						'<label><input type="radio" name="cresco-ai-change" value="add"><span>Add</span></label>' +
						'<label><input type="radio" name="cresco-ai-change" value="rebuild"><span>Rebuild</span></label>' +
					'</div><small>Auto chooses the smallest safe change. Rebuild is the only destructive mode.</small></div>' +
					'<label class="cresco-ai-reference"><input type="file" accept="image/*" data-cresco-ai-reference><span><strong>Reference image (optional)</strong><small>Choose it here so the JSON records the reference. Export AI Bundle can include the same file.</small></span></label>' +
					'<div class="cresco-ai-quality" data-cresco-ai-quality><div><span>AI Context Quality</span><strong>Not prepared</strong></div><div class="cresco-ai-quality__bar"><i style="width:0%"></i></div><small>Prepare context to verify runtime, Site Settings, layout graph and live visual metrics.</small></div>' +
					'<div class="cresco-ai-actions"><button type="button" class="cresco-ai-primary" data-cresco-ai-prepare>Prepare for AI</button></div>' +
					'<div class="cresco-ai-ready" data-cresco-ai-ready hidden><div class="cresco-ai-ready__summary"></div><div class="cresco-ai-actions"><button type="button" class="cresco-ai-primary" data-cresco-ai-copy>Copy for AI</button><button type="button" class="cresco-ai-secondary" data-cresco-ai-download>Download JSON</button><button type="button" class="cresco-ai-secondary" data-cresco-ai-bundle>Export AI Bundle</button></div><small>Preferred response: cresco-ai-mutation/v2. AI should return only the intended delta unless you selected Rebuild.</small></div>' +
				'</section>' +
				'<section data-cresco-ai-pane="import" hidden>' +
					'<div class="cresco-ai-target"><span>Apply to selected target</span><strong data-cresco-ai-import-target>Select an Elementor element</strong><small>Cresco validates target, scope, runtime controls and placeholders before applying.</small></div>' +
					'<label class="cresco-ai-import-drop"><input type="file" accept="application/json,.json" data-cresco-ai-import-file hidden><strong>Drop AI result here</strong><span>or paste JSON below</span><button type="button" class="cresco-ai-secondary" data-cresco-ai-choose>Choose JSON</button></label>' +
					'<label class="cresco-ai-field"><span>AI result</span><textarea data-cresco-ai-import rows="10" placeholder="Paste cresco-ai-mutation/v2 (preferred), cresco-layer-patch/v1 or cresco-layer-ai-result/v1. Markdown code fences and common AI wrappers are accepted."></textarea></label>' +
					'<div class="cresco-ai-preview" data-cresco-ai-preview><span>No result validated yet.</span></div>' +
					'<div class="cresco-ai-actions"><button type="button" class="cresco-ai-secondary" data-cresco-ai-preview-button>Preview Changes</button><button type="button" class="cresco-ai-primary" data-cresco-ai-apply disabled>Apply to Elementor</button></div>' +
				'</section>' +
			'</div>';
		document.body.appendChild(box);
		bindPanel(box);
		return box;
	}
	function launcher() {
		var existing = document.getElementById('cresco-ai-launcher'); if (existing) return existing;
		var button = document.createElement('button'); button.id = 'cresco-ai-launcher'; button.type = 'button'; button.className = 'cresco-ai-launcher'; button.innerHTML = '<span>✦</span> Cresco AI';
		button.addEventListener('click', function () { openPanel('prepare'); }); document.body.appendChild(button); return button;
	}
	function openPanel(tab) {
		var box = panel(); refreshTargets(); switchTab(box, tab || 'prepare'); box.hidden = false;
	}
	function closePanel() { var box = document.getElementById('cresco-ai-panel'); if (box) box.hidden = true; }
	function switchTab(box, name) {
		Array.prototype.forEach.call(box.querySelectorAll('[data-cresco-ai-tab]'), function (button) { button.classList.toggle('is-active', button.getAttribute('data-cresco-ai-tab') === name); });
		Array.prototype.forEach.call(box.querySelectorAll('[data-cresco-ai-pane]'), function (pane) { pane.hidden = pane.getAttribute('data-cresco-ai-pane') !== name; });
		refreshTargets();
	}
	function refreshTargets() {
		var info = targetInfo();
		var box = document.getElementById('cresco-ai-panel'); if (!box) return;
		Array.prototype.forEach.call(box.querySelectorAll('[data-cresco-ai-target-label],[data-cresco-ai-import-target]'), function (el) { el.textContent = info.label; });
	}
	function referenceMetadata(file) {
		if (!file) return { provided: false, delivery: 'bundle-or-attach-separately' };
		return { provided: true, name: String(file.name || ''), type: String(file.type || ''), size: Number(file.size || 0), delivery: 'bundle-or-attach-separately' };
	}
	function selectedChangeType(box) {
		var checked = box.querySelector('input[name="cresco-ai-change"]:checked'); return checked ? checked.value : 'auto';
	}
	function prepareContext(box) {
		var info = targetInfo(); if (!info.id) { toast(info.label, 'error'); return; }
		var pid = postId(); if (!pid) { toast('Cannot determine the current Elementor document.', 'error'); return; }
		var requestText = String((box.querySelector('[data-cresco-ai-request]') || {}).value || '').trim();
		var changeType = selectedChangeType(box);
		window.CrescoLayerAIIntent = { request: requestText, changeType: changeType, referenceImage: referenceMetadata(state.referenceImage) };
		ensureExact(); state.prepared = null; state.preparedTarget = '';
		var button = box.querySelector('[data-cresco-ai-prepare]'); setBusy(button, true, 'Preparing exact context…');
		request('/documents/' + pid + '/export?scope=' + encodeURIComponent(info.scope) + '&selected=' + encodeURIComponent(info.id) + '&context=smart')
			.then(function (pkg) {
				if (!pkg || pkg.schema !== 'cresco-ai-context/v3') throw new Error('AI Context v3 was not produced. Reload Elementor so Cresco scripts can initialize in order.');
				state.prepared = pkg; state.preparedTarget = info.id; renderQuality(box, pkg.contextQuality || {}); renderReady(box, pkg); toast('AI context is ready.', 'success');
			}).catch(function (error) { toast(error.message, 'error'); }).finally(function () { setBusy(button, false); });
	}
	function renderQuality(box, quality) {
		var wrap = box.querySelector('[data-cresco-ai-quality]'); if (!wrap) return;
		var score = Math.max(0, Math.min(100, parseInt(quality.score || 0, 10))); var grade = quality.grade || 'Incomplete';
		var strong = wrap.querySelector('strong'); var bar = wrap.querySelector('i'); var small = wrap.querySelector('small');
		if (strong) strong.textContent = score + '/100 · ' + grade; if (bar) bar.style.width = score + '%';
		var failed = (quality.checks || []).filter(function (item) { return !item.ok; }).map(function (item) { return item.key; });
		if (small) small.textContent = failed.length ? ('Missing/partial: ' + failed.join(', ') + '.') : 'Exact Runtime, Site Settings, layout graph, live visual metrics and exchange safety are ready.';
	}
	function renderReady(box, pkg) {
		var wrap = box.querySelector('[data-cresco-ai-ready]'); if (!wrap) return; wrap.hidden = false;
		var summary = wrap.querySelector('.cresco-ai-ready__summary');
		var discovered = pkg.taskRuntimeDiscovery && pkg.taskRuntimeDiscovery.discoveredWidgets ? pkg.taskRuntimeDiscovery.discoveredWidgets.length : 0;
		if (summary) summary.innerHTML = '<strong>Context ready</strong><span>' + esc(pkg.target && pkg.target.type || 'Element') + ' · ' + esc(pkg.target && pkg.target.id || '') + '</span><span>' + (pkg.currentInterface && pkg.currentInterface.elementCount || 0) + ' source elements · ' + (pkg.layoutGraph && pkg.layoutGraph.nodes ? pkg.layoutGraph.nodes.length : 0) + ' layout nodes · ' + discovered + ' task-discovered widgets</span>';
	}
	function copyPrepared(box) {
		if (!state.prepared) { toast('Prepare the AI context first.', 'error'); return; }
		if (state.preparedTarget !== targetInfo().id) { toast('The Elementor selection changed. Prepare the context again for the new target.', 'error'); return; }
		var button = box.querySelector('[data-cresco-ai-copy]'); setBusy(button, true, 'Copying…');
		copyText(JSON.stringify(state.prepared, null, 2)).then(function () { toast('AI context copied. Paste it into your AI chat and attach the reference image if you selected one.', 'success'); }).catch(function (error) { toast(error.message, 'error'); }).finally(function () { setBusy(button, false); });
	}
	function downloadPrepared() {
		if (!state.prepared) { toast('Prepare the AI context first.', 'error'); return; }
		var target = state.prepared.target && state.prepared.target.id || 'target'; download('cresco-ai-context-post' + postId() + '-' + target + '.json', state.prepared);
	}
	function exportBundle(box) {
		if (!state.prepared) { toast('Prepare the AI context first.', 'error'); return; }
		if (state.preparedTarget !== targetInfo().id) { toast('The Elementor selection changed. Prepare the context again before exporting the bundle.', 'error'); return; }
		if (!window.CrescoLayerAIBundle || typeof window.CrescoLayerAIBundle.export !== 'function') { toast('AI Bundle exporter is unavailable. Reload Elementor.', 'error'); return; }
		var button = box.querySelector('[data-cresco-ai-bundle]'); setBusy(button, true, 'Building bundle…');
		window.CrescoLayerAIBundle.export(state.prepared, state.referenceImage).then(function (manifest) {
			var raster = manifest && manifest.raster && manifest.raster.file ? ' Raster capture included.' : ' Raster capture was unavailable; structured visual context is still included.';
			toast('AI Bundle exported.' + raster, 'success');
		}).catch(function (error) { toast(error.message, 'error'); }).finally(function () { setBusy(button, false); });
	}
	function readFile(file, callback) {
		var reader = new FileReader(); reader.onload = function () { callback(String(reader.result || '')); }; reader.onerror = function () { toast('Could not read that file.', 'error'); }; reader.readAsText(file);
	}
	function importBody(raw) {
		var info = targetInfo();
		return { aiResult: raw, selectedElementId: info.id, expectedScope: { mode: info.scope, rootElementId: info.id } };
	}
	function previewImport(box) {
		var info = targetInfo(); if (!info.id) { toast(info.label, 'error'); return; }
		var area = box.querySelector('[data-cresco-ai-import]'); var raw = String(area && area.value || '').trim();
		if (!raw) { toast('Paste or choose the AI result first.', 'error'); return; }
		var button = box.querySelector('[data-cresco-ai-preview-button]'); var apply = box.querySelector('[data-cresco-ai-apply]');
		state.previewedText = ''; state.previewedTarget = ''; if (apply) apply.disabled = true; setBusy(button, true, 'Validating…');
		request('/documents/' + postId() + '/preview', { method: 'POST', body: JSON.stringify(importBody(raw)) })
			.then(function (data) { state.importText = raw; state.previewedText = raw; state.previewedTarget = info.id; renderPreview(box, data); if (apply) apply.disabled = false; toast('Changes validated. Review the preview before applying.', 'success'); })
			.catch(function (error) { renderPreviewError(box, error.message); toast(error.message, 'error'); })
			.finally(function () { setBusy(button, false); });
	}
	function renderPreview(box, data) {
		var wrap = box.querySelector('[data-cresco-ai-preview]'); if (!wrap) return;
		var diff = data.diff || {}; var semantic = data.semantic || {}; var imported = data.aiImport || {};
		var destructive = Number(diff.replaced || 0) + Number(diff.removed || 0);
		var risk = destructive ? 'Review carefully' : 'Low risk';
		var repairCount = Number(imported.autoRepairCount || (imported.autoRepaired || []).length || 0);
		var generatedCount = (imported.generatedIds || []).length;
		var refCount = imported.resolvedRefs ? Object.keys(imported.resolvedRefs).length : 0;
		wrap.className = 'cresco-ai-preview is-ready';
		wrap.innerHTML = '<div class="cresco-ai-preview__head"><strong>Ready to apply</strong><span>' + esc(risk) + '</span></div>' +
			'<div class="cresco-ai-preview__grid"><span><b>' + Number(diff.inserted || 0) + '</b> added</span><span><b>' + Number(diff.updated || 0) + '</b> updated</span><span><b>' + Number(diff.moved || 0) + '</b> moved</span><span><b>' + Number(diff.replaced || 0) + '</b> replaced</span><span><b>' + Number(diff.removed || 0) + '</b> removed</span><span><b>' + Number((semantic.warnings || []).length || 0) + '</b> warnings</span><span><b>' + repairCount + '</b> auto-repaired</span><span><b>' + generatedCount + '</b> IDs allocated</span><span><b>' + refCount + '</b> refs resolved</span></div>' +
			'<small>' + (imported.source ? ('Normalized as ' + esc(imported.source) + '. ') : '') + (destructive ? 'Existing elements will be replaced/removed; verify this matches your explicit intent.' : 'Existing UI is preserved by delta operations.') + '</small>';
	}
	function renderPreviewError(box, message) {
		var wrap = box.querySelector('[data-cresco-ai-preview]'); if (!wrap) return; wrap.className = 'cresco-ai-preview is-error'; wrap.innerHTML = '<strong>Cannot apply this result</strong><span>' + esc(message) + '</span>';
	}
	function refreshPreview() {
		try { if (window.elementor && typeof elementor.reloadPreview === 'function') { elementor.reloadPreview(); return; } } catch (e) {}
		try {
			var frame = document.querySelector('#elementor-preview-iframe,iframe[name="elementor-preview-iframe"],iframe[src*="elementor-preview"]');
			if (frame && frame.contentWindow) frame.contentWindow.location.reload();
		} catch (e2) {}
	}
	function applyImport(box) {
		var info = targetInfo(); var area = box.querySelector('[data-cresco-ai-import]'); var raw = String(area && area.value || '').trim();
		if (!raw || raw !== state.previewedText || info.id !== state.previewedTarget) { toast('Preview this exact result again before applying.', 'error'); return; }
		if (!window.confirm('Apply these reviewed AI changes to the Elementor working document? This does not publish the page.')) return;
		var button = box.querySelector('[data-cresco-ai-apply]'); setBusy(button, true, 'Applying…');
		request('/documents/' + postId() + '/apply', { method: 'POST', body: JSON.stringify(importBody(raw)) })
			.then(function (data) {
				state.previewedText = ''; state.previewedTarget = ''; button.disabled = true; refreshPreview();
				var verified = !data.verification || data.verification.verified !== false;
				toast(verified ? 'AI changes applied. Elementor preview is refreshing.' : 'Changes were saved, but verification reported a mismatch. Review the refreshed target.', verified ? 'success' : 'error');
			})
			.catch(function (error) { toast(error.message, 'error'); button.disabled = false; })
			.finally(function () { setBusy(button, false); });
	}
	function bindPanel(box) {
		box.querySelector('[data-cresco-ai-close]').addEventListener('click', closePanel);
		Array.prototype.forEach.call(box.querySelectorAll('[data-cresco-ai-tab]'), function (button) { button.addEventListener('click', function () { switchTab(box, button.getAttribute('data-cresco-ai-tab')); }); });
		box.querySelector('[data-cresco-ai-prepare]').addEventListener('click', function () { prepareContext(box); });
		box.querySelector('[data-cresco-ai-copy]').addEventListener('click', function () { copyPrepared(box); });
		box.querySelector('[data-cresco-ai-download]').addEventListener('click', downloadPrepared);
		box.querySelector('[data-cresco-ai-bundle]').addEventListener('click', function () { exportBundle(box); });
		box.querySelector('[data-cresco-ai-reference]').addEventListener('change', function (event) { state.referenceImage = event.target.files && event.target.files[0] ? event.target.files[0] : null; });
		var importFile = box.querySelector('[data-cresco-ai-import-file]'); var choose = box.querySelector('[data-cresco-ai-choose]'); var drop = box.querySelector('.cresco-ai-import-drop'); var area = box.querySelector('[data-cresco-ai-import]');
		choose.addEventListener('click', function (event) { event.preventDefault(); importFile.click(); });
		importFile.addEventListener('change', function () { if (importFile.files && importFile.files[0]) readFile(importFile.files[0], function (text) { area.value = text; state.previewedText = ''; box.querySelector('[data-cresco-ai-apply]').disabled = true; }); });
		['dragenter', 'dragover'].forEach(function (name) { drop.addEventListener(name, function (event) { event.preventDefault(); drop.classList.add('is-dragging'); }); });
		['dragleave', 'drop'].forEach(function (name) { drop.addEventListener(name, function (event) { event.preventDefault(); drop.classList.remove('is-dragging'); }); });
		drop.addEventListener('drop', function (event) { var file = event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0]; if (file) readFile(file, function (text) { area.value = text; }); });
		area.addEventListener('input', function () { state.previewedText = ''; state.previewedTarget = ''; box.querySelector('[data-cresco-ai-apply]').disabled = true; });
		box.querySelector('[data-cresco-ai-preview-button]').addEventListener('click', function () { previewImport(box); });
		box.querySelector('[data-cresco-ai-apply]').addEventListener('click', function () { applyImport(box); });
	}
	function hideLegacyToolbar() {
		var legacy = document.getElementById('cresco-layer-editor-tools'); if (legacy) legacy.setAttribute('data-cresco-ai-legacy-hidden', 'true');
	}
	function boot() {
		launcher(); panel(); hideLegacyToolbar(); refreshTargets(); ensureExact();
		if (window.MutationObserver && document.documentElement) new MutationObserver(function () { hideLegacyToolbar(); }).observe(document.documentElement, { childList: true, subtree: true });
	}

	window.CrescoLayerAIPanel = {
		version: '1.1.0', open: openPanel, close: closePanel,
		getState: function () { return { prepared: !!state.prepared, preparedTarget: state.preparedTarget, previewedTarget: state.previewedTarget, referenceImage: referenceMetadata(state.referenceImage) }; }
	};
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true }); else boot();
}());