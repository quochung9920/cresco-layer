(function () {
	'use strict';

	var api = window.CrescoLayerAIBundle;
	if (!api) return;

	function documentTemplate(pkg) {
		var target = pkg.target || {};
		return {
			schema: 'cresco-layer-patch/v1',
			base: { postId: Number(target.postId || 0) },
			scope: { mode: 'document', rootElementId: '', elementIds: [] },
			label: 'External AI document edit',
			operations: [
				{
					operation: 'update-setting',
					elementId: '<existing-element-id-from-context>',
					setting: '<exact-runtime-control-key>',
					value: '<runtime-valid-value>'
				}
			]
		};
	}

	function documentInsertTemplate(pkg) {
		var target = pkg.target || {};
		return {
			schema: 'cresco-layer-patch/v1',
			base: { postId: Number(target.postId || 0) },
			scope: { mode: 'document', rootElementId: '', elementIds: [] },
			label: 'External AI document insertion',
			operations: [
				{
					operation: 'insert-element',
					parentId: '',
					position: 999999,
					element: {
						ref: '$new:top-level-section',
						elType: 'container',
						settings: {},
						elements: []
					}
				}
			]
		};
	}

	function normalize(pkg) {
		if (!pkg || pkg.schema !== 'cresco-ai-context/v3') return pkg;
		var target = pkg.target || {};
		var contract = pkg.outputContract || {};
		contract.rules = Array.isArray(contract.rules) ? contract.rules : [];

		if (target.scope === 'document') {
			contract.scope = 'document';
			contract.preferredSchema = 'cresco-layer-patch/v1';
			contract.strategy = 'document-delta-first';
			contract.templates = contract.templates || {};
			contract.templates.documentEdit = documentTemplate(pkg);
			contract.templates.documentInsert = documentInsertTemplate(pkg);
			delete contract.templates.semanticDesignAdd;
			delete contract.templates.semanticDesignEdit;
			contract.rules.unshift('Document scope: return cresco-layer-patch/v1 with scope.mode=document. Semantic mutation v3 requires a concrete Elementor root target and is not the preferred whole-document transport.');
			contract.rules.unshift('For a whole-page rebuild, use replace-document only when the user explicitly requested replacement and return a complete valid Elementor content tree. Prefer smaller document-scope update/insert/move operations whenever possible.');
			contract.rules.unshift('For new elements in insert-element operations, use temporary ref values such as $new:hero and omit invented final IDs; Cresco allocates collision-free Elementor IDs during import.');
			target.canAcceptChildren = false;
			target.documentRoot = true;
		} else {
			contract.preferredSchema = 'cresco-ai-mutation/v3';
			contract.rules.unshift('Element/subtree external exchange: prefer cresco-ai-mutation/v3 so Cresco resolves semantic design intent to runtime-proven Elementor controls and allocates new IDs.');
		}

		pkg.outputContract = contract;
		pkg.target = target;
		pkg.externalExchangePolicy = {
			schema: 'cresco-external-exchange-policy/v1',
			mode: target.scope === 'document' ? 'document-patch' : 'semantic-target-mutation',
			preferredSchema: contract.preferredSchema,
			designPromptLocation: 'external-chat',
			fullRuntimeContextRequired: true
		};
		return pkg;
	}

	var baseBuild = api.build;
	var basePackage = api.package;
	var baseExport = api.export;
	var baseExportJson = api.exportJson;

	api.build = function (pkg, referenceFile) { return baseBuild(normalize(pkg), referenceFile); };
	api.package = function (pkg) { return basePackage(normalize(pkg)); };
	api.export = function (pkg, referenceFile) { return baseExport(normalize(pkg), referenceFile); };
	api.exportJson = function (pkg) { return baseExportJson(normalize(pkg)); };
	api.normalizeExternalContext = normalize;
	api.externalPolicySchema = 'cresco-external-exchange-policy/v1';
	api.version = '4.1.0';

	window.CrescoLayerExternalAIExchangePolicy = {
		version: '1.0.0',
		schema: 'cresco-external-exchange-policy/v1',
		normalize: normalize,
		documentTemplate: documentTemplate,
		documentInsertTemplate: documentInsertTemplate
	};
}());