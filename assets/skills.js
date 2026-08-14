(function () {
	'use strict';

	var cfg = window.crescoLayerEditor || {};
	var api = window.CrescoLayerSkills = window.CrescoLayerSkills || {};
	var panel = null;
	var launcher = null;
	var profile = null;
	var activeSkill = null;
	var selectedElementId = '';
	var loadingFor = '';
	var pollTimer = null;

	api.version = cfg.version || '0.6.0';
	api.runtime = 'deterministic-widget-skills-v1';
	api.usesChatbot = false;

	function validId(value) {
		return /^[A-Za-z0-9_-]{1,64}$/.test(String(value || ''));
	}

	function escapeHtml(value) {
		return String(value == null ? '' : value)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#039;');
	}

	function modelId(model) {
		if (!model) return '';
		var id = model.id || (model.attributes && model.attributes.id) || '';
		if (!id && typeof model.get === 'function') id = model.get('id') || '';
		return validId(id) ? String(id) : '';
	}

	function idFromNode(node) {
		if (!node || node.nodeType === 3) return '';
		var target = node.closest ? (node.closest('[data-id],[data-e-id],[data-element-id]') || node) : node;
		if (!target || !target.getAttribute) return '';
		var keys = ['data-id', 'data-e-id', 'data-element-id'];
		for (var i = 0; i < keys.length; i++) {
			var value = target.getAttribute(keys[i]);
			if (validId(value)) return String(value);
		}
		return '';
	}

	function selectedFromDom(doc) {
		if (!doc || !doc.querySelector) return '';
		var selectors = [
			'.elementor-element.elementor-selected[data-id]',
			'.elementor-element.elementor-element-edit-mode[data-id]',
			'[data-id][aria-selected="true"]', '[data-e-id][aria-selected="true"]', '[data-element-id][aria-selected="true"]'
		];
		for (var i = 0; i < selectors.length; i++) {
			try {
				var id = idFromNode(doc.querySelector(selectors[i]));
				if (id) return id;
			} catch (e) {}
		}
		return '';
	}

	function selectedId() {
		try {
			if (window.elementor && elementor.channels && elementor.channels.editor) {
				var selected = elementor.channels.editor.request('selectedElement');
				var model = selected && (selected.model || selected);
				var id = modelId(model);
				if (id) return id;
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
		return '';
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
		if (!cfg.restRoot) return Promise.reject(new Error('Cresco REST configuration is missing. Reload Elementor.'));
		options = options || {};
		options.headers = Object.assign({ 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' }, options.headers || {});
		return fetch(endpoint(path), options).then(function (response) {
			return response.json().catch(function () { return {}; }).then(function (body) {
				if (!response.ok) throw new Error(body.message || ('Cresco skill request failed (' + response.status + ').'));
				return body;
			});
		});
	}

	function editorApi() {
		return window.$e && typeof window.$e.run === 'function' ? window.$e : null;
	}

	function getContainer(id) {
		if (!validId(id) || !window.elementor) return null;
		try {
			var container = elementor.getContainer(String(id));
			if (container) return container;
		} catch (e) {}
		try {
			var e = editorApi();
			var component = e && e.components && typeof e.components.get === 'function' ? e.components.get('document') : null;
			if (component && component.utils && typeof component.utils.findContainerById === 'function') return component.utils.findContainerById(String(id)) || null;
		} catch (e2) {}
		return null;
	}

	function liveSettings(id) {
		var container = getContainer(id);
		try {
			if (container && container.settings && typeof container.settings.toJSON === 'function') return container.settings.toJSON() || {};
		} catch (e) {}
		return {};
	}

	function startHistory(label) {
		var e = editorApi();
		if (!e || typeof e.internal !== 'function') return null;
		try { return e.internal('document/history/start-log', { type: 'change', title: label || 'Cresco Skill' }); }
		catch (error) { return null; }
	}

	function endHistory(id) {
		var e = editorApi();
		if (!e || typeof e.internal !== 'function' || id === null || typeof id === 'undefined') return;
		try { e.internal('document/history/end-log', { id: id }); } catch (error) {}
	}

	function applyResolution(resolution) {
		var id = String(resolution.elementId || '');
		if (!validId(id)) throw new Error('Skill resolution returned an invalid target.');
		if (selectedId() !== id) throw new Error('Selection changed. Re-open Cresco Skills for the current widget.');
		var container = getContainer(id);
		var e = editorApi();
		if (!container || !e) throw new Error('Elementor live settings API is unavailable.');
		var history = startHistory(resolution.historyLabel || 'Cresco Skill');
		try {
			(resolution.operations || []).forEach(function (operation) {
				if (operation.elementId !== id) throw new Error('Skill attempted to escape the selected widget.');
				if (operation.operation === 'update-setting') {
					var changes = {};
					changes[operation.setting] = operation.value;
					e.run('document/elements/settings', { container: container, settings: changes, options: { external: true } });
				} else if (operation.operation === 'remove-setting') {
					var remove = {};
					remove[operation.setting] = undefined;
					e.run('document/elements/settings', { container: container, settings: remove, options: { external: true } });
				} else {
					throw new Error('Widget skill runtime only permits live setting operations.');
				}
			});
			try {
				if (elementor.saver && typeof elementor.saver.setFlagEditorChange === 'function') elementor.saver.setFlagEditorChange(true);
			} catch (e2) {}
		} finally {
			endHistory(history);
		}
	}

	function ensureLauncher() {
		if (launcher && document.body.contains(launcher)) return launcher;
		launcher = document.createElement('button');
		launcher.type = 'button';
		launcher.id = 'cresco-layer-skills-launcher';
		launcher.className = 'cresco-layer-skills-launcher';
		launcher.innerHTML = '<span class="cresco-layer-skills-launcher__spark">✦</span><span data-cresco-skill-launch-label>Cresco Skills</span>';
		launcher.addEventListener('click', function () { openPanel(); });
		var tools = document.querySelector('.cresco-layer-editor-tools');
		if (tools) {
			launcher.classList.add('is-inside-tools');
			tools.insertBefore(launcher, tools.firstChild);
		} else if (document.body) {
			document.body.appendChild(launcher);
		}
		return launcher;
	}

	function updateLauncher(id) {
		var button = ensureLauncher();
		var label = button.querySelector('[data-cresco-skill-launch-label]');
		button.disabled = !validId(id);
		button.classList.toggle('has-selection', validId(id));
		if (label) label.textContent = validId(id) ? 'Skills · ' + id : 'Cresco Skills';
	}

	function ensurePanel() {
		if (panel) return panel;
		panel = document.createElement('aside');
		panel.id = 'cresco-layer-skills-panel';
		panel.className = 'cresco-layer-skills-panel';
		panel.hidden = true;
		panel.innerHTML = '' +
			'<header class="cresco-layer-skills-head"><div><span>Runtime Skill Engine</span><h2>Cresco Skills</h2><p data-cresco-skill-target>Select an Elementor widget.</p></div><button type="button" data-cresco-skill-close aria-label="Close">×</button></header>' +
			'<div class="cresco-layer-skill-command"><div class="cresco-layer-skill-command__label"><strong>Command</strong><span>No chatbot · deterministic</span></div><div class="cresco-layer-skill-command__row"><input type="text" data-cresco-skill-command placeholder="padding 24px · mobile font size 28px"><button type="button" data-cresco-skill-run>Run</button></div><div class="cresco-layer-skill-examples" data-cresco-skill-examples></div></div>' +
			'<div class="cresco-layer-skill-toolbar"><input type="search" data-cresco-skill-search placeholder="Search skills, controls, roles…"><span data-cresco-skill-count>0 skills</span></div>' +
			'<div class="cresco-layer-skill-status" data-cresco-skill-status>Choose a widget to inspect its runtime skills.</div>' +
			'<div class="cresco-layer-skill-list" data-cresco-skill-list></div>' +
			'<section class="cresco-layer-skill-editor" data-cresco-skill-editor hidden></section>';
		document.body.appendChild(panel);
		panel.querySelector('[data-cresco-skill-close]').addEventListener('click', closePanel);
		panel.querySelector('[data-cresco-skill-search]').addEventListener('input', renderSkillList);
		panel.querySelector('[data-cresco-skill-run]').addEventListener('click', runCommand);
		panel.querySelector('[data-cresco-skill-command]').addEventListener('keydown', function (event) {
			if (event.key === 'Enter') { event.preventDefault(); runCommand(); }
		});
		return panel;
	}

	function openPanel() {
		var id = selectedId();
		if (!id) { setStatus('Select an Elementor widget or container first.', 'error'); return; }
		ensurePanel().hidden = false;
		loadProfile(id, true);
	}

	function closePanel() {
		if (panel) panel.hidden = true;
		activeSkill = null;
	}

	function setStatus(message, tone) {
		ensurePanel();
		var status = panel.querySelector('[data-cresco-skill-status]');
		if (!status) return;
		status.className = 'cresco-layer-skill-status is-' + (tone || 'info');
		status.textContent = message || '';
	}

	function loadProfile(id, force) {
		if (!validId(id)) return;
		if (!force && profile && profile.element && profile.element.id === id) return;
		if (loadingFor === id) return;
		var pid = postId();
		if (!pid) { setStatus('Cannot determine the current Elementor document ID.', 'error'); return; }
		loadingFor = id;
		setStatus('Reading this widget’s Elementor controls and compiling skills…', 'busy');
		request('/documents/' + pid + '/skills/' + encodeURIComponent(id)).then(function (data) {
			loadingFor = '';
			if (selectedId() !== id && !panel.hidden) return;
			profile = data;
			activeSkill = null;
			renderProfile();
			setStatus('Runtime controls compiled into ' + ((data.compiler && data.compiler.executableSkillCount) || 0) + ' executable skills.', 'success');
		}).catch(function (error) {
			loadingFor = '';
			profile = null;
			renderProfile();
			setStatus(error.message, 'error');
		});
	}

	function renderProfile() {
		ensurePanel();
		var target = panel.querySelector('[data-cresco-skill-target]');
		var count = panel.querySelector('[data-cresco-skill-count]');
		var examples = panel.querySelector('[data-cresco-skill-examples]');
		if (!profile || !profile.element) {
			if (target) target.textContent = 'Select an Elementor widget.';
			if (count) count.textContent = '0 skills';
			if (examples) examples.innerHTML = '';
			renderSkillList();
			return;
		}
		if (target) target.textContent = (profile.element.title || profile.element.name) + ' · ' + profile.element.id + (profile.element.isAtomic ? ' · Atomic/V4' : '');
		if (count) count.textContent = (profile.compiler.skillCount || 0) + ' skills';
		if (examples) {
			examples.innerHTML = (profile.commandExamples || []).slice(0, 6).map(function (item) {
				return '<button type="button" data-cresco-command-example="' + escapeHtml(item) + '">' + escapeHtml(item) + '</button>';
			}).join('');
			Array.prototype.forEach.call(examples.querySelectorAll('[data-cresco-command-example]'), function (button) {
				button.addEventListener('click', function () {
					panel.querySelector('[data-cresco-skill-command]').value = button.getAttribute('data-cresco-command-example') || '';
					runCommand();
				});
			});
		}
		renderSkillList();
	}

	function skillHaystack(skill) {
		return [skill.label, skill.control, skill.setting, skill.role, skill.category, skill.type].concat(skill.searchTerms || []).join(' ').toLowerCase();
	}

	function renderSkillList() {
		ensurePanel();
		var list = panel.querySelector('[data-cresco-skill-list]');
		if (!list) return;
		if (!profile || !Array.isArray(profile.skills)) {
			list.innerHTML = '<div class="cresco-layer-skill-empty">No runtime skill profile loaded.</div>';
			return;
		}
		var query = String(panel.querySelector('[data-cresco-skill-search]').value || '').trim().toLowerCase();
		var skills = profile.skills.filter(function (skill) { return !query || skillHaystack(skill).indexOf(query) !== -1; });
		var groups = {};
		skills.forEach(function (skill) {
			var category = skill.category || 'Advanced';
			(groups[category] = groups[category] || []).push(skill);
		});
		var html = '';
		Object.keys(groups).sort().forEach(function (category) {
			html += '<section class="cresco-layer-skill-group"><h3>' + escapeHtml(category) + '<span>' + groups[category].length + '</span></h3>';
			groups[category].forEach(function (skill) {
				var disabled = skill.mode === 'read-only';
				html += '<button type="button" class="cresco-layer-skill-card' + (disabled ? ' is-readonly' : '') + '" data-cresco-skill-id="' + escapeHtml(skill.id) + '"' + (disabled ? ' disabled' : '') + '>' +
					'<span class="cresco-layer-skill-card__main"><strong>' + escapeHtml(skill.label) + '</strong><small>' + escapeHtml(skill.role || skill.setting) + '</small></span>' +
					'<span class="cresco-layer-skill-card__badges"><i>' + escapeHtml(skill.type) + '</i>' + (skill.responsive ? '<i>responsive</i>' : '') + (skill.dynamic ? '<i>dynamic</i>' : '') + '<i class="is-risk-' + escapeHtml(skill.risk) + '">' + escapeHtml(skill.risk) + '</i></span></button>';
			});
			html += '</section>';
		});
		list.innerHTML = html || '<div class="cresco-layer-skill-empty">No skills match this search.</div>';
		Array.prototype.forEach.call(list.querySelectorAll('[data-cresco-skill-id]'), function (button) {
			button.addEventListener('click', function () { chooseSkill(button.getAttribute('data-cresco-skill-id')); });
		});
	}

	function findSkill(id) {
		if (!profile || !Array.isArray(profile.skills)) return null;
		for (var i = 0; i < profile.skills.length; i++) if (profile.skills[i].id === id) return profile.skills[i];
		return null;
	}

	function chooseSkill(id) {
		var skill = findSkill(id);
		if (!skill) return;
		activeSkill = skill;
		renderSkillEditor(skill);
	}

	function optionMarkup(options, current) {
		return Object.keys(options || {}).map(function (value) {
			var label = typeof options[value] === 'string' ? options[value] : value;
			return '<option value="' + escapeHtml(value) + '"' + (String(current) === String(value) ? ' selected' : '') + '>' + escapeHtml(label) + '</option>';
		}).join('');
	}

	function currentValue(skill, device) {
		var devices = skill.current && skill.current.devices || {};
		return devices[device || 'desktop'];
	}

	function renderSkillEditor(skill) {
		var editor = panel.querySelector('[data-cresco-skill-editor]');
		if (!editor) return;
		var input = skill.input || {};
		var device = 'desktop';
		var current = currentValue(skill, device);
		var devices = (skill.devices || ['desktop']).map(function (item) { return '<option value="' + escapeHtml(item) + '">' + escapeHtml(item) + '</option>'; }).join('');
		var deviceField = skill.responsive ? '<label>Device<select data-cresco-param="device">' + devices + '</select></label>' : '';
		var valueField = '';
		if (skill.type === 'select' || skill.type === 'choose' || skill.type === 'select2') {
			valueField = '<label>Value<select data-cresco-param="value">' + optionMarkup(input.options || {}, current) + '</select></label>';
		} else if (skill.type === 'switcher') {
			valueField = '<label>Value<select data-cresco-param="value"><option value="yes">On</option><option value="">Off</option></select></label>';
		} else if (skill.type === 'dimensions') {
			var dimension = current && typeof current === 'object' ? current : {};
			valueField = '<div class="cresco-layer-skill-dimensions"><label>Top<input data-cresco-param="top" value="' + escapeHtml(dimension.top || '') + '"></label><label>Right<input data-cresco-param="right" value="' + escapeHtml(dimension.right || '') + '"></label><label>Bottom<input data-cresco-param="bottom" value="' + escapeHtml(dimension.bottom || '') + '"></label><label>Left<input data-cresco-param="left" value="' + escapeHtml(dimension.left || '') + '"></label></div>' + unitField(input.units, dimension.unit || 'px');
		} else if (skill.type === 'slider' || skill.type === 'size') {
			var slider = current && typeof current === 'object' ? current : {};
			valueField = '<label>Value<input type="number" step="any" data-cresco-param="value" value="' + escapeHtml(slider.size == null ? '' : slider.size) + '"></label>' + unitField(input.units, slider.unit || 'px');
		} else if (skill.mode === 'expert' || ['repeater', 'gallery', 'structure'].indexOf(skill.type) !== -1) {
			valueField = '<label>Structured JSON<textarea data-cresco-param="json">' + escapeHtml(JSON.stringify(current == null ? null : current, null, 2)) + '</textarea></label>';
		} else {
			var scalar = current == null || typeof current === 'object' ? '' : current;
			valueField = '<label>Value<input data-cresco-param="value" value="' + escapeHtml(scalar) + '" placeholder="Enter value"></label>';
		}
		editor.hidden = false;
		editor.innerHTML = '<div class="cresco-layer-skill-editor__head"><div><span>' + escapeHtml(skill.category) + '</span><h3>' + escapeHtml(skill.label) + '</h3><p>' + escapeHtml(skill.description || skill.setting) + '</p></div><button type="button" data-cresco-editor-close>×</button></div>' +
			'<div class="cresco-layer-skill-editor__meta"><span>' + escapeHtml(skill.setting) + '</span><span>' + escapeHtml(skill.type) + '</span><span>' + escapeHtml(skill.risk) + '</span>' + (skill.dynamic ? '<span>Dynamic Tag capable</span>' : '') + '</div>' +
			'<div class="cresco-layer-skill-fields">' + deviceField + valueField + '</div>' +
			(skill.conditions && Object.keys(skill.conditions).length ? '<details><summary>Control conditions</summary><pre>' + escapeHtml(JSON.stringify(skill.conditions, null, 2)) + '</pre></details>' : '') +
			'<footer><button type="button" class="cresco-layer-skill-cancel" data-cresco-editor-close>Cancel</button><button type="button" class="cresco-layer-skill-apply" data-cresco-skill-apply>Apply skill</button></footer>';
		Array.prototype.forEach.call(editor.querySelectorAll('[data-cresco-editor-close]'), function (button) {
			button.addEventListener('click', function () { editor.hidden = true; activeSkill = null; });
		});
		var deviceSelect = editor.querySelector('[data-cresco-param="device"]');
		if (deviceSelect) deviceSelect.addEventListener('change', function () { updateEditorCurrentForDevice(skill, deviceSelect.value); });
		editor.querySelector('[data-cresco-skill-apply]').addEventListener('click', applyActiveSkill);
	}

	function unitField(units, current) {
		units = Array.isArray(units) ? units : [];
		if (!units.length) return '';
		return '<label>Unit<select data-cresco-param="unit">' + units.map(function (unit) { return '<option value="' + escapeHtml(unit) + '"' + (unit === current ? ' selected' : '') + '>' + escapeHtml(unit) + '</option>'; }).join('') + '</select></label>';
	}

	function updateEditorCurrentForDevice(skill, device) {
		var editor = panel.querySelector('[data-cresco-skill-editor]');
		var current = currentValue(skill, device);
		if (skill.type === 'dimensions' && current && typeof current === 'object') {
			['top', 'right', 'bottom', 'left', 'unit'].forEach(function (key) { var el = editor.querySelector('[data-cresco-param="' + key + '"]'); if (el) el.value = current[key] == null ? '' : current[key]; });
		} else if ((skill.type === 'slider' || skill.type === 'size') && current && typeof current === 'object') {
			var value = editor.querySelector('[data-cresco-param="value"]'); if (value) value.value = current.size == null ? '' : current.size;
			var unit = editor.querySelector('[data-cresco-param="unit"]'); if (unit && current.unit) unit.value = current.unit;
		} else {
			var input = editor.querySelector('[data-cresco-param="value"]'); if (input) input.value = current == null || typeof current === 'object' ? '' : current;
		}
	}

	function editorParams() {
		var editor = panel.querySelector('[data-cresco-skill-editor]');
		var params = {};
		Array.prototype.forEach.call(editor.querySelectorAll('[data-cresco-param]'), function (field) {
			var key = field.getAttribute('data-cresco-param');
			if (key === 'json') {
				try { params.value = JSON.parse(field.value); }
				catch (error) { throw new Error('Structured skill value must be valid JSON.'); }
			} else params[key] = field.value;
		});
		return params;
	}

	function resolve(body) {
		if (!profile || !profile.element) return Promise.reject(new Error('No widget skill profile loaded.'));
		var pid = postId();
		body.liveSettings = liveSettings(profile.element.id);
		return request('/documents/' + pid + '/skills/' + encodeURIComponent(profile.element.id) + '/resolve', { method: 'POST', body: JSON.stringify(body) });
	}

	function applyActiveSkill() {
		if (!activeSkill) return;
		var params;
		try { params = editorParams(); }
		catch (error) { setStatus(error.message, 'error'); return; }
		setStatus('Validating ' + activeSkill.label + ' against this widget’s runtime controls…', 'busy');
		resolve({ skillId: activeSkill.id, params: params }).then(function (resolution) {
			applyResolution(resolution);
			setStatus('Applied ' + activeSkill.label + ' through native Elementor controls. Undo is available in Elementor history.', 'success');
			setTimeout(function () { loadProfile(profile.element.id, true); }, 250);
		}).catch(function (error) { setStatus(error.message, 'error'); });
	}

	function runCommand() {
		ensurePanel();
		var input = panel.querySelector('[data-cresco-skill-command]');
		var command = String(input.value || '').trim();
		if (!command) { setStatus('Enter a deterministic skill command, for example “padding 24px”.', 'error'); return; }
		if (!profile || !profile.element) { setStatus('Select a widget and load its skill profile first.', 'error'); return; }
		setStatus('Routing command to this widget’s native skill registry…', 'busy');
		resolve({ command: command }).then(function (resolution) {
			applyResolution(resolution);
			setStatus('Applied ' + (resolution.label || resolution.skillId) + '. No chatbot was used.', 'success');
			setTimeout(function () { loadProfile(profile.element.id, true); }, 250);
		}).catch(function (error) { setStatus(error.message, 'error'); });
	}

	function selectionPoll() {
		var id = selectedId();
		if (id !== selectedElementId) {
			selectedElementId = id;
			updateLauncher(id);
			if (panel && !panel.hidden && id) loadProfile(id, true);
		}
	}

	function boot() {
		ensureLauncher();
		ensurePanel();
		selectionPoll();
		clearInterval(pollTimer);
		pollTimer = setInterval(selectionPoll, 500);
		document.addEventListener('keydown', function (event) {
			if (event.altKey && !event.ctrlKey && !event.metaKey && String(event.key || '').toLowerCase() === 'k') {
				event.preventDefault();
				if (panel.hidden) openPanel(); else closePanel();
			}
		});
		api.ready = true;
	}

	api.open = openPanel;
	api.close = closePanel;
	api.refresh = function () { var id = selectedId(); if (id) loadProfile(id, true); };
	api.selectedId = selectedId;

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
	else boot();
}());
