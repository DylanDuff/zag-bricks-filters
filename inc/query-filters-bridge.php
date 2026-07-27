<?php
/**
 * Bridges Zag filter elements into Bricks' query filter pipeline.
 *
 * Bricks gates that pipeline on three hardcoded element-name lists in
 * includes/query-filters.php, none of which has a filter hook:
 *
 *   filter_controls_elements()  :707  — builder controls
 *   dynamic_update_elements()   :724  — re-render after a filter AJAX request
 *   indexable_elements()        :742  — inclusion in the filter index
 *
 * A custom element name is invisible to all three. This class reopens two of
 * them; the third (controls) is handled in the element itself.
 *
 * @see \Zag\Element_Zag_Filter_Select::get_filter_controls()
 */

namespace Zag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Query_Filters_Bridge {

	public function __construct() {
		/**
		 * Index injection.
		 *
		 * Bricks registers its indexer at priority 11 (query-filters.php:63).
		 * Running at 12 lets it finish its own pass before we replay the same
		 * public method with a doctored copy of the elements array.
		 */
		add_action( 'update_post_metadata', [ $this, 'index_zag_elements' ], 12, 5 );

		// Build query vars Bricks' name-keyed switch statement skips for us.
		add_filter( 'bricks/query_filters/filter_query_vars', [ $this, 'build_query_vars' ], 10, 4 );
	}

	/**
	 * Replay Bricks' element-table update with Zag elements renamed to the
	 * native filter element each one impersonates.
	 *
	 * Why a replay rather than a rewrite: Bricks' own callback is a separate
	 * subscriber on the same filter, so it receives the original $meta_value
	 * regardless of what we do to ours — WordPress passes filter arguments by
	 * value and only chains the first. We cannot change what Bricks sees, only
	 * run the work again afterwards with different input.
	 *
	 * Order matters. Bricks' pass at priority 11 writes rows for the native
	 * filter elements and deletes any element-table row not present in its
	 * input — which would include ours. Running at 12 with a superset (native
	 * elements untouched + Zag elements renamed) reinstates everything in one
	 * write, so the deletion sweep sees a complete picture.
	 *
	 * The renamed rows land with filter_type set to the native name, which is
	 * what makes Query_Filters::get_filtered_data_from_index() return counts for
	 * our real element IDs.
	 *
	 * @return mixed The unmodified $check — this is a read-only observer.
	 */
	public function index_zag_elements( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
		if ( ! $this->is_bricks_data_key( $meta_key ) ) {
			return $check;
		}

		if ( ! is_array( $meta_value ) || wp_is_post_revision( $object_id ) ) {
			return $check;
		}

		if ( ! class_exists( '\Bricks\Query_Filters' ) ) {
			return $check;
		}

		$doctored = $this->impersonate_native_elements( $meta_value );

		// No Zag elements on this page: Bricks' own pass already did everything.
		if ( $doctored === null ) {
			return $check;
		}

		\Bricks\Query_Filters::get_instance()->qf_update_post_metadata(
			$check,
			$object_id,
			$meta_key,
			$doctored,
			$prev_value
		);

		return $check;
	}

	/**
	 * Rewrite Zag element names to their native counterparts.
	 *
	 * @return array|null Doctored elements, or null if there was nothing to do.
	 */
	private function impersonate_native_elements( $elements ) {
		$map   = element_map();
		$found = false;

		foreach ( $elements as $index => $element ) {
			$name = $element['name'] ?? '';

			if ( ! isset( $map[ $name ] ) ) {
				continue;
			}

			$found = true;

			// Multi-select needs filter-checkbox's array handling, single needs
			// filter-select's scalar handling.
			$elements[ $index ]['name'] = ! empty( $element['settings']['zagMultiple'] )
				? 'filter-checkbox'
				: $map[ $name ];
		}

		return $found ? $elements : null;
	}

	private function is_bricks_data_key( $meta_key ) {
		return in_array(
			$meta_key,
			[ BRICKS_DB_PAGE_HEADER, BRICKS_DB_PAGE_CONTENT, BRICKS_DB_PAGE_FOOTER ],
			true
		);
	}

	/**
	 * Build query vars for Zag filters.
	 *
	 * Query_Filters::generate_query_vars_from_active_filters() switches on the
	 * element name read straight from page data (query-filters.php:2136), so our
	 * filters fall through it untouched. This hook fires immediately after that
	 * switch, which is where we pick them up.
	 *
	 * The native builders (build_taxonomy_query_vars et al, :2444 onward) are all
	 * `private static`, so their logic has to be reproduced rather than called.
	 */
	public function build_query_vars( $query_vars, $filter, $query_id, $filter_index ) {
		$instance_name = $filter['instance_name'] ?? '';

		if ( ! array_key_exists( $instance_name, element_map() ) ) {
			return $query_vars;
		}

		$settings      = $filter['settings'] ?? [];
		$filter_action = $settings['filterAction'] ?? 'filter';

		if ( $filter_action !== 'filter' ) {
			return $query_vars;
		}

		switch ( $settings['filterSource'] ?? false ) {
			case 'taxonomy':
				return $this->build_taxonomy_query_vars( $query_vars, $filter, $query_id, $filter_index );
		}

		return $query_vars;
	}

	/**
	 * Mirror of \Bricks\Query_Filters::build_taxonomy_query_vars() (:2444).
	 *
	 * Kept deliberately close to the original so divergence is easy to spot when
	 * Bricks changes it.
	 */
	private function build_taxonomy_query_vars( $query_vars, $filter, $query_id, $filter_index ) {
		$settings      = $filter['settings'];
		$filter_value  = $filter['value'];
		$taxonomy      = $settings['filterTaxonomy'] ?? false;
		$combine_logic = $settings['filterMultiLogic'] ?? 'OR';
		$multiple      = ! empty( $settings['zagMultiple'] );

		if ( ! $taxonomy ) {
			return $query_vars;
		}

		if ( $combine_logic === 'AND' && $multiple && is_array( $filter_value ) ) {
			$tax_query = [];

			// One clause per term so every one of them must match.
			foreach ( $filter_value as $value ) {
				$tax_query[] = [
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => $value,
				];
			}

			$tax_query['relation'] = 'AND';
		} else {
			$tax_query = [
				'taxonomy' => $taxonomy,
				'field'    => 'slug',
				'terms'    => $filter_value,
			];
		}

		if ( isset( $query_vars['tax_query'] ) ) {
			$query_vars['tax_query'][] = $tax_query;
		} else {
			$query_vars['tax_query'] = [ $tax_query ];
		}

		/**
		 * Write the resolved clause back onto the active filter.
		 *
		 * set_options_with_count() reads query_vars/query_type off sibling
		 * filters to compute counts that account for what else is active. Without
		 * this, every other filter on the query would count against an
		 * incomplete picture.
		 */
		if ( isset( \Bricks\Query_Filters::$active_filters[ $query_id ][ $filter_index ] ) ) {
			\Bricks\Query_Filters::$active_filters[ $query_id ][ $filter_index ]['query_vars'] = $tax_query;
			\Bricks\Query_Filters::$active_filters[ $query_id ][ $filter_index ]['query_type'] = 'tax_query';
		}

		return $query_vars;
	}
}
