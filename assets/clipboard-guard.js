(function () {
	'use strict';

	var nav = window.navigator;
	var clipboard = nav && nav.clipboard ? nav.clipboard : null;
	if (!clipboard || typeof clipboard.writeText !== 'function') return;

	var nativeWriteText = clipboard.writeText.bind(clipboard);
	var state = {
		installed: false,
		lastMethod: '',
		lastError: ''
	};

	function restoreFocus(element) {
		if (!element || typeof element.focus !== 'function') return;
		try { element.focus({ preventScroll: true }); }
		catch (error) {
			try { element.focus(); } catch (ignored) {}
		}
	}

	function legacyCopy(text) {
		return new Promise(function (resolve, reject) {
			if (!document.body || typeof document.createElement !== 'function' || typeof document.execCommand !== 'function') {
				reject(new Error('Legacy clipboard fallback is unavailable.'));
				return;
			}

			var active = document.activeElement;
			var area = document.createElement('textarea');
			area.value = String(text == null ? '' : text);
			area.setAttribute('readonly', '');
			area.setAttribute('aria-hidden', 'true');
			area.style.position = 'fixed';
			area.style.left = '-10000px';
			area.style.top = '0';
			area.style.opacity = '0';
			area.style.pointerEvents = 'none';
			document.body.appendChild(area);

			try {
				if (typeof window.focus === 'function') window.focus();
				try { area.focus({ preventScroll: true }); }
				catch (focusError) { area.focus(); }
				area.select();
				if (typeof area.setSelectionRange === 'function') area.setSelectionRange(0, area.value.length);
				var copied = document.execCommand('copy');
				area.remove();
				restoreFocus(active);
				if (!copied) throw new Error('The browser rejected the clipboard fallback.');
				state.lastMethod = 'execCommand';
				state.lastError = '';
				resolve();
			} catch (error) {
				try { area.remove(); } catch (ignoredRemove) {}
				restoreFocus(active);
				reject(error);
			}
		});
	}

	function focusedDocument() {
		if (typeof document.hasFocus !== 'function') return true;
		try { return document.hasFocus(); }
		catch (error) { return true; }
	}

	function focusWindow() {
		if (focusedDocument()) return;
		try { if (typeof window.focus === 'function') window.focus(); }
		catch (error) {}
	}

	function safeWriteText(text) {
		var value = String(text == null ? '' : text);
		focusWindow();

		var asyncAttempt;
		if (focusedDocument()) {
			try { asyncAttempt = Promise.resolve(nativeWriteText(value)); }
			catch (error) { asyncAttempt = Promise.reject(error); }
		} else {
			asyncAttempt = Promise.reject(new Error('Document is not focused.'));
		}

		return asyncAttempt.then(function () {
			state.lastMethod = 'clipboard-api';
			state.lastError = '';
		}).catch(function (nativeError) {
			return legacyCopy(value).catch(function (fallbackError) {
				state.lastError = nativeError && nativeError.message ? nativeError.message : String(nativeError || fallbackError || 'Clipboard access failed.');
				var error = new Error('Clipboard access is blocked because the Elementor window is not focused. Click once inside Elementor and press Copy again, or use Download file.');
				error.name = nativeError && nativeError.name ? nativeError.name : 'ClipboardError';
				error.cause = nativeError || fallbackError;
				throw error;
			});
		});
	}

	function install() {
		try {
			Object.defineProperty(clipboard, 'writeText', {
				configurable: true,
				writable: true,
				value: safeWriteText
			});
			state.installed = clipboard.writeText === safeWriteText;
		} catch (error) {}

		if (state.installed) return;
		try {
			clipboard.writeText = safeWriteText;
			state.installed = clipboard.writeText === safeWriteText;
		} catch (error) {}

		if (state.installed) return;
		try {
			var proto = Object.getPrototypeOf(clipboard);
			var descriptor = proto ? Object.getOwnPropertyDescriptor(proto, 'writeText') : null;
			if (proto && descriptor && descriptor.configurable) {
				Object.defineProperty(proto, 'writeText', {
					configurable: true,
					writable: true,
					value: safeWriteText
				});
				state.installed = clipboard.writeText === safeWriteText;
			}
		} catch (error) {}
	}

	install();
	window.CrescoLayerClipboardGuard = {
		version: '1.0.0',
		copy: safeWriteText,
		getDiagnostics: function () {
			return {
				installed: state.installed,
				lastMethod: state.lastMethod,
				lastError: state.lastError,
				focused: focusedDocument()
			};
		}
	};
}());
