<?php
/**
 * Zag - Active filters element.
 *
 * One removable chip per active filter value, rendered client-side from the
 * live filter state rather than the server's.
 *
 * That is the whole reason this exists alongside Bricks' own active filters
 * element, which mislabels ours in three ways:
 *
 * - filter-active-filters.php:280 gates one-chip-per-value on
 *   `$instance_name === 'filter-checkbox'`. Ours is 'zag-filter-select', so a
 *   multi-select renders as a single chip holding the whole array.
 * - the value it reads has already been through sanitize_filter_value(), whose
 *   default branch collapses an array to its first element, so that single chip
 *   is usually wrong as well.
 * - :406 gates date formatting on 'filter-datepicker', so our date chips fall
 *   through to generic labelling.
 *
 * Reading window.bricksData.filterInstances[…].currentValue instead sidesteps
 * all three: it is the untouched frontend value, and an array stays an array.
 *
 * Like the smart search, this is NOT a Bricks filter element and is absent from
 * Zag\element_map() — it holds no value of its own.
 *
 * @see src/active-filters.js
 */

namespace Zag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Element_Zag_Active_Filters extends \Bricks\Element {
	public $category = 'filter';
	public $name     = 'zag-active-filters';
	public $icon     = 'ti-close';

	/**
	 * Shared style controls describing parts this element doesn't have.
	 *
	 * It draws no field box and no popover, only chips.
	 */
	const UNUSED_STYLE_CONTROLS = [
		'zagFieldBg',
		'zagSurface',
		'zagFieldSep',
		'zagFieldHeight',
		'zagFieldRadius',
		'zagPopoverSep',
		'zagPopoverShadow',
	];

	public function get_label() {
		return esc_html__( 'Zag', 'zag' ) . ' - ' . esc_html__( 'Active filters', 'zag' );
	}

	public function get_keywords() {
		return [ 'filter', 'active', 'chips', 'tags', 'zag', 'clear', 'reset' ];
	}

	public function enqueue_scripts() {
		wp_enqueue_script( 'bricks-filters' );
		wp_enqueue_script( 'zag-filters' );
		wp_enqueue_style( 'zag-filters' );
	}

	public function set_control_groups() {
		$shared = control_groups();

		$this->control_groups = array_merge(
			$this->control_groups,
			[
				'zagFunction' => $shared['zagFunction'],
				'zagStyle'    => $shared['zagStyle'],
			]
		);
	}

	public function set_controls() {
		$this->controls['zagLabel'] = [
			'group'       => 'zagFunction',
			'type'        => 'text',
			'label'       => esc_html__( 'Leading label', 'zag' ),
			'inline'      => true,
			'placeholder' => esc_html__( 'Filtering by:', 'zag' ),
			'desc'        => esc_html__( 'Shown before the chips. Leave empty for none.', 'zag' ),
		];

		/**
		 * Optional, unlike a real filter's target query.
		 *
		 * Left empty it shows every Zag filter on the page, which is what most
		 * pages want. Set, it ignores filters targeting anything else.
		 */
		$this->controls['zagQueryId'] = [
			'group'            => 'zagFunction',
			'type'             => 'query-list',
			'label'            => esc_html__( 'Limit to query', 'zag' ),
			'placeholder'      => esc_html__( 'Every query on the page', 'zag' ),
			'excludeMainQuery' => true,
			'desc'             => esc_html__( 'Leave empty to show every Zag filter on the page.', 'zag' ),
		];

		/**
		 * Negative option, per the convention.
		 *
		 * A filter carrying an icon has already said the icon is meaningful, so
		 * repeating it on the chip is the default and switching it off is the
		 * exception. Only the select renders one — the date picker's calendar
		 * glyph is chrome, not a control — so this does nothing on a page
		 * without an icon set.
		 */
		$this->controls['zagHideIcons'] = [
			'group' => 'zagFunction',
			'type'  => 'checkbox',
			'label' => esc_html__( 'No chip icons', 'zag' ),
			'desc'  => esc_html__( "Don't repeat the filter's leading icon inside its chips.", 'zag' ),
		];

		$this->controls['zagClearAll'] = [
			'group' => 'zagFunction',
			'type'  => 'checkbox',
			'label' => esc_html__( 'Show clear all', 'zag' ),
			'desc'  => esc_html__( 'A button after the chips that clears every Zag filter at once.', 'zag' ),
		];

		$this->controls['zagClearAllText'] = [
			'group'       => 'zagFunction',
			'type'        => 'text',
			'label'       => esc_html__( 'Clear all: text', 'zag' ),
			'inline'      => true,
			'placeholder' => esc_html__( 'Clear all', 'zag' ),
			'required'    => [ 'zagClearAll', '!=', '' ],
		];

		/**
		 * Used as the accessible name on every remove button, with the chip's
		 * own text appended: "Remove Penrith".
		 */
		$this->controls['zagRemoveText'] = [
			'group'       => 'zagFunction',
			'type'        => 'text',
			'label'       => esc_html__( 'Remove: label', 'zag' ),
			'inline'      => true,
			'placeholder' => esc_html__( 'Remove', 'zag' ),
			'desc'        => esc_html__( 'Screen reader wording. The chip text is appended to it.', 'zag' ),
		];

		// See inc/builder-preview.php.
		$this->controls['zagBuilderPreview'] = zag_builder_preview_control();

		$style = array_merge( shared_style_controls(), active_filters_style_controls() );

		foreach ( self::UNUSED_STYLE_CONTROLS as $key ) {
			unset( $style[ $key ] );
		}

		$this->controls = array_merge( $this->controls, group_controls( $style, 'zagStyle' ) );
	}

	/**
	 * An empty shell.
	 *
	 * Everything inside is built by active-filters.js from the live filter
	 * state. Rendering chips server-side would mean reading the same collapsed,
	 * name-gated data that makes Bricks' own element wrong here — and would then
	 * need this element added to dynamic_update_elements() to ever refresh,
	 * which is not something Bricks allows.
	 *
	 * Hidden until the script finds something active, so a page with no filters
	 * applied has no empty gap where the chips will be.
	 */
	public function render() {
		$settings = $this->settings;

		$label = ! empty( $settings['zagLabel'] )
			? $this->render_dynamic_data( $settings['zagLabel'] )
			: '';

		$clear_all_text = ! empty( $settings['zagClearAllText'] )
			? $this->render_dynamic_data( $settings['zagClearAllText'] )
			: esc_html__( 'Clear all', 'zag' );

		$remove_text = ! empty( $settings['zagRemoveText'] )
			? $this->render_dynamic_data( $settings['zagRemoveText'] )
			: esc_html__( 'Remove', 'zag' );

		$config = [
			'queryId'      => $settings['zagQueryId'] ?? '',
			'label'        => $label,
			'hideIcons'    => ! empty( $settings['zagHideIcons'] ),
			'clearAll'     => ! empty( $settings['zagClearAll'] ),
			'clearAllText' => $clear_all_text,
			'removeText'   => $remove_text,
		];

		// See inc/builder-preview.php.
		apply_builder_preview_attribute( $this );

		$this->set_attribute( '_root', 'class', 'zag-active-filters' );
		$this->set_attribute( '_root', 'data-zag-active-filters', wp_json_encode( $config ) );
		$this->set_attribute( '_root', 'hidden', 'hidden' );

		/**
		 * A live region: chips appear and disappear without the focus ever
		 * moving here, so the change has to be announced.
		 */
		$this->set_attribute( '_root', 'role', 'status' );
		$this->set_attribute( '_root', 'aria-live', 'polite' );

		echo "<div {$this->render_attributes('_root')}></div>";
	}
}
