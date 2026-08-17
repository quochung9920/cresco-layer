(function () {
	'use strict';

	var cfg = window.crescoLayerEditor || {};
	var nativeFetch = typeof window.fetch === 'function' ? window.fetch.bind(window) : null;
	var storageKey = 'cresco-layer-ai-context-profile';
	var MAX_WORKERS = 2;
	var MAX_OPTIONAL_FETCH_WIDGETS = 12;
	var MAX_OPTIONAL_FETCH_ELEMENTS = 6;
	var state = { profile: 'exact', installed: false, lastError: '', lastCapabilityCount: 0, lastDiscovery: null, lastFetchReport: null };
	var constructionWidgets = [
		'heading', 'text-editor', 'button', 'image', 'icon', 'icon-list', 'divider', 'spacer', 'form',
		'cresco-advanced-heading', 'cresco-advanced-button', 'cresco-smart-image', 'cresco-advanced-icon', 'cresco-divider', 'cresco-spacer',
		'e-heading', 'e-paragraph', 'e-button', 'e-image'
	];
	var constructionElements = [ 'container', 'section', 'column', 'e-div-block', 'e-flexbox', 'e-grid' ];

	var TASK_HINTS = {
		faq: ['accordion', 'toggle', 'nested-accordion', 'tabs'],
		accordion: ['accordion', 'nested-accordion', 'toggle'],
		tabs: ['tabs', 'nested-tabs'],
		carousel: ['carousel', 'slides', 'media-carousel', 'loop-carousel'],
		slider: ['slides', 'carousel', 'media-carousel', 'loop-carousel'],
		testimonial: ['testimonial', 'testimonial-carousel', 'carousel', 'slides'],
		menu: ['nav-menu', 'menu', 'wordpress-menu'],
		navigation: ['nav-menu', 'menu', 'wordpress-menu', 'breadcrumbs'],
		breadcrumb: ['breadcrumbs', 'breadcrumb'],
		posts: ['posts', 'loop-grid', 'portfolio'],
		blog: ['posts', 'loop-grid', 'portfolio'],
		grid: ['loop-grid', 'posts', 'portfolio'],
		products: ['woocommerce-products', 'products', 'loop-grid'],
		product: ['woocommerce-products', 'products', 'product-title', 'product-images', 'price'],
		checkout: ['woocommerce-checkout', 'checkout'],
		cart: ['woocommerce-cart', 'cart'],
		form: ['form', 'login', 'search-form'],
		search: ['search-form', 'search'],
		video: ['video'],
		gallery: ['gallery', 'media-carousel'],
		counter: ['counter'],
		progress: ['progress'],
		social: ['social-icons'],
		share: ['share-buttons', 'social-icons'],
		map: ['google_maps', 'map'],
		html: ['html'],
		shortcode: ['shortcode']
	};

	function root() { return String(cfg.restRoot || '').replace(/\/$/, ''); }
	function unique(list) { return Array.from(new Set((list || []).filter(Boolean).map(String))); }
	function words(value) { return String(value || '').toLowerCase(); }
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
		return !!url && url.indexOf(root() + '/documents/') === 0 && /\/export(?:\?|$)/.test(url) && url.indexOf('/export-target-status') === -1;
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
	function taskText() {
		var intent = window.CrescoLayerAIIntent || {};
		return words(intent.request || '');
	}
	function taskMatchesEntry(name, entry, request) {
		var haystack = [name, entry && entry.title, (entry && entry.categories || []).join(' '), (entry && entry.keywords || []).join(' ')].join(' ').toLowerCase();
		if (!request || !haystack) return false;
		var tokens = request.split(/[^a-z0-9_-]+/).filter(function (token) { return token.length >= 3; });
		for (var i = 0; i < tokens.length; i++) {
			if (haystack.indexOf(tokens[i]) !== -1) return true;
		}
		return false;
	}
	function taskAwareWidgets(pkg) {
		var request = taskText();
		if (!request) return [];
		var index = pkg && pkg.registryIndex && pkg.registryIndex.widgets ? pkg.registryIndex.widgets : {};
		var names = [];
		Object.keys(TASK_HINTS).forEach(function (hint) {
			if (request.indexOf(hint) === -1) return;
			TASK_HINTS[hint].forEach(function (name) { if (registered(pkg, 'widget', name)) names.push(name); });
		});
		Object.keys(index).forEach(function (name) {
			if (taskMatchesEntry(name, index[name], request)) names.push(name);
		});
		return unique(names).slice(0, 24);
	}
	function typeSet(pkg) {
		var requiredWidgets = [], requiredElements = [];
		collectTypes(pkg.document ? pkg.document.content : [], requiredWidgets, requiredElements);
		collectTypes(pkg.elementContext || [], requiredWidgets, requiredElements);
		requiredWidgets = unique(requiredWidgets).filter(function (name) { return registered(pkg, 'widget', name); });
		requiredElements = unique(requiredElements).filter(function (name) { return registered(pkg, 'element', name); });

		var optionalWidgets = Object.keys(pkg.widgetCatalog || {});
		var optionalElements = Object.keys(pkg.elementCatalog || {});
		constructionWidgets.forEach(function (name) { if (registered(pkg, 'widget', name)) optionalWidgets.push(name); });
		constructionElements.forEach(function (name) { if (registered(pkg, 'element', name)) optionalElements.push(name); });
		var discovered = taskAwareWidgets(pkg);
		optionalWidgets.push.apply(optionalWidgets, discovered);
		optionalWidgets = unique(optionalWidgets).filter(function (name) { return registered(pkg, 'widget', name) && requiredWidgets.indexOf(name) === -1; });
		optionalElements = unique(optionalElements).filter(function (name) { return registered(pkg, 'element', name) && requiredElements.indexOf(name) === -1; });
		state.lastDiscovery = { request: taskText(), widgets: discovered.slice() };
		return {
			requiredWidgets: requiredWidgets,
			requiredElements: requiredElements,
			optionalWidgets: optionalWidgets,
			optionalElements: optionalElements,
			taskDiscoveredWidgets: discovered
		};
	}
	function existingDetails(kind, names, pkg) {
		var source = kind === 'widget' ? (pkg.widgetCatalog || {}) : (pkg.elementCatalog || {});
		var entries = {}, reused = [];
		names.forEach(function (name) {
			var entry = source[name];
			if (entry && entry.detailLoaded === true) { entries[name] = entry; reused.push(name); }
		});
		return { entries: entries, reused: reused };
	}
	async function loadDetails(kind, names, strict) {
		var queue = names.slice(), out = {}, failed = [];
		async function worker() {
			while (queue.length) {
				var name = queue.shift();
				try {
					var payload = await getJson('/elementor-catalog/' + kind + '/' + encodeURIComponent(name));
					if (!payload.entry || payload.entry.detailLoaded !== true) throw new Error('Incomplete Exact Runtime capability for ' + kind + ' "' + name + '".');
					out[name] = payload.entry;
				} catch (error) {
					if (strict) throw error;
					failed.push({ name: name, message: error && error.message ? error.message : String(error) });
				}
			}
		}
		if (names.length) await Promise.all(Array.from({ length: Math.min(MAX_WORKERS, names.length) }, worker));
		return { entries: out, failed: failed };
	}
	function merge(target, source) { Object.keys(source || {}).forEach(function (key) { target[key] = source[key]; }); return target; }
	async function resolveDetails(kind, requiredNames, optionalNames, pkg) {
		var allNames = unique(requiredNames.concat(optionalNames));
		var seeded = existingDetails(kind, allNames, pkg);
		var entries = seeded.entries;
		var missingRequired = requiredNames.filter(function (name) { return !entries[name]; });
		var requiredFetched = await loadDetails(kind, missingRequired, true);
		merge(entries, requiredFetched.entries);
		var stillMissingRequired = requiredNames.filter(function (name) { return !entries[name]; });
		if (stillMissingRequired.length) throw new Error('Required Exact Runtime capabilities are missing for ' + kind + ': ' + stillMissingRequired.join(', ') + '.');

		var optionalMissing = optionalNames.filter(function (name) { return !entries[name]; });
		var maxOptional = kind === 'widget' ? MAX_OPTIONAL_FETCH_WIDGETS : MAX_OPTIONAL_FETCH_ELEMENTS;
		var optionalFetchNames = optionalMissing.slice(0, maxOptional);
		var omitted = optionalMissing.slice(maxOptional);
		var optionalFetched = await loadDetails(kind, optionalFetchNames, false);
		merge(entries, optionalFetched.entries);

		return {
			entries: entries,
			report: {
				required: requiredNames.slice(),
				optionalRequested: optionalNames.slice(),
				reused: seeded.reused,
				fetchedRequired: Object.keys(requiredFetched.entries),
				fetchedOptional: Object.keys(optionalFetched.entries),
				failedOptional: optionalFetched.failed,
				omittedOptional: omitted,
				available: Object.keys(entries)
			}
		};
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
			'- Do not use element/widget types absent from runtimeCapabilities. Optional construction capabilities can be omitted when runtime detail loading fails or exceeds the bounded fetch budget.',
			'- All element/widget types already present in the editable target or read-only context are required capabilities and are never allowed to degrade silently.',
			'- Reuse siteDesignContext/designSystem and the responsive foundation instead of creating near-duplicate local styles.',
			'- taskRuntimeDiscovery contains additional runtime-proven widgets loaded because their registry metadata matched the current task.'
		].join('\n');
	}
	async function enrich(pkg) {
		if (!pkg || pkg.schema !== 'cresco-layer-ai-package/v2') throw new Error('Exact Runtime requires cresco-layer-ai-package/v2.');
		var set = typeSet(pkg);
		var groups = await Promise.all([
			resolveDetails('widget', set.requiredWidgets, set.optionalWidgets, pkg),
			resolveDetails('element', set.requiredElements, set.optionalElements, pkg)
		]);
		var widgets = groups[0].entries, elements = groups[1].entries;
		var fetchReport = { widgets: groups[0].report, elements: groups[1].report };
		state.lastFetchReport = fetchReport;
		state.lastCapabilityCount = Object.keys(widgets).length + Object.keys(elements).length;
		var optionalPartial = fetchReport.widgets.failedOptional.length || fetchReport.widgets.omittedOptional.length || fetchReport.elements.failedOptional.length || fetchReport.elements.omittedOptional.length;
		pkg.runtimeCapabilities = {
			schema: 'cresco-runtime-capabilities/v1', mode: 'exact-runtime', source: 'server-reuse-plus-live-elementor-catalog',
			controlMetadataVersion: pkg.registryIndex ? (pkg.registryIndex.controlMetadataVersion || 0) : 0,
			constructionSet: { widgets: Object.keys(widgets), elements: Object.keys(elements) },
			requiredSet: { widgets: set.requiredWidgets, elements: set.requiredElements },
			coverage: { requiredComplete: true, optionalPartial: !!optionalPartial, fetch: fetchReport },
			widgets: widgets, elements: elements
		};
		pkg.taskRuntimeDiscovery = {
			schema: 'cresco-task-runtime-discovery/v1',
			request: taskText(),
			discoveredWidgets: set.taskDiscoveredWidgets,
			rule: 'Every discovered widget came from the active Elementor registry and was selected by task hints or registry title/category/keyword matching.'
		};
		pkg.capabilityLock = {
			schema: 'cresco-capability-lock/v1', mode: 'runtime-exact', status: 'trusted', inventControls: false,
			inventResponsiveSuffixes: false, requireDetailedCapability: true, validateControlShape: true,
			validateUnitsOptionsRangesConditions: true, optionalConstructionPartial: !!optionalPartial,
			customCssPolicy: 'only-when-no-native-control-can-express-property'
		};
		pkg.siteDesignContext = designContext(pkg);
		pkg.widgetCatalog = widgets; pkg.elementCatalog = elements;
		pkg.relevantCapabilities = pkg.relevantCapabilities || {};
		pkg.relevantCapabilities.widgets = widgets; pkg.relevantCapabilities.elements = elements;
		pkg.relevantCapabilities.controlMetadataVersion = pkg.runtimeCapabilities.controlMetadataVersion;
		pkg.manifest = pkg.manifest || {}; pkg.manifest.contextProfile = 'exact-runtime';
		pkg.contextResolver = pkg.contextResolver || {}; pkg.contextResolver.profile = 'exact-runtime'; pkg.contextResolver.exactRuntimeFetch = fetchReport;
		pkg.capabilities = pkg.capabilities || {}; pkg.capabilities.runtimeExactExport = true;
		pkg.capabilities.capabilityLock = 'runtime-exact'; pkg.capabilities.customCssFallbackPolicy = pkg.capabilityLock.customCssPolicy;
		pkg.instructions = strictInstructions(pkg.instructions);
		return pkg;
	}
	function jsonResponse(original, payload) {
		var headers = new Headers(original.headers || {}); headers.delete('content-length'); headers.delete('content-encoding'); headers.set('content-type', 'application/json; charset=UTF-8');
		return new Response(JSON.stringify(payload), { status: original.status, statusText: original.statusText, headers: headers });
	}
	function failed(message, originalResponse) {
		state.lastError = String(message || 'Exact Runtime export failed.');
		var errorId = '';
		try { errorId = originalResponse && originalResponse.headers ? String(originalResponse.headers.get('x-cresco-request-id') || '') : ''; } catch (e) {}
		if (!errorId) errorId = 'CX-exact-runtime-' + Date.now().toString(36);
		var diagnostic = {
			schema: 'cresco-export-client-diagnostic/v1', errorId: errorId, stage: 'exact-runtime-enrich', status: 502,
			message: state.lastError, exactRuntime: state.lastFetchReport
		};
		try {
			if (window.CrescoLayerExportDiagnostics && typeof window.CrescoLayerExportDiagnostics.recordClientError === 'function') {
				window.CrescoLayerExportDiagnostics.recordClientError(diagnostic);
			}
		} catch (e2) {}
		return new Response(JSON.stringify({
			code: 'cresco_exact_runtime_export_failed', message: state.lastError + ' [exact-runtime-enrich | ' + errorId + ']',
			data: { status: 502, crescoDiagnostic: diagnostic }
		}), { status: 502, headers: { 'content-type': 'application/json; charset=UTF-8', 'x-cresco-request-id': errorId, 'x-cresco-diagnostic-stage': 'exact-runtime-enrich' } });
	}
	if (nativeFetch) {
		state.installed = true;
		window.fetch = function (input, init) {
			if (state.profile !== 'exact' || !isExport(input)) return nativeFetch(input, init);
			return nativeFetch(input, init).then(function (response) {
				if (!response.ok) return response;
				return response.clone().json().then(enrich).then(function (pkg) { return jsonResponse(response, pkg); }).catch(function (error) { return failed(error && error.message ? error.message : String(error), response); });
			});
		};
	}
	function addProfilePanel() {
		var modal = document.getElementById('cresco-layer-export-modal');
		if (!modal || modal.querySelector('[data-cresco-runtime-profile]')) return;
		var anchor = modal.querySelector('#cresco-layer-selection-panel') || modal.querySelector('#cresco-layer-export-error');
		if (!anchor || !anchor.parentNode) return;
		var panel = document.createElement('div'); panel.className = 'cresco-layer-selection-panel'; panel.setAttribute('data-cresco-runtime-profile', '');
		panel.innerHTML = '<div class="cresco-layer-selection-panel__head"><strong>AI runtime context</strong><span>Exact Runtime recommended for redesigns</span></div><div class="cresco-layer-scope-cards"><label class="cresco-layer-scope-card"><input type="radio" name="cresco-runtime-profile" value="exact"><span><strong>Exact Runtime</strong><small>Real runtime control keys, defaults, units, options, ranges, conditions, selectors, Atomic metadata and task-aware widget discovery. Required target capabilities fail closed; optional construction capabilities are bounded and reusable.</small></span></label><label class="cresco-layer-scope-card"><input type="radio" name="cresco-runtime-profile" value="smart"><span><strong>Smart</strong><small>Smaller package for edits that do not need broad construction capability.</small></span></label></div>';
		anchor.parentNode.insertBefore(panel, anchor);
		Array.prototype.forEach.call(panel.querySelectorAll('input[name="cresco-runtime-profile"]'), function (input) { input.checked = input.value === state.profile; input.addEventListener('change', function () { if (input.checked) setProfile(input.value); }); });
	}
	function observe() {
		addProfilePanel();
		if (!window.MutationObserver || !document.documentElement) return;
		new MutationObserver(addProfilePanel).observe(document.documentElement, { childList: true, subtree: true });
	}
	window.CrescoLayerExactRuntimeExport = {
		version: '1.2.0', getProfile: function () { return state.profile; }, setProfile: setProfile,
		getDiagnostics: function () { return { profile: state.profile, installed: state.installed, lastError: state.lastError, lastCapabilityCount: state.lastCapabilityCount, lastDiscovery: state.lastDiscovery, lastFetchReport: state.lastFetchReport }; },
		constructionSet: { widgets: constructionWidgets.slice(), elements: constructionElements.slice() },
		discoverTaskWidgets: function (pkg) { return taskAwareWidgets(pkg || {}); }
	};
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', observe, { once: true }); else observe();
}());
