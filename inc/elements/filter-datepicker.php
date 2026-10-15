<?php
/**
 * Zag - Date picker filter element.
 *
 * A Bricks query filter whose UI is a Zag.js date-picker machine rather than
 * flatpickr. Single date or range, with optional time.
 */

namespace Zag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Element_Zag_Filter_Datepicker extends \Bricks\Filter_Element {
	public $name        = 'zag-filter-datepicker';
	public $icon        = 'ti-calendar';
	public $filter_type = 'datepicker';

	/** @var int|null Earliest indexed date, as a timestamp. */
	public $min_date = null;

	/** @var int|null Latest indexed date, as a timestamp. */
	public $max_date = null;

	public function get_label() {
		return esc_html__( 'Zag', 'zag' ) . ' - ' . esc_html__( 'Date picker', 'zag' );
	}

	public function get_keywords() {
		return [ 'filter', 'date', 'calendar', 'datepicker', 'zag' ];
	}

	public function enqueue_scripts() {
		wp_enqueue_script( 'bricks-filters' );
		wp_enqueue_script( 'zag-filters' );
		wp_enqueue_style( 'zag-filters' );
	}

	public function is_filter_input() {
		return ! empty( $this->settings['filterQueryId'] ) && ! empty( $this->settings['filterSource'] );
	}

	private function is_range() {
		return isset( $this->settings['isDateRange'] );
	}

	private function has_time() {
		return isset( $this->settings['enableTime'] );
	}

	/**
	 * Native filter controls, unlocked by impersonation.
	 *
	 * @see \Zag\Element_Zag_Filter_Select::get_filter_controls() for why.
	 */
	public function get_filter_controls() {
		$real_name  = $this->name;
		$this->name = 'filter-datepicker';

		$controls = parent::get_filter_controls();

		$this->name = $real_name;

		return is_array( $controls ) ? $controls : [];
	}


	public function set_control_groups() {
		// Merge, don't replace: set_common_control_groups() has already run.
		$this->control_groups = array_merge( $this->control_groups, control_groups() );
	}

	public function set_controls() {
		$filter_controls = group_controls( $this->get_filter_controls(), 'zagFilter' );

		if ( ! empty( $filter_controls ) ) {
			/**
			 * Only offer fields that actually hold a date.
			 *
			 * The inherited control lists every post field. Picking a
			 * non-date one builds a date_query against a column that can't
			 * satisfy it, which returns nothing and looks like a broken filter
			 * rather than a misconfigured one.
			 */
			if ( ! empty( $filter_controls['wpPostField'] ) ) {
				$filter_controls['wpPostField']['options'] = [
					'post_date'     => esc_html__( 'Post date', 'zag' ),
					'post_modified' => esc_html__( 'Post modified date', 'zag' ),
				];
			}

			if ( ! empty( $filter_controls['wpUserField'] ) ) {
				$filter_controls['wpUserField']['options']['user_registered'] = esc_html__( 'User registered date', 'zag' );
			}

			// Dates have no taxonomy dimension; Bricks strips these too.
			unset(
				$filter_controls['filterSource']['options']['taxonomy'],
				$filter_controls['filterTaxonomy'],
				$filter_controls['filterHierarchical'],
				$filter_controls['filterTaxonomyHideEmpty'],
				$filter_controls['filterHideCount'],
				$filter_controls['filterHideEmpty'],
				$filter_controls['labelMapping'],
				$filter_controls['customLabelMapping']
			);

			/**
			 * No 'Enable time' control.
			 *
			 * The query layer handles time fully (hour/minute components for
			 * wpField, DATETIME for customField), but Zag has no time widget so
			 * the UI only ever produces a date. Exposing the setting would let
			 * someone switch the comparison semantics without changing what they
			 * can actually pick. Add the control back alongside a time input,
			 * not before.
			 */
			$filter_controls['isDateRange'] = [
				'label'    => esc_html__( 'Date range', 'zag' ),
				'type'     => 'checkbox',
				'required' => [ [ 'filterSource', '!=', '' ] ],
			];

			$filter_controls['useMinMax'] = [
				'label'       => esc_html__( 'Min/max date', 'zag' ),
				'type'        => 'checkbox',
				'description' => esc_html__( 'Limit selectable dates to the range present in the index.', 'zag' ),
				'required'    => [ [ 'filterSource', '!=', '' ] ],
			];

			// A range spans dates, so a comparison operator is meaningless.
			$filter_controls['fieldCompareOperator']['required'] = [
				[ 'filterSource', '!=', '' ],
				[ 'isDateRange', '=', '' ],
			];

			$filter_controls['fieldCompareOperator']['options'] = [
				'is'     => '==',
				'before' => '<',
				'after'  => '>',
			];

			$filter_controls['fieldCompareOperator']['placeholder'] = '==';

			/**
			 * The mistake this catches: setting Source to Custom field with a
			 * meta key of post_date. That's a wp_posts column, not post meta, so
			 * the meta_query matches nothing and the filter looks broken.
			 */
			$filter_controls['zagCustomFieldInfo'] = [
				'type'     => 'info',
				'content'  => esc_html__( 'Custom fields only. Post date and modified date live under Source: WP field. Values must be stored as Y-m-d or Y-m-d H:i.', 'zag' ),
				'required' => [ [ 'filterSource', '=', 'customField' ] ],
			];

			$this->controls = array_merge( $this->controls, $filter_controls );
		}

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

		$this->controls['zagCaption'] = [
			'group' => 'zagFunction',
			'type'  => 'checkbox',
			'label' => esc_html__( 'Show caption', 'zag' ),
			'desc'  => esc_html__( 'A status line below the field showing the current selection.', 'zag' ),
		];

		$this->controls['zagCaptionEmpty'] = [
			'group'       => 'zagFunction',
			'type'        => 'text',
			'label'       => esc_html__( 'Caption: empty', 'zag' ),
			'inline'      => true,
			'placeholder' => esc_html__( 'Nothing selected', 'zag' ),
			'required'    => [ 'zagCaption', '!=', '' ],
		];

		$this->controls['placeholder'] = [
			'group' => 'zagFunction',
			'type'   => 'text',
			'label'  => esc_html__( 'Placeholder', 'zag' ),
			'inline' => true,
		];

		/**
		 * Display only. The stored value stays ISO (Y-m-d), because that is what
		 * Query_Filters' parser expects — this changes the trigger label alone.
		 */
		/**
		 * Typing needs format and parse to round-trip, which rules out the
		 * ordinal and long styles — "1st May 2026" is not reliably parseable.
		 * With typing on, Zag owns the input using its own locale format, so
		 * Date display no longer applies. Single mode only: one input cannot
		 * sensibly accept both ends of a range.
		 */
		$this->controls['zagTypeable'] = [
			'group'    => 'zagFunction',
			'type'     => 'checkbox',
			'label'    => esc_html__( 'Allow typing', 'zag' ),
			'desc'     => esc_html__( 'Shows an editable field. Uses the locale date format, so Date display does not apply.', 'zag' ),
			'required' => [ 'isDateRange', '=', '' ],
		];

		$this->controls['zagDateFormat'] = [
			'group' => 'zagFunction',
			'type'        => 'select',
			'label'       => esc_html__( 'Date display', 'zag' ),
			'inline'      => true,
			'options'     => [
				'medium'  => esc_html__( 'Medium', 'zag' ) . ' — 1 May 2026',
				'ordinal' => esc_html__( 'Ordinal', 'zag' ) . ' — 1st May 2026',
				'long'    => esc_html__( 'Long', 'zag' ) . ' — 1 May 2026',
				'full'    => esc_html__( 'Full', 'zag' ) . ' — Friday 1 May 2026',
				'short'   => esc_html__( 'Short', 'zag' ) . ' — 01/05/2026',
			],
			'placeholder' => esc_html__( 'Medium', 'zag' ),
			'description' => esc_html__( 'Rendered in the site language. Does not affect the stored value. Ordinal applies to English only; other languages fall back to Long.', 'zag' ),
			'required'    => [ 'zagTypeable', '=', '' ],
		];

		/**
		 * Replaces the built-in calendar glyph rather than prefixing the field.
		 *
		 * The `css` key is not optional here. Assets::generate_css_from_controls()
		 * gives an icon control with no `css` of its own a default rule scoped to
		 * `svg` (assets.php:1662) — inside this element that is the calendar
		 * glyph *and* every nav arrow in the popover, all of which change colour
		 * on hover. Declaring a selector suppresses that default and keeps the
		 * control to the icon it names.
		 *
		 * Targets the glyph rather than its slot so the control's width, height
		 * and colour land on the icon itself, which is what Bricks' own default
		 * does. `> *` covers an <svg>, an <img> and an icon-font <i> alike.
		 *
		 * @see CLAUDE.md — "Bricks' icon colour control recolours our chrome"
		 */
		$this->controls['zagIcon'] = [
			'group' => 'zagFunction',
			'type'  => 'icon',
			'label' => esc_html__( 'Icon', 'zag' ),
			'desc'  => esc_html__( 'Replaces the built-in calendar glyph.', 'zag' ),
			'css'   => [
				[ 'selector' => '.zag-datepicker__icon > *, .zag-datepicker__iconbtn > *' ],
			],
		];

		$this->controls['zagPlacement'] = [
			'group' => 'zagFunction',
			'type'        => 'select',
			'label'       => esc_html__( 'Placement', 'zag' ),
			'inline'      => true,
			'options'     => [
				'bottom-start' => esc_html__( 'Below', 'zag' ),
				'top-start'    => esc_html__( 'Above', 'zag' ),
			],
			'placeholder' => esc_html__( 'Below', 'zag' ),
		];

		// See inc/builder-preview.php.
		$this->controls['zagBuilderPreview'] = zag_builder_preview_control();

		$this->controls = array_merge(
			$this->controls,
			group_controls(
				array_merge( shared_style_controls(), datepicker_style_controls() ),
				'zagStyle'
			)
		);
	}

	/**
	 * Prepare sources, derive the selectable range, and emit data-brx-filter.
	 *
	 * Modelled on \Bricks\Filter_Datepicker::set_as_filter().
	 */
	private function set_as_filter() {
		$settings = $this->settings;

		if ( empty( $settings['filterQueryId'] ) || empty( $settings['filterSource'] ) ) {
			return;
		}

		$this->prepare_sources();

		/**
		 * Min/max come from choices_source rather than the filtered source: a
		 * bound derived from the *filtered* set would exclude the currently
		 * selected date as soon as it was applied.
		 */
		if ( ! empty( $this->choices_source ) && isset( $settings['useMinMax'] ) ) {
			foreach ( $this->choices_source as $choice ) {
				$choice_date = $choice['filter_value'] ?? false;
				$choice_date = $choice_date ? strtotime( $choice_date ) : false;

				if ( ! $choice_date ) {
					continue;
				}

				if ( ! $this->min_date || $choice_date < $this->min_date ) {
					$this->min_date = $choice_date;
				}

				if ( ! $this->max_date || $choice_date > $this->max_date ) {
					$this->max_date = $choice_date;
				}
			}
		}

		$filter_settings                 = $this->get_common_filter_settings();
		$filter_settings['filterSource'] = $settings['filterSource'];

		$this->set_attribute( '_root', 'data-brx-filter', wp_json_encode( $filter_settings ) );
	}

	/**
	 * Current value as the comma-joined string Bricks expects.
	 *
	 * sanitize_filter_value()'s filter-datepicker case exists to normalise
	 * arrays into exactly this shape. Our element name misses that case and
	 * falls to `default`, which string-casts — harmless as long as we only ever
	 * deal in the joined string, unlike multi-select which needed recovery.
	 */
	private function get_current_value() {
		$settings = $this->settings;
		$value    = $settings['filterValue'] ?? ( $settings['value'] ?? '' );

		if ( is_array( $value ) ) {
			$value = implode( ',', $value );
		}

		return (string) $value;
	}

	public function render() {
		$settings         = $this->settings;
		$this->input_name = $settings['name'] ?? "form-field-{$this->id}";

		if ( $this->is_filter_input() ) {
			$this->set_as_filter();

			if ( $this->is_indexing() ) {
				return $this->render_element_placeholder(
					[
						'title' => esc_html__( 'Indexing in progress.', 'zag' ),
					]
				);
			}
		}

		if ( empty( $settings['filterSource'] ) ) {
			return $this->render_element_placeholder(
				[
					'title' => esc_html__( 'No filter source selected.', 'zag' ),
				]
			);
		}

		$current_value = $this->get_current_value();
		$placeholder   = ! empty( $settings['placeholder'] )
			? $this->render_dynamic_data( $settings['placeholder'] )
			: esc_html__( 'Select a date', 'zag' );

		$config = [
			'mode'        => $this->is_range() ? 'range' : 'single',
			// Range typing would need two inputs; the ref is single-date only.
			'typeable'    => ! $this->is_range() && ! empty( $settings['zagTypeable'] ),
			'enableTime'  => $this->has_time(),
			'placeholder' => $placeholder,
			'placement'   => $settings['zagPlacement'] ?? 'bottom-start',
			'dateStyle'   => $settings['zagDateFormat'] ?? 'medium',
			'value'       => $current_value,
			'locale'      => str_replace( '_', '-', get_locale() ),
			// WP stores 0 for Sunday; Zag uses the same convention.
			'startOfWeek' => (int) get_option( 'start_of_week', 1 ),
			'min'         => $this->min_date ? gmdate( 'Y-m-d', $this->min_date ) : null,
			'max'         => $this->max_date ? gmdate( 'Y-m-d', $this->max_date ) : null,
		];

		// See inc/builder-preview.php.
		apply_builder_preview_attribute( $this );

		$this->set_attribute( '_root', 'class', 'zag-datepicker' );
		$this->set_attribute( '_root', 'data-zag-datepicker', wp_json_encode( $config ) );

		echo "<div {$this->render_attributes('_root')}>";


		/**
		 * Always rendered, even with no label text.
		 *
		 * Zag's trigger carries aria-labelledby pointing at its label part. With
		 * no label element that reference dangles and the control has no
		 * accessible name at all, so fall back to the placeholder and hide it.
		 */
		$empty_caption = ! empty( $settings['zagCaptionEmpty'] )
			? $this->render_dynamic_data( $settings['zagCaptionEmpty'] )
			: esc_html__( 'Nothing selected', 'zag' );

		$label_text   = ! empty( $settings['zagLabel'] ) ? $this->render_dynamic_data( $settings['zagLabel'] ) : '';
		$label_hidden = $label_text === '' || ! empty( $settings['zagLabelHidden'] );

		printf(
			'<label class="%s__label%s" data-zag-label>%s</label>',
			'zag-datepicker',
			$label_hidden ? ' is-hidden' : '',
			esc_html( $label_text !== '' ? $label_text : $placeholder )
		);

		/**
		 * Progressive enhancement baseline.
		 *
		 * A plain text input rather than <input type="date">: the range and
		 * enable-time formats have no native equivalent, and a native control
		 * would submit a different string shape than the parser expects.
		 */
		printf(
			'<input type="text" class="zag-datepicker__native" name="%s" value="%s" placeholder="%s" data-zag-native>',
			esc_attr( $this->input_name ),
			esc_attr( $current_value ),
			esc_attr( $placeholder )
		);

		/**
		 * Carrier for a custom icon.
		 *
		 * Rendered here and relocated by datepicker.js into whichever host the
		 * mode builds, so Bricks' icon markup and classes survive intact and no
		 * arbitrary HTML is ever assigned through innerHTML. Same approach the
		 * select uses; hidden because this node is only ever a courier.
		 */
		if ( ! empty( $settings['zagIcon'] ) ) {
			printf(
				'<span data-zag-icon hidden>%s</span>',
				self::render_icon( $settings['zagIcon'] )
			);
		}


		// Live region: the text is rewritten client-side as the value changes.
		if ( ! empty( $settings['zagCaption'] ) ) {
			printf(
				'<p class="%s__caption" data-zag-caption data-empty-text="%s"><span class="%s__dot"></span><span data-zag-caption-text>%s</span></p>',
				'zag-datepicker',
				esc_attr( $empty_caption ),
				'zag-datepicker',
				esc_html( $empty_caption )
			);
		}

		echo '</div>';
	}
}
