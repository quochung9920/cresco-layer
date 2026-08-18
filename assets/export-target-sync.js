(function () {
	'use strict';

	var cfg = window.crescoLayerEditor || {};
	var bridge = window.CrescoLayerEditorBridge || {};
	var MAX_STATUS_ATTEMPTS = 4;
	var RETRY_DELAYS = [160, 320, 640, 1000];
	var state = {
		inFlight: false,
		lastStatus: null,
		lastError: '',
		lastAutosave: 'not-run',
		lastTarget: '',
		lastScope: ''
	};

	function root() { return String(cfg.restRoot || '').replace(/\/$/, ''); }
	function postId() {
		var value = parseInt(cfg.postId || 0, 10);
		if (value) return value;
		try { return parseInt(window.elementor && elementor.config && elementor.config.document && elementor.config.document.id || 0, 10) || 0; }
		catch (e) { return 0; }
	}
	function selectedId() {
		try {
			var diagnostics = bridge.getDiagnostics ? bridge.getDiagnostics() : null;
			if (diagnostics && diagnostics.selectedElementId) return String(diagnostics.selectedElementId);
		} catch (e) {}
		try {
			var selected = window.elementor && elementor.channels && elementor.channels.editor ? elementor.channels.editor.request('selectedElement') : null;
			var model = selected && (selected.model || selected);
			var id = model && (model.id || (typeof model.get === 'function' ? model.get('id') : ''));
			if (id) return String(id);
		} catch (e2) {}
		return '';
	}
	function clientTargetPresent(targetId) {
		if (!targetId) return null;
		var inspected = false;
		try {
			if (window.elementor && typeof window.elementor.getContainer === 'function') {
				inspected = true;
				if (window.elementor.getContainer(String(targetId))) return true;
			}
		} catch (e) {}
		try {
			var components = window.$e && window.$e.components;
			var component = components && typeof components.get === 'function' ? components.get('document/elements') : null;
			var finder = component && component.utils && component.utils.findContainerById;
			if (typeof finder === 'function') {
				inspected = true;
				if (finder(String(targetId))) return true;
			}
		} catch (e2) {}
		return inspected ? false : null;
	}
	function currentScope() {
		var checked = document.querySelector('#cresco-ai-panel input[name="cresco-export-scope"]:checked');
		return checked ? String(checked.value || 'subtree') : 'subtree';
	}
	function exportButton(node) {
		if (!node || typeof node.closest !== 'function') return null;
		return node.closest('[data-cresco-export-bundle],[data-cresco-export-json]');
	}
	function wait(ms) { return new Promise(function (resolve) { setTimeout(resolve, ms); }); }
	function setStatus(message) {
		var wrap = document.querySelector('#cresco-ai-panel [data-cresco-ai-quality]');
		var small = wrap && wrap.querySelector('small');
		if (small) small.textContent = String(message || '');
	}
	function toast(message, tone) {
		var el = document.getElementById('cresco-ai-panel-toast');
		if (!el) {
			el = document.createElement('div');
			el.id = 'cresco-ai-panel-toast';
			document.body.appendChild(el);
		}
		el.className = 'cresco-ai-panel-toast is-' + (tone || 'info');
		el.textContent = String(message || '');
		el.hidden = false;
		clearTimeout(el._crescoTargetSyncTimer);
		el._crescoTargetSyncTimer = setTimeout(function () { el.hidden = true; }, 6500);
	}
	function targetStatus(pid, scope, targetId) {
		var url = root() + '/documents/' + encodeURIComponent(pid) + '/export-target-status?scope=' + encodeURIComponent(scope);
		if (scope !== 'document') {
			url += '&selected=' + encodeURIComponent(targetId);
			var present = clientTargetPresent(targetId);
			if (present !== null) url += '&client_present=' + (present ? '1' : '0');
		}
		return window.fetch(url, {
			method: 'GET',
			headers: { 'X-WP-Nonce': cfg.nonce || '', 'Content-Type': 'application/json' }
		}).then(function (response) {
			return response.json().catch(function () { return {}; }).then(function (body) {
				if (!response.ok) throw new Error(body.message || ('Export target preflight failed (' + response.status + ').'));
				return body;
			});
		});
	}
	function forceAutosave() {
		if (!window.$e || typeof window.$e.run !== 'function') {
			state.lastAutosave = 'unavailable';
			return Promise.resolve({ available: false });
		}
		state.lastAutosave = 'running';
		setStatus('Synchronizing the latest Elementor changes...');
		var result;
		try {
			result = window.$e.run('document/save/auto', { force: true });
		} catch (error) {
			state.lastAutosave = 'failed';
			state.lastError = error && error.message ? error.message : String(error);
			return Promise.resolve({ available: true, ok: false, error: state.lastError });
		}
		return Promise.resolve(result).then(function () {
			state.lastAutosave = 'completed';
			return { available: true, ok: true };
		}).catch(function (error) {
			state.lastAutosave = 'failed';
			state.lastError = error && error.message ? error.message : String(error);
			return { available: true, ok: false, error: state.lastError };
		});
	}
	function waitForServerTarget(pid, scope, targetId) {
		var attempt = 0;
		function check() {
			return targetStatus(pid, scope, targetId).then(function (status) {
				state.lastStatus = status;
				if (status && status.ready) return status;
				if (status && status.retryable === false) return status;
				if (attempt >= MAX_STATUS_ATTEMPTS - 1) return status;
				var delay = RETRY_DELAYS[Math.min(attempt, RETRY_DELAYS.length - 1)];
				attempt += 1;
				setStatus('Waiting for Elementor autosave to reach the server...');
				return wait(delay).then(check);
			});
		}
		return check();
	}
	function diagnosticMessage(status, autosave) {
		if (!autosave.available) return 'Cresco could not access Elementor autosave. Your element was not changed. Save/Update the page once, then export again.';
		if (autosave.ok === false) return 'Elementor could not finish its autosave before export. Your element was not changed. Try Save/Update once, then export again.';
		if (status && status.state === 'stale-target') return 'The selected Elementor target no longer exists in the live editor. Select the current element again before exporting.';
		if (status && status.state === 'sync-required') return 'Elementor autosave is still behind the selected element. Cresco stopped the export rather than sending stale data to ChatGPT.';
		if (status && status.state === 'sync-pending') return 'The selected element exists in Elementor, but its ID has not reached the server working document yet. Cresco stopped safely; wait a moment or Save/Update once, then export again.';
		return 'Cresco could not confirm the selected target in server-side Elementor data. Re-select the element, then export again.';
	}
	function preflight() {
		var pid = postId();
		var scope = currentScope();
		var targetId = scope === 'document' ? '' : selectedId();
		state.lastTarget = targetId;
		state.lastScope = scope;
		state.lastError = '';
		if (!pid) return Promise.reject(new Error('Cannot determine the current Elementor document.'));
		if (scope !== 'document' && !targetId) return Promise.reject(new Error('Select the Elementor element you want to export, or choose Entire page.'));

		if (scope !== 'document' && clientTargetPresent(targetId) === false) {
			state.lastStatus = {
				schema: 'cresco-export-target-status/v1',
				postId: pid,
				scope: scope,
				selectedIds: [targetId],
				clientPresent: false,
				state: 'stale-target',
				ready: false,
				retryable: false,
				recommendedAction: 'reselect-target'
			};
			return Promise.reject(new Error('The selected Elementor target no longer exists in the live editor. Select the current element again before exporting.'));
		}

		return forceAutosave().then(function (autosave) {
			return waitForServerTarget(pid, scope, targetId).then(function (status) {
				if (!status || !status.ready) throw new Error(diagnosticMessage(status, autosave));
				setStatus('Elementor synchronized. Preparing Exact Runtime export...');
				return status;
			});
		});
	}
	function restoreButton(button) {
		if (!button) return;
		if (button.dataset.crescoSyncOriginalLabel) {
			button.textContent = button.dataset.crescoSyncOriginalLabel;
			delete button.dataset.crescoSyncOriginalLabel;
		}
		button.removeAttribute('aria-busy');
	}
	function guardExport(event) {
		var button = exportButton(event.target);
		if (!button) return;
		if (button.dataset.crescoSyncPass === '1') {
			delete button.dataset.crescoSyncPass;
			return;
		}
		if (state.inFlight) {
			event.preventDefault();
			event.stopImmediatePropagation();
			return;
		}

		event.preventDefault();
		event.stopImmediatePropagation();
		state.inFlight = true;
		button.dataset.crescoSyncOriginalLabel = button.textContent || '';
		button.textContent = 'Synchronizing Elementor...';
		button.setAttribute('aria-busy', 'true');

		preflight().then(function () {
			restoreButton(button);
			button.dataset.crescoSyncPass = '1';
			button.click();
		}).catch(function (error) {
			state.lastError = error && error.message ? error.message : String(error);
			restoreButton(button);
			setStatus(state.lastError);
			toast(state.lastError, 'error');
		}).finally(function () {
			state.inFlight = false;
		});
	}

	document.addEventListener('click', guardExport, true);

	window.CrescoLayerExportTargetSync = {
		version: '1.1.1',
		schema: 'cresco-export-target-sync/v1',
		preflight: preflight,
		getClientTargetPresent: clientTargetPresent,
		getState: function () {
			return {
				inFlight: state.inFlight,
				lastStatus: state.lastStatus,
				lastError: state.lastError,
				lastAutosave: state.lastAutosave,
				lastTarget: state.lastTarget,
				lastScope: state.lastScope
			};
		}
	};
}());
