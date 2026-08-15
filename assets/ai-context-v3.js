(function () {
	'use strict';

	var cfg = window.crescoLayerEditor || {};
	var upstreamFetch = typeof window.fetch === 'function' ? window.fetch.bind(window) : null;
	var state = { installed: false, lastError: '', lastScore: 0, lastTarget: '' };

	function root() { return String(cfg.restRoot || '').replace(/\/$/, ''); }
	function isExport(input) {
		var url = typeof input === 'string' ? input : (input && input.url ? String(input.url) : '');
		return !!url && url.indexOf(root() + '/documents/') === 0 && url.indexOf('/export') !== -1;
	}
	function clone(value) {
		try { return JSON.parse(JSON.stringify(value)); } catch (e) { return value; }
	}
	function pick(source, keys) {
		var out = {};
		(keys || []).forEach(function (key) {
			if (source && Object.prototype.hasOwnProperty.call(source, key)) out[key] = source[key];
		});
		return out;
	}
	function safeCssEscape(value) {
		if (window.CSS && typeof window.CSS.escape === 'function') return window.CSS.escape(String(value));
		return String(value).replace(/[^A-Za-z0-9_-]/g, '\\$&');
	}
	function previewDocuments() {
		var docs = [document];
		if (!document.querySelectorAll) return docs;
		Array.prototype.forEach.call(document.querySelectorAll('iframe'), function (frame) {
			try { if (frame.contentDocument) docs.push(frame.contentDocument); } catch (e) {}
		});
		return docs;
	}
	function domNode(id) {
		if (!id) return null;
		var selector = '[data-id="' + safeCssEscape(id) + '"],[data-e-id="' + safeCssEscape(id) + '"],[data-element-id="' + safeCssEscape(id) + '"]';
		var docs = previewDocuments();
		for (var i = 0; i < docs.length; i++) {
			try {
				var node = docs[i].querySelector(selector);
				if (node) return node;
			} catch (e) {}
		}
		return null;
	}
	function rect(node) {
		if (!node || typeof node.getBoundingClientRect !== 'function') return null;
		var r = node.getBoundingClientRect();
		return {
			x: Math.round(r.x * 100) / 100,
			y: Math.round(r.y * 100) / 100,
			width: Math.round(r.width * 100) / 100,
			height: Math.round(r.height * 100) / 100
		};
	}
	function computed(node) {
		if (!node || !node.ownerDocument || !node.ownerDocument.defaultView) return {};
		var style;
		try { style = node.ownerDocument.defaultView.getComputedStyle(node); } catch (e) { return {}; }
		var keys = [
			'display', 'position', 'width', 'height', 'minWidth', 'maxWidth', 'minHeight', 'maxHeight',
			'flexDirection', 'flexWrap', 'justifyContent', 'alignItems', 'alignSelf', 'gap', 'rowGap', 'columnGap',
			'gridTemplateColumns', 'gridTemplateRows', 'gridAutoFlow',
			'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
			'marginTop', 'marginRight', 'marginBottom', 'marginLeft',
			'overflow', 'overflowX', 'overflowY', 'zIndex',
			'backgroundColor', 'color', 'borderTopWidth', 'borderTopStyle', 'borderTopColor', 'borderRadius',
			'fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'textAlign', 'textTransform', 'opacity', 'transform'
		];
		var out = {};
		keys.forEach(function (key) {
			var value = style[key];
			if (value != null && value !== '') out[key] = String(value);
		});
		return out;
	}
	function roleIndex(pkg) {
		var roles = pkg && pkg.layoutContext ? pkg.layoutContext.containerRoles : null;
		var out = {};
		if (Array.isArray(roles)) {
			roles.forEach(function (item) { if (item && item.id) out[item.id] = item.role || item.containerRole || ''; });
		} else if (roles && typeof roles === 'object') {
			Object.keys(roles).forEach(function (key) {
				var item = roles[key];
				out[key] = typeof item === 'string' ? item : (item && (item.role || item.containerRole)) || '';
			});
		}
		return out;
	}
	function importantSettings(settings) {
		if (!settings || typeof settings !== 'object') return {};
		var wanted = [
			'content_width', 'width', 'min_height', 'flex_direction', 'flex_wrap', 'flex_justify_content', 'flex_align_items', 'flex_gap',
			'grid_columns_grid', 'grid_rows_grid', 'grid_auto_flow', 'padding', 'overflow', 'position', 'background_background', 'background_color',
			'title', 'header_size', 'text', 'align', 'typography_typography', 'typography_font_family', 'typography_font_size', 'typography_font_weight',
			'typography_line_height', 'title_color', 'text_color'
		];
		return pick(settings, wanted);
	}
	function buildGraph(pkg) {
		var roles = roleIndex(pkg);
		var nodes = [];
		function walk(elements, parentId, depth) {
			(elements || []).forEach(function (element, index) {
				if (!element || typeof element !== 'object') return;
				var id = String(element.id || '');
				var node = domNode(id);
				nodes.push({
					id: id,
					parentId: parentId || '',
					index: index,
					depth: depth,
					elType: String(element.elType || ''),
					widgetType: String(element.widgetType || ''),
					role: roles[id] || '',
					childCount: Array.isArray(element.elements) ? element.elements.length : 0,
					settings: importantSettings(element.settings || {}),
					bounds: rect(node),
					computed: computed(node)
				});
				walk(element.elements || [], id, depth + 1);
			});
		}
		walk(pkg && pkg.document ? pkg.document.content : [], '', 0);
		return { schema: 'cresco-layout-graph/v1', source: 'elementor-tree+live-preview', nodes: nodes };
	}
	function visualSnapshot(pkg, targetId, graph) {
		var node = domNode(targetId);
		var doc = node && node.ownerDocument ? node.ownerDocument : document;
		var win = doc && doc.defaultView ? doc.defaultView : window;
		var intent = window.CrescoLayerAIIntent || {};
		var visible = (graph.nodes || []).filter(function (item) { return item.bounds && item.bounds.width > 0 && item.bounds.height > 0; });
		return {
			schema: 'cresco-visual-snapshot/v1',
			kind: 'computed-layout',
			note: 'Structured visual snapshot from the live Elementor preview. Attach the reference image separately when the task is image-matching; binary image data is intentionally not embedded in JSON.',
			viewport: {
				width: Math.round((win && win.innerWidth) || 0),
				height: Math.round((win && win.innerHeight) || 0),
				devicePixelRatio: Number((win && win.devicePixelRatio) || 1)
			},
			targetId: targetId,
			targetBounds: rect(node),
			targetComputed: computed(node),
			visibleElementCount: visible.length,
			referenceImage: intent.referenceImage || { provided: false, delivery: 'attach-separately' }
		};
	}
	function compactControl(control) {
		if (!control || typeof control !== 'object') return control;
		var out = pick(control, [
			'type', 'responsive', 'default', 'size_units', 'range', 'options', 'condition', 'conditions', 'selectors', 'selector',
			'allowed_dimensions', 'min', 'max', 'step', 'frontend_available', 'dynamic', 'ai', 'atomic', 'binding', 'value_shape', 'placeholder'
		]);
		if (!Object.keys(out).length) {
			Object.keys(control).slice(0, 16).forEach(function (key) {
				if (typeof control[key] !== 'function') out[key] = control[key];
			});
		}
		return out;
	}
	function compileEntry(entry) {
		if (!entry || typeof entry !== 'object') return {};
		var controls = {};
		Object.keys(entry.controls || {}).forEach(function (key) { controls[key] = compactControl(entry.controls[key]); });
		return {
			name: entry.name || entry.widgetType || entry.elType || '',
			title: entry.title || '',
			detailLoaded: entry.detailLoaded === true,
			controls: controls,
			defaultSettings: entry.defaultSettings || {},
			atomic: entry.atomic || entry.atomicBindings || null
		};
	}
	function compileRuntime(pkg) {
		var source = pkg.runtimeCapabilities || {};
		var widgets = {}, elements = {};
		Object.keys(source.widgets || {}).forEach(function (name) { widgets[name] = compileEntry(source.widgets[name]); });
		Object.keys(source.elements || {}).forEach(function (name) { elements[name] = compileEntry(source.elements[name]); });
		return {
			schema: 'cresco-ai-runtime/v1',
			mode: source.mode || 'unknown',
			source: source.source || '',
			controlMetadataVersion: source.controlMetadataVersion || 0,
			constructionSet: clone(source.constructionSet || { widgets: Object.keys(widgets), elements: Object.keys(elements) }),
			widgets: widgets,
			elements: elements
		};
	}
	function countTree(elements) {
		var count = 0;
		(function walk(list) {
			(list || []).forEach(function (el) { count += 1; walk(el && el.elements); });
		}(elements || []));
		return count;
	}
	function targetElement(pkg, targetId) {
		var found = null;
		(function walk(list) {
			(list || []).some(function (el) {
				if (!el || typeof el !== 'object') return false;
				if (String(el.id || '') === targetId) { found = el; return true; }
				walk(el.elements || []);
				return !!found;
			});
		}(pkg && pkg.document ? pkg.document.content : []));
		return found;
	}
	function summarizeInterface(pkg, targetId) {
		var root = targetElement(pkg, targetId);
		var tree = root ? [root] : (pkg && pkg.document ? pkg.document.content : []);
		var widgetTypes = {}, elementTypes = {};
		(function walk(list) {
			(list || []).forEach(function (el) {
				if (!el) return;
				if (el.widgetType) widgetTypes[el.widgetType] = (widgetTypes[el.widgetType] || 0) + 1;
				else if (el.elType) elementTypes[el.elType] = (elementTypes[el.elType] || 0) + 1;
				walk(el.elements || []);
			});
		}(tree));
		return {
			readOnly: true,
			targetId: targetId,
			elementCount: countTree(tree),
			widgetTypes: widgetTypes,
			elementTypes: elementTypes,
			element: clone(root || null),
			instruction: 'This is source context only. Never echo this existing tree back merely to add or edit a small part.'
		};
	}
	function taskIntent() {
		var intent = window.CrescoLayerAIIntent || {};
		return {
			request: String(intent.request || '').trim(),
			changeType: ['auto', 'edit', 'add', 'rebuild'].indexOf(intent.changeType) !== -1 ? intent.changeType : 'auto',
			preserveExistingUI: intent.changeType !== 'rebuild',
			referenceImage: intent.referenceImage || { provided: false, delivery: 'attach-separately' }
		};
	}
	function outputContract(task, targetId, postId) {
		var strategy = task.changeType === 'rebuild' ? 'explicit-rebuild' : 'delta-first';
		return {
			schema: 'cresco-ai-output-contract/v2',
			strategy: strategy,
			preferredSchema: task.changeType === 'rebuild' ? 'cresco-layer-ai-result/v1' : 'cresco-layer-patch/v1',
			postId: postId,
			targetId: targetId,
			rules: [
				'For additions, return insert-element only for the new subtree; do not copy the existing target tree.',
				'For edits, return update-setting/remove-setting/move-element for the exact existing IDs.',
				'Use replace-element only for an explicit full rebuild of that exact target.',
				'Never return [TRUNCATED], [REDACTED] or __cresco_truncated__.',
				'Use native Elementor controls first; custom_css is last resort; use parent gap for sibling spacing.'
			],
			templates: {
				add: {
					schema: 'cresco-layer-patch/v1', base: { postId: postId },
					scope: { mode: 'subtree', rootElementId: targetId, elementIds: [targetId] },
					operations: [ { operation: 'insert-element', parentId: targetId, position: 999999, element: { elType: 'container', settings: {}, elements: [] } } ]
				},
				edit: {
					schema: 'cresco-layer-patch/v1', base: { postId: postId },
					scope: { mode: 'subtree', rootElementId: targetId, elementIds: [targetId] },
					operations: [ { operation: 'update-setting', elementId: targetId, setting: '<exact-runtime-key>', value: '<valid-value>' } ]
				},
				rebuild: {
					schema: 'cresco-layer-ai-result/v1', intent: 'replace-target', target: { postId: postId, id: targetId },
					element: { id: targetId, elType: 'container', settings: {}, elements: [] }
				}
			}
		};
	}
	function brief(task, target, current, quality) {
		var action = task.changeType === 'add' ? 'Add new UI without rebuilding existing UI.' : task.changeType === 'edit' ? 'Edit only the requested existing controls.' : task.changeType === 'rebuild' ? 'Fully rebuild the selected target only.' : 'Choose the smallest safe delta that satisfies the request.';
		return [
			'# Cresco AI Task',
			'',
			'Goal: ' + (task.request || 'Create or refine the selected Elementor interface.'),
			'Target: ' + target.type + ' · ' + target.id + ' · post ' + target.postId,
			'Action policy: ' + action,
			'Existing UI: read-only source context. Preserve it unless the task explicitly says rebuild.',
			'Element count in source target: ' + current.elementCount + '.',
			'Context quality: ' + quality.score + '/100 (' + quality.grade + ').',
			'Design rules: reuse Active Elementor Site Settings; exact native controls first; parent gap for sibling spacing; custom CSS only when no native runtime control exists.',
			'Output: follow outputContract exactly. Return JSON only.'
		].join('\n');
	}
	function quality(pkg, graph, visual, runtime) {
		var checks = [
			{ key: 'exactRuntime', weight: 25, ok: runtime.mode === 'exact-runtime' && Object.keys(runtime.widgets || {}).length + Object.keys(runtime.elements || {}).length > 0 },
			{ key: 'activeKit', weight: 20, ok: !!(pkg.siteDesignContext || pkg.designSystem) },
			{ key: 'layoutGraph', weight: 20, ok: !!(graph.nodes && graph.nodes.length) },
			{ key: 'liveVisualMetrics', weight: 15, ok: !!visual.targetBounds },
			{ key: 'sourceTree', weight: 10, ok: !!(pkg.document && Array.isArray(pkg.document.content) && pkg.document.content.length) },
			{ key: 'exchangeSafety', weight: 10, ok: !!pkg.exchangePolicy }
		];
		var score = checks.reduce(function (sum, check) { return sum + (check.ok ? check.weight : 0); }, 0);
		var grade = score >= 95 ? 'Excellent' : score >= 80 ? 'Good' : score >= 65 ? 'Usable' : 'Incomplete';
		return { schema: 'cresco-context-quality/v1', score: score, grade: grade, checks: checks };
	}
	function buildV3(pkg) {
		if (!pkg || pkg.schema !== 'cresco-layer-ai-package/v2') return pkg;
		var scope = pkg.editableScope || {};
		var targetId = String(scope.rootElementId || (scope.elementIds && scope.elementIds[0]) || '');
		var postId = Number(pkg.manifest && pkg.manifest.postId || 0);
		var task = taskIntent();
		var graph = buildGraph(pkg);
		var visual = visualSnapshot(pkg, targetId, graph);
		var runtime = compileRuntime(pkg);
		var current = summarizeInterface(pkg, targetId);
		var q = quality(pkg, graph, visual, runtime);
		var target = {
			postId: postId,
			id: targetId,
			type: current.element ? (current.element.widgetType || current.element.elType || 'element') : 'element',
			scope: scope.mode || 'subtree',
			editableElementIds: clone(scope.editableElementIds || scope.elementIds || [])
		};
		var contract = outputContract(task, targetId, postId);
		state.lastScore = q.score; state.lastTarget = targetId;
		return {
			schema: 'cresco-ai-context/v3',
			aiBrief: brief(task, target, current, q),
			task: task,
			target: target,
			currentInterface: current,
			visualSnapshot: visual,
			layoutGraph: graph,
			designSystem: clone(pkg.siteDesignContext || pkg.designSystem || {}),
			responsive: clone(pkg.layoutContext && pkg.layoutContext.responsiveFoundation || {}),
			runtime: runtime,
			rules: {
				sourceContextReadOnly: true,
				deltaMutationByDefault: true,
				nativeControlsFirst: true,
				customCssFallbackOnly: true,
				preferGapOverMargins: true,
				preserveGlobalReferences: true,
				preserveDynamicTags: true,
				checksumRequired: false,
				exchangePolicy: clone(pkg.exchangePolicy || {})
			},
			outputContract: contract,
			contextQuality: q,
			sourceContext: {
				readOnly: true,
				elementContext: clone(pkg.elementContext || []),
				elementStates: clone(pkg.elementStates || {}),
				dynamicTags: clone(pkg.dynamicTags || {}),
				assets: clone(pkg.assets || []),
				templates: clone(pkg.templates || [])
			},
			diagnostics: {
				pluginVersion: pkg.manifest && pkg.manifest.pluginVersion || cfg.version || '',
				elementorVersion: pkg.manifest && pkg.manifest.elementorVersion || cfg.elementorVersion || '',
				elementorProVersion: pkg.manifest && pkg.manifest.elementorProVersion || cfg.elementorProVersion || '',
				legacyPackageSchema: pkg.schema,
				contextProfile: pkg.manifest && pkg.manifest.contextProfile || '',
				serialization: clone(pkg.serialization || {}),
				capabilityCoverage: clone(pkg.capabilityCoverage || {})
			}
		};
	}
	function jsonResponse(original, payload) {
		var headers = new Headers(original.headers || {});
		headers.delete('content-length'); headers.delete('content-encoding'); headers.set('content-type', 'application/json; charset=UTF-8');
		return new Response(JSON.stringify(payload), { status: original.status, statusText: original.statusText, headers: headers });
	}

	if (upstreamFetch) {
		state.installed = true;
		window.fetch = function (input, init) {
			if (!isExport(input)) return upstreamFetch(input, init);
			return upstreamFetch(input, init).then(function (response) {
				if (!response.ok) return response;
				return response.clone().json().then(function (pkg) {
					var v3 = buildV3(pkg);
					return jsonResponse(response, v3);
				}).catch(function (error) {
					state.lastError = error && error.message ? error.message : String(error);
					return response;
				});
			});
		};
	}

	window.CrescoLayerAIContextV3 = {
		version: '1.0.0',
		build: buildV3,
		getDiagnostics: function () { return clone(state); }
	};
}());
