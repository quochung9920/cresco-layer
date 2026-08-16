(function () {
	'use strict';

	var cfg = window.crescoLayerEditor || {};
	var lastSnapshot = null;
	var DEFAULT_POLICY = {
		schema: 'cresco-fidelity-policy/v1',
		threshold: 96,
		categoryFloor: { geometry: 90, spacing: 90, typography: 90, color: 88, structure: 98, quality: 95 },
		weights: { geometry: 0.30, spacing: 0.18, typography: 0.18, color: 0.12, structure: 0.12, quality: 0.10 },
		tolerances: { geometryPx: 2, spacingPx: 2, typographyPx: 1.5, opacity: 0.03, overflowPx: 2 },
		blockingRules: [ 'missing-element', 'parent-drift', 'horizontal-overflow', 'invisible-target', 'invalid-geometry' ],
		capture: { maxElements: 500, includeDescendants: true, includeSiblingGraph: true, computedStyles: true, currentBreakpointOnly: true }
	};

	var STYLE_KEYS = {
		layout: [ 'display', 'position', 'flexDirection', 'flexWrap', 'justifyContent', 'alignItems', 'alignContent', 'gap', 'rowGap', 'columnGap', 'gridTemplateColumns', 'gridTemplateRows', 'overflow', 'overflowX', 'overflowY', 'zIndex', 'transform' ],
		spacing: [ 'marginTop', 'marginRight', 'marginBottom', 'marginLeft', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft' ],
		typography: [ 'fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'lineHeight', 'letterSpacing', 'textAlign', 'textTransform', 'textDecorationLine', 'whiteSpace', 'wordBreak' ],
		visual: [ 'color', 'backgroundColor', 'backgroundImage', 'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth', 'borderTopStyle', 'borderRightStyle', 'borderBottomStyle', 'borderLeftStyle', 'borderTopColor', 'borderRightColor', 'borderBottomColor', 'borderLeftColor', 'borderTopLeftRadius', 'borderTopRightRadius', 'borderBottomRightRadius', 'borderBottomLeftRadius', 'boxShadow', 'opacity', 'visibility' ]
	};

	function clone(value) {
		try { return JSON.parse(JSON.stringify(value)); } catch (e) { return value; }
	}
	function policy() {
		var fromServer = cfg && cfg.fidelityPolicy && typeof cfg.fidelityPolicy === 'object' ? cfg.fidelityPolicy : {};
		var out = clone(DEFAULT_POLICY);
		Object.keys(fromServer).forEach(function (key) {
			if (fromServer[key] && typeof fromServer[key] === 'object' && !Array.isArray(fromServer[key]) && out[key] && typeof out[key] === 'object' && !Array.isArray(out[key])) {
				Object.assign(out[key], fromServer[key]);
			} else {
				out[key] = fromServer[key];
			}
		});
		return out;
	}
	function frame() {
		return document.querySelector('#elementor-preview-iframe,iframe[name="elementor-preview-iframe"],iframe[src*="elementor-preview"]');
	}
	function previewDocument() {
		var el = frame();
		try { return el && el.contentDocument ? el.contentDocument : null; } catch (e) { return null; }
	}
	function previewWindow(doc) {
		return doc && doc.defaultView ? doc.defaultView : null;
	}
	function round(value) {
		var n = Number(value);
		return Number.isFinite(n) ? Math.round(n * 1000) / 1000 : null;
	}
	function elementId(node) {
		if (!node || node.nodeType !== 1) return '';
		var direct = node.getAttribute('data-id');
		if (direct) return String(direct);
		var match = String(node.className || '').match(/(?:^|\s)elementor-element-([A-Za-z0-9_-]+)/);
		return match ? match[1] : '';
	}
	function queryElement(doc, id) {
		if (!doc || !id) return null;
		try {
			var escaped = window.CSS && CSS.escape ? CSS.escape(String(id)) : String(id).replace(/[^A-Za-z0-9_-]/g, '');
			return doc.querySelector('[data-id="' + escaped + '"],.elementor-element-' + escaped);
		} catch (e) { return null; }
	}
	function allElementNodes(doc) {
		if (!doc) return [];
		return Array.prototype.slice.call(doc.querySelectorAll('[data-id], [class*="elementor-element-"]')).filter(function (node, index, list) {
			var id = elementId(node);
			if (!id) return false;
			for (var i = 0; i < index; i++) if (elementId(list[i]) === id) return false;
			return true;
		});
	}
	function currentDevice() {
		try {
			if (window.elementor && elementor.channels && elementor.channels.deviceMode && typeof elementor.channels.deviceMode.request === 'function') {
				return String(elementor.channels.deviceMode.request('currentMode') || 'desktop');
			}
		} catch (e) {}
		return 'desktop';
	}
	function styleGroup(css, keys) {
		var out = {};
		keys.forEach(function (key) { out[key] = css ? String(css[key] == null ? '' : css[key]) : ''; });
		return out;
	}
	function nearestElementParent(node) {
		var current = node ? node.parentElement : null;
		while (current) {
			if (elementId(current)) return current;
			current = current.parentElement;
		}
		return null;
	}
	function directElementChildren(node) {
		if (!node) return [];
		var out = [];
		var descendants = node.querySelectorAll('[data-id], [class*="elementor-element-"]');
		Array.prototype.forEach.call(descendants, function (candidate) {
			if (!elementId(candidate)) return;
			if (nearestElementParent(candidate) === node) out.push(candidate);
		});
		return out;
	}
	function siblingInfo(node) {
		var parent = nearestElementParent(node);
		if (!parent) return { previousId: '', nextId: '', index: 0, count: 1 };
		var children = directElementChildren(parent);
		var id = elementId(node), index = children.findIndex(function (child) { return elementId(child) === id; });
		return {
			previousId: index > 0 ? elementId(children[index - 1]) : '',
			nextId: index >= 0 && index < children.length - 1 ? elementId(children[index + 1]) : '',
			index: index < 0 ? 0 : index,
			count: children.length
		};
	}
	function captureElement(node, doc) {
		var win = previewWindow(doc);
		if (!node || !win) return null;
		var rect = node.getBoundingClientRect();
		var css = win.getComputedStyle(node);
		var parent = nearestElementParent(node);
		var parentRect = parent ? parent.getBoundingClientRect() : { left: 0, top: 0 };
		var sibling = siblingInfo(node);
		var width = round(rect.width), height = round(rect.height);
		var horizontalOverflow = node.scrollWidth > node.clientWidth + (policy().tolerances.overflowPx || 2);
		var verticalOverflow = node.scrollHeight > node.clientHeight + (policy().tolerances.overflowPx || 2);
		return {
			id: elementId(node),
			parentId: parent ? elementId(parent) : '',
			children: directElementChildren(node).map(elementId).filter(Boolean),
			sibling: sibling,
			geometry: {
				x: round(rect.left), y: round(rect.top), width: width, height: height,
				right: round(rect.right), bottom: round(rect.bottom),
				relativeX: round(rect.left - parentRect.left), relativeY: round(rect.top - parentRect.top)
			},
			scroll: {
				clientWidth: round(node.clientWidth), clientHeight: round(node.clientHeight),
				scrollWidth: round(node.scrollWidth), scrollHeight: round(node.scrollHeight)
			},
			layout: styleGroup(css, STYLE_KEYS.layout),
			spacing: styleGroup(css, STYLE_KEYS.spacing),
			typography: styleGroup(css, STYLE_KEYS.typography),
			visual: styleGroup(css, STYLE_KEYS.visual),
			quality: {
				horizontalOverflow: horizontalOverflow,
				verticalOverflow: verticalOverflow,
				hidden: css.display === 'none' || css.visibility === 'hidden' || Number(css.opacity) === 0,
				invalidGeometry: width == null || height == null || width < 0 || height < 0
			}
		};
	}
	function scopedNodes(doc, ids, includeDescendants) {
		if (!ids || !ids.length) return allElementNodes(doc);
		var seen = {}, out = [];
		ids.forEach(function (id) {
			var root = queryElement(doc, id);
			if (!root) return;
			var candidates = [root];
			if (includeDescendants) candidates = candidates.concat(Array.prototype.slice.call(root.querySelectorAll('[data-id], [class*="elementor-element-"]')));
			candidates.forEach(function (node) {
				var key = elementId(node);
				if (!key || seen[key]) return;
				seen[key] = true; out.push(node);
			});
		});
		return out;
	}
	function geometryGraph(elements) {
		var nodes = {}, edges = [];
		(elements || []).forEach(function (item) {
			nodes[item.id] = { parentId: item.parentId, children: item.children, sibling: item.sibling, geometry: item.geometry };
			if (item.parentId) edges.push({ type: 'parent', from: item.parentId, to: item.id });
			if (item.sibling && item.sibling.nextId) edges.push({ type: 'next-sibling', from: item.id, to: item.sibling.nextId });
		});
		return { schema: 'cresco-geometry-graph/v1', nodes: nodes, edges: edges };
	}
	function capture(ids, options) {
		var doc = previewDocument();
		if (!doc) return { schema: 'cresco-fidelity-snapshot/v1', status: 'unavailable', reason: 'Elementor preview iframe is unavailable.', elements: [], geometryGraph: geometryGraph([]) };
		var p = policy(), opts = options || {}, max = Number(opts.maxElements || (p.capture && p.capture.maxElements) || 500);
		var includeDescendants = opts.includeDescendants !== false && (!p.capture || p.capture.includeDescendants !== false);
		var nodes = scopedNodes(doc, (ids || []).map(String), includeDescendants).slice(0, Math.max(1, max));
		var elements = nodes.map(function (node) { return captureElement(node, doc); }).filter(Boolean);
		var win = previewWindow(doc), viewport = win ? { width: round(win.innerWidth), height: round(win.innerHeight), devicePixelRatio: round(win.devicePixelRatio || 1), scrollX: round(win.scrollX), scrollY: round(win.scrollY) } : {};
		lastSnapshot = {
			schema: 'cresco-fidelity-snapshot/v1', status: 'captured', capturedAt: new Date().toISOString(),
			device: currentDevice(), viewport: viewport, requestedElementIds: (ids || []).map(String),
			elementCount: elements.length, truncated: nodes.length >= max, elements: elements, geometryGraph: geometryGraph(elements),
			policy: { schema: p.schema, tolerances: p.tolerances, threshold: p.threshold }
		};
		return lastSnapshot;
	}
	function capturePackage(pkg) {
		var scope = pkg && pkg.editableScope ? pkg.editableScope : {};
		var ids = Array.isArray(scope.editableElementIds) ? scope.editableElementIds : (Array.isArray(scope.elementIds) ? scope.elementIds : []);
		var snapshot = capture(ids, { includeDescendants: true });
		return {
			schema: 'cresco-visual-context/v1', source: 'elementor-preview-computed-runtime',
			currentBreakpointOnly: true, snapshot: snapshot,
			limitations: [
				'Computed geometry reflects the current Elementor preview breakpoint and current browser rendering environment.',
				'Raster-identical pixels are not guaranteed across browsers, operating systems, font rasterizers or graphics stacks.'
			]
		};
	}
	function px(value) {
		if (typeof value === 'number') return value;
		var match = String(value == null ? '' : value).trim().match(/^(-?[0-9.]+)px$/i);
		return match ? Number(match[1]) : null;
	}
	function numericScore(expected, actual, tolerance) {
		var e = Number(expected), a = Number(actual);
		if (!Number.isFinite(e) || !Number.isFinite(a)) return 0;
		var delta = Math.abs(e - a), tol = Math.max(0.001, Number(tolerance) || 1);
		if (delta <= tol) return 100;
		return Math.max(0, 100 - ((delta - tol) / tol) * 20);
	}
	function cssNumericScore(expected, actual, tolerance) {
		var e = px(expected), a = px(actual);
		if (e == null || a == null) return normalizeText(expected) === normalizeText(actual) ? 100 : 0;
		return numericScore(e, a, tolerance);
	}
	function normalizeText(value) { return String(value == null ? '' : value).trim().replace(/\s+/g, ' ').toLowerCase(); }
	function exactScore(expected, actual) { return normalizeText(expected) === normalizeText(actual) ? 100 : 0; }
	function average(list) {
		if (!list || !list.length) return 100;
		return Math.round((list.reduce(function (sum, value) { return sum + Number(value || 0); }, 0) / list.length) * 100) / 100;
	}
	function mapElements(snapshot) {
		var out = {};
		(snapshot && snapshot.elements || []).forEach(function (element) { if (element && element.id) out[element.id] = element; });
		return out;
	}
	function pushIssue(issues, rule, elementId, expected, actual, blocking) {
		issues.push({ rule: rule, elementId: elementId || '', expected: expected, actual: actual, blocking: !!blocking });
	}
	function compare(reference, actual, customPolicy) {
		var p = customPolicy || policy(), ref = mapElements(reference), act = mapElements(actual), scores = { geometry: [], spacing: [], typography: [], color: [], structure: [], quality: [] }, issues = [];
		Object.keys(ref).forEach(function (id) {
			var r = ref[id], a = act[id];
			if (!a) { scores.structure.push(0); pushIssue(issues, 'missing-element', id, 'present', 'missing', true); return; }
			scores.structure.push(exactScore(r.parentId, a.parentId));
			if (r.parentId !== a.parentId) pushIssue(issues, 'parent-drift', id, r.parentId, a.parentId, true);
			[ 'width', 'height', 'relativeX', 'relativeY' ].forEach(function (key) { scores.geometry.push(numericScore(r.geometry[key], a.geometry[key], p.tolerances.geometryPx)); });
			[ 'marginTop', 'marginRight', 'marginBottom', 'marginLeft', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft' ].forEach(function (key) { scores.spacing.push(cssNumericScore(r.spacing[key], a.spacing[key], p.tolerances.spacingPx)); });
			[ 'gap', 'rowGap', 'columnGap' ].forEach(function (key) { scores.spacing.push(cssNumericScore(r.layout[key], a.layout[key], p.tolerances.spacingPx)); });
			[ 'fontSize', 'lineHeight', 'letterSpacing' ].forEach(function (key) { scores.typography.push(cssNumericScore(r.typography[key], a.typography[key], p.tolerances.typographyPx)); });
			[ 'fontFamily', 'fontWeight', 'fontStyle', 'textAlign', 'textTransform' ].forEach(function (key) { scores.typography.push(exactScore(r.typography[key], a.typography[key])); });
			[ 'color', 'backgroundColor', 'borderTopColor', 'borderRightColor', 'borderBottomColor', 'borderLeftColor' ].forEach(function (key) { scores.color.push(exactScore(r.visual[key], a.visual[key])); });
			if (a.quality.horizontalOverflow) { scores.quality.push(0); pushIssue(issues, 'horizontal-overflow', id, false, true, true); } else scores.quality.push(100);
			if (a.quality.hidden && !r.quality.hidden) { scores.quality.push(0); pushIssue(issues, 'invisible-target', id, false, true, true); } else scores.quality.push(100);
			if (a.quality.invalidGeometry) { scores.quality.push(0); pushIssue(issues, 'invalid-geometry', id, false, true, true); } else scores.quality.push(100);
		});
		var categoryScores = {};
		Object.keys(scores).forEach(function (category) { categoryScores[category] = average(scores[category]); });
		var overall = 0, weightTotal = 0;
		Object.keys(p.weights || {}).forEach(function (category) { var w = Number(p.weights[category] || 0); overall += (categoryScores[category] == null ? 100 : categoryScores[category]) * w; weightTotal += w; });
		overall = weightTotal ? Math.round((overall / weightTotal) * 100) / 100 : average(Object.keys(categoryScores).map(function (key) { return categoryScores[key]; }));
		var report = { schema: 'cresco-fidelity-report/v1', mode: 'snapshot-compare', overall: overall, categories: categoryScores, issues: issues, referenceDevice: reference && reference.device || '', actualDevice: actual && actual.device || '' };
		report.gate = gate(report, p);
		return report;
	}
	function categoryForCheck(name) {
		name = String(name || '');
		if (name.indexOf('layout.gap') === 0 || name.indexOf('layout.padding') === 0 || name.indexOf('layout.margin') === 0) return 'spacing';
		if (name.indexOf('layout.') === 0) return 'geometry';
		if (/^style\.(font|lineHeight|letterSpacing|textAlign)/.test(name)) return 'typography';
		if (/^style\.(backgroundColor|textColor)/.test(name)) return 'color';
		if (name.indexOf('a11y.') === 0 || name.indexOf('ux.') === 0) return 'quality';
		return 'structure';
	}
	function scoreChecks(checks, customPolicy) {
		var p = customPolicy || policy(), buckets = { geometry: [], spacing: [], typography: [], color: [], structure: [], quality: [] }, issues = [];
		(checks || []).forEach(function (check) {
			var category = categoryForCheck(check.name), value = check.status === 'pass' ? 100 : (check.status === 'warning' ? 55 : 0);
			buckets[category].push(value);
			if (check.status !== 'pass') issues.push({ rule: check.name || 'verification-mismatch', elementId: check.elementId || '', expected: check.expected, actual: check.actual, blocking: check.status === 'fail' });
		});
		var categories = {};
		Object.keys(buckets).forEach(function (category) { categories[category] = average(buckets[category]); });
		var overall = 0, weightTotal = 0;
		Object.keys(p.weights).forEach(function (category) { var w = Number(p.weights[category] || 0); overall += categories[category] * w; weightTotal += w; });
		overall = weightTotal ? Math.round((overall / weightTotal) * 100) / 100 : 100;
		var report = { schema: 'cresco-fidelity-report/v1', mode: 'intent-verification', overall: overall, categories: categories, issues: issues };
		report.gate = gate(report, p);
		return report;
	}
	function gate(report, customPolicy) {
		var p = customPolicy || policy(), blocking = (report.issues || []).filter(function (issue) {
			return issue.blocking || (p.blockingRules || []).indexOf(issue.rule) !== -1;
		});
		var floorFailures = [];
		Object.keys(p.categoryFloor || {}).forEach(function (category) {
			var actual = Number(report.categories && report.categories[category]);
			var minimum = Number(p.categoryFloor[category]);
			if (Number.isFinite(actual) && actual < minimum) floorFailures.push({ category: category, actual: actual, minimum: minimum });
		});
		var pass = Number(report.overall || 0) >= Number(p.threshold || 96) && blocking.length === 0 && floorFailures.length === 0;
		return {
			schema: 'cresco-fidelity-gate/v1', pass: pass, status: pass ? 'pass' : 'blocked', threshold: Number(p.threshold || 96),
			overall: Number(report.overall || 0), blockingIssues: blocking, categoryFloorFailures: floorFailures,
			note: 'The gate verifies deterministic structure and bounded rendered error. It does not claim identical raster pixels across rendering environments.'
		};
	}

	window.CrescoLayerFidelityEngine = {
		version: '1.0.0',
		capture: capture,
		capturePackage: capturePackage,
		compare: compare,
		scoreChecks: scoreChecks,
		gate: gate,
		getPolicy: function () { return clone(policy()); },
		getLastSnapshot: function () { return clone(lastSnapshot); },
		getPreviewDocument: previewDocument,
		getCurrentDevice: currentDevice
	};
}());
