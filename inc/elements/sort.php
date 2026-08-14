<?php
/**
 * Zag - Sort element.
 *
 * Bricks models sorting as an *action* on filter-select and filter-radio rather
 * than as an element of its own (filter-base.php:1136). This subclass keeps that
 * machinery and only fixes the packaging: the action is locked to 'sort', the
 * filtering controls that action hides are dropped outright, and the element
 * gets its own name in the builder so nobody has to know that a control
 * defaulting to "Filter" is where sorting lives.
 *
 * Everything visual — the Zag machine, the markup, the styles, the mount path —
 * is inherited unchanged. `zag-sort` renders `.zag-select` and mounts through
 * mountSelect, so there is no second UI to keep in step.
 *
 * The query vars are built by Query_Filters_Bridge::build_sort_query_vars();
 * Bricks' own sort path skips every element it doesn't recognise, and its
 * dedicated hook sits after that bail.
 *
 * @see docs/sorting.md
 */

namespace Zag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Element_Zag_Sort extends Element_Zag_Filter_Select {
	public $name = 'zag-sort';
	public $icon = 'ti-exchange-vertical';

	/**
	 * Controls that only describe filtering.
	 *
	 * Bricks already hides most of these behind
	 * `[ 'filterAction', '=', [ '', 'filter' ] ]`, but that gate is driven by a
	 * control this element does not render — with the action fixed, the
	 * condition can never be evaluated against anything, so they are removed
	 * rather than hidden.
	 */
	const FILTER_ONLY_CONTROLS = [
		'filterAction',
		'filterSource',
		'sourceFieldType',
		'filterTaxonomy',
		'filterPostField',
		'filterMetaKey',
		'customFieldKey',
		'fieldProvider',
		'fieldCompareOperator',
		'filterHierarchical',
		'filterAutoCheckChildren',
		'filterHideEmpty',
		'filterHideCount',
		'filterCountNoBracket',
		'filterShowCount',
		'filterInputDebounce',
		'filterMultiLogic',
	];

	public function get_label() {
		return esc_html__( 'Zag', 'zag' ) . ' - ' . esc_html__( 'Sort', 'zag' );
	}

	public function get_keywords() {
		return [ 'sort', 'order', 'orderby', 'zag', 'select', 'dropdown' ];
	}

	/**
	 * Always a single selection.
	 *
	 * A query has one order. Left inheritable, zagMultiple would produce an
	 * array value that get_selected_sort_option() cannot parse — it splits a
	 * scalar on the last underscore — so the sort would silently stop applying.
	 */
	public function is_multiple() {
		return false;
	}

	/**
	 * Stands in for filter-select, never filter-checkbox.
	 *
	 * Follows from is_multiple(), but stated explicitly because the sort path in
	 * Bricks is itself gated on the impersonated name.
	 */
	public function impersonates() {
		return 'filter-select';
	}

	public function set_controls() {
		parent::set_controls();

		foreach ( self::FILTER_ONLY_CONTROLS as $key ) {
			unset( $this->controls[ $key ] );
		}

		// Multi-select is meaningless for a single order, and its counter label
		// only ever appeared alongside it.
		unset( $this->controls['zagMultiple'], $this->controls['zagCounterLabel'] );

		/**
		 * The sort options repeater is the whole configuration of this element,
		 * so it leads rather than sitting below the inherited controls.
		 */
		if ( isset( $this->controls['sortOptions'] ) ) {
			$sort_options = $this->controls['sortOptions'];
			unset( $this->controls['sortOptions'] );

			$this->controls = array_merge( [ 'sortOptions' => $sort_options ], $this->controls );
		}
	}

	/**
	 * Force the action, whatever the saved settings say.
	 *
	 * The control is gone, so nothing can set filterAction — but the parent, the
	 * bridge and Bricks' own `get_common_filter_settings()` all read it, and an
	 * element converted from a Zag select would still carry the old value.
	 * Setting it here means every reader sees the same thing.
	 */
	public function render() {
		$this->settings['filterAction'] = 'sort';

		parent::render();
	}
}
