(function () {
	'use strict';

	var cfg = window.crescoLayerEditor || {};
	var cache = {};
	var loading = {};
	var lastId = '';

	function validId(value) { return /^[A-Za-z0-9_-]{1,64}$/.test(String(value || '')); }
	function selectedId() {
		try {
			if (window.elementor && elementor.channels && elementor.channels.editor) {
				var selected = elementor.channels.editor.request('selectedElement');
				var model = selected && (selected.model || selected);
				var id = model && (model.id || (model.attributes && model.attributes.id) || (typeof model.get === 'function' && model.get('id')));
				if (validId(id)) return String(id);
			}
		} catch (e) {}
		var node = document.querySelector('.elementor-element.elementor-selected[data-id],[data-id][aria-selected="true"]');
		return node && validId(node.getAttribute('data-id')) ? node.getAttribute('data-id') : '';
	}
	function postId() {
		var id = parseInt(cfg.postId || 0, 10);
		if (id) return id;
		try { return parseInt(elementor.config.document.id || 0, 10) || 0; } catch (e) { return 0; }
	}
	function endpoint(path) { return String(cfg.restRoot || '').replace(/\/$/, '') + path; }
	function fetchProfile(id) {
		if (cache[id]) return Promise.resolve(cache[id]);
		if (loading[id]) return loading[id];
		var pid = postId();
		if (!pid || !validId(id)) return Promise.resolve(null);
		loading[id] = fetch(endpoint('/documents/' + pid + '/skills/' + encodeURIComponent(id)), { headers: { 'X-WP-Nonce': cfg.nonce } }).then(function (response) {
			if (!response.ok) return null;
			return response.json();
		}).then(function (profile) {
			delete loading[id];
			if (profile && profile.element && profile.element.id === id) cache[id] = profile;
			return profile;
		}).catch(function () { delete loading[id]; return null; });
		return loading[id];
	}
	function decorate(profile) {
		var panel = document.getElementById('cresco-layer-skills-panel');
		if (!panel || !profile || !Array.isArray(profile.skills)) return;
		var map = {};
		profile.skills.forEach(function (skill) { if (skill && skill.id) map[skill.id] = skill; });
		Array.prototype.forEach.call(panel.querySelectorAll('[data-cresco-skill-id]'), function (button) {
			var skill = map[button.getAttribute('data-cresco-skill-id') || ''];
			if (!skill) return;
			var strong = button.querySelector('strong');
			var small = button.querySelector('small');
			if (strong && skill.displayLabel) strong.textContent = skill.displayLabel;
			if (small) small.textContent = skill.semanticBase || skill.semanticId || skill.role || skill.setting || '';
			if (skill.semanticId) button.setAttribute('data-cresco-semantic-id', skill.semanticId);
			if (skill.purpose) button.title = skill.purpose;
		});
	}
	function refresh() {
		var panel = document.getElementById('cresco-layer-skills-panel');
		if (!panel || panel.hidden) return;
		var id = selectedId();
		if (!validId(id)) return;
		if (id !== lastId) lastId = id;
		fetchProfile(id).then(function (profile) { if (selectedId() === id) decorate(profile); });
	}
	function boot() {
		var observer = new MutationObserver(function () { refresh(); });
		observer.observe(document.documentElement, { childList: true, subtree: true });
		document.addEventListener('click', function (event) {
			if (event.target && (event.target.closest('#cresco-layer-skills-launcher') || event.target.closest('#cresco-layer-skills-panel'))) setTimeout(refresh, 40);
		}, true);
		setInterval(refresh, 900);
		refresh();
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true }); else boot();
}());
