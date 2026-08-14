(function () {
	'use strict';

	var cfg = window.crescoLayerEditor || {};
	var local = cfg.localAI || {};
	var pending = null;
	var mounted = false;

	function validId(value) { return /^[A-Za-z0-9_-]{1,64}$/.test(String(value || '')); }
	function escapeHtml(value) {
		return String(value == null ? '' : value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
	}
	function endpoint(path) { return String(cfg.restRoot || '').replace(/\/$/, '') + path; }
	function request(path, options) {
		options = options || {};
		options.headers = Object.assign({ 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' }, options.headers || {});
		return fetch(endpoint(path), options).then(function (response) {
			return response.json().catch(function () { return {}; }).then(function (body) {
				if (!response.ok) throw new Error(body.message || ('Cresco Local AI request failed (' + response.status + ').'));
				return body;
			});
		});
	}
	function postId() {
		var id = parseInt(cfg.postId || 0, 10);
		if (id) return id;
		try { return parseInt(window.elementor.config.document.id || 0, 10) || 0; } catch (e) { return 0; }
	}
	function modelId(model) {
		if (!model) return '';
		var id = model.id || (model.attributes && model.attributes.id) || '';
		if (!id && typeof model.get === 'function') id = model.get('id') || '';
		return validId(id) ? String(id) : '';
	}
	function selectedId() {
		try {
			if (window.elementor && elementor.channels && elementor.channels.editor) {
				var selected = elementor.channels.editor.request('selectedElement');
				var id = modelId(selected && (selected.model || selected));
				if (id) return id;
			}
		} catch (e) {}
		var node = document.querySelector('.elementor-element.elementor-selected[data-id],[data-id][aria-selected="true"]');
		return node && validId(node.getAttribute('data-id')) ? node.getAttribute('data-id') : '';
	}
	function editorApi() { return window.$e && typeof window.$e.run === 'function' ? window.$e : null; }
	function getContainer(id) {
		try { return window.elementor && elementor.getContainer ? elementor.getContainer(String(id)) : null; } catch (e) { return null; }
	}
	function liveSettings(id) {
		var container = getContainer(id);
		try { return container && container.settings && typeof container.settings.toJSON === 'function' ? (container.settings.toJSON() || {}) : {}; } catch (e) { return {}; }
	}
	function setStatus(message, tone) {
		var node = document.querySelector('#cresco-layer-skills-panel [data-cresco-skill-status]');
		if (!node) return;
		node.className = 'cresco-layer-skill-status is-' + (tone || 'info');
		node.textContent = message || '';
	}

	function mount() {
		if (mounted) return true;
		var panel = document.getElementById('cresco-layer-skills-panel');
		if (!panel) return false;
		var row = panel.querySelector('.cresco-layer-skill-command__row');
		var command = panel.querySelector('[data-cresco-skill-command]');
		if (!row || !command) return false;

		var button = document.createElement('button');
		button.type = 'button';
		button.className = 'cresco-layer-ai-analyze';
		button.setAttribute('data-cresco-ai-analyze', '1');
		button.textContent = local.enabled && local.analysisModel ? 'Analyze AI' : 'AI off';
		button.disabled = !(local.enabled && local.analysisModel);
		button.title = local.enabled ? 'Use the configured local model to diagnose this widget and propose validated Cresco skills.' : 'Enable Local AI in Cresco Layer first.';
		row.appendChild(button);

		var meta = document.createElement('div');
		meta.className = 'cresco-layer-ai-meta';
		meta.innerHTML = local.enabled && local.analysisModel
			? '<span>Local AI · ' + escapeHtml(local.analysisModel) + '</span><span>AI analyzes → Skills execute</span>'
			: '<span>Local AI is not configured.</span><a href="' + escapeHtml((cfg.adminUrl || '') + '#cresco-layer-local-ai') + '">Configure in Cresco Layer</a>';
		row.parentNode.appendChild(meta);

		var preview = document.createElement('section');
		preview.className = 'cresco-layer-ai-preview';
		preview.setAttribute('data-cresco-ai-preview', '1');
		preview.hidden = true;
		row.parentNode.appendChild(preview);

		button.addEventListener('click', analyze);
		mounted = true;
		return true;
	}

	function analyze() {
		var id = selectedId();
		var pid = postId();
		var input = document.querySelector('#cresco-layer-skills-panel [data-cresco-skill-command]');
		var task = String(input && input.value || '').trim();
		if (!id || !pid) { setStatus('Select a widget before using Local AI.', 'error'); return; }
		if (!task) { setStatus('Describe the result you want, for example “tối ưu section này cho mobile”.', 'error'); return; }
		pending = null;
		renderPreview(null);
		setStatus('Building a semantic widget context for Local AI…', 'busy');
		var body = { task: task, liveSettings: liveSettings(id) };
		request('/documents/' + pid + '/local-ai/' + encodeURIComponent(id) + '/analyze', { method: 'POST', body: JSON.stringify(body) }).then(function (result) {
			if (!result.browserRequired) return result;
			setStatus('Running the local model in this browser…', 'busy');
			return browserInfer(result).then(function (plan) {
				return request('/documents/' + pid + '/local-ai/' + encodeURIComponent(id) + '/validate', { method: 'POST', body: JSON.stringify({ task: task, liveSettings: liveSettings(id), plan: plan }) });
			});
		}).then(function (result) {
			if (selectedId() !== id) throw new Error('Selection changed while Local AI was analyzing. Run the analysis again for the current widget.');
			pending = { result: result, elementId: id, task: task };
			renderPreview(result);
			var decision = result.decision || {};
			if (decision.accepted) setStatus('Local AI plan validated against this widget’s exact skill registry. Review before applying.', 'success');
			else if (decision.reason === 'clarification-required') setStatus('Local AI needs clarification before it can build a safe plan.', 'warning');
			else if (decision.reason === 'below-confidence-threshold') setStatus('Local AI confidence is below the configured threshold. Nothing will be applied.', 'warning');
			else setStatus('Local AI did not produce an executable plan.', 'warning');
		}).catch(function (error) {
			pending = null;
			renderPreview(null);
			setStatus(error.message, 'error');
		});
	}

	function browserInfer(prepared) {
		var descriptor = prepared.descriptor || {};
		if (descriptor.hasApiToken) return Promise.reject(new Error('Browser inference cannot expose the saved Local AI token. Use WordPress server direct or an unauthenticated Cresco Local Bridge.'));
		if (!descriptor.chatUrl) return Promise.reject(new Error('Local AI chat endpoint is missing.'));
		var options = prepared.requestOptions || {};
		var body;
		if (descriptor.apiStyle === 'ollama') {
			body = { model: prepared.model, messages: prepared.messages || [], stream: false, format: 'json', options: { temperature: options.temperature == null ? 0.2 : options.temperature, num_ctx: options.contextWindow || 32768, num_predict: options.maxOutputTokens || 4096 } };
		} else {
			body = { model: prepared.model, messages: prepared.messages || [], temperature: options.temperature == null ? 0.2 : options.temperature, max_tokens: options.maxOutputTokens || 4096 };
		}
		return fetch(descriptor.chatUrl, { method: 'POST', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).then(function (response) {
			if (!response.ok) throw new Error('Local AI endpoint returned HTTP ' + response.status + '.');
			return response.json();
		}).then(function (data) {
			var content = descriptor.apiStyle === 'ollama' ? (data.message && data.message.content) : (data.choices && data.choices[0] && data.choices[0].message && data.choices[0].message.content);
			if (!content) throw new Error('Local AI returned an empty response.');
			return parsePlan(content);
		}).catch(function (error) {
			if (/Failed to fetch|NetworkError|Load failed/i.test(error.message || '')) throw new Error('Browser cannot reach the local model. Confirm Ollama/LM Studio is running and allows this WordPress origin, or use Cresco Local Bridge / server-direct mode.');
			throw error;
		});
	}

	function parsePlan(content) {
		content = String(content || '').trim().replace(/^```(?:json)?\s*/i, '').replace(/\s*```$/i, '');
		try { return JSON.parse(content); } catch (e) { throw new Error('Local AI did not return valid Cresco plan JSON. Try a stronger model or lower temperature.'); }
	}

	function renderPreview(result) {
		var node = document.querySelector('#cresco-layer-skills-panel [data-cresco-ai-preview]');
		if (!node) return;
		if (!result || !result.plan) { node.hidden = true; node.innerHTML = ''; return; }
		var plan = result.plan;
		var decision = result.decision || {};
		var analysis = plan.analysis || {};
		var evidence = (analysis.evidence || []).map(function (item) { return '<li>' + escapeHtml(item) + '</li>'; }).join('');
		var skills = (plan.requestedSkills || []).map(function (item) {
			return '<li><strong>' + escapeHtml(item.skillId) + '</strong><span>' + escapeHtml(item.reason || '') + '</span><code>' + escapeHtml(JSON.stringify(item.params || {})) + '</code></li>';
		}).join('');
		var questions = (plan.questions || []).map(function (item) { return '<li>' + escapeHtml(item) + '</li>'; }).join('');
		node.hidden = false;
		node.innerHTML = '<header><div><span>Semantic Local AI</span><h3>' + escapeHtml(plan.summary || plan.intent) + '</h3></div><b class="' + (decision.accepted ? 'is-accepted' : 'is-blocked') + '">' + Math.round((plan.confidence || 0) * 100) + '%</b></header>' +
			'<div class="cresco-layer-ai-diagnosis"><strong>Diagnosis</strong><p>' + escapeHtml(analysis.problem || '') + '</p>' + (evidence ? '<ul>' + evidence + '</ul>' : '') + '</div>' +
			(skills ? '<div class="cresco-layer-ai-plan"><strong>Validated skill plan</strong><ol>' + skills + '</ol></div>' : '') +
			(questions ? '<div class="cresco-layer-ai-questions"><strong>Clarification needed</strong><ul>' + questions + '</ul></div>' : '') +
			'<footer><span>' + escapeHtml(decision.reason || '') + ' · threshold ' + Math.round((decision.minimumConfidence || 0) * 100) + '%</span>' + (decision.accepted ? '<button type="button" data-cresco-ai-apply>Apply validated plan</button>' : '') + '</footer>';
		var apply = node.querySelector('[data-cresco-ai-apply]');
		if (apply) apply.addEventListener('click', applyPending);
	}

	function applyPending() {
		if (!pending || !pending.result || !pending.result.plan) return;
		var id = pending.elementId;
		if (selectedId() !== id) { setStatus('Selection changed. Analyze the current widget again.', 'error'); return; }
		var items = pending.result.plan.requestedSkills || [];
		if (!items.length) return;
		setStatus('Resolving every AI suggestion through native Cresco skills before apply…', 'busy');
		var pid = postId();
		var resolutions = [];
		var chain = Promise.resolve();
		items.forEach(function (item) {
			chain = chain.then(function () {
				return request('/documents/' + pid + '/skills/' + encodeURIComponent(id) + '/resolve', { method: 'POST', body: JSON.stringify({ skillId: item.skillId, params: item.params || {}, liveSettings: liveSettings(id) }) }).then(function (resolution) {
					if (resolution.elementId !== id) throw new Error('Resolved AI skill attempted to escape the selected widget.');
					resolutions.push(resolution);
				});
			});
		});
		chain.then(function () {
			applyBatch(id, resolutions, 'Cresco Local AI · ' + (pending.result.plan.intent || 'Validated plan'));
			setStatus('Applied the validated Local AI plan through native Elementor controls. Undo is available in Elementor history.', 'success');
			pending = null;
			renderPreview(null);
			if (window.CrescoLayerSkills && typeof window.CrescoLayerSkills.refresh === 'function') setTimeout(function () { window.CrescoLayerSkills.refresh(); }, 250);
		}).catch(function (error) { setStatus(error.message, 'error'); });
	}

	function applyBatch(id, resolutions, label) {
		var container = getContainer(id);
		var e = editorApi();
		if (!container || !e) throw new Error('Elementor live settings API is unavailable.');
		var before = liveSettings(id);
		var touched = {};
		(resolutions || []).forEach(function (resolution) {
			(resolution.operations || []).forEach(function (operation) {
				if (operation.elementId !== id) throw new Error('Local AI plan attempted to escape the selected widget.');
				if (operation.operation !== 'update-setting' && operation.operation !== 'remove-setting') throw new Error('Local AI plans may only execute validated setting operations.');
				touched[operation.setting] = true;
			});
		});
		var history = null;
		try {
			if (typeof e.internal === 'function') history = e.internal('document/history/start-log', { type: 'change', title: label || 'Cresco Local AI' });
			(resolutions || []).forEach(function (resolution) {
				(resolution.operations || []).forEach(function (operation) {
					var change = {}; change[operation.setting] = operation.operation === 'remove-setting' ? undefined : operation.value;
					e.run('document/elements/settings', { container: container, settings: change, options: { external: true } });
				});
			});
			try { if (elementor.saver && typeof elementor.saver.setFlagEditorChange === 'function') elementor.saver.setFlagEditorChange(true); } catch (flagError) {}
		} catch (error) {
			Object.keys(touched).forEach(function (setting) {
				try { var rollback = {}; rollback[setting] = Object.prototype.hasOwnProperty.call(before, setting) ? before[setting] : undefined; e.run('document/elements/settings', { container: container, settings: rollback, options: { external: true } }); } catch (rollbackError) {}
			});
			throw error;
		} finally {
			try { if (history != null && typeof e.internal === 'function') e.internal('document/history/end-log', { id: history }); } catch (endError) {}
		}
	}

	function boot() {
		if (mount()) return;
		var observer = new MutationObserver(function () { if (mount()) observer.disconnect(); });
		observer.observe(document.documentElement, { childList: true, subtree: true });
		setTimeout(function () { try { observer.disconnect(); } catch (e) {} }, 30000);
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true }); else boot();
}());
