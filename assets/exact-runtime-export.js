(function () {
	'use strict';

	var cfg = window.crescoLayerEditor || {};
	var originalFetch = typeof window.fetch === 'function' ? window.fetch.bind(window) : null;
	var PROFILE_KEY = 'cresco-layer-ai-context-profile';
	var DEFAULT_PROFILE = 'exact';
	var state = {
		profile: DEFAULT_PROFILE,
		installed: false,
		lastError: '',
		lastCapabilityCount: 0
	};

	var CONSTRUCTION_WIDGETS = [
		'heading', 'text-editor', 'button', 'image', 'icon', 'icon-list', 'divider', 'spacer', 'form',
		'cresco-advanced-heading', 'cresco-advanced-button', 'cresco-smart-image', 'cresco-advanced-icon', 'cresco-divider', 'cresco-spacer',
		'e-heading', 'e-paragraph', 'e-button', 'e-image'
	];
	var CONSTRUCTION_ELEMENTS = [ 'container', 'section', 'column', 'e-div-block', 'e-flexbox', 'e-grid' ];

	function restRoot() {
		return String(cfg.restRoot || '').replace(/\/$/, '');
	}

	function profileFromStorage() {
		try {
			var value = window.localStorage ? window.localStorage.getItem(PROFILE_KEY) : '';
			if (value === 'smart' || value === 'exact') return value;
		} catch (e) {}
		return DEFAULT_PROFILE;
	}

	function setProfile(value) {
		state.profile = value === 'smart' ? 'smart' : 'exact';
		try {
			if (window.localStorage) window.localStorage.setItem(PROFILE_KEY, state.profile);
		} catch (e) {}
	}

	setProfile(profileFromStorage());

	function isCrescoDocumentExport(input) {
		var url = typeof input === 'string' ? input : (input && input.url ? String(input.url) : '');
		if (!url || url.indexOf('/documents/') === -1 || url.indexOf('/export') === -1) return false;
		var root = restRoot();
		return root ? url.indexOf(root + '/documents/') === 0 : /\/cresco-layer\/v1\/documents\/\d+\/export(?:\?|$)/.test(url);
	}

	function jsonRequest(path) {
		if (!originalFetch || !restRoot()) return Promise.reject(new Error('Cresco Layer REST configuration is unavailable.'));
		return originalFetch(restRoot() + path, {
			method: 'GET',
			headers: {
				'X-WP-Nonce': cfg.nonce || '',
				'Content-Type': 'application/json'
			}
		}).then(function (response) {
			return response.json().catch(function () { return {}; }).then(function (body) {
				if (!response.ok) throw new Error(body.message || ('Runtime capability request failed (' + response.status + ').'));
				return body;
			});
		});
	}

	function unique(values) {
		var out = [];
		(values || []).forEach(function (value) {
			value = String(value || '');
			if (value && out.indexOf(value) === -1) out.push(value);
		});
		return out;
	}

	function registered(packageData, kind, name) {
		var index = packageData && packageData.registryIndex ? packageData.registryIndex : {};
		var collection = kind === 'widget' ? (index.widgets || {}) : (index.elements || {});
		return Object.prototype.hasOwnProperty.call(collection, name);
	}

	function collectTypes(value, widgets, elements) {
		if (!value || typeof value !== 'object') return;
		if (Array.isArray(value)) {
			value.forEach(function (child) { collectTypes(child, widgets, elements); });
			return;
		}
		if (typeof value.widgetType === 'string' && value.widgetType) widgets.push(value.widgetType);
		if (typeof value.elType === 'string' && value.elType && value.elType !== 'widget') elements.push(value.elType);
		Object.keys(value).forEach(function (key) {
			if (key === 'settings' || key === 'rawSettings' || key === 'effectiveWithDefaults') return;
			var child = value[key];
			if (child && typeof child === 'object') collectTypes(child, widgets, elements);
		});
	}

	function exactTypeSet(packageData) {
		var widgets = [];
		var elements = [];
		collectTypes(packageData && packageData.document ? packageData.document.content : [], widgets, elements);
		collectTypes(packageData && packageData.elementContext ? packageData.elementContext : [], widgets, elements);
		Object.keys((packageData && packageData.widgetCatalog) || {}).forEach(function (name) { widgets.push(name); });
		Object.keys((packageData && packageData.elementCatalog) || {}).forEach(function (name) { elements.push(name); });
		CONSTRUCTION_WIDGETS.forEach(function (name) { if (registered(packageData, 'widget', name)) widgets.push(name); });
		CONSTRUCTION_ELEMENTS.forEach(function (name) { if (registered(packageData, 'element', name)) elements.push(name); });
		return {
			widgets: unique(widgets).filter(function (name) { return registered(packageData, 'widget', name); }),
			elements: unique(elements).filter(function (name) { return registered(packageData, 'element', name); })
		};
	}

	function mapLimit(items, limit, worker) {
		var results = new Array(items.length);
		var cursor = 0;
		var workers = [];
		var count = Math.max(1, Math.min(limit, items.length || 1));
		function run() {
			var index = cursor ++;
			if (index >= items.length) return Promise.resolve();
			return Promise.resolvve(worker(items[index], index)).then(function (result) {
				results[index] = result;
				return run();
			});
		}
		for (var i = 0; i < count; i++) workers.push(run());
		return Promise.all(workers).then(function () { return results; });
	}

	function loadDetails(kind, names) {
		return mapLimit(names, 4, function (name) {
			return jsonRequest('/elementor-catalog/' + kind + '/' + encodeURIComponent(name)).then(function (payload) {
				if (!payload || !payload.entry || payload.entry.detailLoaded !== true) {
					throw new Error('Elementor returned incomplete exact capability metadata for ' + kind + "' + name + '".');
			}
				return { name: name, entry: payload.entry };
		});
		});
	}

	function entriesByName(rows) {
		var out = {};
		(rows || []).forEach(function (row) { if (row && row.name && row.entry) out[row.name] = row.entry; });
		return out;
	}

	function pickPrefix(source, prefixes) {
		var out = {};
		Object.keys(source || {}).forEach(function (key) {
			if (prefixes.some(function (prefix) { return key.indexOf(prefix) === 0; })) out[key] = source[key];
		});
		return out;
	}

	function siteDesignContext(packageData) {
		var kit = packageData && packageData.designSystem && typeof packageData.designSystem === 'object' ? packageData.designSystem : {};
		var layout = packageData && packageData.layoutContext && packageData.layoutContext.responsiveFoundation ? packageData.layoutContext.responsiveFoundation : {};
		return {
			schema: 'cresco-site-design-context/v1',
			source: 'active-elementor-kit',
			colors: {
				system: Array.isArray(kit.system_colors) ? kit.system_colors : [],
				custom: Array.isArray(kit.custom_colors) ? kit.custom_colors : []
			},
			typography: {
				system: Array.isArray(kit.system_typography) ? kit.system_typography : [],
				custom: Array.isArray(kit.custom_typography) ? kit.custom_typography : [],
				themeStyle: pickPrefix(kit, [ 'body_', 'link_', 'h1_', 'h2_', 'h3_', 'h4_', 'h5_', 'h6_' ])
			},
			themeStyle: {
				buttons: pickPrefix(kit, [ 'button_' ]),
				forms: pickPrefix(kit, [ 'form_' ]),
				images: pickPrefix(kit, [ 'image_' ])
			},
			layout: {
				breakpoints: packageData && packageData.siteContext ? (packageData.siteContext.breakpoints || {}) : {},
				responsiveFoundation: layout,
			containerWidth: pickPrefix(kit, [ 'container_width' ]),
				containerPadding: pickPrefix(kit, [ 'container_padding' ]),
				widgetGap: kit.space_between_widgets || null
			}
		};
	}

	function exactInstructions(existing) {
		var block = [
			'EXACT RUNTIME CAPABILITY LOCK:',
			'- runtimeCapabilities is authoritative for every setting written to an inserted or modified Elementor element.',
			'- Never invent or infer an Elementor control key. A setting key may be emitted only when it exists in the exact runtime capability entry for that widget/element type.',
			'- Respect each control type, responsive flag, allowed responsive suffixes, size_units, ranges, options, conditions, selectors and Atomic bind/prop schema exactly as exported.',
			'- Use native Elementor controls first. custom_css is allowed only when no current runtime control can express the required visual property; keep any fallback minimal and isolated.',
			'- Do not use a type that is absent from runtimeCapabilities. If a needed type/capability is missing, leave it unchanged or ask for a different export instead of guessing.',
			'- siteDesignContext and designSystem describe the live Active Kit. Reuse global design-system values and responsive foundation instead of creating near-duplicate local styles.'
		].join('\n');
		return String(existing || '').trim() + '\n\n' + block;
	}

	function enrichExactPackage(packageData) {
		if (!packageData || packageData.schema !== 'cresco-layer-ai-package/v2') {
			return Promise.reject(new Error('Exact Runtime requires a Cresco AI package v2 response.'));
		}
		var set = exactTypeSet(packageData);
		return Promise.all([
			loadDetails('widget', set.widgets),
			loadDetails('element', set.elements)
		]).then(function (groups) {
			var widgets = entriesByName(groups[0]);
			var elements = entriesByName(groups[1]);
			var total = Object.keys(widgets).length + Object.keys(elements).length;
			state.lastCapabilityCount = total;
			packageData.runtimeCapabilities = {
				schema: 'cresco-runtime-capabilities/v1',
				mode: 'exact-runtime',
				source: 'live-elementor-catalog',
				controlMetadataVersion: packageData.registryIndex ? (packageData.registryIndex.controlMetadataVersion || 0) : 0,
				constructionSet: { widgets: set.widgets, elements: set.elements },
				widgets: widgets,
				elements: elements
			};
			packageData.capabilityLock = {
				schema: 'cresco-capability-lock/v1',
				mode: 'runtime-exact',
				status: 'trusted',
				inventControls: false,
				inventResponsiveSuffixes: false,
				requireDetailedCapability: true,
				validateControlShape: true,
				validateUnitsOptionsRangesConditions: true,
				customCssPolicy: 'only-when-no-native-control-can-express-property'
			};
			packageData.siteDesignContext = siteDesignContext(packageData);
			packageData.widgetCatalog = widgets;
			packageData.elementCatalog = elements;
			packageData.relevantCapabilities = packageData.relevantCapabilities || {};
			packageData.relevantCapabilities.widgets = widgets;
			packageData.relevantCapabilities.elements = elements;
			packageData.relevantCapabilities.controlMetadataVersion = packageData.runtimeCapabilities.controlMetadataVersion;
			packageData.manifest = packageData.manifest || {};
			packageData.manifest.contextProfile = 'exact-runtime';
			packageData.contextResolver = packageData.contextResolver || {};
			packageData.contextResolver.profile = 'exact-runtime';
			packageData.capabilities = packageData.capabilities || {};
			packageData.capabilities.runtimeExactExport = true;
			packageData.capabilities.capabilityLock = 'runtime-exact';
			packageData.capabilities.customCssFallbackPolicy = 'only-when-no-native-control-can-express-property';
			packageData.instructions = exactInstructions(packageData.instructions);
			return packageData;
		});
	}

	function responseFrom(original, payload) {
		var headers = new Headers(original.headers || {});
		headers.delete('content-length');
		headers.delete('content-encoding');
		headers.set('content-type', 'application/json; charset=UTF-8');
		return new Response(JSON.stringify(payload), {
			status: original.status,
			statusText: original.statusText,
			headers: headers
		});
	}

	function errorResponse(message) {
		state.lastError = String(message || 'Exact Runtime export failed.');
		return new Response(JSON.stringify({ code: 'cresco_exact_runtime_export_failed', message: state.lastError }), {
			status: 502,
			headers: { 'content-type': 'application/json; charset=UTF-8' }
		});
	}

	function installFetchGuard() {
		if (!originalFetch || state.installed) return;
		state.installed = true;
		window.fetch = function (input, init) {
			if (state.profile !== 'exact' || !isCrescoDocumentExport(input)) return originalFetch(input, init);
			return originalFetch(input, init).then(function (response) {
				if (!response.ok) return response;
				return response.clone().json().then(enrichExactPackage).then(function (packageData) {
					return responseFrom(response, packageData);
				}).catch(function (error) {
					return errorResponse(error && error.message ? error.message : String(error));
				});
			});
		};
	}

	function profilePanel() {
		var modal = document.getElementById('cresco-layer-export-modal');
		if (!modal || modal.querySelector('[data-cresco-runtime-profile]')) return;
		var selection = modal.querySelector('#cresco-layer-selection-panel');
		var errorBox = modal.querySelector('#cresco-layer-export-error');
		var anchor = selection || errorBox;
		if (!anchor || !anchor.parentNode) return;
		var panel = document.createElement('div');
		panel.className = 'cresco-layer-selection-panel';
		panel.setAttribute('data-cresco-runtime-profile', '');
		panel.innerHTML = '<div class="cresco-layer-selection-panel__head"><strong>AI runtime context</strong><span>Exact Runtime recommended for redesigns</span></div>' +
			'<div class="cresco-layer-scope-cards">' +
			'<label class="cresco-layer-scope-card"><input type="radio" name="cresco-runtime-profile" value="exact"><span><strong>Exact Runtime</strong><small>Loads the real control keys, defaults, units, options, ranges, conditions, selectors and Atomic bindings for the current construction set. Fails closed instead of guessing.</small></span></label>' +
			'<label class="cresco-layer-scope-card"><input type="radio" name="cresco-runtime-profile" value="smart"><span><strong>Smart</strong><small>Smaller/faster package using task-relevant capability context. Best when the AI only edits existing controls.</small></span></label>' +
			'</div>';
		anchor.parentNode.insertBefore(panel, anchor);
		Array.prototype.forEach.call(panel.querySelectorAll('input[name="cresco-runtime-profile"]'), function (input) {
			input.checked = input.value === state.profile;
			input.addEventListener('change', function () { if (input.checked) setProfile(input.value); });
		});
	}

	function installUiObserver() {
		profilePanel();
		if (!window.MutationObserver || !document.documentElement) return;
		var observer = new MutationObserver(function () { profilePanel(); });
		observer.observe(document.documentElement, { childList: true, subtree: true });
	}

	window.CrescoLayerExactRuntimeExport = {
		version: '1.0.0',
		getProfile: function () { return state.profile; },
		setProfile: setProfile,
		getDiagnostics: function () {
			return {
				profile: state.profile,
				installed: state.installed,
				lastError: state.lastError,
				lastCapabilityCount: state.lastCapabilityCount
			};
		},
		constructionSet: {
			widgets: CONSTRUCTION_WIDGETS.slice(),
			elements: CONSTRUCTION_ELEMENTS.slice()
		}
	};

	installFetchGuard();
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', installUiObserver, { once: true });
	else installUiObserver();
}());
