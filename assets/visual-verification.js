(function () {
	'use strict';

	var cfg = window.crescoLayerEditor || {};
	var upstreamFetch = typeof window.fetch === 'function' ? window.fetch.bind(window) : null;
	var state = { mutation: null, targetId: '', resolvedRefs: {}, lastResult: null };

	function root() { return String(cfg.restRoot || '').replace(/\/$/, ''); }
	function previewDocument() {
		var frame = document.querySelector('#elementor-preview-iframe,iframe[name="elementor-preview-iframe"],iframe[src*="elementor-preview"]');
		try { return frame && frame.contentDocument ? frame.contentDocument : null; } catch (e) { return null; }
	}
	function nodeById(id) {
		var doc = previewDocument();
		if (!doc || !id) return null;
		try { return doc.querySelector('[data-id="' + CSS.escape(String(id)) + '"],.elementor-element-' + CSS.escape(String(id))); } catch (e) { return null; }
	}
	function px(value) {
		if (typeof value === 'number') return value;
		var match = String(value == null ? '' : value).trim().match(/^(-?[0-9.]+)px$/i);
		return match ? Number(match[1]) : null;
	}
	function closeEnough(actual, expected, tolerance) {
		var a = px(actual), e = px(expected);
		if (a == null || e == null) return String(actual).trim().toLowerCase() === String(expected).trim().toLowerCase();
		return Math.abs(a - e) <= (tolerance == null ? 2 : tolerance);
	}
	function normalizeColor(value, doc) {
		if (!value || !doc) return String(value || '').replace(/\s+/g, '').toLowerCase();
		var probe = doc.createElement('span'); probe.style.color = value; doc.body.appendChild(probe);
		var normalized = (doc.defaultView || window).getComputedStyle(probe).color.replace(/\s+/g, '').toLowerCase();
		probe.remove(); return normalized;
	}
	function add(checks, name, expected, actual, ok, severity, note) {
		checks.push({ name: name, expected: expected, actual: actual, status: ok ? 'pass' : (severity === 'warning' ? 'warning' : 'fail'), severity: severity || 'error', note: note || '' });
	}
	function verifyLayout(checks, css, intent) {
		if (!intent || typeof intent !== 'object') return;
		var direct = { direction: 'flexDirection', justify: 'justifyContent', align: 'alignItems', wrap: 'flexWrap', overflow: 'overflow' };
		Object.keys(direct).forEach(function (key) {
			if (intent[key] == null) return;
			var actual = css[direct[key]]; add(checks, 'layout.' + key, intent[key], actual, String(actual).toLowerCase() === String(intent[key]).toLowerCase(), 'error');
		});
		if (intent.gap != null) {
			var actualGap = css.gap && css.gap !== 'normal' ? css.gap : (css.rowGap || css.columnGap);
			add(checks, 'layout.gap', intent.gap, actualGap, closeEnough(actualGap, intent.gap, 2), 'error');
		}
		if (intent.width != null) add(checks, 'layout.width', intent.width, css.width, closeEnough(css.width, intent.width, 3), 'warning', 'Percent, flex and custom-unit widths can resolve to pixels at runtime.');
		if (intent.minHeight != null) add(checks, 'layout.minHeight', intent.minHeight, css.minHeight, closeEnough(css.minHeight, intent.minHeight, 3), 'warning');
		if (intent.maxWidth != null) add(checks, 'layout.maxWidth', intent.maxWidth, css.maxWidth, closeEnough(css.maxWidth, intent.maxWidth, 3), 'warning');
		if (intent.padding != null && typeof intent.padding === 'string') add(checks, 'layout.padding', intent.padding, css.paddingTop, closeEnough(css.paddingTop, intent.padding, 2), 'warning', 'Single-value padding intent is compared against padding-top.');
		if (intent.margin != null && typeof intent.margin === 'string') add(checks, 'layout.margin', intent.margin, css.marginTop, closeEnough(css.marginTop, intent.margin, 2), 'warning', 'Single-value margin intent is compared against margin-top.');
	}
	function verifyStyle(checks, css, intent, doc) {
		if (!intent || typeof intent !== 'object') return;
		if (intent.borderRadius != null) add(checks, 'style.borderRadius', intent.borderRadius, css.borderTopLeftRadius, closeEnough(css.borderTopLeftRadius, intent.borderRadius, 2), 'warning');
		if (intent.opacity != null) add(checks, 'style.opacity', intent.opacity, css.opacity, Math.abs(Number(css.opacity) - Number(intent.opacity)) <= 0.03, 'warning');
		if (intent.textAlign != null) add(checks, 'style.textAlign', intent.textAlign, css.textAlign, String(css.textAlign).toLowerCase() === String(intent.textAlign).toLowerCase(), 'warning');
		if (intent.fontSize != null) add(checks, 'style.fontSize', intent.fontSize, css.fontSize, closeEnough(css.fontSize, intent.fontSize, 1.5), 'warning');
		if (intent.lineHeight != null) add(checks, 'style.lineHeight', intent.lineHeight, css.lineHeight, closeEnough(css.lineHeight, intent.lineHeight, 2), 'warning');
		if (intent.letterSpacing != null) add(checks, 'style.letterSpacing', intent.letterSpacing, css.letterSpacing, closeEnough(css.letterSpacing, intent.letterSpacing, 1), 'warning');
		if (intent.fontWeight != null) add(checks, 'style.fontWeight', intent.fontWeight, css.fontWeight, String(css.fontWeight) === String(intent.fontWeight), 'warning');
		if (intent.backgroundColor != null) add(checks, 'style.backgroundColor', intent.backgroundColor, css.backgroundColor, normalizeColor(intent.backgroundColor, doc) === normalizeColor(css.backgroundColor, doc), 'warning');
		if (intent.textColor != null) add(checks, 'style.textColor', intent.textColor, css.color, normalizeColor(intent.textColor, doc) === normalizeColor(css.color, doc), 'warning');
	}
	function verifyAccessibility(checks, node, intent) {
		if (!intent || typeof intent !== 'object') return;
		if (intent.ariaLabel != null) {
			var actual = node.getAttribute('aria-label') || '';
			add(checks, 'a11y.ariaLabel', intent.ariaLabel, actual, actual === String(intent.ariaLabel), 'error');
		}
		if (intent.decorative === true) {
			var hidden = node.getAttribute('aria-hidden') === 'true' || (node.tagName === 'IMG' && (node.getAttribute('alt') || '') === '');
			add(checks, 'a11y.decorative', true, hidden, hidden, 'warning', 'Decorative output should be removed from the accessibility tree.');
		}
	}
	function verifyQuality(checks, node, mutationNode) {
		var type = String((mutationNode && (mutationNode.widgetIntent || mutationNode.widgetType || mutationNode.role)) || '').toLowerCase();
		var rect = node.getBoundingClientRect();
		if (/button|cta|action/.test(type)) {
			var touch = rect.width >= 44 && rect.height >= 44;
			add(checks, 'ux.touchTarget', '>=44x44px where touch interaction is expected', Math.round(rect.width) + 'x' + Math.round(rect.height) + 'px', touch, 'warning');
		}
		var overflows = node.scrollWidth > node.clientWidth + 2;
		add(checks, 'ux.horizontalOverflow', false, overflows, !overflows, 'warning');
	}
	function walkMutation(nodes, out) {
		(nodes || []).forEach(function (node) { if (!node || typeof node !== 'object') return; out.push(node); walkMutation(node.children || node.elements || [], out); });
	}
	function resolveId(node, refs) {
		if (node.id) return String(node.id);
		if (node.ref && refs && refs[node.ref]) return String(refs[node.ref]);
		return '';
	}
	function verify(targetId, mutation, resolvedRefs) {
		var doc = previewDocument();
		if (!doc) return { schema: 'cresco-visual-verification/v1', status: 'unavailable', reason: 'Elementor preview iframe is unavailable.', checks: [] };
		var nodes = []; walkMutation(mutation && mutation.nodes || [], nodes);
		var reports = [];
		if (!nodes.length) {
			var target = nodeById(targetId);
			return { schema: 'cresco-visual-verification/v1', status: target ? 'snapshot-only' : 'unavailable', targetId: targetId, checks: [], reason: 'No semantic design nodes were present to compare.' };
		}
		nodes.forEach(function (mutationNode) {
			var id = resolveId(mutationNode, resolvedRefs || {}); if (!id) return;
			var node = nodeById(id);
			if (!node) { reports.push({ elementId: id, ref: mutationNode.ref || '', status: 'unavailable', checks: [], reason: 'Rendered element was not found in the preview.' }); return; }
			var css = (doc.defaultView || window).getComputedStyle(node), checks = [];
			verifyLayout(checks, css, mutationNode.layoutIntent || {});
			verifyStyle(checks, css, mutationNode.styleIntent || {}, doc);
			verifyAccessibility(checks, node, mutationNode.accessibilityIntent || {});
			verifyQuality(checks, node, mutationNode);
			var failures = checks.filter(function (item) { return item.status === 'fail'; }).length;
			var warnings = checks.filter(function (item) { return item.status === 'warning'; }).length;
			reports.push({ elementId: id, ref: mutationNode.ref || '', status: failures ? 'mismatch' : (warnings ? 'partial' : 'pass'), checks: checks });
		});
		var all = reports.reduce(function (sum, report) { return sum.concat(report.checks || []); }, []);
		var failCount = all.filter(function (item) { return item.status === 'fail'; }).length;
		var warningCount = all.filter(function (item) { return item.status === 'warning'; }).length;
		var passCount = all.filter(function (item) { return item.status === 'pass'; }).length;
		return { schema: 'cresco-visual-verification/v1', status: failCount ? 'mismatch' : (warningCount ? 'partial' : (all.length ? 'pass' : 'unavailable')), targetId: targetId, summary: { pass: passCount, warning: warningCount, fail: failCount, checked: all.length }, elements: reports, checks: all, note: 'Rendered geometry/computed-style verification; not a claim of pixel-perfect image similarity.' };
	}

	function extractMutation(raw) {
		if (!raw) return null;
		try {
			var parsed = JSON.parse(raw); var depth = 0;
			while (parsed && typeof parsed === 'object' && !parsed.schema && depth++ < 5) parsed = parsed.result || parsed.data || parsed.output || parsed.response || parsed.payload || parsed.aiResult || parsed;
			return parsed && (parsed.schema === 'cresco-ai-mutation/v3' || parsed.schema === 'cresco-ai-mutation/v2') ? parsed : null;
		} catch (e) { return null; }
	}
	function isApply(input) {
		var url = typeof input === 'string' ? input : (input && input.url ? String(input.url) : '');
		return !!url && root() && url.indexOf(root() + '/documents/') === 0 && /\/apply(?:\?|$)/.test(url);
	}
	function captureApplyRequest(init) {
		if (!init || typeof init.body !== 'string') return null;
		try {
			var body = JSON.parse(init.body); var mutation = extractMutation(body.aiResult || '');
			return mutation ? { mutation: mutation, targetId: String(body.selectedElementId || (mutation.target && mutation.target.id) || '') } : null;
		} catch (e) { return null; }
	}
	function enableButton() {
		var button = document.querySelector('[data-cresco-visual-verify]'); if (button) button.disabled = !state.mutation;
	}
	if (upstreamFetch) {
		window.fetch = function (input, init) {
			if (!isApply(input)) return upstreamFetch(input, init);
			var pending = captureApplyRequest(init);
			return upstreamFetch(input, init).then(function (response) {
				if (!response.ok || !pending) return response;
				return response.clone().json().then(function (body) {
					state.mutation = pending.mutation; state.targetId = pending.targetId; state.resolvedRefs = body && body.aiImport && body.aiImport.resolvedRefs ? body.aiImport.resolvedRefs : {}; state.lastResult = null; enableButton(); return response;
				}).catch(function () { return response; });
			});
		};
	}
	function renderResult(result) {
		var wrap = document.querySelector('[data-cresco-ai-preview]'); if (!wrap || !result) return;
		var summary = result.summary || {}; var old = wrap.querySelector('[data-cresco-visual-verification-result]'); if (old) old.remove();
		var box = document.createElement('div'); box.setAttribute('data-cresco-visual-verification-result', ''); box.style.marginTop = '10px';
		box.innerHTML = '<strong>Rendered verification: ' + String(result.status || 'unavailable') + '</strong><small style="display:block;margin-top:4px">' + Number(summary.pass || 0) + ' pass · ' + Number(summary.warning || 0) + ' warnings · ' + Number(summary.fail || 0) + ' mismatches.</small>';
		wrap.appendChild(box);
	}
	function injectButton() {
		var panel = document.getElementById('cresco-ai-panel'); if (!panel || panel.querySelector('[data-cresco-visual-verify]')) return;
		var actions = panel.querySelector('[data-cresco-ai-pane="import"] .cresco-ai-actions'); if (!actions) return;
		var button = document.createElement('button'); button.type = 'button'; button.className = 'cresco-ai-secondary'; button.setAttribute('data-cresco-visual-verify', ''); button.textContent = 'Verify Render'; button.disabled = !state.mutation;
		button.addEventListener('click', function () { state.lastResult = verify(state.targetId, state.mutation, state.resolvedRefs); renderResult(state.lastResult); });
		actions.appendChild(button);
	}
	function boot() {
		injectButton();
		if (window.MutationObserver && document.documentElement) new MutationObserver(function () { injectButton(); enableButton(); }).observe(document.documentElement, { childList: true, subtree: true });
	}

	window.CrescoLayerVisualVerification = { version: '1.1.0', verify: verify, captureNode: nodeById, getLastResult: function () { return state.lastResult; }, getLastApply: function () { return { targetId: state.targetId, resolvedRefs: state.resolvedRefs, hasMutation: !!state.mutation }; } };
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true }); else boot();
}());
