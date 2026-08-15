(function () {
	'use strict';

	var cfg = window.crescoLayerEditor || {};
	var upstreamFetch = typeof window.fetch === 'function' ? window.fetch.bind(window) : null;

	function root() { return String(cfg.restRoot || '').replace(/\/$/, ''); }
	function isExport(input) {
		var url = typeof input === 'string' ? input : (input && input.url ? String(input.url) : '');
		return !!url && url.indexOf(root() + '/documents/') === 0 && url.indexOf('/export') !== -1;
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
		contract.rules = Array.isArray(contract.rules) ? contract.rules : [];
		if (mode === 'widget') contract.rules.unshift('Widget target: edit native settings or explicitly rebuild this widget. Do not insert child UI under a widget; select a Container for additions.');
		pkg.outputContract = contract;
		target.canAcceptChildren = mode !== 'widget';
		pkg.target = target;
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

	window.CrescoLayerAIContextPolicy = { version: '1.0.0', patch: patchContract };
}());
