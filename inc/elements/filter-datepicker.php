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

	public function set_controls() {
		$filter_controls = $this->get_filter_controls();

		if ( ! empty( $filter_controls ) ) {
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

			$filter_controls['enableTime'] = [
				'label'    => esc_html__( 'Enable time', 'zag' ),
				'type'     => 'checkbox',
				'required' => [ [ 'filterSource', '!=', '' ] ],
			];

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

			$this->controls = array_merge( $this->controls, $filter_controls );
		}

		$this->controls['zagSep'] = [
			'label' => esc_html__( 'Zag UI', 'zag' ),
			'type'  => 'separator',
		];

		$this->controls['placeholder'] = [
			'type'   => 'text',
			'label'  => esc_html__( 'Placeholder', 'zag' ),
			'inline' => true,
		];

		$this->controls['zagPlacement'] = [
			'type'        => 'select',
			'label'       => esc_html__( 'Placement', 'zag' ),
			'inline'      => true,
			'options'     => [
				'bottom-start' => esc_html__( 'Below', 'zag' ),
				'top-start'    => esc_html__( 'Above', 'zag' ),
			],
			'placeholder' => esc_html__( 'Below', 'zag' ),
		];
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
			'enableTime'  => $this->has_time(),
			'placeholder' => $placeholder,
			'placement'   => $settings['zagPlacement'] ?? 'bottom-start',
			'value'       => $current_value,
			'locale'      => str_replace( '_', '-', get_locale() ),
			// WP stores 0 for Sunday; Zag uses the same convention.
			'startOfWeek' => (int) get_option( 'start_of_week', 1 ),
			'min'         => $this->min_date ? gmdate( 'Y-m-d', $this->min_date ) : null,
			'max'         => $this->max_date ? gmdate( 'Y-m-d', $this->max_date ) : null,
		];

		$this->set_attribute( '_root', 'class', 'zag-datepicker' );
		$this->set_attribute( '_root', 'data-zag-datepicker', wp_json_encode( $config ) );

		echo "<div {$this->render_attributes('_root')}>";

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

		echo '</div>';
	}
}
