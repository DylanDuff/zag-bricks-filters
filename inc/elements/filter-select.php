<?php
/**
 * Zag - Select filter element.
 *
 * A Bricks query filter whose UI is a Zag.js select machine rather than a
 * native <select>. Single and multi-select in one element.
 */

namespace Zag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Element_Zag_Filter_Select extends \Bricks\Filter_Element {
	public $name        = 'zag-filter-select';
	public $icon        = 'ti-angle-down';
	public $filter_type = 'select';

	/**
	 * Native controls we inherit but that describe markup we don't render.
	 *
	 * The mode block draws native radio/checkbox buttons; we draw a Zag trigger
	 * and listbox, so none of it applies. The indentation gap styles the native
	 * checkbox stack.
	 */
	const UNUSED_CONTROLS = [
		'modeSep',
		'displayMode',
		'buttonSep',
		'buttonStyle',
		'buttonSize',
		'buttonCircle',
		'buttonOutline',
		'buttonBackgroundColor',
		'buttonBorder',
		'buttonTypography',
		'buttonActiveSep',
		'buttonActiveBackgroundColor',
		'buttonActiveBorder',
		'buttonActiveTypography',
		'filterChildIndentationGap',
	];

	public function get_label() {
		return esc_html__( 'Zag', 'zag' ) . ' - ' . esc_html__( 'Select', 'zag' );
	}

	public function get_keywords() {
		return [ 'filter', 'select', 'dropdown', 'zag', 'combobox' ];
	}

	public function enqueue_scripts() {
		wp_enqueue_script( 'bricks-filters' );
		wp_enqueue_script( 'zag-filters' );
		wp_enqueue_style( 'zag-filters' );
	}

	/**
	 * Whether this instance behaves as a multi-select.
	 *
	 * Drives both the Zag machine config and which native filter element we
	 * impersonate server-side: multi-value filtering needs filter-checkbox's
	 * array handling, single needs filter-select's scalar handling.
	 */
	public function is_multiple() {
		return ! empty( $this->settings['zagMultiple'] );
	}

	/**
	 * The native filter element this instance stands in for.
	 *
	 * @see \Zag\Query_Filters_Bridge for why impersonation is needed at all.
	 */
	public function impersonates() {
		return $this->is_multiple() ? 'filter-checkbox' : 'filter-select';
	}

	/**
	 * Native filter controls, unlocked by impersonation.
	 *
	 * \Bricks\Filter_Element::get_filter_controls() returns [] unless $this->name
	 * appears in Query_Filters::filter_controls_elements() — a hardcoded list with
	 * no filter hook (query-filters.php:707). $name is a public property, so we
	 * borrow a native name across the call.
	 *
	 * Controls are static per element, so they can't vary by the zagMultiple
	 * setting. We take the union of both impersonation targets: filter-select
	 * contributes filterAction and the sort options, filter-checkbox contributes
	 * filterCountNoBracket and filterAutoCheckChildren.
	 */
	public function get_filter_controls() {
		$select   = $this->native_filter_controls( 'filter-select' );
		$checkbox = $this->native_filter_controls( 'filter-checkbox' );

		// filter-select wins on shared keys; filter-checkbox contributes its extras.
		$controls = array_merge( $checkbox, $select );

		foreach ( self::UNUSED_CONTROLS as $key ) {
			unset( $controls[ $key ] );
		}

		return $controls;
	}

	private function native_filter_controls( $native_name ) {
		$real_name  = $this->name;
		$this->name = $native_name;

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
			// Single select compares scalars; multi compares sets.
			$filter_controls['fieldCompareOperator']['options'] = [
				'='           => '=',
				'!='          => '!=',
				'IN'          => 'IN',
				'NOT IN'      => 'NOT IN',
				'BETWEEN'     => 'BETWEEN',
				'NOT BETWEEN' => 'NOT BETWEEN',
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

		/**
		 * Leading icon.
		 *
		 * The ref pairs this with its pill variant, but a pill is just a large
		 * Field radius — no display mode needed, and the user keeps full control
		 * of the shape. The icon is the part that couldn't be expressed as a
		 * style value, so it's the only piece that became a control.
		 */
		$this->controls['zagIcon'] = [
			'group' => 'zagFunction',
			'type'  => 'icon',
			'label' => esc_html__( 'Icon', 'zag' ),
			'desc'  => esc_html__( 'Shown at the start of the field.', 'zag' ),
		];

		$this->controls['zagMultiple'] = [
			'group' => 'zagFunction',
			'type'  => 'checkbox',
			'label' => esc_html__( 'Multi-select', 'zag' ),
			'desc'  => esc_html__( 'Allow selecting more than one option.', 'zag' ),
		];

		/**
		 * Shown after the count once more than one option is selected, e.g.
		 * "3 locations". Only ever appears in the plural, since a single
		 * selection shows its own label instead — so no singular form is needed.
		 */
		$this->controls['zagCounterLabel'] = [
			'group'       => 'zagFunction',
			'type'        => 'text',
			'label'       => esc_html__( 'Counter label', 'zag' ),
			'inline'      => true,
			'placeholder' => esc_html__( 'selected', 'zag' ),
			'desc'        => esc_html__( 'Follows the count, e.g. "3 locations".', 'zag' ),
			'required'    => [ 'zagMultiple', '!=', '' ],
		];

		$this->controls['placeholder'] = [
			'group' => 'zagFunction',
			'type'   => 'text',
			'label'  => esc_html__( 'Placeholder', 'zag' ),
			'inline' => true,
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

		/**
		 * Phrased as the exception, not the default.
		 *
		 * A checkbox only has set and unset, and `default => true` is a builder-UI
		 * affordance that never reaches $settings — so an unchecked box and an
		 * untouched one are indistinguishable, and a default-on option can never
		 * be turned off. Negative options avoid that entirely: unset is the
		 * default behaviour.
		 */
		$this->controls['zagMenuUncapped'] = [
			'group' => 'zagFunction',
			'type'  => 'checkbox',
			'label' => esc_html__( 'Uncapped menu height', 'zag' ),
			'desc'  => esc_html__( 'Grow to fit every option instead of scrolling.', 'zag' ),
		];

		// Negative option for the same reason as above.
		$this->controls['zagAutoWidth'] = [
			'group' => 'zagFunction',
			'type'  => 'checkbox',
			'label' => esc_html__( 'Auto menu width', 'zag' ),
			'desc'  => esc_html__( 'Size the menu to its content rather than the field.', 'zag' ),
		];

		$this->controls = array_merge(
			$this->controls,
			group_controls(
				array_merge( shared_style_controls(), select_style_controls() ),
				'zagStyle'
			)
		);
	}

	/**
	 * Count options with the combine logic a multi-select actually uses.
	 *
	 * set_options_with_count() branches on $this->name in three places
	 * (filter-base.php:581, :697, :701), all gated on 'filter-checkbox', and all
	 * three are what make AND logic count correctly: including our own clause in
	 * the count source, counting against filtered_source, and — via
	 * not_found_option_as_zero — disabling options that would return nothing.
	 *
	 * Without the spoof, counts are computed as though the logic were OR, so
	 * combinations that can only ever return zero results still look available
	 * and the conflict is only discovered after selecting.
	 *
	 * Single-select deliberately doesn't spoof: it wants the non-checkbox branch
	 * at :697, which is already what our real name selects.
	 */
	public function set_options_with_count() {
		if ( ! $this->is_multiple() ) {
			parent::set_options_with_count();

			return;
		}

		$real_name  = $this->name;
		$this->name = 'filter-checkbox';

		parent::set_options_with_count();

		$this->name = $real_name;
	}

	/**
	 * Prepare sources, populate options, and emit the data-brx-filter payload
	 * Bricks' frontend reads to register this as a filter instance.
	 *
	 * Modelled on \Bricks\Filter_Select::set_as_filter().
	 */
	private function set_as_filter() {
		$settings = $this->settings;

		if ( empty( $settings['filterQueryId'] ) ) {
			return;
		}

		$filter_action = $settings['filterAction'] ?? 'filter';

		if ( $filter_action === 'filter' ) {
			if ( empty( $settings['filterSource'] ) ) {
				return;
			}

			$this->prepare_sources();
			$this->set_data_source();
			$this->set_options_with_count();
		} elseif ( $filter_action === 'sort' ) {
			$this->setup_sort_options();
		} else {
			$this->setup_per_page_options();
		}

		$filter_settings                 = $this->get_common_filter_settings();
		$filter_settings['filterSource'] = $settings['filterSource'] ?? false;
		$filter_settings['hierarchy']    = $settings['filterHierarchical'] ?? false;
		$filter_settings['autoCheck']    = $settings['filterAutoCheckChildren'] ?? false;

		$this->set_attribute( '_root', 'data-brx-filter', wp_json_encode( $filter_settings ) );
	}

	/**
	 * Current value, normalised to an array.
	 *
	 * filterValue is injected by Query_Filters::set_active_filters() via the
	 * bricks/element/settings hook during a filter request.
	 */
	private function get_current_value() {
		$settings = $this->settings;
		$value    = $settings['value'] ?? ( $this->is_multiple() ? [] : '' );

		if ( isset( $settings['filterValue'] ) ) {
			$value = $settings['filterValue'];
		}

		/**
		 * filterValue arrives already collapsed for multi-select.
		 *
		 * set_active_filters() runs the value through sanitize_filter_value(),
		 * whose `default` branch reduces an array to its first element, and only
		 * then injects it as filterValue. So a page loaded with two terms in the
		 * URL would restore showing just one as selected. Go back to the request.
		 */
		if ( $this->is_multiple() ) {
			$recovered = Query_Filters_Bridge::recover_multi_value( $this->id, $settings );

			if ( $recovered !== null ) {
				$value = $recovered;
			}
		}

		if ( ! is_array( $value ) ) {
			$value = ( $value === '' || $value === null ) ? [] : [ $value ];
		}

		return array_map( 'strval', $value );
	}

	public function render() {
		$settings         = $this->settings;
		$this->input_name = $settings['name'] ?? "form-field-{$this->id}";
		$multiple         = $this->is_multiple();

		// filter_type drives the frontend switch in assets/js/filters.js: 'select'
		// keeps a scalar currentValue, 'checkbox' keeps an array.
		$this->filter_type = $multiple ? 'checkbox' : 'select';

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

		$filter_action = $settings['filterAction'] ?? 'filter';

		if ( $filter_action === 'filter' && empty( $settings['filterSource'] ) ) {
			return $this->render_element_placeholder(
				[
					'title' => esc_html__( 'No filter source selected.', 'zag' ),
				]
			);
		}

		$current_value = $this->get_current_value();
		$placeholder   = ! empty( $settings['placeholder'] )
			? $this->render_dynamic_data( $settings['placeholder'] )
			: esc_html__( 'Select', 'zag' );

		$options = [];

		foreach ( $this->get_populated_options() as $option ) {
			// Every option needs text. 0 is allowed; '' collides with the placeholder.
			if ( isset( $option['text'] ) && $option['text'] === '' ) {
				continue;
			}

			if ( ! empty( $option['is_placeholder'] ) ) {
				continue;
			}

			$label = wp_strip_all_tags( $this->get_option_text_with_count( $option ) );
			$text  = wp_strip_all_tags( (string) $option['text'] );

			$item = [
				'value'    => (string) $option['value'],
				'label'    => $label,
				'disabled' => ! empty( $option['disabled'] ),
				'depth'    => $option['depth'] ?? 0,
			];

			/**
			 * Count-free text for keyword matching.
			 *
			 * The visible label may carry a result count ("Penrith (4)"), which a
			 * controller element must not try to match a typed word against. Only
			 * emitted when it actually differs, so pages without counts keep the
			 * payload they had.
			 *
			 * @see src/search.js
			 */
			if ( $text !== '' && $text !== $label ) {
				$item['match'] = $text;
			}

			$options[] = $item;
		}

		$config = [
			'multiple'    => $multiple,
			'placement'   => $settings['zagPlacement'] ?? 'bottom-start',
			'sameWidth'   => empty( $settings['zagAutoWidth'] ),
			'placeholder' => $placeholder,
			'counterLabel' => ! empty( $settings['zagCounterLabel'] )
				? $this->render_dynamic_data( $settings['zagCounterLabel'] )
				: esc_html__( 'selected', 'zag' ),
			'name'        => $this->input_name,
			'value'       => $current_value,
			'items'       => $options,
		];

		if ( ! empty( $settings['zagMenuUncapped'] ) ) {
			$this->set_attribute( '_root', 'data-zag-menu-uncapped', 'true' );
		}

		$this->set_attribute( '_root', 'class', 'zag-select' );
		$this->set_attribute( '_root', 'data-zag-select', wp_json_encode( $config ) );

		if ( $multiple ) {
			$this->set_attribute( '_root', 'data-zag-multiple', 'true' );
		}

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
			'zag-select',
			$label_hidden ? ' is-hidden' : '',
			esc_html( $label_text !== '' ? $label_text : $placeholder )
		);

		/**
		 * Rendered here rather than passed through the config JSON so the icon
		 * markup never round-trips as a string: select.js relocates this node
		 * into the trigger, so no arbitrary HTML is ever assigned via innerHTML.
		 */
		if ( ! empty( $settings['zagIcon'] ) ) {
			printf(
				'<span class="zag-select__icon" data-zag-icon>%s</span>',
				self::render_icon( $settings['zagIcon'] )
			);
		}

		/**
		 * Progressive enhancement baseline.
		 *
		 * Rendered as a working native control so the filter is usable before
		 * (and if) the Zag bundle runs. zag-filters.js hides it on mount and
		 * mirrors every selection back into it, which also keeps Bricks' own
		 * value plumbing pointed at a real form control.
		 */
		$select_name = $multiple ? $this->input_name . '[]' : $this->input_name;

		printf(
			'<select class="zag-select__native" name="%s" data-zag-native%s>',
			esc_attr( $select_name ),
			$multiple ? ' multiple' : ''
		);

		if ( ! $multiple ) {
			printf(
				'<option value="" %s>%s</option>',
				selected( $current_value === [] || $current_value === [ '' ], true, false ),
				esc_html( $placeholder )
			);
		}

		foreach ( $options as $option ) {
			printf(
				'<option value="%s"%s%s>%s</option>',
				esc_attr( $option['value'] ),
				in_array( $option['value'], $current_value, true ) ? ' selected' : '',
				$option['disabled'] ? ' disabled' : '',
				esc_html( $option['label'] )
			);
		}

		echo '</select>';


		// Live region: the text is rewritten client-side as the value changes.
		if ( ! empty( $settings['zagCaption'] ) ) {
			printf(
				'<p class="%s__caption" data-zag-caption data-empty-text="%s"><span class="%s__dot"></span><span data-zag-caption-text>%s</span></p>',
				'zag-select',
				esc_attr( $empty_caption ),
				'zag-select',
				esc_html( $empty_caption )
			);
		}

		echo '</div>';
	}
}
