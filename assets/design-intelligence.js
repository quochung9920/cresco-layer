(function () {
	'use strict';

	var SOURCE = {
		name: 'UI/UX Pro Max',
		repository: 'nextlevelbuilder/ui-ux-pro-max-skill',
		revision: 'a38d04c3d5c298c851dbe5e6ee1965ee3de42cb5',
		license: 'MIT',
		mode: 'principles-inspired-no-runtime-dependency'
	};

	function text(value) { return String(value == null ? '' : value); }
	function lower(value) { return text(value).toLowerCase(); }
	function clamp(value, min, max) { return Math.max(min, Math.min(max, Number(value) || 0)); }
	function unique(values) { return Array.from(new Set((values || []).filter(Boolean))); }

	function explicitDial(name) {
		var intent = window.CrescoLayerAIIntent || {};
		var dials = intent.designDials && typeof intent.designDials === 'object' ? intent.designDials : {};
		var value = parseInt(dials[name], 10);
		if (!(value >= 1 && value <= 10)) {
			var field = document.querySelector('[data-cresco-design-dial="' + name + '"]');
			value = field ? parseInt(field.value, 10) : 0;
		}
		return value >= 1 && value <= 10 ? value : 0;
	}

	function inferProduct(request) {
		var q = lower(request);
		if (/dashboard|analytics|admin|operations|console|crm|erp/.test(q)) return 'dashboard';
		if (/shop|store|commerce|e-commerce|ecommerce|product|checkout|cart/.test(q)) return 'commerce';
		if (/portfolio|agency|studio|creative/.test(q)) return 'portfolio';
		if (/saas|software|platform|app|tool/.test(q)) return 'saas';
		if (/blog|editorial|news|magazine|article/.test(q)) return 'editorial';
		if (/restaurant|hotel|travel|spa|clinic|service|survey|lead/.test(q)) return 'service';
		return 'general-web';
	}

	function inferDials(request, product) {
		var q = lower(request);
		var variance = explicitDial('variance');
		var motion = explicitDial('motion');
		var density = explicitDial('density');
		if (!variance) variance = /brutal|experimental|asymmetric|bold|editorial/.test(q) ? 8 : (/minimal|clean|professional|corporate/.test(q) ? 3 : 5);
		if (!motion) motion = /immersive|animated|dynamic|cinematic|motion/.test(q) ? 7 : (/static|subtle|minimal motion/.test(q) ? 2 : 4);
		if (!density) density = product === 'dashboard' ? 8 : (/luxury|spacious|premium|editorial/.test(q) ? 3 : (product === 'commerce' ? 6 : 5));
		return { variance: clamp(variance, 1, 10), motion: clamp(motion, 1, 10), density: clamp(density, 1, 10) };
	}

	function tier(value, labels) {
		if (value <= 3) return labels[0];
		if (value >= 8) return labels[2];
		return labels[1];
	}

	function spacingScale(density) {
		if (density >= 8) return { xs: '4px', sm: '8px', md: '12px', lg: '16px', xl: '24px', section: 'clamp(32px, 4vw, 56px)' };
		if (density <= 3) return { xs: '8px', sm: '12px', md: '24px', lg: '32px', xl: '48px', section: 'clamp(64px, 8vw, 112px)' };
		return { xs: '4px', sm: '8px', md: '16px', lg: '24px', xl: '32px', section: 'clamp(48px, 6vw, 88px)' };
	}

	function styleKeywords(request, dials) {
		var q = lower(request), out = [];
		['minimal', 'modern', 'professional', 'luxury', 'editorial', 'playful', 'bold', 'dark', 'light', 'glass', 'brutal', 'organic', 'technical'].forEach(function (word) { if (q.indexOf(word) !== -1) out.push(word); });
		if (!out.length) out.push(tier(dials.variance, ['minimal-structured', 'balanced-modern', 'bold-asymmetric']));
		return unique(out);
	}

	function qualityPriorities() {
		return [
			{ priority: 1, category: 'accessibility', severity: 'critical', checks: ['text contrast >= 4.5:1 for normal text', 'visible keyboard focus', 'semantic heading order', 'meaningful image alt text', 'interactive controls expose accessible names'] },
			{ priority: 2, category: 'touch-interaction', severity: 'critical', checks: ['touch targets should be about 44x44 CSS px when touch interaction is expected', 'do not rely on hover as the only state', 'show feedback for async actions'] },
			{ priority: 3, category: 'performance', severity: 'high', checks: ['reserve media space to avoid layout shift', 'prefer responsive optimized media', 'avoid layout-thrashing animation'] },
			{ priority: 4, category: 'style-consistency', severity: 'high', checks: ['reuse Active Kit globals before local literals', 'keep one coherent visual language', 'prefer native SVG/icon widgets over emoji decoration'] },
			{ priority: 5, category: 'layout-responsive', severity: 'high', checks: ['fluid-first sizing', 'no unintended horizontal overflow', 'structural breakpoint changes only when needed'] },
			{ priority: 6, category: 'typography-color', severity: 'medium', checks: ['body copy normally >= 16px', 'comfortable body line-height around 1.5+', 'use semantic/global colors when available'] },
			{ priority: 7, category: 'motion', severity: 'medium', checks: ['motion communicates hierarchy or continuity', 'respect prefers-reduced-motion', 'avoid animating layout-heavy properties when transform/opacity can express the same effect'] },
			{ priority: 8, category: 'forms-feedback', severity: 'medium', checks: ['visible labels', 'errors adjacent to the relevant field', 'preserve behavioral submit/email/webhook configuration for visual-only tasks'] },
			{ priority: 9, category: 'navigation', severity: 'high', checks: ['predictable hierarchy and active state', 'preserve menu/data source bindings unless explicitly requested'] }
		];
	}

	function activeKitSummary(context) {
		var design = context.designSystem || {};
		return { source: 'active-elementor-kit', hasSystemColors: !!(design.colors && design.colors.system && design.colors.system.length), hasCustomColors: !!(design.colors && design.colors.custom && design.colors.custom.length), hasTypography: !!design.typography, breakpoints: context.responsive && context.responsive.breakpoints ? context.responsive.breakpoints : (design.layout && design.layout.breakpoints ? design.layout.breakpoints : {}) };
	}

	function antiPatterns(product, dials) {
		var out = [
			'inventing Elementor controls or responsive suffixes',
			'using HTML/custom CSS when a native runtime-proven widget/control expresses the same UI',
			'detaching Global Colors, Global Fonts or Dynamic Tags without an explicit request',
			'using arbitrary one-off spacing values when the site rhythm or semantic spacing scale is suitable',
			'rebuilding an existing subtree for a small edit or addition'
		];
		if (product === 'dashboard') out.push('oversized marketing spacing that reduces information density');
		if (dials.motion <= 3) out.push('decorative continuous motion that conflicts with the requested subtle motion tier');
		if (dials.variance <= 3) out.push('unnecessary asymmetry or ornamental variation that breaks a minimal structured composition');
		return out;
	}

	function intentContract() {
		return {
			schema: 'cresco-semantic-design-intent/v1',
			nodeFields: {
				layoutIntent: ['direction', 'justify', 'align', 'wrap', 'gap', 'width', 'minHeight', 'maxWidth', 'padding', 'margin', 'overflow'],
				styleIntent: ['backgroundColor', 'textColor', 'borderRadius', 'opacity', 'textAlign', 'fontSize', 'lineHeight', 'letterSpacing', 'fontWeight'],
				responsiveIntent: 'Map active device names to partial layout/style intent objects. Cresco validates every responsive suffix against the active runtime.',
				accessibilityIntent: ['ariaLabel', 'semanticLevel', 'decorative']
			},
			rules: [
				'Prefer semantic design intent over raw Elementor setting names when cresco-ai-mutation/v3 can express the requested result.',
				'If Cresco cannot resolve an intent to an exact active-runtime control, the compiler fails closed instead of guessing.',
				'Explicit settings remain an expert escape hatch and are still validated by SemanticPatchGuard.'
			]
		};
	}

	function enrich(context) {
		if (!context || context.schema !== 'cresco-ai-context/v3') return context;
		var request = context.task && context.task.request ? context.task.request : '';
		var product = inferProduct(request);
		var dials = inferDials(request, product);
		context.designIntelligence = {
			schema: 'cresco-design-intelligence/v1', source: SOURCE, productArchetype: product, styleKeywords: styleKeywords(request, dials),
			designDials: { variance: { value: dials.variance, tier: tier(dials.variance, ['centered-minimal', 'balanced-modern', 'bold-asymmetric']) }, motion: { value: dials.motion, tier: tier(dials.motion, ['subtle', 'standard', 'expressive']) }, density: { value: dials.density, tier: tier(dials.density, ['spacious', 'standard', 'dense']) } },
			spacingIntentScale: spacingScale(dials.density), qualityPriorities: qualityPriorities(), activeKit: activeKitSummary(context), antiPatterns: antiPatterns(product, dials),
			principles: ['Accessibility and interaction safety outrank decorative style choices.', 'Reuse the current Elementor design language before introducing a new local value.', 'Use fluid layout and spacing first; use breakpoints for structural changes.', 'Motion must have a purpose and must degrade for reduced-motion users.', 'Visual tasks must not rewrite form, query, navigation or commerce behavior.']
		};
		context.semanticDesignIntent = intentContract();
		context.outputContract = context.outputContract || {};
		context.outputContract.preferredSchema = 'cresco-ai-mutation/v3';
		context.outputContract.acceptedSchemas = unique((context.outputContract.acceptedSchemas || []).concat(['cresco-ai-mutation/v3', 'cresco-ai-mutation/v2', 'cresco-layer-patch/v1', 'cresco-layer-ai-result/v1']));
		context.outputContract.semanticDesignCompiler = { preferred: true, contract: 'cresco-semantic-design-intent/v1', fallback: 'Use explicit runtime-proven settings only when a semantic intent cannot represent the required property.' };
		context.rules = context.rules || {};
		context.rules.designQuality = context.designIntelligence.principles.concat(context.designIntelligence.antiPatterns.map(function (item) { return 'Avoid: ' + item + '.'; }));
		return context;
	}

	function option(value, label) { return '<option value="' + value + '">' + label + '</option>'; }
	function injectDials() {
		var panel = document.getElementById('cresco-ai-panel');
		if (!panel || panel.querySelector('[data-cresco-design-dials]')) return;
		var change = panel.querySelector('.cresco-ai-segmented');
		var host = change && change.parentNode ? change.parentNode : panel.querySelector('[data-cresco-ai-pane="prepare"]');
		if (!host) return;
		var details = document.createElement('details'); details.setAttribute('data-cresco-design-dials', ''); details.className = 'cresco-ai-field';
		details.innerHTML = '<summary><strong>Design Intelligence</strong> <small>optional</small></summary>' +
			'<div class="cresco-ai-design-dials" style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:10px">' +
			'<label><span>Variance</span><select data-cresco-design-dial="variance">' + option('0','Auto') + option('2','Minimal') + option('5','Balanced') + option('9','Bold') + '</select></label>' +
			'<label><span>Motion</span><select data-cresco-design-dial="motion">' + option('0','Auto') + option('2','Subtle') + option('5','Standard') + option('9','Expressive') + '</select></label>' +
			'<label><span>Density</span><select data-cresco-design-dial="density">' + option('0','Auto') + option('2','Spacious') + option('5','Standard') + option('9','Dense') + '</select></label>' +
			'</div><small>Inspired by UI/UX Pro Max design dials; Cresco combines them with the active Elementor Kit and runtime instead of copying a separate design system.</small>';
		host.appendChild(details);
	}

	function boot() {
		injectDials();
		if (window.MutationObserver && document.documentElement) new MutationObserver(injectDials).observe(document.documentElement, { childList: true, subtree: true });
	}

	window.CrescoLayerDesignIntelligence = { version: '1.1.0', source: SOURCE, enrich: enrich, inferProduct: inferProduct, inferDials: inferDials };
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true }); else boot();
}());
