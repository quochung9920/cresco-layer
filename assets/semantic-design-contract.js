(function () {
	'use strict';

	var intelligence = window.CrescoLayerDesignIntelligence;
	if (!intelligence || typeof intelligence.enrich !== 'function') return;
	var baseEnrich = intelligence.enrich;

	function templateAdd(context) {
		var target = context.target || {};
		var widgets = context.widgetIntelligence && context.widgetIntelligence.roles ? context.widgetIntelligence.roles : {};
		var headline = widgets.headline && widgets.headline.preferredWidget ? widgets.headline.preferredWidget : 'heading';
		return {
			schema: 'cresco-ai-mutation/v3',
			intent: 'add',
			target: { postId: target.postId || 0, id: target.id || '' },
			placement: { mode: 'inside-end' },
			nodes: [{
				ref: '$new:section',
				widgetIntent: 'container',
				layoutIntent: { direction: 'column', gap: '24px' },
				children: [{
					ref: '$new:headline', widgetIntent: headline,
					content: { text: 'Example headline', semanticLevel: 'h2' },
					styleIntent: { fontSize: 'clamp(32px, 4vw, 56px)' }
				}]
			}]
		};
	}

	function templateEdit(context) {
		var target = context.target || {};
		return {
			schema: 'cresco-ai-mutation/v3',
			intent: 'edit',
			target: { postId: target.postId || 0, id: target.id || '' },
			designChanges: [{
				elementId: target.id || '',
				layoutIntent: { gap: '24px' },
				responsiveIntent: { mobile: { layout: { direction: 'column' } } }
			}]
		};
	}

	function contract(context) {
		context.semanticDesignIntent = context.semanticDesignIntent || {};
		context.semanticDesignIntent.schema = 'cresco-semantic-design-intent/v1';
		context.semanticDesignIntent.add = {
			contract: 'Use nodes for new UI. Each node names a runtime-proven widgetIntent and may carry content, layoutIntent, styleIntent, responsiveIntent, accessibilityIntent, children and explicit settings.',
			children: 'Only structural Elementor element types may receive arbitrary child nodes. Widget-native nested/repeater content must use runtime controls or a dedicated adapter.'
		};
		context.semanticDesignIntent.edit = {
			contract: 'Use designChanges for existing elements: elementId + semantic content/layout/style/responsive/accessibility intent. Cresco resolves the live element type and exact runtime controls.',
			preserve: 'Omitted settings and behavioral bindings remain unchanged.'
		};
		context.semanticDesignIntent.valuePolicy = {
			fluidFirst: true,
			customUnit: 'Use clamp/min/max/calc/var only when the exact native control supports custom. Otherwise use a runtime-supported unit/value.',
			globalFirst: 'Prefer existing Active Kit global references from designSystem/siteDesignContext when the requested design matches them.'
		};
		context.outputContract = context.outputContract || {};
		context.outputContract.preferredSchema = 'cresco-ai-mutation/v3';
		context.outputContract.templates = context.outputContract.templates || {};
		context.outputContract.templates.semanticDesignAdd = templateAdd(context);
		context.outputContract.templates.semanticDesignEdit = templateEdit(context);
		context.outputContract.rules = Array.isArray(context.outputContract.rules) ? context.outputContract.rules : [];
		context.outputContract.rules.unshift('Prefer cresco-ai-mutation/v3 semantic design intent. Let Cresco resolve exact Elementor controls from the active runtime instead of writing raw setting keys when the v3 intent vocabulary can express the change.');
		return context;
	}

	intelligence.enrich = function (context) { return contract(baseEnrich(context)); };
	intelligence.contract = contract;
	intelligence.version = '1.2.0';
	window.CrescoLayerDesignIntelligence = intelligence;
}());
