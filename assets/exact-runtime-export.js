(function () {
	'use strict';

	var cfg = window.crescoLayerEditor || {};
	var nativeFetch = typeof window.fetch === 'function' ? window.fetch.bind(window) : null;
	var storageKey = 'cresco-layer-ai-context-profile';
	var state = { profile: 'exact', installed: false, lastError: '', lastCapabilityCount: 0 };
	var constructionWidgets = [
		'heading', 'text-editor', 'button', 'image', 'icon', 'icon-list', 'divider', 'spacer', 'form',
		'cresco-advanced-heading', 'cresco-advanced-button', 'cresco-smart-image', 'cresco-advanced-icon', 'cresco-divider', 'cresco-spacer',
		'e-heading', 'e-paragraph', 'e-button', 'e-image'
	];
	var constructionElements = [ 'container', 'section', 'column', 'e-div-block', 'e-flexbox', 'e-grid' ];

	function root() { return String(cfg.restRoot || '').replace(/\/$/, ''); }
	function unique(list) { return Array.from(new Set((list || []).filter(Boolean).map(String))); }
	function registered(pkg, kind, name) {
		var index = pkg && pkg.registryIndex ? pkg.registryIndex : {};
		var items = kind === 'widget' ? (index.widgets || {}) : (index.elements || {});
		return Object.prototype.hasOwnProperty.call(items, name);
	}
	function setProfile(value) {
		state.profile = value === 'smart' ? 'smart' : 'exact';
		try { if (window.localStorage) window.localStorage.setItem(storageKey, state.profile); } catch (e) {}
	}
	try {
		var saved = window.localStorage ? window.localStorage.getItem(storageKey) : '';
		if (saved === 'smart' || saved === 'exact') state.profile = saved;
	} catch (e) {}

	function isExport(input) {
		var url = typeof input === 'string' ? input : (input && input.url ? String(input.url) : '');
		return !!url && url.indexOf(root() + '/documents/') === 0 && url.indexOf('/export') !== -1;
	}
	async function getJson(path) {
		if (!nativeFetch || !root()) throw new Error('Cresco Layer REST configuration is unavailable.');
		var response = await nativeFetch(root() + path, { method: 'GET', headers: { 'X-WP-Nonce': cfg.nonce || '', 'Content-Type': 'application/json' } });
		var body = await response.json().catch(function () { return {}; });
		if (!response.ok) throw new Error(body.message || ('Runtime capability request failed (' + response.status + ').'));
		return body;
	}
	function collectTypes(value, widgets, elements) {
		if (!value || typeof value !== 'object') return;
		if (Array.isArray(value)) { value.forEach(function (item) { collectTypes(item, widgets, elements); }); return; }
		if (typeof value.widgetType === 'string' && value.widgetType) widgets.push(value.widgetType);
		if (typeof value.elType === 'string' && value.elType && value.elType !== 'widget') elements.push(value.elType);
		Object.keys(value).forEach(function (key) {
			if ([ 'settings', 'rawSettings', 'effectiveWithDefaults' ].indexOf(key) !== -1) return;
			if (value[key] && typeof value[key] === 'object') collectTypes(value[key], widgets, elements);
		});
	}
	function typeSet(pkg) {
		var widgets = [], elements = [];
		collectTypes(pkg.document ? pkg.document.content : [], widgets, elements);
		collectTypes(pkg.elementContext || [], widgets, elements);
		widgets.push.apply(widgets, Object.keys(pkg.widgetCatalog || {}));
		elements.push.apply(elements, Object.keys(pkg.elementCatalog || {}));
		constructionWidgets.forEach(function (name) { if (registered(pkg, 'widget', name)) widgets.push(name); });
		constructionElements.forEach(function (name) { if (registered(pkg, 'element', name)) elements.push(name); });
		return {
			widgets: unique(widgets).filter(function (name) { return registered(pkg, 'widget', name); }),
			elements: unique(elements).filter(function (name) { return registered(pkg, 'element', name); })
		};
	}
	async function loadDetails(kind, names) {
		var queue = names.slice(), out = {};
		async function worker() {
			while (queue.length) {
				var name = queue.shift();
				var payload = await getJson('/elementor-catalog/' + kind + '/' + encodeURIComponent(name));
				if (!payload.entry || payload.entry.detailLoaded !== true) throw new Error('Incomplete Exact Runtime capability for ' + kind + ' "' + name + '".');
				out[name] = payload.entry;
			}
		}
		await Promise.all(Array.from({ length: Math.min(4, Math.max(1, names.length)) }, worker));
		return out;
	}
	function prefixed(source, prefixes) {
		var out = {};
		Object.keys(source || {}).forEach(function (key) { if (prefixes.some(function (p) { return key.indexOf(p) === 0; })) out[key] = source[key]; });
		return out;
	}
	function designContext(pkg) {
		var kit = pkg.designSystem && typeof pkg.designSystem === 'object' ? pkg.designSystem : {};
		return {
			schema: 'cresco-site-design-context/v1', source: 'active-elementor-kit',
			colors: { system: kit.system_colors || [], custom: kit.custom_colors || [] },
			typography: { system: kit.system_typography || [], custom: kit.custom_typography || [], themeStyle: prefixed(kit, [ 'body_', 'link_', 'h1_', 'h2_', 'h3_', 'h4_', 'h5_', 'h6_' ]) },
			themeStyle: { buttons: prefixed(kit, [ 'button_' ]), forms: prefixed(kit, [ 'form_' ]), images: prefixed(kit, [ 'image_' ]) },
			layout: {
				breakpoints: pkg.siteContext ? (pkg.siteContext.breakpoints || {}) : {},
				responsiveFoundation: pkg.layoutContext ? (pkg.layoutContext.responsiveFoundation || {}) : {},
				containerWidth: prefixed(kit, [ 'container_width' ]), containerPadding: prefixed(kit, [ 'container_padding' ]), widgetGap: kit.space_between_widgets || null
			}
		};
	}
	function strictInstructions(existing) {
		return String(existing || '').trim() + '\n\n' + [
			'EXACT RUNTIME CAPABILITY LOCK:',
			'- runtimeCapabilities is authoritative for every inserted or modified Elementor element.',
			'- Never invent or infer an Elementor control key. Emit a setting only when that exact key exists for that runtime widget/element type.',
			'- Respect responsive flags/suffixes, control types, units, ranges, options, conditions, selectors and Atomic bindings exactly as exported.',
			'- Use native Elementor controls first. custom_css is allowed only when no runtime control can express the required visual property.',
			'- Do not use element/widget types absent from runtimeCapabilities. Do not guess missing capabilities.',
			'- Reuse siteDesignContext/designSystem and the responsive foundation instead of creating near-duplicate local styles.'
		].join('\n');
	}
	async function enrich(pkg) {
		if (!pkg || pkg.schema !== 'cresco-layer-ai-package/v2') throw new Error('Exact Runtime requires cresco-layer-ai-package/v2.');
		var set = typeSet(pkg);
		var groups = await Promise.all([ loadDetails('widget', set.widgets), loadDetails('element', set.elements) ]);
		var widgets = groups[0], elements = groups[1];
		state.lastCapabilityCount = Object.keys(widgets).length + Object.keys(elements).length;
		pkg.runtimeCapabilities = {
			schema: 'cresco-runtime-capabilities/v1', mode: 'exact-runtime', source: 'live-elementor-catalog',
			controlMetadataVersion: pkg.registryIndex ? (pkg.registryIndex.controlMetadataVersion || 0) : 0,
			constructionSet: set, widgets: widgets, elements: elements
		};
		pkg.capabilityLock = {
			schema: 'cresco-capability-lock/v1', mode: 'runtime-exact', status: 'trusted', inventControls: false,
			inventResponsiveSuffixes: false, requireDetailedCapability: true, validateControlShape: true,
			validateUnitsOptionsRangesConditions: true, customCssPolicy: 'only-when-no-native-control-can-express-property'
		};
		pkg.siteDesignContext = designContext(pkg);
		pkg.widgetCatalog = widgets; pkg.elementCatalog = elements;
		pkg.relevantCapabilities = pkg.relevantCapabilities || {};
		pkg.relevantCapabilities.widgets = widgets; pkg.relevantCapabilities.elements = elements;
		pkg.relevantCapabilities.controlMetadataVersion = pkg.runtimeCapabilities.controlMetadataVersion;
		pkg.manifest = pkg.manifest || {}; pkg.manifest.contextProfile = 'exact-runtime';
		pkg.contextResolver = pkg.contextResolver || {}; pkg.contextResolver.profile = 'exact-runtime';
		pkg.capabilities = pkg.capabilities || {}; pkg.capabilities.runtimeExactExport = true;
		pkg.capabilities.capabilityLock = 'runtime-exact'; pkg.capabilities.customCssFallbackPolicy = pkg.capabilityLock.customCssPolicy;
		pkg.instructions = strictInstructions(pkg.instructions);
		return pkg;
	}
	function jsonResponse(original, payload) {
		var headers = new Headers(original.headers || {}); headers.delete('content-length'); headers.delete('content-encoding'); headers.set('content-type', 'application/json; charset=UTF-8');
		return new Response(JSON.stringify(payload), { status: original.status, statusText: original.statusText, headers: headers });
	}
	function failed(message) {
		state.lastError = String(message || 'Exact Runtime export failed.');
		return new Response(JSON.stringify({ code: 'cresco_exact_runtime_export_failed', message: state.lastError }), { status: 502, headers: { 'content-type': 'application/json; charset=UTF-8' } });
	}
	if (nativeFetch) {
		state.installed = true;
		window.fetch = function (input, init) {
			if (state.profile !== 'exact' || !isExport(input)) return nativeFetch(input, init);
			return nativeFetch(input, init).then(function (response) {
				if (!response.ok) return response;
				return response.clone().json().then(enrich).then(function (pkg) { return jsonResponse(response, pkg); }).catch(function (error) { return failed(error && error.message ? error.message : String(error)); });
			});
		};
	}
	function addProfilePanel() {
		var modal = document.getElementById('cresco-layer-export-modal');
		if (!modal || modal.querySelector('[data-cresco-runtime-profile]')) return;
		var anchor = modal.querySelector('#cresco-layer-selection-panel') || modal.querySelector('#cresco-layer-export-error');
		if (!anchor || !anchor.parentNode) return;
		var panel = document.createElement('div'); panel.className = 'cresco-layer-selection-panel'; panel.setAttribute('data-cresco-runtime-profile', '');
		panel.innerHTML = '<div class="cresco-layer-selection-panel__head"><strong>AI runtime context</strong><span>Exact Runtime recommended for redesigns</span></div><div class="cresco-layer-scope-cards"><label class="cresco-layer-scope-card"><input type="radio" name="cresco-runtime-profile" value="exact"><span><strong>Exact Runtime</strong><small>Real runtime control keys, defaults, units, options, ranges, conditions, selectors and Atomic metadata. Fails closed instead of guessing.</small></span></label><label class="cresco-layer-scope-card"><input type="radio" name="cresco-runtime-profile" value="smart"><span><strong>Smart</strong><small>Smaller package for edits that do not need broad construction capability.</small></span></label></div>';
		anchor.parentNode.insertBefore(panel, anchor);
		Array.prototype.forEach.call(panel.querySelectorAll('input[name="cresco-runtime-profile"]'), function (input) { input.checked = input.value === state.profile; input.addEventListener('change', function () { if (input.checked) setProfile(input.value); }); });
	}
	function observe() {
		addProfilePanel();
		if (!window.MutationObserver || !document.documentElement) return;
		new MutationObserver(addProfilePanel).observe(document.documentElement, { childList: true, subtree: true });
	}
	window.CrescoLayerExactRuntimeExport = {
		version: '1.0.0', getProfile: function () { return state.profile; }, setProfile: setProfile,
		getDiagnostics: function () { return { profile: state.profile, installed: state.installed, lastError: state.lastError, lastCapabilityCount: state.lastCapabilityCount }; },
		constructionSet: { widgets: constructionWidgets.slice(), elements: constructionElements.slice() }
	};
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', observe, { once: true }); else observe();
}());
