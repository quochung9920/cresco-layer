(function () {
	'use strict';

	var cfg = window.crescoLayerEditor || {};
	var upstreamFetch = typeof window.fetch === 'function' ? window.fetch.bind(window) : null;

	function root() { return String(cfg.restRoot || '').replace(/\/$/, ''); }
	function isExport(input) {
		var url = typeof input === 'string' ? input : (input && input.url ? String(input.url) : '');
		return !!url && !!root() && url.indexOf(root() + '/documents/') === 0 && /\/export(?:\?|$)/.test(url);
	}
	function jsonResponse(original, payload) {
		var headers = new Headers(original.headers || {});
		headers.delete('content-length'); headers.delete('content-encoding'); headers.set('content-type', 'application/json; charset=UTF-8');
		return new Response(JSON.stringify(payload), { status: original.status, statusText: original.statusText, headers: headers });
	}
	function appendInstructions(existing) {
		return String(existing || '').trim() + '\n\n' + [
			'RENDERED FIDELITY CONTEXT:',
			'- visualContext.snapshot is captured from the real Elementor preview iframe with browser-computed geometry and styles.',
			'- Treat geometryGraph parent/child/sibling relationships as authoritative for the current rendered breakpoint.',
			'- Prefer fixing the Elementor control or parent relationship that owns a mismatch instead of compensating with unrelated offsets.',
			'- fidelityPolicy defines weighted categories, tolerances, blocking rules and the minimum verification score.',
			'- The exported visual snapshot represents the current Elementor preview breakpoint only. Do not invent measurements for breakpoints that were not captured.',
			'- Fidelity means deterministic structure plus bounded render error; it is not a promise of identical raster pixels across browsers, operating systems or font renderers.'
		].join('\n');
	}
	function enrich(pkg) {
		if (!pkg || pkg.schema !== 'cresco-layer-ai-package/v2') return pkg;
		var engine = window.CrescoLayerFidelityEngine;
		pkg.fidelityPolicy = engine && typeof engine.getPolicy === 'function' ? engine.getPolicy() : { schema: 'cresco-fidelity-policy/v1', status: 'unavailable' };
		try {
			pkg.visualContext = engine && typeof engine.capturePackage === 'function'
				? engine.capturePackage(pkg)
				: { schema: 'cresco-visual-context/v1', status: 'unavailable', reason: 'Fidelity Engine is unavailable in the Elementor editor.' };
		} catch (error) {
			pkg.visualContext = { schema: 'cresco-visual-context/v1', status: 'unavailable', reason: error && error.message ? String(error.message) : 'Rendered snapshot capture failed.' };
		}
		pkg.capabilities = pkg.capabilities || {};
		pkg.capabilities.computedVisualSnapshot = 'cresco-fidelity-snapshot/v1';
		pkg.capabilities.geometryGraph = 'cresco-geometry-graph/v1';
		pkg.capabilities.fidelityReport = 'cresco-fidelity-report/v1';
		pkg.capabilities.fidelityVerificationGate = 'cresco-fidelity-gate/v1';
		pkg.capabilities.visualSnapshotBreakpointMode = 'current-preview';
		pkg.instructions = appendInstructions(pkg.instructions);
		return pkg;
	}

	if (upstreamFetch) {
		window.fetch = function (input, init) {
			if (!isExport(input)) return upstreamFetch(input, init);
			return upstreamFetch(input, init).then(function (response) {
				if (!response.ok) return response;
				return response.clone().json().then(function (pkg) {
					return jsonResponse(response, enrich(pkg));
				}).catch(function () { return response; });
			});
		};
	}

	window.CrescoLayerFidelityExport = { version: '1.0.0', enrich: enrich };
}());
