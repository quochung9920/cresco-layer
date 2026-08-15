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
	/**
	 * Documents that may contain the rendered canvas, ordered by how much we trust them.
	 *
	 * The editor chrome carries data-id attributes too — the Navigator lists every element by id — so
	 * searching the top document first finds a panel row and measures the sidebar instead of the
	 * design. Preview iframes come first, and named preview frames come before anonymous ones.
	 */
	function previewDocuments() {
		var out = [];
		if (!document.querySelectorAll) return [{ doc: document, source: 'editor-document', rank: 0 }];

		var named = document.querySelectorAll('#elementor-preview-iframe,iframe[name="elementor-preview-iframe"],iframe[src*="elementor-preview"],iframe[src*="preview=true"]');
		Array.prototype.forEach.call(named, function (frame) {
			try { if (frame.contentDocument) out.push({ doc: frame.contentDocument, source: 'elementor-preview-iframe', rank: 3 }); } catch (e) {}
		});
		Array.prototype.forEach.call(document.querySelectorAll('iframe'), function (frame) {
			try {
				if (!frame.contentDocument) return;
				if (out.some(function (item) { return item.doc === frame.contentDocument; })) return;
				out.push({ doc: frame.contentDocument, source: 'unnamed-iframe', rank: 1 });
			} catch (e) {}
		});
		// The editor document is the last resort, never the first guess.
		out.push({ doc: document, source: 'editor-document', rank: 0 });
		return out;
	}

	/** True when the node sits inside Elementor editor UI rather than the rendered design. */
	function insideEditorChrome(node) {
		if (!node || typeof node.closest !== 'function') return false;
		try {
			return !!node.closest('#elementor-panel,#elementor-navigator,.elementor-panel,.elementor-navigator,#elementor-editor-wrapper > :not(#elementor-preview),[data-elementor-panel]');
		} catch (e) { return false; }
	}

	/**
	 * Resolve the target node together with how much the measurement can be trusted.
	 *
	 * A non-null node is not evidence on its own: the same id exists in the Navigator, and a hit
	 * there produces plausible-looking numbers that describe the sidebar. Confidence therefore comes
	 * from where the node was found and from whether its geometry is consistent with the tree.
	 */
	function resolveTargetNode(id, graph) {
		var result = { node: null, source: 'none', confidence: 0, reasons: [] };
		if (!id) { result.reasons.push('No target element id.'); return result; }

		var selector = '[data-id="' + safeCssEscape(id) + '"],[data-e-id="' + safeCssEscape(id) + '"],[data-element-id="' + safeCssEscape(id) + '"]';
		var candidates = previewDocuments();
		var best = null;

		for (var i = 0; i < candidates.length; i++) {
			var node;
			try { node = candidates[i].doc.querySelector(selector); } catch (e) { continue; }
			if (!node) continue;
			if (insideEditorChrome(node)) {
				if (!best) { result.reasons.push('Matched a node inside Elementor editor UI, which is not the rendered canvas.'); }
				continue;
			}
			best = { node: node, source: candidates[i].source, rank: candidates[i].rank };
			break;
		}

		if (!best) {
			result.reasons.push('Target DOM could not be confidently resolved inside Elementor preview canvas.');
			return result;
		}

		result.node = best.node;
		result.source = best.source;
		result.confidence = best.rank >= 3 ? 0.98 : (best.rank >= 1 ? 0.7 : 0.35);
		if (best.rank < 3) { result.reasons.push('Target was found outside a named Elementor preview iframe.'); }

		applyGeometryChecks(result, graph);
		return result;
	}

	/** Geometry that contradicts the element tree means the measurement describes something else. */
	function applyGeometryChecks(result, graph) {
		var bounds = rect(result.node);
		if (!bounds) {
			result.confidence = 0;
			result.reasons.push('Target node exposed no measurable bounds.');
			return;
		}

		var nodes = (graph && graph.nodes) || [];
		var visible = nodes.filter(function (item) { return item.bounds && item.bounds.width > 0 && item.bounds.height > 0; });

		if (bounds.width <= 1 || bounds.height <= 1) {
			result.confidence = Math.min(result.confidence, 0.25);
			result.reasons.push('Target bounds are effectively zero-sized.');
		}
		// A container with many descendants cannot legitimately render in a sliver of space.
		if (nodes.length > 8 && bounds.height > 0 && bounds.height < 24) {
			result.confidence = Math.min(result.confidence, 0.3);
			result.reasons.push('Target has ' + nodes.length + ' descendants but occupies less than 24px of height.');
		}
		if (nodes.length > 4 && visible.length === 0) {
			result.confidence = Math.min(result.confidence, 0.3);
			result.reasons.push('No descendant reported visible bounds while the tree lists ' + nodes.length + ' elements.');
		}
		var doc = result.node.ownerDocument;
		var win = doc && doc.defaultView;
		if (win && win.innerWidth && (bounds.x > win.innerWidth || bounds.x + bounds.width < 0)) {
			result.confidence = Math.min(result.confidence, 0.3);
			result.reasons.push('Target bounds fall outside the preview viewport.');
		}
	}

	function domNode(id) {
		return resolveTargetNode(id, null).node;
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
	/** Below this the measurement is reported but must not be treated as describing the design. */
	var VISUAL_TRUST_THRESHOLD = 0.6;

	function visualSnapshot(pkg, targetId, graph) {
		var resolved = resolveTargetNode(targetId, graph);
		var node = resolved.node;
		var doc = node && node.ownerDocument ? node.ownerDocument : document;
		var win = doc && doc.defaultView ? doc.defaultView : window;
		var intent = window.CrescoLayerAIIntent || {};
		var visible = (graph.nodes || []).filter(function (item) { return item.bounds && item.bounds.width > 0 && item.bounds.height > 0; });
		var trusted = !!node && resolved.confidence >= VISUAL_TRUST_THRESHOLD;

		return {
			schema: 'cresco-visual-snapshot/v1',
			kind: 'computed-layout',
			// An AI that believes an untrusted measurement will design against the sidebar, so the
			// status is stated rather than left to be inferred from a non-null bounds object.
			status: trusted ? 'trusted' : 'untrusted',
			source: resolved.source,
			confidence: Math.round(resolved.confidence * 100) / 100,
			reason: trusted ? '' : (resolved.reasons[0] || 'Target DOM could not be confidently resolved inside Elementor preview canvas.'),
			diagnostics: resolved.reasons,
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
	/**
	 * Context quality, scored per dimension rather than as a checklist of present fields.
	 *
	 * A field existing is not the same as a field being usable: targetBounds is non-null even when it
	 * measures the Navigator panel, and awarding full marks for that told the AI to design against
	 * the sidebar. Visual credit is therefore proportional to the resolver's confidence, so an
	 * untrusted snapshot lowers the score instead of hiding inside it.
	 */
	function quality(pkg, graph, visual, runtime) {
		var visualConfidence = typeof visual.confidence === 'number' ? visual.confidence : (visual.targetBounds ? 0.5 : 0);
		if ('untrusted' === visual.status) { visualConfidence = Math.min(visualConfidence, 0.3); }

		var checks = [
			{ key: 'exactRuntime', max: 25, score: runtime.mode === 'exact-runtime' && Object.keys(runtime.widgets || {}).length + Object.keys(runtime.elements || {}).length > 0 ? 25 : 0 },
			{ key: 'activeKit', max: 20, score: !!(pkg.siteDesignContext || pkg.designSystem) ? 20 : 0 },
			{ key: 'layoutGraph', max: 20, score: !!(graph.nodes && graph.nodes.length) ? 20 : 0 },
			{ key: 'visualConfidence', max: 15, score: Math.round(15 * visualConfidence) },
			{ key: 'sourceTree', max: 10, score: !!(pkg.document && Array.isArray(pkg.document.content) && pkg.document.content.length) ? 10 : 0 },
			{ key: 'exchangeSafety', max: 10, score: !!pkg.exchangePolicy ? 10 : 0 }
		];
		checks.forEach(function (check) { check.ok = check.score === check.max; });

		var score = checks.reduce(function (sum, check) { return sum + check.score; }, 0);
		var grade = score >= 95 ? 'Excellent' : score >= 80 ? 'Good' : score >= 65 ? 'Usable' : 'Incomplete';
		var warnings = [];
		if ('untrusted' === visual.status) {
			warnings.push('Visual snapshot is untrusted: ' + (visual.reason || 'the target could not be located in the preview canvas') + ' Do not rely on the reported geometry.');
		}
		return { schema: 'cresco-context-quality/v2', score: score, grade: grade, checks: checks, warnings: warnings };
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
