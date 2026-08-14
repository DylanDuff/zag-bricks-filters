<?php
/**
 * Which native Bricks filter element each Zag element impersonates.
 *
 * Its own file, and deliberately free of WordPress calls, so the test harness
 * can require the real thing rather than keep a copy. A duplicated map goes
 * stale silently: the tests still pass, because a missing entry makes the bridge
 * skip the element exactly as it would for a native one.
 */

namespace Zag;

if ( ! function_exists( __NAMESPACE__ . '\element_map' ) ) {
	/**
	 * Element names this plugin registers, mapped to the native Bricks filter
	 * element each one impersonates for indexing and query building.
	 *
	 * Bricks gates its filter pipeline on hardcoded element-name lists in
	 * includes/query-filters.php (filter_controls_elements, indexable_elements,
	 * dynamic_update_elements). Custom names are invisible to all three, so every
	 * integration point needs to know which native element to stand in for.
	 *
	 * zag-smart-search and zag-active-filters are deliberately absent: they hold
	 * no filter value, so they have nothing to index, no query vars to build and
	 * no counts to refresh. Every hook in Query_Filters_Bridge keys off this map,
	 * which is exactly what keeps them out of all of them.
	 *
	 * @see Query_Filters_Bridge
	 */
	function element_map() {
		return [
			'zag-filter-select'     => 'filter-select',
			'zag-filter-datepicker' => 'filter-datepicker',

			/**
			 * Sorting is gated on the same name lists as filtering.
			 *
			 * Present so Query_Filters_Bridge::build_query_vars() picks the
			 * element up — Bricks' own sort path bails on anything that isn't
			 * filter-select or filter-radio (query-filters.php:2106), and because
			 * it `continue`s, its dedicated sort hook is unreachable for us.
			 */
			'zag-sort'              => 'filter-select',
		];
	}
}
