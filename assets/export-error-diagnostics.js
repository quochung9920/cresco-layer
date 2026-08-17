(function () {
	'use strict';

	var cfg = window.crescoLayerEditor || {};
	var previousFetch = typeof window.fetch === 'function' ? window.fetch.bind(window) : null;
	var history = [];
	var MAX_HISTORY = 12;

	function root() { return String(cfg.restRoot || '').replace(/\/$/, ''); }
	function urlOf(input) { return typeof input === 'string' ? input : (input && input.url ? String(input.url) : ''); }
	function isExport(input) {
		var url = urlOf(input);
		return !!url && url.indexOf(root() + '/documents/') === 0 && /\/export(?:\?|$)/.test(url) && url.indexOf('/export-target-status') === -1;
	}
	function makeId() {
		try {
			if (window.crypto && typeof window.crypto.randomUUID === 'function') return 'CX-' + window.crypto.randomUUID().replace(/-/g, '').slice(0, 20);
		} catch (e) {}
		return 'CX-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
	}
	function excerpt(value) {
		var text = String(value || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
		return text.length > 360 ? text.slice(0, 360) + '...' : text;
	}
	function remember(entry) {
		history.unshift(entry);
		if (history.length > MAX_HISTORY) history.length = MAX_HISTORY;
		try { console.error('[Cresco Export Diagnostics]', entry); } catch (e) {}
	}
	function headersFor(input, init, id) {
		var source = init && init.headers ? init.headers : (input && input.headers ? input.headers : {});
		var headers = new Headers(source || {});
		headers.set('X-Cresco-Request-Id', id);
		return headers;
	}
	function diagnosticFrom(parsed) {
		return parsed && parsed.data && parsed.data.crescoDiagnostic ? parsed.data.crescoDiagnostic : (parsed && parsed.crescoDiagnostic ? parsed.crescoDiagnostic : null);
	}
	function responseWithJson(response, payload, id, stage, statusOverride) {
		var headers = new Headers(response.headers || {});
		headers.delete('content-length');
		headers.delete('content-encoding');
		headers.set('content-type', 'application/json; charset=UTF-8');
		headers.set('x-cresco-request-id', id);
		headers.set('x-cresco-diagnostic-stage', stage);
		return new Response(JSON.stringify(payload), {
			status: statusOverride || response.status,
			statusText: response.statusText,
			headers: headers
		});
	}
	function isServerFailurePayload(parsed) {
		if (!parsed || typeof parsed !== 'object') return false;
		if (parsed.code === 'cresco_export_fatal' || parsed.code === 'cresco_export_http_error') return true;
		var status = parsed.data && Number(parsed.data.status || 0);
		return status >= 500 && !!(parsed.message || diagnosticFrom(parsed));
	}

	if (previousFetch) {
		window.fetch = function (input, init) {
			if (!isExport(input)) return previousFetch(input, init);

			var requestId = makeId();
			var startedAt = Date.now();
			var nextInit = Object.assign({}, init || {});
			nextInit.headers = headersFor(input, init, requestId);

			return previousFetch(input, nextInit).then(function (response) {
				return response.clone().text().catch(function () { return ''; }).then(function (text) {
					var parsed = null;
					try { parsed = text ? JSON.parse(text) : null; } catch (e) {}
					var serverDiagnostic = diagnosticFrom(parsed) || {};
					var serverId = serverDiagnostic.errorId || response.headers.get('x-cresco-request-id') || requestId;
					var stage = serverDiagnostic.stage || response.headers.get('x-cresco-diagnostic-stage') || '';
					if (!stage && parsed && parsed.code === 'cresco_exact_runtime_export_failed') stage = 'exact-runtime-enrich';
					if (!stage) stage = response.ok ? 'response' : 'http-response';

					// PHP can fail during REST response serialization after WordPress has already emitted a
					// 200 status header. The fatal shutdown body carries data.status=500; normalize that
					// transport anomaly before Exact Runtime sees the response.
					if (response.ok && isServerFailurePayload(parsed)) {
						var fatalEntry = {
							schema: 'cresco-export-client-diagnostic/v1', errorId: serverId, stage: stage,
							status: 500, transportStatus: response.status, elapsedMs: Math.max(0, Date.now() - startedAt),
							server: serverDiagnostic, responseExcerpt: excerpt(text)
						};
						remember(fatalEntry);
						return responseWithJson(response, parsed, serverId, stage, Number(parsed.data && parsed.data.status || 500));
					}

					if (response.ok) return response;
					var entry = {
						schema: 'cresco-export-client-diagnostic/v1',
						errorId: serverId,
						stage: stage,
						status: response.status,
						elapsedMs: Math.max(0, Date.now() - startedAt),
						server: serverDiagnostic,
						responseExcerpt: excerpt(text)
					};
					remember(entry);

					if (parsed && parsed.message) return response;
					var message = 'Cresco export failed at ' + stage + ' [' + serverId + '] (HTTP ' + response.status + ').';
					var short = excerpt(text);
					if (short) message += ' Server response: ' + short;
					return responseWithJson(response, {
						code: 'cresco_export_http_error',
						message: message,
						data: { status: response.status, crescoDiagnostic: entry }
					}, serverId, stage);
				});
			}).catch(function (error) {
				var entry = {
					schema: 'cresco-export-client-diagnostic/v1',
					errorId: requestId,
					stage: 'network',
					status: 0,
					elapsedMs: Math.max(0, Date.now() - startedAt),
					message: error && error.message ? error.message : String(error)
				};
				remember(entry);
				throw error;
			});
		};
	}

	window.CrescoLayerExportDiagnostics = {
		version: '1.1.0',
		schema: 'cresco-export-client-diagnostic/v1',
		getLastError: function () { return history.length ? history[0] : null; },
		getHistory: function () { return history.slice(); },
		clear: function () { history.length = 0; }
	};
}());
