(function () {
	'use strict';

	var cfg = window.crescoLayerLocalAI || {};
	if (!cfg.restRoot || !document.querySelector('.cresco-layer-admin')) return;

	var state = { summary: null, providers: {}, models: [] };
	var root = mount();
	var els = collect();

	function mount() {
		var section = document.createElement('section');
		section.className = 'cresco-layer-card cresco-layer-local-ai';
		section.id = 'cresco-layer-local-ai';
		section.innerHTML = '' +
			'<div class="cresco-layer-local-ai__head">' +
				'<div><p class="cresco-layer-eyebrow">Local analysis engine</p><h2>Local AI Manager</h2><p>Configure a model running on this computer or on a private LAN. Local AI may analyze and plan; only the validated Cresco Skill Runtime can execute Elementor changes.</p></div>' +
				'<span class="cresco-layer-local-ai__status is-idle" data-local-ai-status>Not checked</span>' +
			'</div>' +
			'<div class="cresco-layer-local-ai__contract"><strong>Execution boundary</strong><span>Local AI → semantic plan → validated Cresco skills → preview → Elementor. The model never receives direct write authority.</span></div>' +
			'<div class="cresco-layer-local-ai__grid">' +
				'<div class="cresco-layer-local-ai__panel"><h3>AI engine</h3>' +
					'<label class="cresco-layer-toggle"><input type="checkbox" data-ai="enabled"><span>Enable Local AI analysis</span></label>' +
					'<label>Provider<select data-ai="provider"></select></label>' +
					'<label>Connection mode<select data-ai="connectionMode"><option value="browser">Browser / Local Bridge</option><option value="server">WordPress server direct</option></select></label>' +
					'<label>Endpoint<input type="url" data-ai="endpoint" placeholder="http://127.0.0.1:11434"></label>' +
					'<label>Optional API token<input type="password" data-ai="apiToken" autocomplete="new-password" placeholder="Leave blank to keep the saved token"></label>' +
					'<p class="description" data-ai-help></p>' +
					'<div class="cresco-layer-actions"><button type="button" class="button button-primary" data-ai-action="test">Test connection</button><button type="button" class="button" data-ai-action="models">Refresh models</button></div>' +
				'</div>' +
				'<div class="cresco-layer-local-ai__panel"><h3>Models</h3>' +
					'<label>Analysis / planning model<select data-ai="analysisModel"><option value="">Select a model…</option></select></label>' +
					'<label>Vision model <span class="cresco-layer-local-ai__optional">optional</span><select data-ai="visionModel"><option value="">Disabled</option></select></label>' +
					'<div class="cresco-layer-local-ai__model-note" data-ai-model-note>Refresh models after the endpoint is reachable.</div>' +
				'</div>' +
				'<div class="cresco-layer-local-ai__panel"><h3>Runtime</h3>' +
					'<div class="cresco-layer-local-ai__fields"><label>Temperature<input type="number" min="0" max="2" step="0.05" data-ai="temperature"></label><label>Minimum confidence<input type="number" min="0.5" max="1" step="0.01" data-ai="minimumConfidence"></label><label>Context window<input type="number" min="2048" step="1024" data-ai="contextWindow"></label><label>Max output tokens<input type="number" min="256" step="256" data-ai="maxOutputTokens"></label></div>' +
				'</div>' +
				'<div class="cresco-layer-local-ai__panel"><h3>Behavior & privacy</h3>' +
					'<label class="cresco-layer-toggle"><input type="checkbox" data-ai="requirePreview"><span>Require preview before AI-assisted changes</span></label>' +
					'<label class="cresco-layer-toggle"><input type="checkbox" data-ai="autoApplySafe"><span>Allow auto-apply for SAFE skills only</span></label>' +
					'<label class="cresco-layer-toggle"><input type="checkbox" data-ai="includeNeighborContext"><span>Include parent / sibling context for analysis</span></label>' +
					'<label class="cresco-layer-toggle"><input type="checkbox" data-ai="allowScreenshots"><span>Allow canvas screenshots for local vision analysis</span></label>' +
					'<div class="cresco-layer-local-ai__privacy">Sensitive-context redaction is always enabled. Passwords, tokens, API keys, nonces and credential-like values are not part of the model context.</div>' +
				'</div>' +
			'</div>' +
			'<div class="cresco-layer-local-ai__footer"><div class="cresco-layer-actions"><button type="button" class="button button-primary" data-ai-action="save">Save configuration</button><button type="button" class="button" data-ai-action="diagnostics">Run diagnostics</button></div><span data-ai-save-status></span></div>' +
			'<div class="cresco-layer-local-ai__diagnostics" data-ai-diagnostics hidden></div>';

		var slot = document.getElementById('cresco-layer-local-ai-slot');
		if (slot) { slot.appendChild(section); return section; }
		var admin = document.querySelector('.cresco-layer-admin');
		var hero = admin.querySelector('.cresco-layer-admin__hero');
		if (hero && hero.nextSibling) admin.insertBefore(section, hero.nextSibling);
		else admin.appendChild(section);
		return section;
	}

	function collect() {
		var map = {};
		root.querySelectorAll('[data-ai]').forEach(function (el) { map[el.getAttribute('data-ai')] = el; });
		map.status = root.querySelector('[data-local-ai-status]');
		map.help = root.querySelector('[data-ai-help]');
		map.modelNote = root.querySelector('[data-ai-model-note]');
		map.saveStatus = root.querySelector('[data-ai-save-status]');
		map.diagnostics = root.querySelector('[data-ai-diagnostics]');
		return map;
	}

	function endpoint(path) { return String(cfg.restRoot).replace(/\/$/, '') + path; }
	function api(path, options) {
		options = options || {};
		options.headers = Object.assign({ 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' }, options.headers || {});
		return fetch(endpoint(path), options).then(function (response) {
			return response.json().catch(function () { return {}; }).then(function (body) {
				if (!response.ok) throw new Error(body.message || ('Cresco Local AI request failed (' + response.status + ').'));
				return body;
			});
		});
	}

	function setStatus(text, tone) { els.status.textContent = text || ''; els.status.className = 'cresco-layer-local-ai__status is-' + (tone || 'idle'); }
	function setSave(text, tone) { els.saveStatus.textContent = text || ''; els.saveStatus.className = tone ? 'is-' + tone : ''; }
	function boolValue(name) { return !!els[name].checked; }
	function numberValue(name, fallback) { var value = parseFloat(els[name].value); return Number.isFinite(value) ? value : fallback; }

	function formData() {
		return {
			enabled: boolValue('enabled'), provider: els.provider.value, connectionMode: els.connectionMode.value, endpoint: els.endpoint.value.trim(), apiToken: els.apiToken.value,
			analysisModel: els.analysisModel.value, visionModel: els.visionModel.value, temperature: numberValue('temperature', 0.2), contextWindow: Math.round(numberValue('contextWindow', 32768)),
			maxOutputTokens: Math.round(numberValue('maxOutputTokens', 4096)), minimumConfidence: numberValue('minimumConfidence', 0.85), requirePreview: boolValue('requirePreview'),
			autoApplySafe: boolValue('autoApplySafe'), allowScreenshots: boolValue('allowScreenshots'), includeNeighborContext: boolValue('includeNeighborContext')
		};
	}

	function fillProviderOptions(providers, selected) {
		state.providers = providers || {}; els.provider.innerHTML = '';
		Object.keys(state.providers).forEach(function (id) { var option = document.createElement('option'); option.value = id; option.textContent = state.providers[id].label || id; option.selected = id === selected; els.provider.appendChild(option); });
	}

	function applySummary(data) {
		state.summary = data; var s = data.settings || {};
		fillProviderOptions(data.providers || {}, s.provider || 'ollama');
		els.enabled.checked = !!s.enabled; els.connectionMode.value = s.connectionMode || 'browser'; els.endpoint.value = s.endpoint || ''; els.apiToken.value = '';
		els.apiToken.placeholder = s.hasApiToken ? 'Saved token retained — type to replace it' : 'Optional for local/private APIs';
		els.temperature.value = s.temperature == null ? 0.2 : s.temperature; els.contextWindow.value = s.contextWindow || 32768; els.maxOutputTokens.value = s.maxOutputTokens || 4096; els.minimumConfidence.value = s.minimumConfidence == null ? 0.85 : s.minimumConfidence;
		els.requirePreview.checked = s.requirePreview !== false; els.autoApplySafe.checked = !!s.autoApplySafe; els.allowScreenshots.checked = !!s.allowScreenshots; els.includeNeighborContext.checked = s.includeNeighborContext !== false;
		populateModels([], s.analysisModel || '', s.visionModel || ''); updateModeHelp();
		if (data.configured) setStatus('Configured · not tested', 'ready'); else setStatus(s.enabled ? 'Model not selected' : 'Disabled', s.enabled ? 'warning' : 'idle');
	}

	function populateModels(models, analysis, vision) {
		state.models = models || [];
		function fill(select, firstLabel, selected) {
			select.innerHTML = ''; var first = document.createElement('option'); first.value = ''; first.textContent = firstLabel; select.appendChild(first);
			state.models.forEach(function (model) { var option = document.createElement('option'); option.value = model.id; option.textContent = modelLabel(model); select.appendChild(option); });
			if (selected && !state.models.some(function (model) { return model.id === selected; })) { var saved = document.createElement('option'); saved.value = selected; saved.textContent = selected + ' · saved'; select.appendChild(saved); }
			select.value = selected || '';
		}
		fill(els.analysisModel, 'Select a model…', analysis); fill(els.visionModel, 'Disabled', vision);
		els.modelNote.textContent = state.models.length ? state.models.length + ' model(s) discovered from the local provider.' : 'Refresh models after the endpoint is reachable.';
	}

	function modelLabel(model) { var parts = [model.name || model.id]; if (model.parameterSize) parts.push(model.parameterSize); if (model.quantization) parts.push(model.quantization); return parts.filter(Boolean).join(' · '); }
	function updateModeHelp() {
		els.help.textContent = els.connectionMode.value === 'browser'
			? 'Recommended when WordPress is hosted remotely. Your browser contacts the local endpoint on this computer; the WordPress server does not need access to localhost.'
			: 'Use only when the model server is reachable from the WordPress/PHP host itself. 127.0.0.1 then refers to the WordPress server, not your PC.';
	}

	function saveSettings(silent) {
		if (!silent) setSave('Saving…', 'busy');
		return api('/local-ai/settings', { method: 'POST', body: JSON.stringify(formData()) }).then(function (data) { state.summary = data; els.apiToken.value = ''; if (!silent) setSave('Configuration saved.', 'success'); return data; }).catch(function (error) { setSave(error.message, 'error'); throw error; });
	}

	function browserJson(url) {
		return fetch(url, { method: 'GET', headers: { 'Accept': 'application/json' } }).then(function (response) { if (!response.ok) throw new Error('Local endpoint returned HTTP ' + response.status + '.'); return response.json(); }).catch(function (error) {
			if (/Failed to fetch|NetworkError|Load failed/i.test(error.message || '')) throw new Error('Browser could not reach the local endpoint. Confirm the local server is running and allows this WordPress origin, or use a Cresco Local Bridge / server-direct connection.'); throw error;
		});
	}

	function normalizeBrowserModels(provider, data) {
		if (provider === 'ollama') return (data.models || []).map(function (model) { var d = model.details || {}; return { id: model.model || model.name || '', name: model.name || model.model || '', size: model.size || 0, family: d.family || '', parameterSize: d.parameter_size || '', quantization: d.quantization_level || '' }; }).filter(function (model) { return !!model.id; });
		return (data.data || data.models || []).map(function (model) { if (typeof model === 'string') return { id: model, name: model }; var id = model.id || model.model || model.name || ''; return { id: id, name: model.name || id, ownedBy: model.owned_by || '' }; }).filter(function (model) { return !!model.id; });
	}

	function testConnection() {
		setStatus('Testing…', 'busy');
		return saveSettings(true).then(function () { return api('/local-ai/test', { method: 'POST', body: '{}' }); }).then(function (result) {
			if (!result.browserRequired) { setStatus('Connected · ' + (result.latencyMs || 0) + ' ms' + (result.version ? ' · v' + result.version : ''), 'success'); return result; }
			if (result.descriptor && result.descriptor.hasApiToken) throw new Error('Browser mode never exposes saved API tokens. Use an unauthenticated local bridge/API or switch to WordPress server direct.');
			var started = performance.now(); return browserJson(result.descriptor.healthUrl).then(function (body) { setStatus('Connected from browser · ' + Math.round(performance.now() - started) + ' ms' + (body.version ? ' · v' + body.version : ''), 'success'); return result; });
		}).catch(function (error) { setStatus(error.message, 'error'); throw error; });
	}

	function refreshModels() {
		setStatus('Discovering models…', 'busy'); var selectedAnalysis = els.analysisModel.value; var selectedVision = els.visionModel.value;
		return saveSettings(true).then(function () { return api('/local-ai/models'); }).then(function (result) {
			if (!result.browserRequired) return result.models || [];
			if (result.descriptor && result.descriptor.hasApiToken) throw new Error('Browser model discovery cannot use the saved token. Use server direct or an unauthenticated local bridge.');
			return browserJson(result.descriptor.modelsUrl).then(function (data) { return normalizeBrowserModels(result.descriptor.provider, data); });
		}).then(function (models) { populateModels(models, selectedAnalysis, selectedVision); setStatus(models.length ? 'Ready · ' + models.length + ' model(s)' : 'Connected · no models found', models.length ? 'success' : 'warning'); return models; }).catch(function (error) { setStatus(error.message, 'error'); throw error; });
	}

	function renderDiagnostics(checks) {
		els.diagnostics.hidden = false; els.diagnostics.innerHTML = ''; var title = document.createElement('h3'); title.textContent = 'Local AI diagnostics'; els.diagnostics.appendChild(title);
		(checks || []).forEach(function (check) { var row = document.createElement('div'); row.className = 'cresco-layer-local-ai__check is-' + (check.status || 'warning'); var mark = document.createElement('span'); mark.className = 'cresco-layer-local-ai__check-mark'; mark.textContent = check.status === 'pass' ? '✓' : (check.status === 'fail' ? '×' : '•'); var text = document.createElement('div'); var strong = document.createElement('strong'); strong.textContent = check.label || check.id || 'Check'; var detail = document.createElement('span'); detail.textContent = check.detail || ''; text.appendChild(strong); text.appendChild(detail); row.appendChild(mark); row.appendChild(text); els.diagnostics.appendChild(row); });
	}

	function runDiagnostics() {
		setStatus('Running diagnostics…', 'busy');
		return saveSettings(true).then(function () { return api('/local-ai/diagnostics', { method: 'POST', body: '{}' }); }).then(function (result) {
			if (!result.browserRequired) { renderDiagnostics(result.checks || []); setStatus(result.ok === false ? 'Diagnostics found a problem' : 'Diagnostics complete', result.ok === false ? 'error' : 'success'); return; }
			var checks = (result.checks || []).filter(function (check) { return check.id !== 'connection'; });
			if (result.descriptor && result.descriptor.hasApiToken) { checks.push({ id: 'connection', label: 'Browser connection', status: 'fail', detail: 'Saved tokens are not exposed to browser mode.' }); renderDiagnostics(checks); setStatus('Diagnostics found a problem', 'error'); return; }
			var started = performance.now(); return browserJson(result.descriptor.healthUrl).then(function () { checks.push({ id: 'connection', label: 'Browser connection', status: 'pass', detail: Math.round(performance.now() - started) + ' ms' }); return browserJson(result.descriptor.modelsUrl); }).then(function (data) {
				var models = normalizeBrowserModels(result.descriptor.provider, data); checks.push({ id: 'models', label: 'Model discovery', status: models.length ? 'pass' : 'warning', detail: models.length + ' model(s) available' }); var selected = els.analysisModel.value; checks.push({ id: 'analysis-model', label: 'Analysis model', status: selected && models.some(function (m) { return m.id === selected; }) ? 'pass' : 'warning', detail: selected || 'Not selected' }); renderDiagnostics(checks); setStatus('Diagnostics complete', 'success');
			}).catch(function (error) { checks.push({ id: 'connection', label: 'Browser connection', status: 'fail', detail: error.message }); renderDiagnostics(checks); setStatus('Diagnostics found a problem', 'error'); });
		}).catch(function (error) { setStatus(error.message, 'error'); });
	}

	root.addEventListener('click', function (event) { var button = event.target.closest('[data-ai-action]'); if (!button) return; var action = button.getAttribute('data-ai-action'); if (action === 'save') saveSettings(false); else if (action === 'test') testConnection(); else if (action === 'models') refreshModels(); else if (action === 'diagnostics') runDiagnostics(); });
	els.connectionMode.addEventListener('change', updateModeHelp);
	els.provider.addEventListener('change', function () { var provider = state.providers[els.provider.value] || {}; els.endpoint.value = provider.defaultEndpoint || els.endpoint.value; populateModels([], '', ''); });
	api('/local-ai').then(function (data) { applySummary(data); }).catch(function (error) { setStatus('Unavailable', 'error'); setSave(error.message, 'error'); });
}());
