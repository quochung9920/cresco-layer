(function () {
	'use strict';

	function updatePanel() {
		var panel = document.getElementById('cresco-ai-panel');
		if (!panel) return;
		var importArea = panel.querySelector('[data-cresco-ai-import]');
		if (importArea) {
			importArea.placeholder = 'Paste the JSON returned by ChatGPT: cresco-ai-mutation/v3, v2, cresco-layer-patch/v1 or cresco-layer-ai-result/v1.';
		}
		var exportNote = panel.querySelector('[data-cresco-ai-pane="export"] [data-cresco-ai-ready] > small');
		if (exportNote && !exportNote.dataset.externalExchangeCopy) {
			exportNote.dataset.externalExchangeCopy = '1';
			exportNote.textContent = 'Upload the exported file to ChatGPT, describe the interface change there, then import the returned JSON here. The package declares the preferred result schema for its scope.';
		}
	}

	function boot() {
		updatePanel();
		if (window.MutationObserver && document.documentElement) {
			new MutationObserver(updatePanel).observe(document.documentElement, { childList: true, subtree: true });
		}
	}

	window.CrescoLayerSemanticDesignUI = { version: '1.1.0', mode: 'external-ai-exchange', refresh: updatePanel };
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true }); else boot();
}());