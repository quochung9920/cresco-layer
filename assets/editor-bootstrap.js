(function () {
	'use strict';

	var cfg = window.crescoLayerEditor || {};
	var bridge = window.CrescoLayerEditorBridge = window.CrescoLayerEditorBridge || {};
	var timeoutMs = Math.max(2000, Number(cfg.bootstrap && cfg.bootstrap.elementorReadyTimeoutMs || 8000));
	var state = {
		status: 'booting',
		activated: false,
		passive: false,
		loading: null,
		loaded: false,
		lastError: '',
		contextTargetId: '',
		hooksObject: null,
		hooksInstalled: false
	};

	var exchangeScripts = [
		'exact-runtime-export.js',
		'fidelity-engine.js',
		'fidelity-export.js',
		'ai-context-v3.js',
		'external-ai-intelligence.js',
		'design-intelligence.js',
		'semantic-design-contract.js',
		'design-reasoning.js',
		'ai-context-policy.js',
		'ai-bundle.js',
		'external-ai-exchange-policy.js',
		'visual-verification.js',
		'fidelity-verification.js',
		'ai-panel.js'
	];

	function assetUrl(file) {
		var base = String(cfg.assetBaseUrl || '');
		if (base && base.charAt(base.length - 1) !== '/') base += '/';
		return base + file + (cfg.version ? ('?ver=' + encodeURIComponent(cfg.version)) : '');
	}

	function ready() {
		return !!(
			window.elementor &&
			elementor.hooks &&
			typeof elementor.hooks.addFilter === 'function'
		);
	}

	function modelId(model) {
		if (!model) return '';
		var id = model.id || (model.attributes && model.attributes.id) || '';
		if (!id && typeof model.get === 'function') id = model.get('id') || '';
		return /^[A-Za-z0-9_-]{1,64}$/.test(String(id || '')) ? String(id) : '';
	}

	function selectedId() {
		if (state.contextTargetId) return state.contextTargetId;
		try {
			if (window.elementor && elementor.selection && typeof elementor.selection.getElements === 'function') {
				var selected = elementor.selection.getElements();
				if (selected && selected.length) {
					var item = selected[selected.length - 1];
					var selectionId = modelId(item) || modelId(item && item.model);
					if (selectionId) return selectionId;
				}
			}
		} catch (e) {}
		try {
			if (window.elementor && elementor.channels && elementor.channels.editor) {
				var legacy = elementor.channels.editor.request('selectedElement');
				var legacyId = modelId(legacy && (legacy.model || legacy));
				if (legacyId) return legacyId;
			}
		} catch (e2) {}
		return '';
	}

	bridge.getDiagnostics = function () {
		return {
			version: cfg.version || '',
			state: state.status,
			postId: Number(cfg.postId || 0),
			elementorPresent: !!window.elementor,
			hooksReady: ready(),
			hooksInstalled: state.hooksInstalled,
			selectedElementId: selectedId(),
			safeLazyBootstrap: true
		};
	};

	function loadScript(file) {
		return new Promise(function (resolve, reject) {
			var key = 'cresco-lazy-' + file.replace(/[^A-Za-z0-9_-]+/g, '-');
			var existing = document.querySelector('script[data-cresco-lazy="' + key + '"]');
			if (existing) {
				if (existing.dataset.crescoLoaded === '1') { resolve(); return; }
				existing.addEventListener('load', function () { resolve(); }, { once: true });
				existing.addEventListener('error', function () { reject(new Error('Could not load ' + file + '.')); }, { once: true });
				return;
			}
			var script = document.createElement('script');
			script.src = assetUrl(file);
			script.async = false;
			script.defer = false;
			script.dataset.crescoLazy = key;
			script.addEventListener('load', function () { script.dataset.crescoLoaded = '1'; resolve(); }, { once: true });
			script.addEventListener('error', function () { reject(new Error('Could not load ' + file + '.')); }, { once: true });
			(document.head || document.documentElement).appendChild(script);
		});
	}

	function bindPanelLifecycle() {
		var box = document.getElementById('cresco-ai-panel');
		if (box && !box.dataset.crescoSafeLifecycleBound) {
			box.dataset.crescoSafeLifecycleBound = '1';
			var close = box.querySelector ? box.querySelector('[data-cresco-ai-close]') : null;
			if (close) close.addEventListener('click', function () { state.contextTargetId = ''; });
		}
		var panelLauncher = document.getElementById('cresco-ai-launcher');
		if (panelLauncher && !panelLauncher.dataset.crescoSafeLifecycleBound) {
			panelLauncher.dataset.crescoSafeLifecycleBound = '1';
			panelLauncher.addEventListener('click', function () { state.contextTargetId = ''; }, true);
		}
	}

	function ensureExchange() {
		if (state.loaded && window.CrescoLayerAIPanel) return Promise.resolve(window.CrescoLayerAIPanel);
		if (state.loading) return state.loading;
		state.status = 'loading-exchange';
		state.loading = exchangeScripts.reduce(function (promise, file) {
			return promise.then(function () { return loadScript(file); });
		}, Promise.resolve()).then(function () {
			if (!window.CrescoLayerAIPanel || typeof window.CrescoLayerAIPanel.open !== 'function') {
				throw new Error('Cresco external exchange panel did not initialize.');
			}
			state.loaded = true;
			state.status = 'ready';
			bindPanelLifecycle();
			var safeLauncher = document.getElementById('cresco-safe-launcher');
			if (safeLauncher) safeLauncher.hidden = true;
			return window.CrescoLayerAIPanel;
		}).catch(function (error) {
			state.lastError = error && error.message ? error.message : String(error);
			state.status = 'exchange-error';
			state.loading = null;
			throw error;
		});
		return state.loading;
	}

	function launcher() {
		var existing = document.getElementById('cresco-safe-launcher');
		if (existing) return existing;
		if (!document.body) return null;
		var button = document.createElement('button');
		button.id = 'cresco-safe-launcher';
		button.type = 'button';
		button.className = 'cresco-ai-launcher';
		button.innerHTML = '<span>&harr;</span> Cresco Export / Import';
		button.addEventListener('click', function () { open('export', '').catch(function () {}); });
		document.body.appendChild(button);
		return button;
	}

	function setLauncherBusy(busy) {
		var button = document.getElementById('cresco-safe-launcher');
		if (!button) return;
		button.disabled = !!busy;
		if (busy) {
			if (!button.dataset.crescoLabel) button.dataset.crescoLabel = button.innerHTML;
			button.textContent = 'Loading Cresco...';
		} else if (button.dataset.crescoLabel) {
			button.innerHTML = button.dataset.crescoLabel;
			delete button.dataset.crescoLabel;
		}
	}

	function open(tab, contextTargetId) {
		state.contextTargetId = /^[A-Za-z0-9_-]{1,64}$/.test(String(contextTargetId || '')) ? String(contextTargetId) : '';
		if (state.passive || !state.activated || !ready()) {
			state.lastError = 'Elementor is not ready. Cresco stayed passive to avoid blocking the editor.';
			return Promise.reject(new Error(state.lastError));
		}
		setLauncherBusy(true);
		return ensureExchange().then(function (panel) {
			panel.open(tab === 'import' ? 'import' : 'export');
			return panel;
		}).catch(function (error) {
			try { console.error('[Cresco Safe Bootstrap]', error); } catch (e) {}
			throw error;
		}).finally(function () { setLauncherBusy(false); });
	}

	function viewId(view) {
		try { return modelId(view && view.model); } catch (e) { return ''; }
	}

	function contextGroup(view) {
		var targetId = viewId(view);
		return {
			name: 'cresco-layer-ai-exchange',
			actions: [
				{
					name: 'cresco-export-external-ai',
					icon: 'eicon-export-kit',
					title: 'Cresco - Export to ChatGPT',
					isEnabled: function () { return true; },
					callback: function () { open('export', targetId).catch(function () {}); }
				},
				{
					name: 'cresco-import-external-ai',
					icon: 'eicon-import-kit',
					title: 'Cresco - Import AI Result',
					isEnabled: function () { return true; },
					callback: function () { open('import', targetId).catch(function () {}); }
				}
			]
		};
	}

	function replaceGroup(groups, type, view) {
		if (!Array.isArray(groups) || type === 'document') return groups;
		var out = [], inserted = false;
		groups.forEach(function (item) {
			if (item && item.name === 'cresco-layer-ai-exchange') {
				if (!inserted) { out.push(contextGroup(view)); inserted = true; }
				return;
			}
			out.push(item);
		});
		if (!inserted) out.push(contextGroup(view));
		return out;
	}

	function installContextMenu() {
		if (!ready()) return false;
		if (state.hooksInstalled && state.hooksObject === elementor.hooks) return true;
		state.hooksObject = elementor.hooks;
		state.hooksInstalled = true;
		var types = ['widget', 'container', 'section', 'column', 'e-div-block', 'e-flexbox', 'e-grid'];
		elementor.hooks.addFilter('elements/context-menu/groups', function (groups, type, view) { return replaceGroup(groups, type, view); });
		types.forEach(function (type) {
			elementor.hooks.addFilter('elements/' + type + '/contextMenuGroups', function (groups, view) { return replaceGroup(groups, type, view); });
		});
		return true;
	}

	function activate() {
		if (state.passive || state.activated || !ready()) return false;
		state.activated = true;
		state.status = 'ready';
		launcher();
		installContextMenu();
		try { window.dispatchEvent(new CustomEvent('cresco-layer:safe-bootstrap-ready')); } catch (e) {}
		return true;
	}

	function start() {
		if (cfg.safeMode) { state.passive = true; state.status = 'safe-mode'; return; }
		if (activate()) return;

		window.addEventListener('elementor/init', function () { activate(); }, { once: true });
		window.addEventListener('load', function () { activate(); }, { once: true });

		// One bounded watchdog only. No setInterval, no DOM observer, no infinite retries.
		setTimeout(function () {
			if (state.activated || state.passive) return;
			if (activate()) return;
			state.passive = true;
			state.status = 'passive-timeout';
			state.lastError = 'Elementor did not become ready within the Cresco bootstrap budget. Cresco stopped itself and left the editor untouched.';
			try { console.warn('[Cresco Safe Bootstrap] ' + state.lastError); } catch (e) {}
		}, timeoutMs);
	}

	window.CrescoLayerSafeBootstrap = {
		version: '1.2.0',
		mode: 'safe-lazy',
		open: open,
		ensure: function (group) { return group === 'exchange' ? ensureExchange() : Promise.resolve(); },
		getState: function () {
			return {
				status: state.status,
				activated: state.activated,
				passive: state.passive,
				loaded: state.loaded,
				lastError: state.lastError,
				selectedElementId: selectedId(),
				hooksInstalled: state.hooksInstalled
			};
		}
	};

	start();
}());
