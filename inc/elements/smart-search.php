<?php
/**
 * Zag - Smart search element.
 *
 * A search field that drives the other Zag filters on the page instead of
 * filtering anything itself. What the visitor types is matched against the
 * options those filters already offer, plus an authored synonym table, and
 * every match is applied as one batched filter request.
 *
 * Deliberately NOT a Bricks filter element, and deliberately absent from
 * Zag\element_map(). It holds no value of its own, so none of the impersonation
 * machinery applies: it is never indexed, never builds query vars, never goes
 * through sanitize_filter_value(), and is never re-rendered by Bricks after a
 * filter response — which is what lets the input keep focus and caret position
 * while the results update around it.
 *
 * @see src/search.js for the matching and reconciliation.
 */

namespace Zag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Element_Zag_Smart_Search extends \Bricks\Element {
	// Sits with the filter elements in the builder panel: not what it is, but
	// where anyone looking for it will look.
	public $category = 'filter';
	public $name     = 'zag-smart-search';
	public $icon     = 'ti-search';

	/**
	 * URL parameter carrying the raw sentence.
	 *
	 * Not 's' or 'q': 's' triggers WordPress' own search on the main query, and
	 * 'q' is common enough elsewhere to collide.
	 */
	const DEFAULT_PARAM = 'zag-search';

	/**
	 * Shared style controls describing a popover this element doesn't have.
	 *
	 * Same reasoning as Element_Zag_Filter_Select::UNUSED_CONTROLS: a control
	 * that renders nothing is worse than a missing one, because it invites
	 * someone to set it and then look for the bug.
	 */
	const UNUSED_STYLE_CONTROLS = [
		'zagSurface',
		'zagPopoverSep',
		'zagPopoverShadow',
	];

	public function get_label() {
		return esc_html__( 'Zag', 'zag' ) . ' - ' . esc_html__( 'Smart search', 'zag' );
	}

	public function get_keywords() {
		return [ 'filter', 'search', 'zag', 'smart', 'natural', 'sentence' ];
	}

	public function enqueue_scripts() {
		wp_enqueue_script( 'bricks-filters' );
		wp_enqueue_script( 'zag-filters' );
		wp_enqueue_style( 'zag-filters' );
	}

	public function set_control_groups() {
		$shared = control_groups();

		// Assigned in panel order.
		$this->control_groups = array_merge(
			$this->control_groups,
			[
				'zagFunction'   => $shared['zagFunction'],
				'zagVocabulary' => [
					'title' => esc_html__( 'Vocabulary', 'zag' ),
					'tab'   => 'content',
				],
				'zagStyle'      => $shared['zagStyle'],
			]
		);
	}

	public function set_controls() {
		$this->controls['zagLabel'] = [
			'group'  => 'zagFunction',
			'type'   => 'text',
			'label'  => esc_html__( 'Label', 'zag' ),
			'inline' => true,
			'desc'   => esc_html__( 'Leave empty to use the placeholder as a hidden accessible name.', 'zag' ),
		];

		$this->controls['zagLabelHidden'] = [
			'group' => 'zagFunction',
			'type'  => 'checkbox',
			'label' => esc_html__( 'Hide label visually', 'zag' ),
			'desc'  => esc_html__( 'Still read by screen readers.', 'zag' ),
		];

		$this->controls['placeholder'] = [
			'group'       => 'zagFunction',
			'type'        => 'text',
			'label'       => esc_html__( 'Placeholder', 'zag' ),
			'inline'      => true,
			'placeholder' => esc_html__( 'Search', 'zag' ),
			'desc'        => esc_html__( 'A worked example reads best, e.g. "a podiatrist in Penrith who accepts NDIS".', 'zag' ),
		];

		/**
		 * Optional, unlike a real filter's target query.
		 *
		 * Left empty the search drives every Zag filter it finds on the page,
		 * which is the point of the element. Set, it ignores filters targeting
		 * anything else — for pages carrying two independent filtered queries.
		 */
		$this->controls['zagQueryId'] = [
			'group'            => 'zagFunction',
			'type'             => 'query-list',
			'label'            => esc_html__( 'Limit to query', 'zag' ),
			'placeholder'      => esc_html__( 'Every query on the page', 'zag' ),
			'excludeMainQuery' => true,
			'desc'             => esc_html__( 'Leave empty to drive every Zag filter on the page.', 'zag' ),
		];

		$this->controls['zagIcon'] = [
			'group'    => 'zagFunction',
			'type'     => 'icon',
			'label'    => esc_html__( 'Icon', 'zag' ),
			'desc'     => esc_html__( 'Replaces the built-in magnifier.', 'zag' ),
			'required' => [ 'zagNoIcon', '=', '' ],
		];

		// Negative option: a magnifier is the default, so unset must mean shown.
		$this->controls['zagNoIcon'] = [
			'group' => 'zagFunction',
			'type'  => 'checkbox',
			'label' => esc_html__( 'No icon', 'zag' ),
			'desc'  => esc_html__( 'Hide the leading magnifier entirely.', 'zag' ),
		];

		/**
		 * The interpretation hint.
		 *
		 * Off unless asked for. Reading a sentence back as a list of filters is
		 * reassuring on a page where the dropdowns are visible and can be seen
		 * moving, and redundant clutter where they are not.
		 */
		$this->controls['zagHint'] = [
			'group' => 'zagFunction',
			'type'  => 'checkbox',
			'label' => esc_html__( 'Show interpretation hint', 'zag' ),
			'desc'  => esc_html__( 'A line below the field naming what the sentence was understood as.', 'zag' ),
		];

		$this->controls['zagHintPrefix'] = [
			'group'       => 'zagFunction',
			'type'        => 'text',
			'label'       => esc_html__( 'Hint: prefix', 'zag' ),
			'inline'      => true,
			'placeholder' => esc_html__( 'Read as', 'zag' ),
			'required'    => [ 'zagHint', '!=', '' ],
		];

		$this->controls['zagHintEmpty'] = [
			'group'       => 'zagFunction',
			'type'        => 'text',
			'label'       => esc_html__( 'Hint: no match', 'zag' ),
			'inline'      => true,
			'placeholder' => esc_html__( 'Nothing matched those words yet.', 'zag' ),
			'required'    => [ 'zagHint', '!=', '' ],
		];

		$this->controls['zagParam'] = [
			'group'       => 'zagFunction',
			'type'        => 'text',
			'label'       => esc_html__( 'URL parameter', 'zag' ),
			'inline'      => true,
			'placeholder' => self::DEFAULT_PARAM,
			'desc'        => esc_html__( 'Keeps the typed sentence across reloads and back/forward.', 'zag' ),
		];

		$this->controls['zagVocabularyInfo'] = [
			'group'   => 'zagVocabulary',
			'type'    => 'info',
			'content' => esc_html__( 'Every option label on the page is already matched automatically — typing "Penrith" selects the Penrith option wherever it lives. Add rows below only for words that are not themselves option labels.', 'zag' ),
		];

		/**
		 * Targets are matched by label, not by value.
		 *
		 * The options belong to sibling elements and may come from a taxonomy, a
		 * custom field or a WP field, so their values are unknowable here — a
		 * taxonomy option's value is a term id. The label is the only thing an
		 * editor can see in the builder and type back in.
		 */
		$this->controls['zagSynonyms'] = [
			'group'         => 'zagVocabulary',
			'type'          => 'repeater',
			'label'         => esc_html__( 'Synonyms', 'zag' ),
			'titleProperty' => 'zagPhrases',
			'fields'        => [
				'zagPhrases' => [
					'type'           => 'text',
					'label'          => esc_html__( 'When they type', 'zag' ),
					'inline'         => true,
					'hasDynamicData' => false,
					'placeholder'    => esc_html__( 'bunion, heel, plantar', 'zag' ),
					'desc'           => esc_html__( 'Comma separated. Matches from the start of a word, so "physio" catches "physiotherapist".', 'zag' ),
				],

				'zagTargets' => [
					'type'           => 'text',
					'label'          => esc_html__( 'Select these options', 'zag' ),
					'inline'         => true,
					'hasDynamicData' => false,
					'placeholder'    => esc_html__( 'Podiatry', 'zag' ),
					'desc'           => esc_html__( 'Comma separated option labels, exactly as they appear in the filters. Several labels select several options at once.', 'zag' ),
				],
			],
		];

		$style = array_merge( shared_style_controls(), search_style_controls() );

		foreach ( self::UNUSED_STYLE_CONTROLS as $key ) {
			unset( $style[ $key ] );
		}

		$this->controls = array_merge( $this->controls, group_controls( $style, 'zagStyle' ) );
	}

	/**
	 * The URL parameter this instance reads and writes.
	 *
	 * sanitize_key so whatever an editor types is a usable query key.
	 */
	private function get_param() {
		$param = ! empty( $this->settings['zagParam'] )
			? sanitize_key( $this->settings['zagParam'] )
			: '';

		return $param !== '' ? $param : self::DEFAULT_PARAM;
	}

	/**
	 * Repeater rows, split into the phrase and target lists the parser wants.
	 *
	 * Splitting here rather than in JS keeps the frontend free of authoring
	 * concerns and means an empty or half-filled row is dropped once, server
	 * side, instead of being guarded against on every keystroke.
	 */
	private function get_synonyms() {
		$rows = $this->settings['zagSynonyms'] ?? [];

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$synonyms = [];

		foreach ( $rows as $row ) {
			$phrases = $this->split_list( $row['zagPhrases'] ?? '' );
			$targets = $this->split_list( $row['zagTargets'] ?? '' );

			// A row missing either half can never fire.
			if ( ! $phrases || ! $targets ) {
				continue;
			}

			$synonyms[] = [
				'phrases' => $phrases,
				'targets' => $targets,
			];
		}

		return $synonyms;
	}

	private function split_list( $value ) {
		$parts = array_map( 'trim', explode( ',', (string) $value ) );

		return array_values( array_filter( $parts, static fn( $part ) => $part !== '' ) );
	}

	/**
	 * The built-in magnifier.
	 *
	 * Unguarded against Bricks' icon colour rule on purpose: here the magnifier
	 * *is* the icon the control refers to, so recolouring it is the intent
	 * rather than a collision.
	 */
	private function magnifier() {
		return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>';
	}

	public function render() {
		$settings = $this->settings;
		$param    = $this->get_param();

		$placeholder = ! empty( $settings['placeholder'] )
			? $this->render_dynamic_data( $settings['placeholder'] )
			: esc_html__( 'Search', 'zag' );

		$label_text   = ! empty( $settings['zagLabel'] ) ? $this->render_dynamic_data( $settings['zagLabel'] ) : '';
		$label_hidden = $label_text === '' || ! empty( $settings['zagLabelHidden'] );

		$hint_prefix = ! empty( $settings['zagHintPrefix'] )
			? $this->render_dynamic_data( $settings['zagHintPrefix'] )
			: esc_html__( 'Read as', 'zag' );

		$hint_empty = ! empty( $settings['zagHintEmpty'] )
			? $this->render_dynamic_data( $settings['zagHintEmpty'] )
			: esc_html__( 'Nothing matched those words yet.', 'zag' );

		/**
		 * Seeded from the URL so a reload shows the sentence that produced the
		 * filters Bricks has already restored, with no flash of an empty field.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display value, escaped on output.
		$initial = isset( $_GET[ $param ] ) ? sanitize_text_field( wp_unslash( $_GET[ $param ] ) ) : '';

		$config = [
			'param'      => $param,
			'queryId'    => $settings['zagQueryId'] ?? '',
			'hint'       => ! empty( $settings['zagHint'] ),
			'hintPrefix' => $hint_prefix,
			'hintEmpty'  => $hint_empty,
			'synonyms'   => $this->get_synonyms(),
		];

		$input_id = "zag-search-{$this->id}";

		$this->set_attribute( '_root', 'class', 'zag-search' );
		$this->set_attribute( '_root', 'data-zag-search', wp_json_encode( $config ) );

		echo "<div {$this->render_attributes('_root')}>";

		printf(
			'<label class="zag-search__label%s" for="%s">%s</label>',
			$label_hidden ? ' is-hidden' : '',
			esc_attr( $input_id ),
			esc_html( $label_text !== '' ? $label_text : $placeholder )
		);

		/**
		 * The field box lives on this wrapper, never on the input.
		 *
		 * Bricks ships `body.bricks-is-frontend.bricks-is-frontend :focus { box-shadow: none }`
		 * at (0,3,1), which strips a shadow from whatever holds focus. A div is
		 * only ever :focus-within, so the rule cannot reach it.
		 */
		echo '<div class="zag-search__control">';

		if ( empty( $settings['zagNoIcon'] ) ) {
			printf(
				'<span class="zag-search__icon">%s</span>',
				! empty( $settings['zagIcon'] )
					? self::render_icon( $settings['zagIcon'] )
					: $this->magnifier()
			);
		}

		/**
		 * type="search" for the Escape-to-clear behaviour browsers give it for
		 * free. The webkit cancel button is hidden in CSS: Firefox has no
		 * equivalent, and a clear affordance that exists in one browser and not
		 * another is worse than none.
		 *
		 * No name attribute — this input is never submitted anywhere. Its value
		 * reaches the server only as the URL parameter, written by search.js.
		 */
		printf(
			'<input id="%s" class="zag-search__input" type="search" value="%s" placeholder="%s" autocomplete="off" data-zag-search-input>',
			esc_attr( $input_id ),
			esc_attr( $initial ),
			esc_attr( $placeholder )
		);

		echo '</div>';

		if ( ! empty( $settings['zagHint'] ) ) {
			// Live region: search.js rewrites the text as the sentence is parsed.
			printf(
				'<p class="zag-search__hint" data-zag-search-hint role="status" aria-live="polite"%s></p>',
				$initial === '' ? ' hidden' : ''
			);
		}

		echo '</div>';
	}
}
