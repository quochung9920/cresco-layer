(function () {
	'use strict';

	var cfg = window.crescoLayerEditor || {};
	var upstreamFetch = typeof window.fetch === 'function' ? window.fetch.bind(window) : null;
	var state = { installed: false, lastError: '', lastScore: 0, lastTarget: '' };

	function root() { return String(cfg.restRoot || '').replace(/\/$/, ''); }
	function isExport(input) {
		var url = typeof input === 'string' ? input : (input && input.url ? String(input.url) : '');
		return !!url && url.indexOf(root() + '/documents/') === 0 && url.indexOf('/export') !== -1;
	}
	function clone(value) {
		try { return JSON.parse(JSON.stringify(value)); } catch (e) { return value; }
	}
	function words(value) { return String(value || '').toLowerCase(); }
	function has(obj, key) { return !!obj && Object.prototype.hasOwnProperty.call(obj, key); }
	function unique(list) { return Array.from(new Set((list || []).filter(Boolean))); }

	var FAMILY = {
		layout: {
			purpose: 'Arrange child elements with native Elementor flex/grid, sizing, spacing, containment and responsive behavior.',
			roles: ['layout-group', 'section-shell', 'content-column', 'cta-row', 'form-card'], risk: 'safe'
		},
		heading: {
			purpose: 'Render semantic headings or short prominent text with native heading level and typography controls.',
			roles: ['eyebrow', 'headline', 'section-heading', 'card-title'], risk: 'safe'
		},
		text: {
			purpose: 'Render readable body copy, descriptions and rich text while preserving links and dynamic content.',
			roles: ['body-copy', 'description', 'supporting-copy'], risk: 'safe'
		},
		button: {
			purpose: 'Render interactive calls to action using native label, link, icon, hover and button styling controls.',
			roles: ['primary-cta', 'secondary-cta', 'action'], risk: 'safe'
		},
		icon: {
			purpose: 'Render a compact visual symbol using Elementor icon controls rather than text emoji or custom markup.',
			roles: ['decorative-icon', 'feature-icon', 'status-icon'], risk: 'safe'
		},
		list: {
			purpose: 'Render repeated proof, benefits or metadata as a native list when the runtime widget supports it.',
			roles: ['proof-list', 'benefit-list', 'metadata-list'], risk: 'safe'
		},
		image: {
			purpose: 'Render responsive visual media while preserving attachment metadata, aspect ratio and accessibility.',
			roles: ['media', 'hero-image', 'illustration'], risk: 'safe'
		},
		form: {
			purpose: 'Collect user input with native fields, labels, validation and submit behavior.',
			roles: ['lead-form', 'contact-form', 'search-form'], risk: 'external'
		},
		navigation: {
			purpose: 'Render navigation with native menu, dropdown, active-state and responsive behavior.',
			roles: ['navigation', 'breadcrumb'], risk: 'conditional'
		},
		query: {
			purpose: 'Render dynamic collections from a query/template configuration.',
			roles: ['content-grid', 'post-list', 'product-grid'], risk: 'structural'
		},
		carousel: {
			purpose: 'Render sliding content with native loop, navigation, autoplay and responsive slide controls.',
			roles: ['carousel', 'slider', 'testimonial-slider'], risk: 'conditional'
		},
		disclosure: {
			purpose: 'Render accordion, tabs or toggle disclosure with native interaction and state behavior.',
			roles: ['faq', 'tabs', 'accordion'], risk: 'conditional'
		},
		video: {
			purpose: 'Render video media with native source, playback and responsive aspect-ratio controls.',
			roles: ['video', 'media'], risk: 'conditional'
		},
		commerce: {
			purpose: 'Render commerce data and transactional interactions while preserving dynamic product bindings.',
			roles: ['product', 'price', 'cart-action', 'checkout'], risk: 'external'
		},
		code: {
			purpose: 'Render HTML, shortcode or executable/custom markup only when a native semantic widget cannot express the requirement.',
			roles: ['custom-code'], risk: 'expert'
		},
		generic: {
			purpose: 'Render an Elementor element using only capabilities proven by the active runtime.',
			roles: [], risk: 'conditional'
		}
	};

	var ROLE_CANDIDATES = {
		'layout-group': ['container', 'section', 'column'],
		'eyebrow': ['heading', 'advanced-heading'],
		'headline': ['heading', 'advanced-heading'],
		'section-heading': ['heading', 'advanced-heading'],
		'card-title': ['heading', 'advanced-heading'],
		'body-copy': ['text-editor', 'text', 'paragraph'],
		'description': ['text-editor', 'text', 'paragraph'],
		'primary-cta': ['button', 'advanced-button'],
		'secondary-cta': ['button', 'advanced-button'],
		'decorative-icon': ['icon', 'advanced-icon'],
		'proof-list': ['icon-list', 'list'],
		'media': ['image', 'smart-image'],
		'lead-form': ['form'],
		'navigation': ['nav-menu', 'menu', 'wordpress-menu'],
		'carousel': ['slides', 'carousel', 'media-carousel', 'loop-carousel'],
		'faq': ['nested-accordion', 'accordion', 'toggle'],
		'content-grid': ['loop-grid', 'posts', 'portfolio'],
		'video': ['video'],
		'product-grid': ['woocommerce-products', 'products', 'loop-grid']
	};

	var PROTECTED = {
		form: ['actions_after_submit', 'email', 'email_to', 'email_from', 'webhook', 'redirect_to', 'recaptcha', 'stripe', 'paypal'],
		navigation: ['menu', 'menu_id', 'source'],
		query: ['query', 'posts', 'query_id', 'template_id', 'pagination'],
		commerce: ['payment', 'checkout', 'cart', 'product_id', 'query', 'price'],
		code: ['html', 'code', 'shortcode'],
		video: ['youtube_url', 'vimeo_url', 'hosted_url', 'autoplay']
	};

	function family(name) {
		name = words(name);
		if (/container|section|column|flex|grid/.test(name)) return 'layout';
		if (/heading|headline|title/.test(name)) return 'heading';
		if (/text-editor|paragraph|rich-text|^text$/.test(name)) return 'text';
		if (/button|cta/.test(name)) return 'button';
		if (/icon-list|list/.test(name)) return 'list';
		if (/image|gallery|media/.test(name)) return 'image';
		if (/form|login|search-form/.test(name)) return 'form';
		if (/nav|menu|breadcrumb/.test(name)) return 'navigation';
		if (/loop|posts|archive|query|portfolio/.test(name)) return 'query';
		if (/carousel|slides|slider/.test(name)) return 'carousel';
		if (/accordion|toggle|tabs/.test(name)) return 'disclosure';
		if (/video|youtube|vimeo/.test(name)) return 'video';
		if (/product|woocommerce|cart|checkout|price/.test(name)) return 'commerce';
		if (/html|code|shortcode/.test(name)) return 'code';
		if (/icon|social/.test(name)) return 'icon';
		return 'generic';
	}

	function runtimeIndex(runtime) {
		var out = {};
		Object.keys((runtime && runtime.widgets) || {}).forEach(function (name) { out[name] = { kind: 'widget', entry: runtime.widgets[name] }; });
		Object.keys((runtime && runtime.elements) || {}).forEach(function (name) { out[name] = { kind: 'element', entry: runtime.elements[name] }; });
		return out;
	}

	function pickSupported(candidates, index) {
		for (var i = 0; i < (candidates || []).length; i++) {
			if (index[candidates[i]]) return candidates[i];
		}
		return '';
	}

	function importantControls(entry, fam) {
		var keys = Object.keys((entry && entry.controls) || {});
		var patterns = {
			layout: /width|height|flex|grid|gap|padding|margin|align|justify|overflow|position|background|border|radius/,
			heading: /title|header|typography|font|line_height|letter_spacing|color|align/,
			text: /editor|text|typography|font|line_height|color|align/,
			button: /text|link|icon|typography|background|color|border|radius|padding|align|width/,
			icon: /selected_icon|icon|size|color|background|align|link/,
			list: /icon_list|list|icon|text|space|color|typography/,
			image: /image|size|width|height|object|position|border|radius|caption|link/,
			form: /form_fields|field|label|button|message|actions_after_submit|email|webhook|redirect/,
			navigation: /menu|layout|dropdown|submenu|breakpoint|color|typography|space/,
			query: /query|posts|columns|gap|pagination|template|taxonomy/,
			carousel: /slides|carousel|autoplay|loop|navigation|pagination|gap|height/,
			disclosure: /tabs|accordion|toggle|active|item|icon|content|title/,
			video: /video|url|autoplay|mute|controls|aspect|image|poster/,
			commerce: /product|price|cart|checkout|quantity|button|query/,
			code: /html|code|shortcode|custom_css/,
			generic: /.*/
		};
		var re = patterns[fam] || patterns.generic;
		return keys.filter(function (key) { return re.test(key); }).slice(0, 32);
	}

	function widgetIntelligence(context) {
		var runtime = context.runtime || {};
		var index = runtimeIndex(runtime);
		var widgets = {};
		Object.keys(index).forEach(function (name) {
			var fam = family(name);
			var card = FAMILY[fam] || FAMILY.generic;
			widgets[name] = {
				kind: index[name].kind,
				family: fam,
				purpose: card.purpose,
				preferredRoles: card.roles,
				importantControls: importantControls(index[name].entry, fam),
				risk: card.risk,
				detailLoaded: !!(index[name].entry && index[name].entry.detailLoaded)
			};
		});

		var roles = {};
		Object.keys(ROLE_CANDIDATES).forEach(function (role) {
			var candidates = ROLE_CANDIDATES[role];
			var preferred = pickSupported(candidates, index);
			if (!preferred) return;
			roles[role] = {
				preferredWidget: preferred,
				alternatives: candidates.filter(function (name) { return name !== preferred && !!index[name]; }),
				avoidWidgets: role === 'headline' ? ['text-editor', 'html'] : role.indexOf('cta') !== -1 ? ['heading', 'text-editor', 'html'] : [],
				reason: (FAMILY[family(preferred)] || FAMILY.generic).purpose,
				runtimeProven: true
			};
		});

		return {
			schema: 'cresco-widget-intelligence/v1',
			source: 'active-elementor-runtime+deterministic-semantic-families',
			rules: [
				'Recommend only widget/element types present in the active runtime.',
				'Prefer the semantic widget for content/interaction; use Container for structure and layout.',
				'HTML/code is expert fallback, not a substitute for Heading, Text Editor, Button, Icon, Image, Form or native layout.',
				'Preserve dynamic/global bindings and behavioral settings unless the task explicitly requests them.'
			],
			roles: roles,
			widgets: widgets
		};
	}

	function controlExample(control) {
		if (!control || typeof control !== 'object') return null;
		if (has(control, 'default') && control.default !== '' && control.default != null) return clone(control.default);
		var options = control.options && typeof control.options === 'object' ? Object.keys(control.options) : [];
		if (options.length) return options[0];
		var units = Array.isArray(control.size_units) ? control.size_units : [];
		if (units.length) {
			var unit = units.indexOf('px') !== -1 ? 'px' : units[0];
			var range = control.range && control.range[unit] && typeof control.range[unit] === 'object' ? control.range[unit] : control.range;
			var min = range && typeof range.min === 'number' ? range.min : null;
			var max = range && typeof range.max === 'number' ? range.max : null;
			var size = min != null && max != null ? Math.round(((min + max) / 2) * 100) / 100 : (min != null ? min : 0);
			return { unit: unit, size: size, sizes: [] };
		}
		if (control.type === 'switcher') return 'yes';
		return null;
	}

	function controlExamples(context) {
		var runtime = context.runtime || {};
		var out = { schema: 'cresco-control-examples/v1', widgets: {}, elements: {}, rules: [] };
		['widgets', 'elements'].forEach(function (kind) {
			Object.keys(runtime[kind] || {}).forEach(function (name) {
				var examples = {};
				Object.keys((runtime[kind][name] && runtime[kind][name].controls) || {}).slice(0, 80).forEach(function (key) {
					var example = controlExample(runtime[kind][name].controls[key]);
					if (example != null) examples[key] = example;
				});
				if (Object.keys(examples).length) out[kind][name] = examples;
			});
		});
		out.rules.push('Examples describe the value shape only; runtime range/options remain authoritative.');
		out.rules.push('If a requested px value is outside a slider range and the same native control supports custom, Cresco may normalize it to a custom CSS-length value before validation.');
		out.rules.push('Never clamp or convert values when semantic equivalence cannot be proven.');
		return out;
	}

	function nodeText(element) {
		var settings = (element && element.settings) || {};
		return String(settings.title || settings.text || settings.editor || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 160);
	}

	function semanticRole(element, counters) {
		var type = String(element.widgetType || element.elType || '');
		var fam = family(type);
		var settings = element.settings || {};
		if (fam === 'heading') {
			if (String(settings.header_size || '').toLowerCase() === 'h1') return { role: 'headline', confidence: 0.98 };
			var text = nodeText(element);
			if (text && text.length < 90 && text === text.toUpperCase()) return { role: 'eyebrow', confidence: 0.72 };
			return { role: 'section-heading', confidence: 0.9 };
		}
		if (fam === 'text') return { role: 'body-copy', confidence: 0.94 };
		if (fam === 'button') {
			counters.buttons = (counters.buttons || 0) + 1;
			return { role: counters.buttons === 1 ? 'primary-cta' : 'secondary-cta', confidence: 0.8 };
		}
		if (fam === 'form') return { role: 'lead-form', confidence: 0.96 };
		if (fam === 'list') return { role: 'proof-list', confidence: 0.82 };
		if (fam === 'image') return { role: 'media', confidence: 0.92 };
		if (fam === 'icon') return { role: 'decorative-icon', confidence: 0.82 };
		if (fam === 'navigation') return { role: 'navigation', confidence: 0.96 };
		if (fam === 'query') return { role: 'content-grid', confidence: 0.88 };
		if (fam === 'carousel') return { role: 'carousel', confidence: 0.92 };
		if (fam === 'disclosure') return { role: 'faq', confidence: 0.78 };
		if (fam === 'video') return { role: 'video', confidence: 0.94 };
		if (fam === 'layout') {
			var childFamilies = (element.elements || []).map(function (child) { return family(child.widgetType || child.elType || ''); });
			if (childFamilies.indexOf('form') !== -1) return { role: 'form-card', confidence: 0.78 };
			if (childFamilies.filter(function (x) { return x === 'button'; }).length >= 1) return { role: 'cta-row', confidence: 0.72 };
			return { role: 'layout-group', confidence: 0.9 };
		}
		return { role: 'element', confidence: 0.5 };
	}

	function semanticScene(context) {
		var rootElement = context.currentInterface && context.currentInterface.element;
		var request = words(context.task && context.task.request);
		var rootRole = /hero/.test(request) ? 'hero' : /ticker|marquee|trust strip/.test(request) ? 'trust-ticker' : /faq/.test(request) ? 'faq-section' : 'selected-region';
		var parts = [];
		var counters = {};
		function walk(element, parentId, depth) {
			if (!element || typeof element !== 'object') return;
			var semantic = semanticRole(element, counters);
			parts.push({
				elementId: String(element.id || ''), parentId: parentId || '', depth: depth,
				role: semantic.role, confidence: semantic.confidence,
				elType: String(element.elType || ''), widgetType: String(element.widgetType || ''),
				textPreview: nodeText(element)
			});
			(element.elements || []).forEach(function (child) { walk(child, String(element.id || ''), depth + 1); });
		}
		walk(rootElement, '', 0);
		return {
			schema: 'cresco-semantic-scene/v1',
			region: { role: rootRole, elementId: context.target && context.target.id || '', confidence: rootRole === 'selected-region' ? 0.55 : 0.82 },
			parts: parts,
			note: 'Roles are deterministic guidance inferred from runtime type, content and hierarchy; confidence below 0.8 should not be treated as authoritative.'
		};
	}

	function placementContext(context) {
		var target = context.target || {};
		var scope = target.scope || 'subtree';
		var info = context.sourceContext && Array.isArray(context.sourceContext.elementContext) ? context.sourceContext.elementContext[0] : null;
		var parent = info && info.parent ? info.parent : null;
		var siblings = info && Array.isArray(info.siblings) ? info.siblings : [];
		var index = info && typeof info.index === 'number' ? info.index : -1;
		var editable = new Set(target.editableElementIds || []);
		var canAcceptChildren = !!(context.currentInterface && context.currentInterface.element && context.currentInterface.element.elType !== 'widget');
		var siblingWritable = !!parent && editable.has(String(parent.id || ''));
		var placements = [];
		if (canAcceptChildren) {
			placements.push({ intent: 'inside-start', parentId: target.id, position: 0, allowed: true });
			placements.push({ intent: 'inside-end', parentId: target.id, position: 999999, allowed: true });
		}
		if (parent) {
			placements.push({ intent: 'before-target', parentId: parent.id, position: Math.max(0, index), allowed: siblingWritable, requiresWiderScope: !siblingWritable });
			placements.push({ intent: 'after-target', parentId: parent.id, position: index < 0 ? 999999 : index + 1, allowed: siblingWritable, requiresWiderScope: !siblingWritable });
		}
		return {
			schema: 'cresco-placement-context/v1', targetId: target.id || '', scope: scope,
			parent: parent ? clone(parent) : null, index: index,
			previousSibling: index > 0 ? clone(siblings[index - 1]) : null,
			nextSibling: index >= 0 && index + 1 < siblings.length ? clone(siblings[index + 1]) : null,
			canAcceptChildren: canAcceptChildren, allowedPlacements: placements,
			instruction: siblingWritable ? 'Sibling placement is inside the editable scope.' : 'If a requested before/after placement is marked requiresWiderScope, select/export the parent Container instead of inserting outside the current scope.'
		};
	}

	function mutationBoundary(context) {
		var editable = unique((context.target && context.target.editableElementIds) || []);
		var readOnly = [];
		var protectedItems = [];
		var info = context.sourceContext && Array.isArray(context.sourceContext.elementContext) ? context.sourceContext.elementContext[0] : null;
		if (info && info.parent && editable.indexOf(String(info.parent.id || '')) === -1) readOnly.push(String(info.parent.id || ''));
		(info && info.siblings || []).forEach(function (sibling) {
			var id = String(sibling.id || '');
			if (id && editable.indexOf(id) === -1) readOnly.push(id);
		});
		(context.semanticScene && context.semanticScene.parts || []).forEach(function (part) {
			var fam = family(part.widgetType || part.elType);
			if (!PROTECTED[fam]) return;
			protectedItems.push({ elementId: part.elementId, family: fam, properties: PROTECTED[fam], reason: 'Behavioral/external binding: preserve during generic visual edits unless explicitly requested.' });
		});
		return {
			schema: 'cresco-mutation-boundary/v1', editable: editable, readOnlyContext: unique(readOnly), protected: protectedItems,
			preserveByDefault: ['__globals__', 'dynamicTags', 'media attachment IDs', 'template/query bindings', 'unknown persisted Elementor fields']
		};
	}

	function roleWidget(intelligence, role, fallbackRole) {
		var record = intelligence.roles[role] || intelligence.roles[fallbackRole || ''];
		return record && record.preferredWidget || '';
	}

	function constructionPlan(context, intelligence) {
		var request = words(context.task && context.task.request);
		var pattern = /ticker|marquee|trust strip/.test(request) ? 'trust-ticker' : /faq/.test(request) ? 'faq-section' : /hero/.test(request) ? (/form|survey|lead/.test(request) ? 'two-column-lead-hero' : 'hero-section') : 'generic-section';
		var recipes = {
			'two-column-lead-hero': [
				['hero-shell', 'layout-group'], ['content-column', 'layout-group'], ['eyebrow', 'eyebrow'], ['headline', 'headline'],
				['description', 'body-copy'], ['cta-row', 'layout-group'], ['primary-cta', 'primary-cta'], ['secondary-cta', 'secondary-cta'],
				['proof', 'proof-list'], ['form-card', 'layout-group'], ['lead-form', 'lead-form']
			],
			'hero-section': [['hero-shell', 'layout-group'], ['headline', 'headline'], ['description', 'body-copy'], ['primary-cta', 'primary-cta'], ['media', 'media']],
			'trust-ticker': [['ticker-shell', 'layout-group'], ['ticker-track', 'layout-group'], ['ticker-item', 'layout-group'], ['ticker-text', 'body-copy'], ['separator', 'decorative-icon']],
			'faq-section': [['faq-shell', 'layout-group'], ['section-heading', 'section-heading'], ['faq', 'faq']],
			'generic-section': [['section-shell', 'layout-group'], ['section-heading', 'section-heading'], ['body-copy', 'body-copy']]
		};
		var steps = (recipes[pattern] || recipes['generic-section']).map(function (pair) {
			var widget = roleWidget(intelligence, pair[1], pair[1] === 'layout-group' ? 'layout-group' : '');
			return { role: pair[0], semanticRole: pair[1], widgetType: widget, supported: !!widget };
		});
		return {
			schema: 'cresco-construction-plan/v1', pattern: pattern,
			recommended: steps.filter(function (step) { return step.supported; }),
			unsupported: steps.filter(function (step) { return !step.supported; }),
			rule: 'Every recommended widget is runtime-proven. Unsupported recipe parts must be redesigned with a supported alternative rather than invented.'
		};
	}

	function runtimeSelection(context) {
		var relevant = new Set();
		Object.keys((context.currentInterface && context.currentInterface.widgetTypes) || {}).forEach(function (name) { relevant.add(name); });
		(context.constructionPlan && context.constructionPlan.recommended || []).forEach(function (step) { if (step.widgetType) relevant.add(step.widgetType); });
		var all = Object.keys((context.runtime && context.runtime.widgets) || {});
		return {
			schema: 'cresco-task-runtime-selection/v1',
			relevantWidgetTypes: Array.from(relevant),
			availableWidgetCount: all.length,
			omittedFromGuidanceCount: Math.max(0, all.length - Array.from(relevant).filter(function (name) { return all.indexOf(name) !== -1; }).length),
			note: 'Full runtime remains available for exact validation; this list identifies the task-relevant construction set so the AI does not need to reason over every installed widget.'
		};
	}

	function outputContract(context) {
		var old = clone(context.outputContract || {});
		old.schema = 'cresco-ai-output-contract/v3';
		old.preferredSchema = 'cresco-ai-mutation/v2';
		old.strategy = context.task && context.task.changeType === 'rebuild' ? 'explicit-rebuild' : 'semantic-delta-first';
		old.idPolicy = 'Never invent final Elementor IDs for new nodes. Omit id or use a unique $new:<name> ref; Cresco allocates final IDs.';
		old.widgetPolicy = 'Choose widgetIntent from widgetIntelligence roles and active runtime only.';
		old.semanticMutationTemplate = {
			schema: 'cresco-ai-mutation/v2',
			intent: context.task && context.task.changeType === 'edit' ? 'edit' : 'add',
			target: { postId: context.target && context.target.postId || 0, id: context.target && context.target.id || '' },
			placement: { mode: 'inside-end' },
			nodes: [ { ref: '$new:headline', role: 'headline', widgetIntent: roleWidget(context.widgetIntelligence || { roles: {} }, 'headline') || '<runtime-widget>', content: { text: '<content>', semanticLevel: 'h2' }, settings: {} } ]
		};
		old.compatibility = ['cresco-layer-patch/v1', 'cresco-layer-ai-result/v1'];
		old.rules = unique((old.rules || []).concat([
			'Use semantic mutation v2 when possible; Cresco compiles it to an internal scoped patch.',
			'Use widgetIntelligence for widget choice and controlExamples only as shape hints; runtime metadata is authoritative.',
			'Do not use a sibling placement marked requiresWiderScope; ask/select the parent Container instead.',
			'Preserve mutationBoundary protected bindings during visual-only work.'
		]));
		return old;
	}

	function quality(context) {
		var previous = context.contextQuality || {};
		var visual = context.visualSnapshot || {};
		var visualScore = visual.status === 'trusted' ? Math.round(15 * Math.max(0, Math.min(1, Number(visual.confidence || 0)))) : Math.min(4, Math.round(15 * Number(visual.confidence || 0)));
		var checks = [
			{ key: 'runtimeCoverage', max: 15, score: context.runtime && context.runtime.mode === 'exact-runtime' ? 15 : 0 },
			{ key: 'widgetIntelligence', max: 15, score: Object.keys((context.widgetIntelligence && context.widgetIntelligence.roles) || {}).length >= 5 ? 15 : 8 },
			{ key: 'semanticScene', max: 10, score: context.semanticScene && context.semanticScene.parts && context.semanticScene.parts.length ? 10 : 0 },
			{ key: 'visualConfidence', max: 15, score: visualScore },
			{ key: 'activeKit', max: 10, score: context.designSystem && Object.keys(context.designSystem).length ? 10 : 0 },
			{ key: 'responsive', max: 10, score: context.responsive && Object.keys(context.responsive).length ? 10 : 0 },
			{ key: 'placement', max: 10, score: context.placementContext && context.placementContext.allowedPlacements ? 10 : 0 },
			{ key: 'bindingsProtection', max: 10, score: context.mutationBoundary ? 10 : 0 },
			{ key: 'outputContract', max: 5, score: context.outputContract && context.outputContract.preferredSchema === 'cresco-ai-mutation/v2' ? 5 : 0 }
		];
		checks.forEach(function (check) { check.ok = check.score === check.max; });
		var score = checks.reduce(function (sum, check) { return sum + check.score; }, 0);
		var warnings = (previous.warnings || []).slice();
		if (visual.status !== 'trusted') warnings.push('Pixel/geometry matching is limited because the live preview snapshot is not trusted. Attach a reference image and do not infer exact geometry from untrusted bounds.');
		if (!context.placementContext || !context.placementContext.allowedPlacements.length) warnings.push('Placement context is incomplete; structural additions may require exporting a parent Container.');
		return { schema: 'cresco-context-quality/v3', score: score, grade: score >= 95 ? 'Excellent' : score >= 80 ? 'Good' : score >= 65 ? 'Usable' : 'Incomplete', checks: checks, warnings: unique(warnings) };
	}

	function enrich(context) {
		if (!context || context.schema !== 'cresco-ai-context/v3') return context;
		var out = clone(context);
		out.widgetIntelligence = widgetIntelligence(out);
		out.controlExamples = controlExamples(out);
		out.semanticScene = semanticScene(out);
		out.placementContext = placementContext(out);
		out.mutationBoundary = mutationBoundary(out);
		out.constructionPlan = constructionPlan(out, out.widgetIntelligence);
		out.runtimeSelection = runtimeSelection(out);
		out.outputContract = outputContract(out);
		out.contextQuality = quality(out);
		out.rules = out.rules || {};
		out.rules.finalElementIdsOwnedByCresco = true;
		out.rules.widgetChoiceMustBeRuntimeProven = true;
		out.rules.semanticMutationPreferred = true;
		out.aiBrief = String(out.aiBrief || '') + [
			'', 'External AI intelligence:',
			'- Choose widgets from widgetIntelligence/constructionPlan only.',
			'- New nodes: omit final id or use unique $new:<name>; Cresco owns final Elementor IDs.',
			'- Follow placementContext; never write outside the editable scope.',
			'- Preserve mutationBoundary protected/global/dynamic bindings.',
			'- Preferred output schema: cresco-ai-mutation/v2.'
		].join('\n');
		state.lastScore = out.contextQuality.score;
		state.lastTarget = out.target && out.target.id || '';
		return out;
	}

	function jsonResponse(original, payload) {
		var headers = new Headers(original.headers || {});
		headers.delete('content-length'); headers.delete('content-encoding'); headers.set('content-type', 'application/json; charset=UTF-8');
		return new Response(JSON.stringify(payload), { status: original.status, statusText: original.statusText, headers: headers });
	}

	if (upstreamFetch) {
		state.installed = true;
		window.fetch = function (input, init) {
			if (!isExport(input)) return upstreamFetch(input, init);
			return upstreamFetch(input, init).then(function (response) {
				if (!response.ok) return response;
				return response.clone().json().then(function (payload) {
					return jsonResponse(response, enrich(payload));
				}).catch(function (error) {
					state.lastError = error && error.message ? error.message : String(error);
					return response;
				});
			});
		};
	}

	window.CrescoLayerExternalAIIntelligence = {
		version: '1.0.0',
		enrich: enrich,
		family: family,
		getDiagnostics: function () { return clone(state); }
	};
}());
