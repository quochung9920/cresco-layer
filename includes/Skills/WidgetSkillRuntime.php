<?php
namespace CrescoLayer\Skills;

use CrescoLayer\AI\ElementLocator;
use CrescoLayer\Elementor\ConfigurationCatalog;
use Elementor\Plugin as ElementorPlugin;

final class WidgetSkillRuntime {
	private ElementLocator $locator;
	private SkillCompiler $compiler;

	public function __construct(
		private ConfigurationCatalog $catalog,
		?ElementLocator $locator = null,
		?SkillCompiler $compiler = null
	) {
		$this->locator = $locator ?? new ElementLocator();
		$this->compiler = $compiler ?? new SkillCompiler();
	}

	public function profile( int $post_id, string $element_id ): array {
		$context = $this->context( $post_id, $element_id );
		$compiled = $this->compiler->compile(
			$context['entry'],
			$context['currentSettings'],
			$context['breakpoints'],
			$context['knowledge']
		);
		$semantic_skills = array_map(
			static fn( array $skill ): array => SemanticIdentity::enrich( $skill, $context['element'] ),
			(array) $compiled['skills']
		);

		$result = [
			'schema' => SkillCompiler::SCHEMA,
			'generatedAt' => gmdate( 'c' ),
			'pluginVersion' => defined( 'CRESCO_LAYER_VERSION' ) ? CRESCO_LAYER_VERSION : '',
			'elementorVersion' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
			'elementorProVersion' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : '',
			'element' => $context['element'],
			'breakpoints' => $context['breakpoints'],
			'knowledge' => $context['knowledge'],
			'currentSettings' => $context['currentSettings'],
			'globalReferences' => is_array( $context['currentSettings']['__globals__'] ?? null ) ? $context['currentSettings']['__globals__'] : [],
			'compiler' => [
				'version' => $compiled['compilerVersion'],
				'capabilitySource' => $compiled['capabilitySource'],
				'isAtomic' => $compiled['isAtomic'],
				'controlCount' => $compiled['controlCount'],
				'skillCount' => $compiled['skillCount'],
				'executableSkillCount' => $compiled['executableSkillCount'],
				'semanticIdentityVersion' => SemanticIdentity::VERSION,
			],
			'categories' => $compiled['categories'],
			'roles' => $compiled['roles'],
			'skills' => $semantic_skills,
			'commandExamples' => array_values( array_unique( array_merge(
				(array) ( $context['knowledge']['commandExamples'] ?? [] ),
				$this->examples_from_skills( $semantic_skills )
			) ) ),
			'principles' => [
				'No chatbot or LLM is involved in skill resolution.',
				'Runtime control metadata is the source of truth; Cresco never invents an Elementor setting key.',
				'Semantic Skill Identity V2 disambiguates target part, property and interaction state without replacing the native control binding.',
				'Commands are parsed deterministically into skills and validated against the selected widget controls.',
				'Elementor remains the owner of live editor history, rendering and persistence.',
			],
		];

		return $this->redact( $result );
	}

	public function resolve( int $post_id, string $element_id, array $request ): array {
		$context = $this->context( $post_id, $element_id );
		$current = $context['currentSettings'];
		if ( isset( $request['liveSettings'] ) ) {
			if ( ! is_array( $request['liveSettings'] ) ) { throw new \InvalidArgumentException( 'liveSettings must be an object.' ); }
			$current = array_replace( $current, $request['liveSettings'] );
		}
		$compiled = $this->compiler->compile( $context['entry'], $current, $context['breakpoints'], $context['knowledge'] );
		$command = trim( (string) ( $request['command'] ?? '' ) );
		if ( '' !== $command ) {
			$resolution = $this->compiler->command( $compiled, $command, $current, $element_id );
			$resolution['command'] = $command;
		} else {
			$skill_id = trim( (string) ( $request['skillId'] ?? '' ) );
			if ( '' === $skill_id ) { throw new \InvalidArgumentException( 'skillId or command is required.' ); }
			$params = is_array( $request['params'] ?? null ) ? $request['params'] : [];
			$resolution = $this->compiler->resolve( $compiled, $skill_id, $params, $current, $element_id );
		}

		foreach ( (array) ( $resolution['operations'] ?? [] ) as $operation ) {
			$key = strtolower( (string) ( $operation['setting'] ?? '' ) );
			if ( preg_match( '/(?:secret|password|passwd|api[_-]?key|private[_-]?key|access[_-]?token|refresh[_-]?token|authorization|nonce|smtp[_-]?pass|webhook[_-]?secret)/i', $key ) ) {
				throw new \InvalidArgumentException( 'Sensitive Elementor settings cannot be modified by a generic Cresco skill.' );
			}
		}
		foreach ( (array) $compiled['skills'] as $raw_skill ) {
			if ( (string) ( $raw_skill['id'] ?? '' ) !== (string) ( $resolution['skillId'] ?? '' ) ) { continue; }
			$semantic = SemanticIdentity::enrich( $raw_skill, $context['element'] );
			$resolution['semanticId'] = (string) ( $semantic['semanticId'] ?? '' );
			$resolution['displayLabel'] = (string) ( $semantic['displayLabel'] ?? $resolution['label'] ?? '' );
			$resolution['targetPart'] = (string) ( $semantic['targetPart'] ?? '' );
			$resolution['state'] = (string) ( $semantic['state'] ?? 'normal' );
			break;
		}
		$resolution['element'] = $context['element'];
		$resolution['runtimeValidated'] = true;
		$resolution['usesNativeControl'] = true;
		return $resolution;
	}

	private function context( int $post_id, string $element_id ): array {
		if ( $post_id <= 0 ) { throw new \InvalidArgumentException( 'Elementor document ID is invalid.' ); }
		if ( ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $element_id ) ) { throw new \InvalidArgumentException( 'Element ID is invalid.' ); }
		$manager = ElementorPlugin::instance()->documents;
		$main = $manager->get( $post_id );
		if ( ! $main ) { throw new \RuntimeException( 'Elementor document not found.' ); }
		$document = $manager->get_doc_or_auto_save( $post_id, get_current_user_id() );
		if ( ! $document ) { $document = $main; }
		$elements = (array) $document->get_elements_data();
		$element = $this->locator->find( $elements, $element_id );
		if ( ! is_array( $element ) ) { throw new \RuntimeException( 'Selected Elementor element no longer exists.' ); }

		$widget_type = trim( (string) ( $element['widgetType'] ?? '' ) );
		$el_type = trim( (string) ( $element['elType'] ?? '' ) );
		$kind = '' !== $widget_type ? 'widget' : 'element';
		$name = '' !== $widget_type ? $widget_type : $el_type;
		if ( '' === $name ) { throw new \RuntimeException( 'Selected Elementor element type is unknown.' ); }
		$detail = $this->catalog->detail( $kind, $name );
		$entry = is_array( $detail['entry'] ?? null ) ? $detail['entry'] : [];
		if ( ! $entry ) { throw new \RuntimeException( 'Runtime capability metadata is unavailable for the selected element.' ); }
		$breakpoints = $this->catalog->runtime_breakpoints();
		$current = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
		$knowledge = ExpertProfiles::for( $kind, $name, $entry );

		return [
			'entry' => $entry,
			'breakpoints' => $breakpoints,
			'currentSettings' => $current,
			'knowledge' => $knowledge,
			'element' => [
				'id' => $element_id,
				'kind' => $kind,
				'name' => $name,
				'title' => (string) ( $entry['title'] ?? $name ),
				'elType' => $el_type,
				'widgetType' => $widget_type,
				'isInner' => ! empty( $element['isInner'] ),
				'isAtomic' => ! empty( $entry['isAtomic'] ),
				'childCount' => count( (array) ( $element['elements'] ?? [] ) ),
			],
		];
	}

	private function examples_from_skills( array $skills ): array {
		$examples = [];
		foreach ( $skills as $skill ) {
			if ( ! is_array( $skill ) || 'direct' !== ( $skill['mode'] ?? '' ) ) { continue; }
			$role = (string) ( $skill['role'] ?? '' );
			if ( 'spacing.padding' === $role ) { $examples[] = 'padding 24px'; }
			elseif ( 'spacing.margin' === $role ) { $examples[] = 'margin 16px'; }
			elseif ( 'layout.width' === $role ) { $examples[] = 'width 50%'; }
			elseif ( 'typography.font-size' === $role ) { $examples[] = 'font size 32px'; }
			elseif ( 'typography.color' === $role ) { $examples[] = 'text color #07133F'; }
			elseif ( 'style.background-color' === $role ) { $examples[] = 'background #635BFF'; }
			elseif ( 'style.border-radius' === $role ) { $examples[] = 'radius 16px'; }
			if ( count( $examples ) >= 8 ) { break; }
		}
		return $examples;
	}

	private function redact( $value, string $key = '' ) {
		if ( preg_match( '/(?:secret|password|passwd|api[_-]?key|private[_-]?key|access[_-]?token|refresh[_-]?token|authorization|nonce|smtp[_-]?pass|webhook[_-]?secret)/i', $key ) ) { return '[REDACTED]'; }
		if ( ! is_array( $value ) ) { return $value; }
		foreach ( $value as $child_key => $child ) { $value[ $child_key ] = $this->redact( $child, (string) $child_key ); }
		return $value;
	}
}
