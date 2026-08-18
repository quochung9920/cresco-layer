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
	function withClientPresence(input) {
		if (typeof input !== 'string' || !isExport(input) || /[?&]client_present=/.test(input)) return input;
		var match = input.match(/[?&]selected=([^&]*)/);
		if (!match || !match[1]) return input;
		var targetId = '';
		try { targetId = decodeURIComponent(match[1]); } catch (e) { targetId = match[1]; }
		if (!targetId) return input;
		var sync = window.CrescoLayerExportTargetSync;
		if (!sync || typeof sync.getClientTargetPresent !== 'function') return input;
		var present = sync.getClientTargetPresent(targetId);
		if (present === null || typeof present === 'undefined') return input;
		return input + (input.indexOf('?') === -1 ? '?' : '&') + 'client_present=' + (present ? '1' : '0');
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
	function diagnosticText(entry) {
		try { return JSON.stringify(entry || {}, null, 2); } catch (e) { return String(entry || ''); }
	}
	function copyText(text) {
		try {
			if (window.navigator && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') return navigator.clipboard.writeText(text);
		} catch (e) {}
		return Promise.reject(new Error('Clipboard API unavailable.'));
	}
	function render(entry, message, recovered) {
		if (!window.document || !document.getElementById) return;
		var panel = document.getElementById('cresco-ai-panel');
		if (!panel) return;
		var pane = panel.querySelector && panel.querySelector('[data-cresco-ai-pane="export"]');
		if (!pane) return;
		var card = document.getElementById('cresco-export-diagnostic-card');
		if (!card) {
			card = document.createElement('div');
			card.id = 'cresco-export-diagnostic-card';
			card.className = 'cresco-ai-preview cresco-export-diagnostic-card';
			var actions = pane.querySelector('.cresco-ai-actions');
			if (actions && actions.parentNode) actions.parentNode.insertBefore(card, actions.nextSibling);
			else pane.appendChild(card);
		}
		card.hidden = false;
		while (card.firstChild) card.removeChild(card.firstChild);
		var title = document.createElement('strong');
		title.textContent = recovered ? 'Export recovered automatically' : 'Export diagnostic';
		card.appendChild(title);
		var summary = document.createElement('div');
		summary.textContent = String(message || (recovered ? 'Cresco retried with a lighter server context and kept Exact Runtime validation.' : 'Cresco captured the export failure.'));
		card.appendChild(summary);
		var meta = document.createElement('small');
		var bits = [];
		if (entry && entry.stage) bits.push('Stage: ' + entry.stage);
		if (entry && entry.errorId) bits.push('Error ID: ' + entry.errorId);
		if (entry && entry.status != null) bits.push('HTTP: ' + entry.status);
		if (entry && entry.targetStatus && entry.targetStatus.state) bits.push('Target: ' + entry.targetStatus.state);
		var memory = entry && entry.server && entry.server.memory ? entry.server.memory : null;
		if (memory && memory.peakBytes) bits.push('Peak memory: ' + Math.round(Number(memory.peakBytes) / 1048576) + ' MB / ' + String(memory.limit || '?'));
		var fatal = entry && entry.server && entry.server.fatal ? entry.server.fatal : null;
		if (fatal && fatal.file) bits.push('Fatal: ' + fatal.file + ':' + String(fatal.line || ''));
		meta.textContent = bits.join(' | ');
		card.appendChild(meta);
		var button = document.createElement('button');
		button.type = 'button';
		button.className = 'cresco-ai-secondary';
		button.textContent = 'Copy diagnostics';
		button.addEventListener('click', function () {
			copyText(diagnosticText(entry)).then(function () { button.textContent = 'Copied'; }).catch(function () { button.textContent = 'Copy failed - use getLastError()'; });
		});
		card.appendChild(button);
	}
	function remember(entry, message, recovered) {
		history.unshift(entry);
		if (history.length > MAX_HISTORY) history.length = MAX_HISTORY;
		try { console.error('[Cresco Export Diagnostics]', entry); } catch (e) {}
		try { window.dispatchEvent(new CustomEvent('cresco-layer:export-diagnostic', { detail: entry })); } catch (e2) {}
		render(entry, message, recovered);
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
	function targetStatusFrom(parsed) {
		return parsed && parsed.data && parsed.data.targetStatus && typeof parsed.data.targetStatus === 'object' ? parsed.data.targetStatus : null;
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
	function parseResponse(response) {
		return response.clone().text().catch(function () { return ''; }).then(function (text) {
			var parsed = null;
			try { parsed = text ? JSON.parse(text) : null; } catch (e) {}
			return { text: text, parsed: parsed };
		});
	}
	function errorEntry(response, parsed, text, requestId, startedAt) {
		var serverDiagnostic = diagnosticFrom(parsed) || {};
		var serverId = serverDiagnostic.errorId || response.headers.get('x-cresco-request-id') || requestId;
		var stage = serverDiagnostic.stage || response.headers.get('x-cresco-diagnostic-stage') || '';
		if (!stage && parsed && parsed.code === 'cresco_exact_runtime_export_failed') stage = 'exact-runtime-enrich';
		if (!stage) stage = response.ok ? 'response' : 'http-response';
		var effectiveStatus = response.ok && isServerFailurePayload(parsed) ? Number(parsed.data && parsed.data.status || 500) : response.status;
		var entry = {
			schema: 'cresco-export-client-diagnostic/v1',
			errorId: serverId,
			stage: stage,
			status: effectiveStatus,
			transportStatus: response.status,
			elapsedMs: Math.max(0, Date.now() - startedAt),
			server: serverDiagnostic,
			responseExcerpt: excerpt(text)
		};
		var targetStatus = targetStatusFrom(parsed);
		if (targetStatus) entry.targetStatus = targetStatus;
		return entry;
	}
	function recoveryUrl(input) {
		var url = urlOf(input);
		if (!url || url.indexOf('cresco_recovery=1') !== -1 || !/[?&]context=full(?:&|$)/.test(url)) return '';
		url = url.replace(/([?&])context=full(?=&|$)/, '$1context=smart');
		url += (url.indexOf('?') === -1 ? '?' : '&') + 'cresco_recovery=1';
		return url;
	}
	function canRecover(input, init, entry) {
		var method = String(init && init.method || 'GET').toUpperCase();
		if (method !== 'GET' || !entry || Number(entry.status || 0) < 500) return false;
		if (entry.stage === 'exact-runtime-enrich' || entry.stage === 'network' || entry.stage === 'target-sync-gate') return false;
		if (entry.targetStatus) return false;
		return !!recoveryUrl(input);
	}
	function normalizeFailure(response, parsed, text, entry) {
		var serverId = entry.errorId, stage = entry.stage;
		if (response.ok && isServerFailurePayload(parsed)) {
			return responseWithJson(response, parsed, serverId, stage, Number(parsed.data && parsed.data.status || 500));
		}
		if (parsed && parsed.message) {
			if (serverId && String(parsed.message).indexOf(serverId) === -1) {
				parsed.message = String(parsed.message) + ' [' + stage + ' | ' + serverId + ']';
				return responseWithJson(response, parsed, serverId, stage);
			}
			return response;
		}
		var message = 'Cresco export failed at ' + stage + ' [' + serverId + '] (HTTP ' + entry.status + ').';
		var short = excerpt(text);
		if (short) message += ' Server response: ' + short;
		return responseWithJson(response, {
			code: 'cresco_export_http_error',
			message: message,
			data: { status: entry.status, crescoDiagnostic: entry }
		}, serverId, stage, entry.status);
	}

	if (previousFetch) {
		window.fetch = function (input, init) {
			var effectiveInput = withClientPresence(input);
			if (!isExport(effectiveInput)) return previousFetch(effectiveInput, init);

			var requestId = makeId();
			var startedAt = Date.now();
			var nextInit = Object.assign({}, init || {});
			nextInit.headers = headersFor(effectiveInput, init, requestId);

			return previousFetch(effectiveInput, nextInit).then(function (response) {
				return parseResponse(response).then(function (first) {
					var firstFailed = !response.ok || isServerFailurePayload(first.parsed);
					if (!firstFailed) return response;
					var firstEntry = errorEntry(response, first.parsed, first.text, requestId, startedAt);

					if (canRecover(effectiveInput, nextInit, firstEntry)) {
						var retryUrl = recoveryUrl(effectiveInput);
						var recoveryId = requestId + '-R1';
						var recoveryInit = Object.assign({}, nextInit, { headers: headersFor(effectiveInput, nextInit, recoveryId) });
						return previousFetch(retryUrl, recoveryInit).then(function (retryResponse) {
							return parseResponse(retryResponse).then(function (second) {
								var secondFailed = !retryResponse.ok || isServerFailurePayload(second.parsed);
								if (!secondFailed) {
									var payload = second.parsed;
									if (payload && typeof payload === 'object') {
										payload.manifest = payload.manifest || {};
										payload.manifest.exportRecovery = {
											schema: 'cresco-export-recovery/v1', used: true, fromContext: 'full', toContext: 'smart',
											firstErrorId: firstEntry.errorId, firstStage: firstEntry.stage
										};
									}
									var recoveredEntry = {
										schema: 'cresco-export-client-diagnostic/v1', errorId: recoveryId,
										stage: 'recovered-smart-context', status: 200, elapsedMs: Math.max(0, Date.now() - startedAt),
										recovered: true, firstFailure: firstEntry
									};
									remember(recoveredEntry, 'Full Context failed at ' + firstEntry.stage + '. Cresco automatically retried with bounded Smart server context and preserved Exact Runtime validation.', true);
									return payload && typeof payload === 'object' ? responseWithJson(retryResponse, payload, recoveryId, 'recovered-smart-context') : retryResponse;
								}
								var secondEntry = errorEntry(retryResponse, second.parsed, second.text, recoveryId, startedAt);
								secondEntry.recoveryAttempt = { attempted: true, firstFailure: firstEntry };
								remember(secondEntry, second.parsed && second.parsed.message ? second.parsed.message : 'Export failed after automatic recovery retry.', false);
								return normalizeFailure(retryResponse, second.parsed, second.text, secondEntry);
							});
						});
					}

					remember(firstEntry, first.parsed && first.parsed.message ? first.parsed.message : 'Cresco captured the export failure.', false);
					return normalizeFailure(response, first.parsed, first.text, firstEntry);
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
				remember(entry, entry.message, false);
				throw error;
			});
		};
	}

	window.CrescoLayerExportDiagnostics = {
		version: '1.4.0',
		schema: 'cresco-export-client-diagnostic/v1',
		getLastError: function () { return history.length ? history[0] : null; },
		getHistory: function () { return history.slice(); },
		copyLastError: function () {
			if (!history.length) return Promise.reject(new Error('No export diagnostic is available.'));
			return copyText(diagnosticText(history[0]));
		},
		recordClientError: function (entry) {
			entry = entry && typeof entry === 'object' ? entry : { schema: 'cresco-export-client-diagnostic/v1', stage: 'client', status: 0, message: String(entry || 'Client export error.') };
			remember(entry, entry.message || 'Cresco captured a client-side export failure.', false);
			return entry;
		},
		clear: function () { history.length = 0; var card = window.document && document.getElementById ? document.getElementById('cresco-export-diagnostic-card') : null; if (card) card.hidden = true; }
	};
}());
