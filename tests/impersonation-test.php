<?php
/**
 * Standalone check of the impersonation logic, with the Bricks surface stubbed
 * down to just what our two classes touch. No WordPress or database required.
 *
 * Run: php tests/impersonation-test.php
 *
 * The Filter_Element stub below mirrors the name-gated branches in the real
 * includes/elements/filter-base.php. If Bricks changes which controls sit behind
 * which element name, update the stub to match — a green run against a stale
 * stub proves nothing.
 */

namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'BRICKS_DB_PAGE_HEADER', '_bricks_page_header_2' );
	define( 'BRICKS_DB_PAGE_CONTENT', '_bricks_page_content_2' );
	define( 'BRICKS_DB_PAGE_FOOTER', '_bricks_page_footer_2' );

	// --- WordPress stubs ---------------------------------------------------
	function esc_html__( $t, $d = '' ) { return $t; }
	function esc_html( $t ) { return $t; }
	function esc_attr( $t ) { return $t; }
	function add_action() {}
	function add_filter() {}
	function apply_filters( $hook, $value ) { return $value; }
	function wp_is_post_revision() { return false; }
	function wp_json_encode( $v ) { return json_encode( $v ); }
	function selected( $a, $b, $echo = true ) { return $a == $b ? 'selected' : ''; }
	function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
	function wp_unslash( $v ) { return $v; }
	function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
}

// --- Bricks stubs ----------------------------------------------------------
namespace Bricks {

	class Query_Filters {
		public static $active_filters = [];
		public static $replayed       = [];

		public static function filter_controls_elements() {
			return [
				'filter-checkbox', 'filter-datepicker', 'filter-radio', 'filter-range',
				'filter-search', 'filter-select', 'filter-submit', 'filter-active-filters',
			];
		}

		public static function get_instance() {
			return new self();
		}

		/**
		 * Verbatim mirror of the real method (query-filters.php:2341).
		 *
		 * Public there, so the bridge calls it rather than reproducing it — which
		 * means this stub has to behave identically or the sort tests prove
		 * nothing. The value is `<key>_<ORDER>`, and the key may itself contain
		 * underscores, which is why the order is popped off the end.
		 */
		public static function get_selected_sort_option( $filter_value, $sort_options ) {
			$sort_value = explode( '_', $filter_value );

			if ( count( $sort_value ) < 2 ) {
				return false;
			} elseif ( count( $sort_value ) > 2 ) {
				$order = array_pop( $sort_value );
				$key   = implode( '_', $sort_value );
			} else {
				$key   = $sort_value[0];
				$order = $sort_value[1];
			}

			if ( ! $key || ! $order ) {
				return false;
			}

			$selected_option = array_filter(
				$sort_options,
				function ( $option ) use ( $key, $order ) {
					$db_source  = str_replace( [ 'term|', 'user|' ], '', $option['optionSource'] ?? '' );
					$db_order   = $option['optionOrder'] ?? 'ASC';
					$custom_key = isset( $option['optionMetaKey'] ) && in_array( $db_source, [ 'meta_value', 'meta_value_num' ], true )
						? $option['optionMetaKey']
						: false;

					return ( $db_source === $key || $custom_key === $key ) && $db_order === $order;
				}
			);

			$selected_option = array_shift( $selected_option );

			if ( ! $selected_option ) {
				return false;
			}

			$selected_option['key']   = $key;
			$selected_option['order'] = $order;

			return $selected_option;
		}

		// Record what names our replay would have handed to Bricks.
		public function qf_update_post_metadata( $check, $object_id, $meta_key, $meta_value, $prev ) {
			foreach ( $meta_value as $el ) {
				if ( in_array( $el['name'], self::filter_controls_elements(), true ) ) {
					self::$replayed[] = $el['id'] . ':' . $el['name'];
				}
			}
			return $check;
		}
	}

	class Element {
		public $name = '';
		public $id = 'abc123';
		public $settings = [];
		public $controls = [];
		public $control_groups = [];
	}

	/**
	 * Stub standing in for the real filter-base.php. Reproduces only the shape
	 * that matters here: the name gate, plus a couple of name-dependent controls
	 * mirroring the real branches at filter-base.php:1136 / :1374 / :1762.
	 */
	class Filter_Element extends Element {
		// Records the name in force when the parent runs, which is the whole
		// point of the impersonation.
		public static $count_name_seen = null;

		public function set_options_with_count() {
			self::$count_name_seen = $this->name;
		}

		public function get_filter_controls() {
			if ( ! in_array( $this->name, Query_Filters::filter_controls_elements(), true ) ) {
				return [];
			}

			$controls = [
				'filterQueryId'        => [ 'shared' => true ],
				'filterSource'         => [ 'shared' => true ],
				'fieldCompareOperator' => [ 'options' => [] ],
			];

			if ( in_array( $this->name, [ 'filter-select', 'filter-radio' ], true ) ) {
				$controls['filterAction'] = [ 'from' => 'select' ];
			}

			if ( in_array( $this->name, [ 'filter-checkbox', 'filter-radio' ], true ) ) {
				$controls['filterCountNoBracket'] = [ 'from' => 'checkbox' ];
			}

			if ( $this->name === 'filter-checkbox' ) {
				$controls['filterAutoCheckChildren'] = [ 'from' => 'checkbox' ];
			}

			if ( in_array( $this->name, [ 'filter-radio', 'filter-checkbox' ], true ) ) {
				$controls['displayMode'] = [ 'from' => 'mode-block' ];
				$controls['buttonStyle'] = [ 'from' => 'mode-block' ];
			}

			return $controls;
		}
	}
}


namespace {

	$plugin = dirname( __DIR__ );

	// The real map, not a copy: a stale duplicate makes the bridge skip an
	// element silently, and the tests would still pass.
	require $plugin . '/inc/element-map.php';

	require $plugin . '/inc/query-filters-bridge.php';
	require $plugin . '/inc/keyword-search.php';

	// The element file expects the Bricks parent to exist, which it now does.
	require $plugin . '/inc/elements/filter-select.php';

	$failures = 0;
	function check( $label, $actual, $expected ) {
		global $failures;
		$ok = $actual === $expected;
		if ( ! $ok ) { $failures++; }
		printf(
			"%s %s\n    expected: %s\n    actual:   %s\n",
			$ok ? 'PASS' : 'FAIL',
			$label,
			json_encode( $expected ),
			json_encode( $actual )
		);
	}

	// --- 1. Control impersonation -----------------------------------------
	$el = new Zag\Element_Zag_Filter_Select();
	$controls = $el->get_filter_controls();
	$keys = array_keys( $controls );
	sort( $keys );

	check(
		'get_filter_controls() returns the union of both impersonation targets',
		$keys,
		[ 'fieldCompareOperator', 'filterAction', 'filterAutoCheckChildren', 'filterCountNoBracket', 'filterQueryId', 'filterSource' ]
	);

	check( 'element name restored after impersonation', $el->name, 'zag-filter-select' );
	check( 'mode-block controls pruned', isset( $controls['displayMode'] ) || isset( $controls['buttonStyle'] ), false );
	check( 'filterAction came from the filter-select spoof', $controls['filterAction']['from'], 'select' );
	check( 'filterAutoCheckChildren came from the filter-checkbox spoof', $controls['filterAutoCheckChildren']['from'], 'checkbox' );

	// --- 2. Multi/single impersonation target ------------------------------
	$single = new Zag\Element_Zag_Filter_Select();
	$single->settings = [];
	check( 'single-select impersonates filter-select', $single->impersonates(), 'filter-select' );

	$multi = new Zag\Element_Zag_Filter_Select();
	$multi->settings = [ 'zagMultiple' => true ];
	check( 'multi-select impersonates filter-checkbox', $multi->impersonates(), 'filter-checkbox' );

	// --- 2b. Counting impersonation ----------------------------------------
	// AND-logic counting in filter-base.php is gated on 'filter-checkbox' in
	// three places; multi-select has to be counted under that name or options
	// that can only return zero results still look available.
	$multi->set_options_with_count();
	check(
		'multi-select is counted as filter-checkbox',
		Bricks\Filter_Element::$count_name_seen,
		'filter-checkbox'
	);
	check( 'name restored after counting', $multi->name, 'zag-filter-select' );

	// Single select wants the non-checkbox branch, so it must NOT spoof.
	$single->set_options_with_count();
	check(
		'single select is counted under its own name',
		Bricks\Filter_Element::$count_name_seen,
		'zag-filter-select'
	);

	// --- 3. Index injection replay ----------------------------------------
	$bridge = new Zag\Query_Filters_Bridge();

	$elements = [
		[ 'id' => 'native1', 'name' => 'filter-select', 'settings' => [] ],
		[ 'id' => 'zagA',    'name' => 'zag-filter-select', 'settings' => [] ],
		[ 'id' => 'zagB',    'name' => 'zag-filter-select', 'settings' => [ 'zagMultiple' => true ] ],
		[ 'id' => 'heading', 'name' => 'heading', 'settings' => [] ],
	];

	Bricks\Query_Filters::$replayed = [];
	$bridge->index_zag_elements( null, 42, BRICKS_DB_PAGE_CONTENT, $elements, '' );

	check(
		'replay presents Zag elements under native names, keeping their real IDs',
		Bricks\Query_Filters::$replayed,
		[ 'native1:filter-select', 'zagA:filter-select', 'zagB:filter-checkbox' ]
	);

	// A page with no Zag elements must not trigger a second write.
	Bricks\Query_Filters::$replayed = [];
	$bridge->index_zag_elements( null, 42, BRICKS_DB_PAGE_CONTENT, [ $elements[0] ], '' );
	check( 'no replay when the page has no Zag elements', Bricks\Query_Filters::$replayed, [] );

	// Non-Bricks meta keys must be ignored.
	Bricks\Query_Filters::$replayed = [];
	$bridge->index_zag_elements( null, 42, '_some_other_meta', $elements, '' );
	check( 'ignores non-Bricks meta keys', Bricks\Query_Filters::$replayed, [] );

	// --- 4. Taxonomy query vars -------------------------------------------
	Bricks\Query_Filters::$active_filters = [
		'q1' => [ 0 => [ 'filter_id' => 'zagA' ] ],
	];

	$filter = [
		'instance_name' => 'zag-filter-select',
		'value'         => 'penrith',
		'settings'      => [ 'filterSource' => 'taxonomy', 'filterTaxonomy' => 'clinic_location' ],
	];

	$vars = $bridge->build_query_vars( [], $filter, 'q1', 0 );
	check(
		'single-select builds an OR tax_query',
		$vars,
		[ 'tax_query' => [ [ 'taxonomy' => 'clinic_location', 'field' => 'slug', 'terms' => 'penrith' ] ] ]
	);
	check(
		'clause written back onto the active filter for sibling count queries',
		Bricks\Query_Filters::$active_filters['q1'][0]['query_type'],
		'tax_query'
	);

	$andFilter = [
		'instance_name' => 'zag-filter-select',
		'value'         => [ 'penrith', 'ryde' ],
		'settings'      => [
			'filterSource'     => 'taxonomy',
			'filterTaxonomy'   => 'clinic_location',
			'filterMultiLogic' => 'AND',
			'zagMultiple'      => true,
		],
	];

	$andVars = $bridge->build_query_vars( [], $andFilter, 'q1', 0 );
	check(
		'multi-select with AND logic builds one clause per term',
		$andVars['tax_query'][0],
		[
			0          => [ 'taxonomy' => 'clinic_location', 'field' => 'slug', 'terms' => 'penrith' ],
			1          => [ 'taxonomy' => 'clinic_location', 'field' => 'slug', 'terms' => 'ryde' ],
			'relation' => 'AND',
		]
	);

	// --- 5. Multi-select value recovery ------------------------------------
	// Bricks' sanitize_filter_value() collapses arrays for unrecognised element
	// names, so $filter['value'] arrives holding only the first term.
	$_GET = [ 'brx_zagA' => [ 'penrith', 'ryde', 'blaxland' ] ];

	$collapsed = [
		'filter_id'     => 'zagA',
		'instance_name' => 'zag-filter-select',
		'value'         => 'penrith',
		'settings'      => [
			'filterSource'   => 'taxonomy',
			'filterTaxonomy' => 'clinic_location',
			'zagMultiple'    => true,
		],
	];

	$recovered = $bridge->build_query_vars( [], $collapsed, 'q1', 0 );
	check(
		'multi-select recovers all terms from the URL after Bricks collapses them',
		$recovered['tax_query'][0]['terms'],
		[ 'penrith', 'ryde', 'blaxland' ]
	);

	// Nice name takes precedence over the brx_ fallback.
	$_GET = [ '_location' => [ 'katoomba', 'ryde' ], 'brx_zagA' => [ 'penrith' ] ];

	$niceNamed = $collapsed;
	$niceNamed['settings']['filterNiceName'] = '_location';

	$viaNiceName = $bridge->build_query_vars( [], $niceNamed, 'q1', 0 );
	check(
		'recovery prefers the configured URL parameter name',
		$viaNiceName['tax_query'][0]['terms'],
		[ 'katoomba', 'ryde' ]
	);

	// Absent from the request: leave Bricks' value alone rather than blanking it.
	$_GET = [];
	$absent = $bridge->build_query_vars( [], $collapsed, 'q1', 0 );
	check(
		'falls back to the collapsed value when the request has nothing',
		$absent['tax_query'][0]['terms'],
		'penrith'
	);

	// Single select must not go through recovery at all.
	$_GET = [ 'brx_zagA' => [ 'penrith', 'ryde' ] ];
	$singleFilter = $collapsed;
	unset( $singleFilter['settings']['zagMultiple'] );

	$singleVars = $bridge->build_query_vars( [], $singleFilter, 'q1', 0 );
	check(
		'single select ignores recovery and keeps its scalar value',
		$singleVars['tax_query'][0]['terms'],
		'penrith'
	);

	$_GET = [];

	// --- 6. wpField source -------------------------------------------------
	$wpField = [
		'filter_id'     => 'zagA',
		'instance_name' => 'zag-filter-select',
		'value'         => '7',
		'settings'      => [ 'filterSource' => 'wpField', 'sourceFieldType' => 'post', 'wpPostField' => 'post_author' ],
	];

	check(
		'single select on post_author uses the scalar author key',
		$bridge->build_query_vars( [], $wpField, 'q1', 0 ),
		[ 'author' => '7' ]
	);

	$_GET = [ 'brx_zagA' => [ '7', '9' ] ];
	$wpFieldMulti = $wpField;
	$wpFieldMulti['settings']['zagMultiple'] = true;

	check(
		'multi-select on post_author switches to author__in',
		$bridge->build_query_vars( [], $wpFieldMulti, 'q1', 0 ),
		[ 'author__in' => [ '7', '9' ] ]
	);
	$_GET = [];

	// Unmapped fields pass the field name straight through as the query key.
	$wpFieldRaw = $wpField;
	$wpFieldRaw['settings']['wpPostField'] = 'post_type';
	$wpFieldRaw['value'] = 'page';
	check(
		'unmapped wp fields use the field name as the query key',
		$bridge->build_query_vars( [], $wpFieldRaw, 'q1', 0 ),
		[ 'post_type' => 'page' ]
	);

	// No field configured: leave the query untouched.
	$wpFieldEmpty = $wpField;
	unset( $wpFieldEmpty['settings']['wpPostField'] );
	check( 'wpField with no field selected is a no-op', $bridge->build_query_vars( [], $wpFieldEmpty, 'q1', 0 ), [] );

	// --- 7. customField source ---------------------------------------------
	$customField = [
		'filter_id'     => 'zagA',
		'instance_name' => 'zag-filter-select',
		'value'         => 'blue',
		'settings'      => [ 'filterSource' => 'customField', 'customFieldKey' => 'colour' ],
	];

	check(
		'single select defaults to the = compare operator',
		$bridge->build_query_vars( [], $customField, 'q1', 0 )['meta_query'][0],
		[ 'key' => 'colour', 'value' => 'blue', 'compare' => '=' ]
	);

	$_GET = [ 'brx_zagA' => [ 'blue', 'red' ] ];
	$customMulti = $customField;
	$customMulti['settings']['zagMultiple'] = true;

	check(
		'multi-select defaults to the IN compare operator',
		$bridge->build_query_vars( [], $customMulti, 'q1', 0 )['meta_query'][0],
		[ 'key' => 'colour', 'value' => [ 'blue', 'red' ], 'compare' => 'IN' ]
	);

	$customAnd = $customMulti;
	$customAnd['settings']['filterMultiLogic'] = 'AND';

	check(
		'multi-select with AND logic builds one meta clause per value',
		$bridge->build_query_vars( [], $customAnd, 'q1', 0 )['meta_query'][0],
		[
			0          => [ 'key' => 'colour', 'value' => 'blue', 'compare' => 'IN' ],
			1          => [ 'key' => 'colour', 'value' => 'red', 'compare' => 'IN' ],
			'relation' => 'AND',
		]
	);
	$_GET = [];

	// An explicit operator wins over the multi/single default.
	$customExplicit = $customField;
	$customExplicit['settings']['fieldCompareOperator'] = 'LIKE';
	check(
		'an explicit compare operator overrides the default',
		$bridge->build_query_vars( [], $customExplicit, 'q1', 0 )['meta_query'][0]['compare'],
		'LIKE'
	);

	// No meta key configured: leave the query untouched.
	$customEmpty = $customField;
	unset( $customEmpty['settings']['customFieldKey'] );
	check( 'customField with no key is a no-op', $bridge->build_query_vars( [], $customEmpty, 'q1', 0 ), [] );

	// --- 8. Date picker ----------------------------------------------------
	$makeDate = function ( $settings, $value ) {
		return [
			'filter_id'     => 'zagD',
			'instance_name' => 'zag-filter-datepicker',
			'value'         => $value,
			'settings'      => array_merge( [ 'filterSource' => 'wpField' ], $settings ),
		];
	};

	check(
		'single date builds a date_query from components',
		$bridge->build_query_vars( [], $makeDate( [ 'wpPostField' => 'post_date' ], '2026-05-01' ), 'q1', 0 ),
		[ 'date_query' => [ [ 'year' => '2026', 'month' => '5', 'day' => '1' ] ] ]
	);

	check(
		'a date range builds an inclusive after/before clause',
		$bridge->build_query_vars( [], $makeDate( [ 'isDateRange' => true ], '2026-05-01,2026-05-15' ), 'q1', 0 )['date_query'][0],
		[
			'after'     => [ 'year' => '2026', 'month' => '5', 'day' => '1' ],
			'before'    => [ 'year' => '2026', 'month' => '5', 'day' => '15' ],
			'inclusive' => true,
		]
	);

	check(
		'a non-default field sets the date_query column',
		$bridge->build_query_vars( [], $makeDate( [ 'wpPostField' => 'post_modified' ], '2026-05-01' ), 'q1', 0 )['date_query'][0]['column'],
		'post_modified'
	);

	check(
		'enableTime carries hour and minute through',
		$bridge->build_query_vars( [], $makeDate( [ 'enableTime' => true ], '2026-05-01 09:30' ), 'q1', 0 )['date_query'][0],
		[ 'year' => '2026', 'month' => '5', 'day' => '1', 'hour' => '09', 'minute' => '30' ]
	);

	check(
		'a before/after operator wraps the components',
		$bridge->build_query_vars( [], $makeDate( [ 'fieldCompareOperator' => 'before' ], '2026-05-01' ), 'q1', 0 )['date_query'][0],
		[ 'before' => [ 'year' => '2026', 'month' => '5', 'day' => '1' ] ]
	);

	// Custom field dates compare as strings, not components.
	check(
		'a custom field date builds a DATE meta clause',
		$bridge->build_query_vars( [], $makeDate( [ 'filterSource' => 'customField', 'customFieldKey' => 'event_date' ], '2026-05-01' ), 'q1', 0 )['meta_query'][0],
		[ 'key' => 'event_date', 'value' => '2026-05-01', 'compare' => '=', 'type' => 'DATE' ]
	);

	check(
		'a custom field range builds paired >= and <= clauses',
		$bridge->build_query_vars( [], $makeDate( [ 'filterSource' => 'customField', 'customFieldKey' => 'event_date', 'isDateRange' => true ], '2026-05-01,2026-05-15' ), 'q1', 0 )['meta_query'][0],
		[
			[ 'key' => 'event_date', 'value' => '2026-05-01', 'compare' => '>=', 'type' => 'DATE' ],
			[ 'key' => 'event_date', 'value' => '2026-05-15', 'compare' => '<=', 'type' => 'DATE' ],
		]
	);

	check(
		'enableTime switches the meta type to DATETIME',
		$bridge->build_query_vars( [], $makeDate( [ 'filterSource' => 'customField', 'customFieldKey' => 'event_date', 'enableTime' => true ], '2026-05-01 09:30' ), 'q1', 0 )['meta_query'][0]['type'],
		'DATETIME'
	);

	// A half-entered range must not build a lopsided query.
	check(
		'range mode with only one date is a no-op',
		$bridge->build_query_vars( [], $makeDate( [ 'isDateRange' => true ], '2026-05-01' ), 'q1', 0 ),
		[]
	);

	check(
		'single mode with a range value is a no-op',
		$bridge->build_query_vars( [], $makeDate( [], '2026-05-01,2026-05-15' ), 'q1', 0 ),
		[]
	);

	check( 'an unparseable date is a no-op', $bridge->build_query_vars( [], $makeDate( [], 'not-a-date' ), 'q1', 0 ), [] );
	check( 'an empty date is a no-op', $bridge->build_query_vars( [], $makeDate( [], '' ), 'q1', 0 ), [] );

	/* ---------------------------------------------------------------------
	 * Sorting
	 *
	 * Bricks skips every element it doesn't recognise before reaching its own
	 * sort hook (query-filters.php:2106), so the bridge reproduces the builder.
	 * ------------------------------------------------------------------- */

	$sortOptions = [
		[ 'optionLabel' => 'Newest', 'optionSource' => 'date', 'optionOrder' => 'DESC' ],
		[ 'optionLabel' => 'Title', 'optionSource' => 'title', 'optionOrder' => 'ASC' ],
		[ 'optionLabel' => 'Price', 'optionSource' => 'meta_value_num', 'optionOrder' => 'ASC', 'optionMetaKey' => 'price' ],
		[ 'optionLabel' => 'Code', 'optionSource' => 'meta_value', 'optionOrder' => 'ASC', 'optionMetaKey' => 'product_code' ],
	];

	$makeSort = function ( $value, $options = null ) use ( $sortOptions ) {
		return [
			'instance_name' => 'zag-sort',
			'filter_id'     => 'sort1',
			'value'         => $value,
			'settings'      => [
				'filterAction' => 'sort',
				'sortOptions'  => $options === null ? $sortOptions : $options,
			],
		];
	};

	check(
		'a post field sort sets orderby',
		$bridge->build_query_vars( [], $makeSort( 'title_ASC' ), 'q1', 0 ),
		[ 'orderby' => [ 'title' => 'ASC' ] ]
	);

	check(
		'order is taken from the value, not the option',
		$bridge->build_query_vars( [], $makeSort( 'date_DESC' ), 'q1', 0 ),
		[ 'orderby' => [ 'date' => 'DESC' ] ]
	);

	// Numeric meta sorts need the key, the meta_value_num orderby and the type.
	check(
		'a numeric custom field sort sets meta_key and NUMERIC',
		$bridge->build_query_vars( [], $makeSort( 'price_ASC' ), 'q1', 0 ),
		[ 'meta_key' => 'price', 'orderby' => [ 'meta_value_num' => 'ASC' ], 'meta_type' => 'NUMERIC' ]
	);

	check(
		'a text custom field sort orders by the key without a meta_type',
		$bridge->build_query_vars( [], $makeSort( 'product_code_ASC' ), 'q1', 0 ),
		[ 'meta_key' => 'product_code', 'orderby' => [ 'product_code' => 'ASC' ] ]
	);

	// Sorting replaces whatever order the query already carried.
	check(
		'sort overrides an existing orderby',
		$bridge->build_query_vars( [ 'orderby' => [ 'menu_order' => 'ASC' ] ], $makeSort( 'title_ASC' ), 'q1', 0 ),
		[ 'orderby' => [ 'title' => 'ASC' ] ]
	);

	// The clause has to be recorded, same as every other builder.
	Bricks\Query_Filters::$active_filters = [ 'q1' => [ 0 => [] ] ];
	$bridge->build_query_vars( [], $makeSort( 'title_ASC' ), 'q1', 0 );
	check( 'sort records its query_type', Bricks\Query_Filters::$active_filters['q1'][0]['query_type'], 'sort' );
	check(
		'sort records the selected option',
		Bricks\Query_Filters::$active_filters['q1'][0]['sort_option_info']['optionLabel'],
		'Title'
	);

	check( 'a value matching no option is a no-op', $bridge->build_query_vars( [], $makeSort( 'nope_ASC' ), 'q1', 0 ), [] );
	check( 'a value with no order is a no-op', $bridge->build_query_vars( [], $makeSort( 'title' ), 'q1', 0 ), [] );
	check( 'the wrong order for an option is a no-op', $bridge->build_query_vars( [], $makeSort( 'title_DESC' ), 'q1', 0 ), [] );
	check( 'no sort options configured is a no-op', $bridge->build_query_vars( [], $makeSort( 'title_ASC', [] ), 'q1', 0 ), [] );

	// per_page is offered by the inherited Action control but deliberately unhandled.
	$perPage = [
		'instance_name' => 'zag-filter-select',
		'value'         => '24',
		'settings'      => [ 'filterAction' => 'per_page' ],
	];
	check( 'per_page is left inert', $bridge->build_query_vars( [], $perPage, 'q1', 0 ), [] );

	// A native filter must pass through our hook untouched.
	$nativeFilter = [
		'instance_name' => 'filter-select',
		'value'         => 'x',
		'settings'      => [ 'filterSource' => 'taxonomy', 'filterTaxonomy' => 't' ],
	];
	check( 'native filters pass through untouched', $bridge->build_query_vars( [], $nativeFilter, 'q1', 0 ), [] );

	// And a native sort must too — Bricks handles those itself.
	$nativeSort = [
		'instance_name' => 'filter-select',
		'value'         => 'title_ASC',
		'settings'      => [ 'filterAction' => 'sort', 'sortOptions' => $sortOptions ],
	];
	check( 'native sorts pass through untouched', $bridge->build_query_vars( [], $nativeSort, 'q1', 0 ), [] );

	// --- 6. Keyword search: is there anything to OR against? ---------------
	//
	// The answer decides between a union and a plain `s`, and getting it wrong
	// is invisible: a wrong "yes" returns every post, a wrong "no" quietly drops
	// the filters. Both read as the search being broken.
	Bricks\Query_Filters::$active_filters = [
		'q1' => [ [ 'query_type' => 'sort' ], [ 'query_type' => 'per_page' ] ],
	];
	check( 'sort and per_page alone do not constrain', Zag\Keyword_Search::has_constraining_filters( 'q1' ), false );

	Bricks\Query_Filters::$active_filters['q1'][] = [ 'query_type' => 'tax_query' ];
	check( 'a taxonomy clause constrains', Zag\Keyword_Search::has_constraining_filters( 'q1' ), true );

	// A filter left empty never gets a clause, so it must not count as one.
	Bricks\Query_Filters::$active_filters = [ 'q1' => [ [ 'filter_id' => 'abc' ] ] ];
	check( 'a filter with no clause does not constrain', Zag\Keyword_Search::has_constraining_filters( 'q1' ), false );

	check( 'an untouched query does not constrain', Zag\Keyword_Search::has_constraining_filters( 'nope' ), false );

	// --- 7. Keyword search: rebuilding the query around the union ----------
	$base = [
		'post_type'      => 'practitioner',
		'posts_per_page' => 10,
		'meta_query'     => [ [ 'key' => 'listed', 'value' => '1' ] ],
	];

	$merged = array_merge(
		$base,
		[
			'tax_query' => [ [ 'taxonomy' => 'discipline', 'terms' => 'podiatry' ] ],
			'orderby'   => [ 'title' => 'ASC' ],
			'paged'     => 3,
		]
	);

	$union = Zag\Keyword_Search::union_query_vars( $base, $merged, [ 12, 34 ] );

	// post__in already carries what the filters resolved to. Re-applying their
	// clauses would AND the halves back together and lose every post matched by
	// the words alone — the whole point of the feature.
	check( 'the filters\' own clauses are dropped', isset( $union['tax_query'] ), false );

	// The query element's own constraints are not filters and must survive.
	check( 'the base query is kept', $union['post_type'], 'practitioner' );
	check( 'a base meta_query is kept', $union['meta_query'], [ [ 'key' => 'listed', 'value' => '1' ] ] );

	// Sorting and paging shape the page, they do not decide what is on it.
	check( 'a sort filter\'s ordering survives', $union['orderby'], [ 'title' => 'ASC' ] );
	check( 'paging survives', $union['paged'], 3 );

	check( 'the union lands in post__in', $union['post__in'], [ 12, 34 ] );

	// [] is ignored by WP_Query, which would hand back the entire post type.
	$empty = Zag\Keyword_Search::union_query_vars( $base, $merged, [] );
	check( 'an empty union matches nothing, not everything', $empty['post__in'], [ 0 ] );

	// --- 8. Keyword search: which words are worth OR-ing --------------------
	//
	// The whole feature turns on this. WP ANDs its search terms, so a sentence
	// matches nothing and the union's keyword half is always empty; OR-ing the
	// words fixes that, but one surviving stopword ORs in the entire post type.
	check(
		'a sentence keeps only its meaningful words',
		Zag\Keyword_Search::search_words( 'penrith in crossing' ),
		[ 'penrith', 'crossing' ]
	);

	check(
		'stopwords and short words are dropped',
		Zag\Keyword_Search::search_words( 'a podiatrist in penrith who accepts ndis' ),
		[ 'podiatrist', 'penrith', 'accepts', 'ndis' ]
	);

	check( 'case is normalised', Zag\Keyword_Search::search_words( 'Ropes CROSSING' ), [ 'ropes', 'crossing' ] );

	// Two clauses for one word is just a slower query with the same answer.
	check( 'repeats are deduplicated', Zag\Keyword_Search::search_words( 'penrith penrith' ), [ 'penrith' ] );

	// Punctuation splits, so a typed sentence needs no cleaning first.
	check( "punctuation is a separator", Zag\Keyword_Search::search_words( "o'brien, penrith." ), [ 'brien', 'penrith' ] );

	// Nothing searchable must mean no query, not a query matching everything.
	check( 'a sentence of stopwords yields nothing', Zag\Keyword_Search::search_words( 'who are the' ), [] );
	check( 'an empty term yields nothing', Zag\Keyword_Search::search_words( '' ), [] );

	echo "\n" . ( $failures ? "$failures FAILED\n" : "all checks passed\n" );
	exit( $failures ? 1 : 0 );
}
