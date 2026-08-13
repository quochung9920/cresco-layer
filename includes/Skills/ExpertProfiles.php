<?php
namespace CrescoLayer\Skills;

final class ExpertProfiles {
	/**
	 * Human/domain knowledge augments runtime control metadata. Runtime controls
	 * remain authoritative; these profiles never invent Elementor settings.
	 */
	public static function for( string $kind, string $name, array $entry = [] ): array {
		$identity = strtolower( trim( $kind . ':' . $name . ':' . (string) ( $entry['title'] ?? '' ) ) );
		$domains = [ 'content', 'layout', 'spacing', 'style', 'responsive', 'advanced' ];
		$notes = [
			'Every executable skill must bind to a control/prop discovered from the active Elementor runtime.',
			'Persisted instance settings override registry defaults; omitted settings may still be editable through runtime defaults.',
			'Responsive, Dynamic Tag, Global Style, condition and Atomic/V4 metadata must be preserved when exposed by Elementor.',
		];
		$preferred = [];
		$examples = [];
		$matched = [];

		$profiles = self::profiles();
		foreach ( $profiles as $profile ) {
			if ( ! preg_match( $profile['pattern'], $identity ) ) { continue; }
			$matched[] = $profile['id'];
			$domains = array_merge( $domains, $profile['domains'] );
			$notes = array_merge( $notes, $profile['notes'] );
			$preferred = array_merge( $preferred, $profile['preferredRoles'] );
			$examples = array_merge( $examples, $profile['commands'] );
		}

		if ( ! empty( $entry['isAtomic'] ) ) {
			$matched[] = 'atomic-v4';
			$domains[] = 'atomic';
			$notes[] = 'Atomic/V4 skills bind to the normalized Atomic control bind/prop schema instead of guessing legacy setting names.';
		}

		return [
			'strategy' => 'runtime-derived+expert-hints',
			'kind' => $kind,
			'name' => $name,
			'profiles' => array_values( array_unique( $matched ) ),
			'domains' => array_values( array_unique( $domains ) ),
			'preferredRoles' => array_values( array_unique( $preferred ) ),
			'commandExamples' => array_values( array_unique( $examples ) ),
			'notes' => array_values( array_unique( $notes ) ),
		];
	}

	private static function profiles(): array {
		return [
			[
				'id' => 'container-layout',
				'pattern' => '/(?:element:container|element:section|inner-section|container)/',
				'domains' => [ 'flex', 'grid', 'structure', 'position', 'background', 'effects' ],
				'preferredRoles' => [ 'layout.width', 'layout.min-height', 'layout.direction', 'layout.justify', 'layout.align', 'layout.gap', 'spacing.padding', 'spacing.margin', 'style.background-color', 'style.border-radius', 'responsive.visibility' ],
				'commands' => [ 'padding 24px', 'mobile padding 20px', 'width 50%', 'min height 480px', 'gap 24px', 'background #07133F', 'radius 16px', 'hide mobile' ],
				'notes' => [ 'Container skills must respect flex/grid mode, axis semantics, wrap, child sizing and responsive inheritance.', 'Layout skills should prefer native Container controls over custom CSS.' ],
			],
			[
				'id' => 'text-content',
				'pattern' => '/(?:heading|text-editor|animated-headline|blockquote|testimonial|text-path|site-title|page-title|post-title)/',
				'domains' => [ 'typography', 'text', 'semantic-html', 'links' ],
				'preferredRoles' => [ 'content.text', 'content.html-tag', 'typography.font-size', 'typography.font-weight', 'typography.line-height', 'typography.letter-spacing', 'typography.color', 'typography.align' ],
				'commands' => [ 'font size 36px', 'mobile font size 28px', 'font weight 700', 'text color #07133F', 'align center' ],
				'notes' => [ 'Text skills distinguish content from typography and preserve links, Dynamic Tags and semantic heading tags when they are not explicitly changed.' ],
			],
			[
				'id' => 'button-action',
				'pattern' => '/(?:button|call-to-action|paypal-button|stripe-button|hotspot)/',
				'domains' => [ 'action', 'links', 'icons', 'states', 'typography' ],
				'preferredRoles' => [ 'content.text', 'content.link', 'typography.font-size', 'typography.color', 'style.background-color', 'style.border-radius', 'spacing.padding' ],
				'commands' => [ 'radius 999px', 'background #635BFF', 'text color #ffffff', 'padding 14px' ],
				'notes' => [ 'Action skills preserve URL attributes, icon placement, hover/focus states and accessibility labels unless explicitly targeted.' ],
			],
			[
				'id' => 'media-image',
				'pattern' => '/(?:image|gallery|media-carousel|image-carousel|logo|author-box)/',
				'domains' => [ 'media', 'image', 'caption', 'lightbox', 'object-fit', 'filters' ],
				'preferredRoles' => [ 'media.source', 'media.size', 'media.object-fit', 'style.border-radius', 'style.opacity', 'content.link' ],
				'commands' => [ 'radius 20px', 'opacity 90%', 'width 100%' ],
				'notes' => [ 'Media skills must preserve attachment IDs and metadata when changing presentation-only controls.', 'Gallery/repeater mutations are structural and require array-aware validation.' ],
			],
			[
				'id' => 'video-media',
				'pattern' => '/(?:video|video-playlist)/',
				'domains' => [ 'media', 'playback', 'poster', 'aspect-ratio', 'privacy' ],
				'preferredRoles' => [ 'media.source', 'media.aspect-ratio', 'media.autoplay', 'media.controls' ],
				'commands' => [ 'autoplay off', 'controls on' ],
				'notes' => [ 'Video skills distinguish provider/source controls from presentation controls and must not rewrite URLs unless requested.' ],
			],
			[
				'id' => 'forms',
				'pattern' => '/(?:form|login|search-form|subscribe)/',
				'domains' => [ 'form', 'fields', 'validation', 'actions', 'messages', 'submission', 'email', 'webhook' ],
				'preferredRoles' => [ 'form.fields', 'form.required', 'form.placeholder', 'form.actions', 'form.messages', 'style.field', 'style.button' ],
				'commands' => [ 'field gap 16px', 'button radius 8px' ],
				'notes' => [ 'Repeater field definitions and actions-after-submit are structural/external skills, not scalar style edits.', 'Email, webhook, redirect and credential-like settings require elevated risk classification and must never expose secrets.' ],
			],
			[
				'id' => 'navigation',
				'pattern' => '/(?:nav-menu|menu|table-of-contents|breadcrumbs|sitemap)/',
				'domains' => [ 'navigation', 'menu-source', 'dropdown', 'breakpoint', 'submenu', 'states' ],
				'preferredRoles' => [ 'navigation.source', 'navigation.layout', 'navigation.breakpoint', 'typography.color', 'style.background-color' ],
				'commands' => [ 'mobile breakpoint 1024px', 'align center' ],
				'notes' => [ 'Navigation skills preserve menu identity and distinguish desktop menu styling from dropdown/submenu responsive states.' ],
			],
			[
				'id' => 'query-loop',
				'pattern' => '/(?:posts|portfolio|archive|loop-grid|loop-carousel|search-results|taxonomy|product|products|related)/',
				'domains' => [ 'query', 'loop', 'pagination', 'taxonomy', 'template', 'ordering', 'commerce' ],
				'preferredRoles' => [ 'query.source', 'query.include', 'query.exclude', 'query.order', 'query.pagination', 'layout.columns' ],
				'commands' => [ 'columns 3', 'tablet columns 2', 'mobile columns 1' ],
				'notes' => [ 'Query skills must preserve query IDs, template references and filters that are not explicitly changed.', 'Include/exclude/taxonomy controls may be structured values and must not be coerced to strings.' ],
			],
			[
				'id' => 'carousel-slides',
				'pattern' => '/(?:carousel|slides|testimonial-carousel|reviews)/',
				'domains' => [ 'carousel', 'slides', 'navigation', 'autoplay', 'transition', 'responsive' ],
				'preferredRoles' => [ 'carousel.slides', 'carousel.columns', 'carousel.autoplay', 'carousel.navigation', 'carousel.speed', 'layout.gap' ],
				'commands' => [ 'autoplay off', 'slides 3', 'mobile slides 1', 'gap 20px' ],
				'notes' => [ 'Carousel skills distinguish repeater slide content from scalar playback/navigation settings.' ],
			],
			[
				'id' => 'accordion-tabs',
				'pattern' => '/(?:accordion|tabs|toggle|nested-tabs|nested-accordion)/',
				'domains' => [ 'disclosure', 'nested-content', 'states', 'icons' ],
				'preferredRoles' => [ 'content.items', 'interaction.active-item', 'style.border', 'typography.color' ],
				'commands' => [ 'radius 12px', 'gap 12px' ],
				'notes' => [ 'Nested widget children are structural Elementor elements; item content and child-tree operations must not be conflated.' ],
			],
			[
				'id' => 'metrics',
				'pattern' => '/(?:counter|progress|countdown|rating|star-rating)/',
				'domains' => [ 'metrics', 'number', 'progress', 'timing' ],
				'preferredRoles' => [ 'content.number', 'content.label', 'style.progress', 'typography.number' ],
				'commands' => [ 'text color #07133F', 'font size 42px' ],
				'notes' => [ 'Metric skills preserve numeric bounds and timing semantics exposed by control ranges.' ],
			],
			[
				'id' => 'social-interaction',
				'pattern' => '/(?:social-icons|share-buttons|facebook|twitter|soundcloud)/',
				'domains' => [ 'social', 'icons', 'networks', 'links', 'states' ],
				'preferredRoles' => [ 'content.items', 'style.icon-color', 'style.background-color', 'layout.gap' ],
				'commands' => [ 'gap 12px', 'icon size 24px' ],
				'notes' => [ 'Social item repeaters are structural; network URLs and presentation styles are separate skills.' ],
			],
			[
				'id' => 'commerce',
				'pattern' => '/(?:woocommerce|woo|product|cart|checkout|my-account|menu-cart)/',
				'domains' => [ 'commerce', 'pricing', 'cart', 'checkout', 'product-data' ],
				'preferredRoles' => [ 'commerce.source', 'commerce.price', 'commerce.cart', 'query.source', 'style.button' ],
				'commands' => [ 'button radius 8px', 'columns 4' ],
				'notes' => [ 'Commerce skills must not mutate transactional identifiers or payment configuration through a generic visual command.' ],
			],
			[
				'id' => 'code-embed',
				'pattern' => '/(?:html|shortcode|code-highlight)/',
				'domains' => [ 'code', 'embed' ],
				'preferredRoles' => [ 'content.code' ],
				'commands' => [],
				'notes' => [ 'Code/HTML/shortcode settings are expert-only; active executable markup remains subject to Cresco safety validation.' ],
			],
		];
	}
}
