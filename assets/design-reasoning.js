(function () {
	'use strict';

	var SOURCE = {
		name: 'UI/UX Pro Max',
		repository: 'nextlevelbuilder/ui-ux-pro-max-skill',
		revision: 'a38d04c3d5c298c851dbe5e6ee1965ee3de42cb5',
		license: 'MIT',
		mode: 'workflow-and-priority-model-inspired-no-dataset-vendoring'
	};

	function text(value) { return String(value == null ? '' : value); }
	function lower(value) { return text(value).toLowerCase(); }
	function unique(values) { return Array.from(new Set((values || []).filter(Boolean))); }

	var PRODUCT_PROFILES = {
		saas: {
			goal: 'Communicate value quickly, prove credibility, and convert without visual noise.',
			hierarchy: ['clear value proposition', 'product proof', 'trust evidence', 'primary CTA', 'secondary detail'],
			composition: ['strong hero hierarchy', 'product UI or proof visual', 'feature grouping', 'social proof', 'focused CTA'],
			avoid: ['generic icon-only feature grids with no product proof', 'too many equally prominent CTAs', 'decorative gradients that reduce text clarity']
		},
		dashboard: {
			goal: 'Maximize scanability, prioritization, and confident repeated use.',
			hierarchy: ['status and key metrics', 'primary actions', 'exceptions and alerts', 'supporting detail'],
			composition: ['stable navigation', 'dense but grouped information', 'clear table/chart hierarchy', 'persistent filters when useful'],
			avoid: ['marketing-page spacing inside operational UI', 'excessively large headings', 'color used as the only state signal']
		},
		commerce: {
			goal: 'Reduce purchase uncertainty and preserve a fast path to conversion.',
			hierarchy: ['product identity', 'price and availability', 'purchase action', 'proof and delivery information', 'supporting detail'],
			composition: ['product-first visual hierarchy', 'high-confidence CTA', 'trust and fulfillment cues', 'comparison-friendly content'],
			avoid: ['hiding price or availability', 'competing purchase CTAs', 'decorative UI that delays product comprehension']
		},
		service: {
			goal: 'Build trust, explain the service clearly, and make enquiry or booking effortless.',
			hierarchy: ['problem and outcome', 'service credibility', 'primary enquiry CTA', 'process and proof'],
			composition: ['outcome-led hero', 'proof near conversion points', 'simple service explanation', 'low-friction form or contact path'],
			avoid: ['long forms before trust is established', 'vague benefits', 'stock-photo-heavy sections with little evidence']
		},
		editorial: {
			goal: 'Optimize reading flow, hierarchy, and content discovery.',
			hierarchy: ['title and context', 'primary narrative', 'supporting metadata', 'related content'],
			composition: ['controlled measure', 'strong typographic rhythm', 'clear section hierarchy', 'low-distraction navigation'],
			avoid: ['wide unreadable text measures', 'dense decorative chrome', 'weak heading contrast']
		},
		portfolio: {
			goal: 'Make the work the evidence while keeping identity and contact paths memorable.',
			hierarchy: ['signature work', 'positioning', 'selected proof', 'contact or next step'],
			composition: ['visual storytelling', 'intentional asymmetry when appropriate', 'case-study depth', 'strong image treatment'],
			avoid: ['effects that compete with the work', 'identical card grids for every project', 'unclear project context']
		},
		'general-web': {
			goal: 'Create a clear, coherent, accessible interface aligned with the current site language.',
			hierarchy: ['primary task', 'supporting information', 'next action'],
			composition: ['clear hierarchy', 'consistent spacing', 'obvious interaction states'],
			avoid: ['unmotivated visual novelty', 'inconsistent spacing systems', 'multiple competing emphasis styles']
		}
	};

	function inferPage(request, product) {
		var q = lower(request);
		if (/checkout|payment/.test(q)) return 'checkout';
		if (/pricing|plans?\b/.test(q)) return 'pricing';
		if (/dashboard|analytics|admin|console/.test(q)) return 'dashboard';
		if (/article|blog post|editorial|news/.test(q)) return 'article';
		if (/portfolio|case study/.test(q)) return 'portfolio';
		if (/lead|survey|quote|booking|contact form/.test(q)) return 'lead-generation';
		if (/landing|homepage|home page|hero|marketing/.test(q)) return 'landing';
		return product === 'dashboard' ? 'dashboard' : 'content-section';
	}

	function inferAudience(request) {
		var q = lower(request), tags = [];
		if (/enterprise|b2b|professional|business|team/.test(q)) tags.push('professional-decision-maker');
		if (/consumer|customer|shopper|b2c/.test(q)) tags.push('consumer');
		if (/developer|technical|engineer/.test(q)) tags.push('technical');
		if (/mobile|phone|touch/.test(q)) tags.push('touch-heavy');
		if (/accessib|elder|senior|low vision/.test(q)) tags.push('accessibility-sensitive');
		return tags.length ? unique(tags) : ['general-web-user'];
	}

	function pageStrategy(page) {
		var map = {
			landing: { primaryAction: 'one dominant conversion action per viewport region', proof: 'place credible proof close to major claims and CTAs', rhythm: 'alternate explanation, proof, and action rather than repeating identical feature blocks' },
			pricing: { primaryAction: 'make plan differences and recommended choice scannable', proof: 'surface billing terms and key limits before conversion', rhythm: 'comparison first, supporting FAQ/proof second' },
			checkout: { primaryAction: 'complete purchase with minimal interruption', proof: 'show security, delivery, totals, and error recovery where decisions occur', rhythm: 'linear progression with no decorative detours' },
			dashboard: { primaryAction: 'support repeated high-frequency tasks and anomaly detection', proof: 'use labels, legends, deltas, and secondary cues for data states', rhythm: 'stable shell with progressive detail' },
			article: { primaryAction: 'read and navigate content comfortably', proof: 'make metadata and sources secondary to the narrative', rhythm: 'typographic sections with controlled measure and generous content rhythm' },
			portfolio: { primaryAction: 'inspect work then contact or continue to case study', proof: 'show outcomes, context, and role alongside visuals', rhythm: 'vary composition without sacrificing navigation consistency' },
			'lead-generation': { primaryAction: 'submit enquiry or booking with minimum friction', proof: 'place trust, accreditation, reviews, or response expectations beside the form', rhythm: 'outcome → trust → action → process' },
			'content-section': { primaryAction: 'support the page goal without creating a competing hierarchy', proof: 'use only proof relevant to the section claim', rhythm: 'match surrounding section rhythm and Active Kit spacing' }
		};
		return map[page] || map['content-section'];
	}

	function designVocabulary(context) {
		var design = context.designIntelligence || {}, spacing = design.spacingIntentScale || {};
		return {
			schema: 'cresco-design-vocabulary/v1',
			emphasis: {
				primary: 'highest local visual priority; reserve for the main action or message',
				secondary: 'supporting action or message that must not compete with primary',
				muted: 'supporting metadata or low-priority detail'
			},
			surfaces: {
				base: 'page/default surface from Active Kit',
				subtle: 'low-contrast grouping surface',
				elevated: 'card/dialog-like surface with restrained border/shadow',
				brand: 'brand/accent-led surface used selectively'
			},
			spacingRoles: {
				micro: spacing.xs || '4px',
				control: spacing.sm || '8px',
				component: spacing.md || '16px',
				group: spacing.lg || '24px',
				section: spacing.section || 'clamp(48px, 6vw, 88px)'
			},
			depth: {
				flat: 'no decorative elevation',
				subtle: 'soft border or low shadow; default for professional cards',
				prominent: 'use only for a focal product preview, modal, or conversion surface'
			}
		};
	}

	function qualityGates() {
		return {
			schema: 'cresco-design-quality-gates/v1',
			critical: [
				{ id: 'a11y-readable-contrast', rule: 'Normal text must maintain readable contrast; target WCAG AA where applicable.', action: 'reject-or-repair' },
				{ id: 'a11y-focus', rule: 'Interactive controls must keep a visible keyboard focus state.', action: 'reject-or-repair' },
				{ id: 'interaction-touch', rule: 'Touch-oriented controls should provide a comfortably tappable target and spacing.', action: 'repair-or-warn' },
				{ id: 'behavior-preservation', rule: 'Visual changes must not rewrite form, query, navigation, payment, or external behavior without explicit user intent.', action: 'reject' }
			],
			high: [
				{ id: 'responsive-overflow', rule: 'No unintended horizontal overflow or clipped primary content.', action: 'repair-or-warn' },
				{ id: 'hierarchy-single-primary', rule: 'A local region should have one clearly dominant message/action rather than several equal priorities.', action: 'warn' },
				{ id: 'kit-consistency', rule: 'Reuse Active Kit colors, typography and spacing language before adding local literals.', action: 'warn' },
				{ id: 'layout-stability', rule: 'Reserve media/layout space and avoid changes likely to create noticeable layout shift.', action: 'warn' }
			],
			advisory: [
				{ id: 'motion-purpose', rule: 'Motion should communicate hierarchy, feedback, or continuity and respect reduced-motion.', action: 'recommend' },
				{ id: 'component-proof', rule: 'Marketing feature cards should show concrete product/proof detail when possible, not only icon + generic text.', action: 'recommend' },
				{ id: 'visual-rhythm', rule: 'Vary section composition intentionally while keeping spacing and typography coherent.', action: 'recommend' }
			]
		};
	}

	function referenceTranslation(context) {
		var ref = context.task && context.task.referenceImage ? context.task.referenceImage : {};
		var provided = !!(ref && ref.provided);
		return {
			schema: 'cresco-reference-translation/v1',
			provided: provided,
			mode: provided ? 'analyze-and-adapt' : 'not-provided',
			extract: ['visual hierarchy', 'section composition', 'relative proportions', 'spacing rhythm', 'typography character', 'color relationships', 'surface depth', 'component patterns', 'imagery treatment', 'interaction/motion cues if visible'],
			preserveFromSite: ['Active Kit global colors and fonts unless redesign explicitly requires change', 'existing behavioral bindings', 'runtime-supported widget semantics', 'responsive foundation'],
			doNotCopyBlindly: ['brand names or protected identity from the reference', 'pixel values that conflict with the current design system', 'framework-specific markup', 'interaction behavior not supported by the active Elementor runtime'],
			rule: 'Match the reference at the level of hierarchy, proportion, rhythm and visual character, then translate it through Cresco Widget Intelligence, Active Kit and Exact Runtime.'
		};
	}

	function buildBrief(context) {
		var task = context.task || {}, design = context.designIntelligence || {};
		var product = design.productArchetype || 'general-web';
		var page = inferPage(task.request || '', product);
		var profile = PRODUCT_PROFILES[product] || PRODUCT_PROFILES['general-web'];
		var strategy = pageStrategy(page);
		return {
			schema: 'cresco-design-reasoning/v1',
			source: SOURCE,
			productArchetype: product,
			pageArchetype: page,
			audienceSignals: inferAudience(task.request || ''),
			designDials: design.designDials || {},
			objective: profile.goal,
			visualHierarchy: profile.hierarchy,
			compositionStrategy: profile.composition,
			pageStrategy: strategy,
			designVocabulary: designVocabulary(context),
			qualityGates: qualityGates(),
			referenceTranslation: referenceTranslation(context),
			antiPatterns: unique((design.antiPatterns || []).concat(profile.avoid)),
			decisionOrder: ['accessibility-and-behavior-safety', 'user-task-and-hierarchy', 'responsive-layout', 'active-kit-consistency', 'widget-and-runtime-fit', 'typography-and-color', 'depth-and-motion', 'decorative-polish'],
			elementorTranslation: [
				'Choose semantic roles and runtime-proven widgets before styling.',
				'Prefer semantic layout/style/responsive intent in cresco-ai-mutation/v3.',
				'Use Active Kit globals when they express the requested design.',
				'Use native Elementor controls before custom CSS.',
				'Use visual verification after apply; repair only mismatched properties with a delta.'
			]
		};
	}

	function enrich(context) {
		if (!context || context.schema !== 'cresco-ai-context/v3') return context;
		context.designReasoning = buildBrief(context);
		context.rules = context.rules || {};
		context.rules.designReasoning = [
			'Follow designReasoning.decisionOrder when aesthetic preferences conflict with usability or runtime safety.',
			'Treat designReasoning.referenceTranslation as adaptation guidance, not permission to copy unsupported or site-inconsistent values.',
			'Use designReasoning.qualityGates as a pre-delivery checklist before returning a mutation.'
		];
		context.outputContract = context.outputContract || {};
		context.outputContract.designReasoning = {
			schema: 'cresco-design-reasoning/v1',
			instruction: 'Use the design brief to decide hierarchy/composition, then express only the final delta with runtime-proven semantic design intent.'
		};
		return context;
	}

	window.CrescoLayerDesignReasoning = {
		version: '1.0.0',
		source: SOURCE,
		enrich: enrich,
		buildBrief: buildBrief,
		inferPage: inferPage
	};
}());
