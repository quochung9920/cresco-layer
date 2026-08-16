(function () {
	'use strict';

	function updatePanel() {
		var panel = document.getElementById('cresco-ai-panel');
		if (!panel) return;
		var importArea = panel.querySelector('[data-cresco-ai-import]');
		if (importArea) importArea.placeholder = 'Paste cresco-ai-mutation/v3 (preferred), cresco-ai-mutation/v2, cresco-layer-patch/v1 or cresco-layer-ai-result/v1. Markdown code fences and common AI wrappers are accepted.';
		var ready = panel.querySelector('[data-cresco-ai-ready] > small');
		if (ready) ready.textContent = 'Preferred response: cresco-ai-mutation/v3 semantic design delta. Cresco compiles layout/style/responsive intent to exact runtime controls; Rebuild remains the only destructive mode.';
		var targetNote = panel.querySelector('[data-cresco-ai-pane="prepare"] .cresco-ai-target small');
		if (targetNote) targetNote.textContent = 'Exact Runtime, semantic design intelligence and safe widget/subtree scope are automatic.';
	}

	function boot() {
		updatePanel();
		if (window.MutationObserver && document.documentElement) {
			new MutationObserver(updatePanel).observe(document.documentElement, { childList: true, subtree: true });
		}
	}

	window.CrescoLayerSemanticDesignUI = { version: '1.0.0', refresh: updatePanel };
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true }); else boot();
}());
