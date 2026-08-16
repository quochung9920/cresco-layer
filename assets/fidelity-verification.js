(function () {
	'use strict';

	var cfg = window.crescoLayerEditor || {};
	var upstreamFetch = typeof window.fetch === 'function' ? window.fetch.bind(window) : null;
	var state = { lastReport: null, lastVisualResult: null, autoAttempts: 0 };

	function root() { return String(cfg.restRoot || '').replace(/\/$/, ''); }
	function isApply(input) {
		var url = typeof input === 'string' ? input : (input && input.url ? String(input.url) : '');
		return !!url && !!root() && url.indexOf(root() + '/documents/') === 0 && /\/apply(?:\?|$)/.test(url);
	}
	function capturePending(init) {
		if (!init || typeof init.body !== 'string') return null;
		try {
			var body = JSON.parse(init.body), visual = window.CrescoLayerVisualVerification;
			var mutation = visual && typeof visual.extractMutation === 'function' ? visual.extractMutation(body.aiResult || '') : null;
			if (!mutation) return null;
			return { mutation: mutation, targetId: String(body.selectedElementId || (mutation.target && mutation.target.id) || '') };
		} catch (e) { return null; }
	}
	function evaluate(result) {
		var engine = window.CrescoLayerFidelityEngine;
		if (!result || !engine || typeof engine.scoreChecks !== 'function') return result;
		var report = engine.scoreChecks(result.checks || []);
		result.fidelityReport = report;
		result.verificationGate = report.gate;
		state.lastVisualResult = result;
		state.lastReport = report;
		return result;
	}
	function categoryLine(report) {
		var categories = report && report.categories ? report.categories : {};
		return [ 'geometry', 'spacing', 'typography', 'color', 'structure', 'quality' ].map(function (key) {
			return key + ' ' + Number(categories[key] == null ? 100 : categories[key]).toFixed(1);
		}).join(' · ');
	}
	function render(report) {
		var wrap = document.querySelector('[data-cresco-ai-preview]');
		if (!wrap || !report) return;
		var old = wrap.querySelector('[data-cresco-fidelity-result]'); if (old) old.remove();
		var gate = report.gate || {}, box = document.createElement('div');
		box.setAttribute('data-cresco-fidelity-result', '');
		box.setAttribute('data-status', gate.pass ? 'pass' : 'blocked');
		box.style.marginTop = '10px';
		box.innerHTML = '<strong>Fidelity Score: ' + Number(report.overall || 0).toFixed(1) + '/100 · Gate ' + (gate.pass ? 'PASS' : 'BLOCKED') + '</strong>' +
			'<small style="display:block;margin-top:4px">' + categoryLine(report) + '</small>' +
			'<small style="display:block;margin-top:4px">Threshold ' + Number(gate.threshold || 96).toFixed(1) + '. Blocking issues: ' + Number((gate.blockingIssues || []).length) + '.</small>';
		wrap.appendChild(box);
		try {
			window.dispatchEvent(new CustomEvent('cresco-layer:fidelity-verified', { detail: { report: report, gate: gate } }));
		} catch (e) {}
	}
	function verifyNow(pending) {
		var visual = window.CrescoLayerVisualVerification;
		if (!visual || typeof visual.verify !== 'function' || !pending) return null;
		var applyState = typeof visual.getLastApply === 'function' ? visual.getLastApply() : {};
		var resolvedRefs = applyState && applyState.resolvedRefs ? applyState.resolvedRefs : {};
		var result = visual.verify(pending.targetId || applyState.targetId || '', pending.mutation, resolvedRefs);
		if (!result || result.status === 'unavailable') return result;
		evaluate(result); render(result.fidelityReport); return result;
	}
	function scheduleVerification(pending) {
		state.autoAttempts = 0;
		[ 250, 650, 1200 ].forEach(function (delay, index) {
			setTimeout(function () {
				if (state.lastReport && state.autoAttempts > 0) return;
				state.autoAttempts = index + 1;
				var result = verifyNow(pending);
				if (result && result.status !== 'unavailable') state.autoAttempts = 99;
			}, delay);
		});
	}
	function evaluateManualResult() {
		setTimeout(function () {
			var visual = window.CrescoLayerVisualVerification;
			var result = visual && typeof visual.getLastResult === 'function' ? visual.getLastResult() : null;
			if (!result) return;
			evaluate(result); render(result.fidelityReport);
		}, 0);
	}

	if (upstreamFetch) {
		window.fetch = function (input, init) {
			if (!isApply(input)) return upstreamFetch(input, init);
			var pending = capturePending(init);
			state.lastReport = null; state.lastVisualResult = null;
			return upstreamFetch(input, init).then(function (response) {
				if (response.ok && pending) scheduleVerification(pending);
				return response;
			});
		};
	}

	document.addEventListener('click', function (event) {
		var button = event.target && event.target.closest ? event.target.closest('[data-cresco-visual-verify]') : null;
		if (button) evaluateManualResult();
	}, true);

	window.CrescoLayerFidelityVerification = {
		version: '1.0.0',
		evaluate: evaluate,
		verifyNow: verifyNow,
		getLastReport: function () { return state.lastReport; },
		getLastGate: function () { return state.lastReport && state.lastReport.gate ? state.lastReport.gate : null; }
	};
}());
