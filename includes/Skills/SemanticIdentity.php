<?php
namespace CrescoLayer\Skills;

final class SemanticIdentity {
	public const VERSION = 2;

	public static function enrich( array $skill, array $element = [] ): array {
		$role = trim( (string) ( $skill['role'] ?? '' ) );
		$setting = trim( (string) ( $skill['setting'] ?? $skill['control'] ?? '' ) );
		$label = trim( (string) ( $skill['label'] ?? '' ) );
		$element_name = strtolower( trim( (string) ( $element['name'] ?? $element['widgetType'] ?? $element['elType'] ?? '' ) ) );
		$haystack = strtolower( implode( ' ', [ $setting, (string) ( $skill['control'] ?? '' ), $label, (string) ( $skill['group']['type'] ?? '' ), (string) ( $skill['group']['prefix'] ?? '' ) ] ) );

		$domain = self::domain( $role, (string) ( $skill['category'] ?? '' ), $haystack );
		$target = self::target( $role, $element_name, $haystack );
		$state = self::state( $haystack );
		$property = self::property( $role, $setting, (string) ( $skill['type'] ?? '' ) );
		$source = self::slug( '' !== $setting ? $setting : (string) ( $skill['control'] ?? 'control' ) );
		$base = implode( '.', array_filter( [ $domain, $target, $property, 'normal' === $state ? '' : $state ] ) );
		$semantic_id = $base . ( '' !== $source ? '#' . $source : '' );
		$source_label = self::humanize( $setting );
		$display = self::humanize( $target ) . ' · ' . ( '' !== $label ? $label : self::humanize( $property ) );
		if ( '' !== $source_label && strtolower( $source_label ) !== strtolower( $label ) && strlen( $source_label ) <= 48 ) {
			$display .= ' (' . $source_label . ')';
		}
		if ( 'normal' !== $state ) { $display .= ' · ' . ucfirst( $state ); }

		$skill['semanticVersion'] = self::VERSION;
		$skill['semanticId'] = $semantic_id;
		$skill['semanticBase'] = $base;
		$skill['semanticDomain'] = $domain;
		$skill['targetPart'] = $target;
		$skill['property'] = $property;
		$skill['state'] = $state;
		$skill['displayLabel'] = $display;
		$skill['purpose'] = self::purpose( $domain, $target, $property, $state );
		$skill['searchTerms'] = array_values( array_unique( array_filter( array_merge(
			(array) ( $skill['searchTerms'] ?? [] ),
			[ $semantic_id, $base, $domain, $target, $property, $state, $source ]
		) ) ) );
		return $skill;
	}

	private static function domain( string $role, string $category, string $haystack ): string {
		if ( str_contains( $role, '.' ) ) { return self::slug( explode( '.', $role, 2 )[0] ); }
		$category = strtolower( $category );
		foreach ( [ 'layout', 'spacing', 'typography', 'style', 'content', 'responsive', 'position', 'motion', 'interaction', 'data' ] as $domain ) {
			if ( str_contains( $category, $domain ) || str_contains( $haystack, $domain ) ) { return $domain; }
		}
		return 'control';
	}

	private static function target( string $role, string $element_name, string $haystack ): string {
		$targets = [
			'submenu' => '/sub[_ -]?menu|submenu/',
			'dropdown' => '/dropdown/',
			'overlay' => '/overlay/',
			'icon' => '/\bicon\b/',
			'image' => '/image|media|thumbnail/',
			'button' => '/button|submit|cta/',
			'label' => '/\blabel\b/',
			'field' => '/field|input|textarea|select/',
			'title' => '/title|heading|headline/',
			'content' => '/content|description|editor|paragraph|text/',
			'background' => '/background|bg_/',
			'border' => '/border/',
			'item' => '/item|slide|tab|accordion/',
		];
		foreach ( $targets as $target => $pattern ) { if ( preg_match( $pattern, $haystack ) ) { return $target; } }
		if ( preg_match( '/container|section|column|flex|grid/', $element_name ) && ( str_starts_with( $role, 'layout.' ) || str_starts_with( $role, 'spacing.' ) ) ) { return 'container'; }
		if ( str_starts_with( $role, 'typography.' ) ) { return 'text'; }
		return 'element';
	}

	private static function state( string $haystack ): string {
		foreach ( [ 'hover', 'active', 'focus', 'selected', 'disabled', 'checked', 'open' ] as $state ) {
			if ( preg_match( '/(?:^|[_\-\s])' . preg_quote( $state, '/' ) . '(?:$|[_\-\s])/', $haystack ) ) { return $state; }
		}
		return 'normal';
	}

	private static function property( string $role, string $setting, string $type ): string {
		if ( str_contains( $role, '.' ) ) { return self::slug( substr( $role, strpos( $role, '.' ) + 1 ) ); }
		$setting = preg_replace( '/_(?:tablet|mobile|widescreen|laptop|tablet_extra|mobile_extra)$/', '', strtolower( $setting ) ) ?? strtolower( $setting );
		$tokens = array_values( array_filter( preg_split( '/[^a-z0-9]+/', $setting ) ?: [] ) );
		if ( $tokens ) {
			$ignore = [ 'element', 'widget', 'style', 'control', 'normal', 'classic' ];
			$tokens = array_values( array_filter( $tokens, static fn( string $token ): bool => ! in_array( $token, $ignore, true ) ) );
			if ( $tokens ) { return implode( '-', array_slice( $tokens, -3 ) ); }
		}
		return self::slug( $type ?: 'value' );
	}

	private static function purpose( string $domain, string $target, string $property, string $state ): string {
		$purpose = 'Control ' . str_replace( '-', ' ', $property ) . ' for the ' . str_replace( '-', ' ', $target );
		if ( 'normal' !== $state ) { $purpose .= ' in the ' . $state . ' state'; }
		return ucfirst( $purpose ) . ' using the native Elementor ' . $domain . ' capability.';
	}

	private static function slug( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = preg_replace( '/[^a-z0-9]+/', '-', $value ) ?? '';
		return trim( $value, '-' );
	}

	private static function humanize( string $value ): string {
		$value = trim( str_replace( [ '_', '-', '.' ], ' ', $value ) );
		return '' === $value ? '' : ucwords( $value );
	}
}
