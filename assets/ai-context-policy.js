(function () {
	'use strict';

	var cfg = window.crescoLayerEditor || {};
	var upstreamFetch = typeof window.fetch === 'function' ? window.fetch.bind(window) : null;

	function root() { return String(cfg.restRoot || '').replace(/\/$/, ''); }
	function isExport(input) {
		var url = typeof input === 'string' ? input : (input && input.url ? String(input.url) : '');
		return !!url && url.indexOf(root() + '/documents/') === 0 && url.indexOf('/export') !== -1;
	}
	function unique(values) {
		return Array.from(new Set((values || []).filter(Boolean).map(function (value) { return String(value).toLowerCase(); })));
	}
	function responsiveSuffixes(pkg) {
		var source = pkg && pkg.designSystem && pkg.designSystem.layout ? pkg.designSystem.layout.breakpoints : null;
		var names = [];
		if (Array.isArray(source)) {
			source.forEach(function (item) {
				if (typeof item === 'string') names.push(item);
				else if (item && typeof item === 'object') names.push(item.id || item.name || item.key || '');
			});
		} else if (source && typeof source === 'object') {
			names = Object.keys(source);
		}
		return unique(names).filter(function (name) { return name && name !== 'desktop' && /^[a-z0-9_]+$/.test(name); });
	}
	function addEmittableKeys(pkg) {
		var suffixes = responsiveSuffixes(pkg);
		var runtime = pkg.runtime || {};
		['widgets', 'elements'].forEach(function (groupName) {
			var group = runtime[groupName] || {};
			Object.keys(group).forEach(function (typeName) {
				var controls = group[typeName] && group[typeName].controls ? group[typeName].controls : {};
				Object.keys(controls).forEach(function (key) {
					var control = controls[key];
					if (!control || typeof control !== 'object') return;
					var keys = [key];
					if (control.responsive === true || control.responsive === 'yes' || control.responsive === 1) {
						suffixes.forEach(function (suffix) { keys.push(key + '_' + suffix); });
					}
					control.emittableKeys = keys;
				});
			});
		});
		runtime.activeResponsiveSuffixes = suffixes;
		pkg.runtime = runtime;
		return pkg;
	}
	function firstControl(controls, candidates) {
		for (var i = 0; i < candidates.length; i++) {
			if (controls[candidates[i]] && typeof controls[candidates[i]] === 'object') return candidates[i];
		}
		return '';
	}
	function semanticBindings(pkg) {
		var out = {
			schema: 'cresco-semantic-bindings/v1',
			source: 'active-runtime-controls',
			widgets: {},
			rules: [
				'Bindings are emitted only when the exact control key exists on the active runtime widget.',
				'Use content shortcuts for semantic content; explicit settings remain available and are still validated by SemanticPatchGuard.',
				'Do not infer a missing binding from another widget type.'
			]
		};
		var runtime = pkg.runtime || {};
		var intelligence = pkg.widgetIntelligence && pkg.widgetIntelligence.widgets ? pkg.widgetIntelligence.widgets : {};
		Object.keys(runtime.widgets || {}).forEach(function (name) {
			var entry = runtime.widgets[name] || {};
			var controls = entry.controls || {};
			var family = intelligence[name] && intelligence[name].family ? intelligence[name].family : '';
			var binding = { family: family, content: {} };
			var key = '';
			if (family === 'heading' || /heading|headline|title/.test(name)) {
				key = firstControl(controls, ['title', 'text', 'heading', 'headline']); if (key) binding.content.text = key;
				key = firstControl(controls, ['header_size', 'html_tag', 'tag']); if (key) binding.content.semanticLevel = key;
			} else if (family === 'text' || /text-editor|paragraph|rich-text/.test(name)) {
				key = firstControl(controls, ['editor', 'text', 'content', 'description']); if (key) { binding.content.text = key; binding.content.html = key; }
			} else if (family === 'button' || /button|cta/.test(name)) {
				key = firstControl(controls, ['text', 'button_text', 'label', 'title']); if (key) binding.content.text = key;
				key = firstControl(controls, ['link', 'url', 'button_link']); if (key) binding.content.url = key;
			} else if (family === 'image' || /image|media/.test(name)) {
				key = firstControl(controls, ['image', 'media', 'source']); if (key) binding.content.image = key;
			} else if (family === 'icon' || /icon/.test(name)) {
				key = firstControl(controls, ['selected_icon', 'icon']); if (key) binding.content.icon = key;
			}
			if (Object.keys(binding.content).length) out.widgets[name] = binding;
		});
		return out;
	}
	function structureGrammar(pkg) {
		var runtime = pkg.runtime || {};
		var out = {
			schema: 'cresco-structure-grammar/v1',
			source: 'active-runtime-element-kind+safe-containment-policy',
			elements: {}, widgets: {},
			rules: [
				'Structural Elementor element types may own child elements when the current scope permits it.',
				'Widgets do not accept arbitrary Elementor child nodes through semantic mutation. Nested/disclosure widgets must use their native runtime controls/repeaters unless a dedicated adapter proves a child grammar.',
				'Never infer a nested child schema from rendered DOM.'
			]
		};
		Object.keys(runtime.elements || {}).forEach(function (name) {
			var entry = runtime.elements[name] || {};
			out.elements[name] = { canAcceptChildren: true, childPolicy: 'elementor-structural-children', isAtomic: !!entry.isAtomic };
		});
		Object.keys(runtime.widgets || {}).forEach(function (name) {
			var entry = runtime.widgets[name] || {};
			var managedNested = /nested|accordion|tabs|carousel|slides|menu|loop/i.test(name);
			out.widgets[name] = {
				canAcceptChildren: false,
				childPolicy: managedNested ? 'runtime-managed-nested-content' : 'widget-native-content-only',
				runtimeManagedNestedContent: managedNested,
				isAtomic: !!entry.isAtomic
			};
		});
		return out;
	}
	function rebuildSkeleton(pkg, target) {
		var current = pkg && pkg.currentInterface ? pkg.currentInterface.element : null;
		var element = { id: target.id || '', elType: 'container', settings: {}, elements: [] };
		if (!current || typeof current !== 'object') return element;
		if (current.elType) element.elType = current.elType;
		if (current.widgetType) element.widgetType = current.widgetType;
		if (Object.prototype.hasOwnProperty.call(current, 'isInner')) element.isInner = !!current.isInner;
		return element;
	}
	function patchContract(pkg) {
		if (!pkg || pkg.schema !== 'cresco-ai-context/v3') return pkg;
		var target = pkg.target || {};
		var mode = target.scope === 'widget' ? 'widget' : 'subtree';
		var contract = pkg.outputContract || {};
		contract.scope = mode;
		if (contract.templates && contract.templates.edit && contract.templates.edit.scope) contract.templates.edit.scope.mode = mode;
		if (contract.templates && contract.templates.add) {
			if (mode === 'widget') {
				contract.templates.add = {
					supported: false,
					reason: 'A widget cannot own a new sibling/section. Select its parent Container and export again for Add operations.'
				};
			} else if (contract.templates.add.scope) {
				contract.templates.add.scope.mode = 'subtree';
			}
		}
		if (contract.templates && contract.templates.rebuild) {
			contract.templates.rebuild.target = { postId: target.postId || 0, id: target.id || '' };
			contract.templates.rebuild.element = rebuildSkeleton(pkg, target);
		}
		contract.rules = Array.isArray(contract.rules) ? contract.rules : [];
		if (mode === 'widget') contract.rules.unshift('Widget target: edit native settings or explicitly rebuild this same widget type. Do not insert child UI under a widget; select a Container for additions.');
		pkg.outputContract = contract;
		target.canAcceptChildren = mode !== 'widget';
		pkg.target = target;
		pkg = addEmittableKeys(pkg);
		pkg.semanticBindings = semanticBindings(pkg);
		pkg.structureGrammar = structureGrammar(pkg);
		if (window.CrescoLayerDesignIntelligence && typeof window.CrescoLayerDesignIntelligence.enrich === 'function') {
			pkg = window.CrescoLayerDesignIntelligence.enrich(pkg);
		}
		if (window.CrescoLayerDesignReasoning && typeof window.CrescoLayerDesignReasoning.enrich === 'function') {
			pkg = window.CrescoLayerDesignReasoning.enrich(pkg);
		}
		return pkg;
	}
	function jsonResponse(original, payload) {
		var headers = new Headers(original.headers || {});
		headers.delete('content-length'); headers.delete('content-encoding'); headers.set('content-type', 'application/json; charset=UTF-8');
		return new Response(JSON.stringify(payload), { status: original.status, statusText: original.statusText, headers: headers });
	}

	if (upstreamFetch) {
		window.fetch = function (input, init) {
			if (!isExport(input)) return upstreamFetch(input, init);
			return upstreamFetch(input, init).then(function (response) {
				if (!response.ok) return response;
				return response.clone().json().then(function (pkg) { return jsonResponse(response, patchContract(pkg)); }).catch(function () { return response; });
			});
		};
	}

	window.CrescoLayerAIContextPolicy = { version: '1.5.0', patch: patchContract, responsiveSuffixes: responsiveSuffixes, semanticBindings: semanticBindings, structureGrammar: structureGrammar };
}());
