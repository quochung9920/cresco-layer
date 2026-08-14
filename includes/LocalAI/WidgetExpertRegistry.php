<?php
namespace CrescoLayer\LocalAI;

final class WidgetExpertRegistry {
	public static function for( string $kind, string $name, array $knowledge = [] ): array {
		$family = self::family( strtolower( $name ) );
		$cards = self::cards();
		$card = $cards[ $family ] ?? $cards['generic'];
		$card['family'] = $family;
		$card['widgetType'] = $name;
		$card['kind'] = $kind;
		$card['preferredRoles'] = array_values( array_unique( array_merge( (array) ( $card['preferredRoles'] ?? [] ), (array) ( $knowledge['preferredRoles'] ?? [] ) ) ) );
		$card['runtimeNotes'] = array_slice( array_values( array_filter( array_map( 'strval', (array) ( $knowledge['notes'] ?? [] ) ) ) ), 0, 12 );
		return $card;
	}

	private static function family( string $name ): string {
		if ( preg_match( '/container|section|column|flex|grid/', $name ) ) { return 'layout'; }
		if ( preg_match( '/heading|headline|title/', $name ) ) { return 'heading'; }
		if ( preg_match( '/text|editor|paragraph/', $name ) ) { return 'text'; }
		if ( preg_match( '/button|cta/', $name ) ) { return 'button'; }
		if ( preg_match( '/image|gallery|media/', $name ) ) { return 'image'; }
		if ( preg_match( '/form|login|search-form/', $name ) ) { return 'form'; }
		if ( preg_match( '/nav|menu|breadcrumb/', $name ) ) { return 'navigation'; }
		if ( preg_match( '/loop|posts|archive|query|portfolio/', $name ) ) { return 'query'; }
		if ( preg_match( '/carousel|slides|slider/', $name ) ) { return 'carousel'; }
		if ( preg_match( '/accordion|toggle|tabs/', $name ) ) { return 'disclosure'; }
		if ( preg_match( '/video|youtube|vimeo/', $name ) ) { return 'video'; }
		if ( preg_match( '/icon|social/', $name ) ) { return 'icon'; }
		if ( preg_match( '/html|code|shortcode/', $name ) ) { return 'code'; }
		if ( preg_match( '/product|woocommerce|cart|checkout|price/', $name ) ) { return 'commerce'; }
		return 'generic';
	}

	private static function cards(): array {
		return [
			'layout' => [
				'purpose' => 'Arrange child elements and control responsive layout, sizing, spacing and visual containment.',
				'importantParts' => [ 'layout mode', 'direction', 'wrap', 'justify', 'align', 'gap', 'width', 'min height', 'padding', 'overflow', 'responsive stacking' ],
				'designRules' => [ 'Prefer native flex/grid controls over custom CSS.', 'Check child widths together with gap before changing parent width.', 'On narrow screens avoid horizontal overflow and preserve readable stacking.', 'Do not change structure when a responsive control can solve the problem.' ],
				'commonProblems' => [ 'horizontal overflow', 'unbalanced columns', 'incorrect mobile stacking', 'excessive min height', 'inconsistent gap', 'misaligned children', 'double spacing from parent and child' ],
				'preferredRoles' => [ 'layout.width', 'layout.min-height', 'layout.gap', 'spacing.padding', 'spacing.margin' ],
			],
			'heading' => [
				'purpose' => 'Display a semantic heading or short prominent text that establishes visual and document hierarchy.',
				'importantParts' => [ 'content', 'semantic level', 'font size', 'weight', 'line height', 'color', 'alignment', 'responsive scale' ],
				'designRules' => [ 'Preserve semantic heading level unless explicitly requested.', 'Keep multiline line-height readable.', 'Scale typography to the available mobile width.', 'Preserve adequate color contrast.' ],
				'commonProblems' => [ 'oversized mobile heading', 'poor line height', 'weak hierarchy', 'weak contrast', 'excessive margin', 'awkward wrapping' ],
				'preferredRoles' => [ 'typography.font-size', 'typography.font-weight', 'typography.color', 'typography.align', 'spacing.margin' ],
			],
			'text' => [
				'purpose' => 'Present readable body copy or descriptive content.',
				'importantParts' => [ 'content', 'font size', 'line height', 'measure', 'color', 'alignment', 'spacing' ],
				'designRules' => [ 'Prioritize readability and stable line length.', 'Avoid reducing body text excessively on mobile.', 'Preserve links and dynamic content bindings.' ],
				'commonProblems' => [ 'dense copy', 'small mobile text', 'long line length', 'low contrast', 'inconsistent paragraph spacing' ],
				'preferredRoles' => [ 'typography.font-size', 'typography.color', 'spacing.margin' ],
			],
			'button' => [
				'purpose' => 'Provide a clear interactive call to action.',
				'importantParts' => [ 'label', 'link', 'size', 'padding', 'contrast', 'border radius', 'alignment', 'hover state' ],
				'designRules' => [ 'Keep the call to action visually distinct.', 'Preserve a usable touch target.', 'Do not detach dynamic links unintentionally.', 'Maintain readable normal and hover contrast.' ],
				'commonProblems' => [ 'weak prominence', 'small touch target', 'poor contrast', 'inconsistent radius', 'misalignment', 'mobile width mismatch' ],
				'preferredRoles' => [ 'style.background-color', 'typography.color', 'style.border-radius', 'spacing.padding', 'layout.width' ],
			],
			'image' => [
				'purpose' => 'Display visual media while preserving aspect ratio, crop intent, accessibility and responsive behavior.',
				'importantParts' => [ 'source', 'alt/caption', 'size', 'aspect ratio', 'object fit', 'position', 'border radius', 'responsive width' ],
				'designRules' => [ 'Avoid distortion.', 'Preserve accessibility metadata.', 'Prefer native image sizing and object-fit controls.', 'Check crop behavior across breakpoints.' ],
				'commonProblems' => [ 'distortion', 'unwanted crop', 'overflow', 'missing accessible description', 'inconsistent aspect ratio', 'oversized mobile media' ],
				'preferredRoles' => [ 'layout.width', 'style.border-radius', 'spacing.margin' ],
			],
			'form' => [
				'purpose' => 'Collect user input with clear labels, validation, actions and accessible interaction states.',
				'importantParts' => [ 'fields', 'labels', 'required state', 'validation', 'submit action', 'messages', 'field spacing', 'button', 'privacy' ],
				'designRules' => [ 'Do not alter external actions or credentials through generic styling requests.', 'Preserve required/validation semantics.', 'Keep labels and errors readable.', 'Optimize mobile field widths and touch targets.' ],
				'commonProblems' => [ 'dense fields', 'unclear labels', 'weak validation feedback', 'small submit target', 'mobile overflow', 'unsafe external action changes' ],
				'preferredRoles' => [ 'spacing.padding', 'spacing.margin', 'layout.width', 'typography.font-size' ],
			],
			'navigation' => [
				'purpose' => 'Expose site navigation and nested destinations consistently across breakpoints.',
				'importantParts' => [ 'menu source', 'layout', 'breakpoint', 'dropdown', 'submenu', 'active state', 'spacing', 'accessibility' ],
				'designRules' => [ 'Preserve keyboard/focus behavior.', 'Check mobile collapse behavior before changing spacing.', 'Keep active and hover states distinguishable.' ],
				'commonProblems' => [ 'submenu overflow', 'poor mobile collapse', 'weak active state', 'crowded items', 'inconsistent dropdown spacing' ],
				'preferredRoles' => [ 'layout.gap', 'spacing.padding', 'typography.color' ],
			],
			'query' => [
				'purpose' => 'Render dynamic collections from a query/template configuration.',
				'importantParts' => [ 'query', 'template', 'columns', 'gap', 'pagination', 'taxonomy', 'responsive layout' ],
				'designRules' => [ 'Treat query changes as higher risk than visual changes.', 'Do not change data source when the request is purely visual.', 'Preserve pagination and template bindings.' ],
				'commonProblems' => [ 'wrong column count', 'dense cards', 'query mismatch', 'pagination inconsistency', 'mobile grid overflow' ],
				'preferredRoles' => [ 'layout.gap', 'spacing.padding', 'layout.width' ],
			],
			'carousel' => [
				'purpose' => 'Present multiple items in a controlled sliding viewport.',
				'importantParts' => [ 'slides', 'slides per view', 'gap', 'navigation', 'autoplay', 'loop', 'height', 'responsive settings' ],
				'designRules' => [ 'Avoid aggressive autoplay.', 'Keep navigation discoverable.', 'Check slides-per-view at every breakpoint.' ],
				'commonProblems' => [ 'cropped content', 'too many mobile slides', 'navigation overlap', 'excessive motion', 'height jumps' ],
				'preferredRoles' => [ 'layout.gap', 'layout.width', 'spacing.padding' ],
			],
			'disclosure' => [
				'purpose' => 'Reveal and hide grouped content through tabs, accordion or toggle interaction.',
				'importantParts' => [ 'items', 'active state', 'title', 'content', 'icon', 'spacing', 'keyboard interaction' ],
				'designRules' => [ 'Preserve keyboard and expanded-state semantics.', 'Keep title and content hierarchy clear.', 'Avoid hiding essential information unintentionally.' ],
				'commonProblems' => [ 'unclear active state', 'dense content', 'icon misalignment', 'excessive nested spacing' ],
				'preferredRoles' => [ 'spacing.padding', 'typography.color', 'style.background-color' ],
			],
			'video' => [
				'purpose' => 'Embed video media with responsive sizing and controlled playback behavior.',
				'importantParts' => [ 'source', 'aspect ratio', 'poster', 'controls', 'autoplay', 'mute', 'responsive width' ],
				'designRules' => [ 'Avoid autoplay with sound.', 'Preserve aspect ratio.', 'Treat source changes separately from styling.' ],
				'commonProblems' => [ 'aspect ratio mismatch', 'mobile overflow', 'intrusive autoplay', 'missing poster' ],
				'preferredRoles' => [ 'layout.width', 'style.border-radius', 'spacing.margin' ],
			],
			'icon' => [
				'purpose' => 'Provide a compact visual symbol or social action.',
				'importantParts' => [ 'icon', 'size', 'color', 'background', 'spacing', 'link', 'accessible label' ],
				'designRules' => [ 'Keep icon meaning clear.', 'Preserve adequate touch target when interactive.', 'Maintain contrast.' ],
				'commonProblems' => [ 'tiny touch target', 'weak contrast', 'misalignment', 'inconsistent icon size' ],
				'preferredRoles' => [ 'typography.color', 'style.background-color', 'spacing.padding' ],
			],
			'code' => [
				'purpose' => 'Render custom markup, code or shortcode output.',
				'importantParts' => [ 'content', 'execution boundary', 'output size', 'responsive wrapper' ],
				'designRules' => [ 'Treat code/content mutation as expert risk.', 'Prefer surrounding native layout controls for visual changes.', 'Never invent executable code for a styling-only request.' ],
				'commonProblems' => [ 'unsafe mutation', 'overflow', 'unbounded embed size', 'styling coupled to custom code' ],
				'preferredRoles' => [ 'layout.width', 'spacing.padding', 'spacing.margin' ],
			],
			'commerce' => [
				'purpose' => 'Render commerce data and transactional interactions.',
				'importantParts' => [ 'product data', 'price', 'quantity', 'actions', 'layout', 'responsive behavior', 'transaction safety' ],
				'designRules' => [ 'Keep transactional behavior separate from visual edits.', 'Never modify payment/security configuration through generic skills.', 'Preserve dynamic product bindings.' ],
				'commonProblems' => [ 'dense product layout', 'mobile overflow', 'weak CTA hierarchy', 'price hierarchy', 'unsafe transactional changes' ],
				'preferredRoles' => [ 'layout.width', 'layout.gap', 'spacing.padding', 'typography.font-size' ],
			],
			'generic' => [
				'purpose' => 'Render an Elementor element using the capabilities exposed by its runtime controls.',
				'importantParts' => [ 'content', 'layout', 'style', 'responsive behavior', 'dynamic bindings' ],
				'designRules' => [ 'Use only runtime-proven native skills.', 'Preserve IDs, dynamic bindings and global references.', 'Prefer the smallest effective change.' ],
				'commonProblems' => [ 'spacing inconsistency', 'responsive mismatch', 'weak hierarchy', 'overflow', 'unintended local override' ],
				'preferredRoles' => [],
			],
		];
	}
}
