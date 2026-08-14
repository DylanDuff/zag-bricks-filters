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

	/**
	 * The current filter AJAX request's payload, if there is one.
	 *
	 * Null on a page load, which is what tells the readers below apart: an
	 * absent key in a request that exists means "cleared", while no request at
	 * all means "look at the URL".
	 *
	 * @see capture_rest_request()
	 */
	private static $rest_params = null;

	public function __construct() {
		// Stash the raw multi-select values before Bricks flattens them.
		add_filter( 'rest_request_before_callbacks', [ $this, 'capture_rest_request' ], 10, 3 );

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

		// Refresh our option counts in the filter AJAX response.
		add_filter( 'rest_post_dispatch', [ $this, 'inject_updated_filters' ], 10, 3 );
	}

	/**
	 * Add our elements' re-rendered HTML to the filter AJAX response.
	 *
	 * Query_Filters::get_updated_filters() (:3013) skips us: it checks
	 * `dynamic_update_elements()` against the element name from page data. Without
	 * this, option counts are correct on page load and then frozen — which reads
	 * as authoritative while being wrong.
	 *
	 * We re-render after dispatch rather than replacing Bricks' own work, so
	 * native filters on the same query are untouched. Counts come out right
	 * because Query_Filters::$active_filters is still populated at this point,
	 * which is what set_options_with_count() reads.
	 */
	public function inject_updated_filters( $response, $server, $request ) {
		if ( ! is_object( $request ) || $request->get_route() !== '/bricks/v1/query_result' ) {
			return $response;
		}

		if ( ! is_object( $response ) || ! method_exists( $response, 'get_data' ) ) {
			return $response;
		}

		$data = $response->get_data();

		if ( ! is_array( $data ) ) {
			return $response;
		}

		$params     = $request->get_json_params();
		$post_id    = $params['postId'] ?? 0;
		$filter_ids = $params['filters'] ?? [];

		if ( ! $post_id || ! is_array( $filter_ids ) || empty( $filter_ids ) ) {
			return $response;
		}

		$map     = element_map();
		$updated = $data['updated_filters'] ?? [];
		$added   = false;

		foreach ( $filter_ids as $filter_id ) {
			// Bricks already rendered it: a native filter, leave it alone.
			if ( isset( $updated[ $filter_id ] ) ) {
				continue;
			}

			$element_data = \Bricks\Helpers::get_element_data( $post_id, $filter_id );
			$element      = $element_data['element'] ?? false;

			if ( ! $element || ! array_key_exists( $element['name'] ?? '', $map ) ) {
				continue;
			}

			$html = \Bricks\Frontend::render_element( $element );

			if ( ! $html ) {
				continue;
			}

			$updated[ $filter_id ] = $html;
			$added                 = true;
		}

		if ( $added ) {
			$data['updated_filters'] = $updated;
			$response->set_data( $data );
		}

		return $response;
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

	/**
	 * Keep a copy of the filter request's payload, untouched.
	 *
	 * Query_Filters::sanitize_filter_value() coerces a filter's value by
	 * switching on the element name. Our name matches no case, so multi-select
	 * arrays hit the `default` branch and get collapsed with reset() — first
	 * value kept, the rest silently dropped. The collapse happens inside
	 * set_active_filters(), which offers no hook, so the only way back to the
	 * full value is the request as it arrived.
	 *
	 * The whole payload is kept rather than just selectedFilters, because the
	 * smart search's keyword pass needs urlParams from the same request and has
	 * no business registering a second subscriber to read it.
	 *
	 * @see Keyword_Search::term()
	 */
	public function capture_rest_request( $response, $handler, $request ) {
		if ( ! is_object( $request ) || $request->get_route() !== '/bricks/v1/query_result' ) {
			return $response;
		}

		$params = $request->get_json_params();

		if ( is_array( $params ) ) {
			self::$rest_params = $params;
		}

		return $response;
	}

	/** Whether this request is a Bricks filter round trip rather than a page load. */
	public static function is_filter_request() {
		return self::$rest_params !== null;
	}

	/**
	 * The page's query string as the filter request reported it.
	 *
	 * Bricks sends this as `urlParams` (@since 2.3.8). Null when the key is
	 * absent — an older Bricks, or no request at all — which callers must tell
	 * apart from an empty array, meaning a page with no parameters.
	 *
	 * @return array|null
	 */
	public static function rest_url_params() {
		$params = self::$rest_params['urlParams'] ?? null;

		return is_array( $params ) ? $params : null;
	}

	/**
	 * Recover a multi-select filter's full value.
	 *
	 * Checks the filter AJAX payload first, then the URL — the two ways a value
	 * reaches us. Returns null when the filter isn't present in either, which is
	 * the normal "nothing selected" case.
	 *
	 * @return array|null Sanitised values, or null if absent.
	 */
	public static function recover_multi_value( $filter_id, $settings = [] ) {
		$raw      = null;
		$selected = self::$rest_params['selectedFilters'] ?? null;

		if ( is_array( $selected ) && isset( $selected[ $filter_id ] ) ) {
			$raw = $selected[ $filter_id ];
		} else {
			$nice_name = $settings['filterNiceName'] ?? '';
			$key       = $nice_name !== '' ? $nice_name : 'brx_' . $filter_id;

			if ( isset( $_GET[ $key ] ) ) {
				$raw = wp_unslash( $_GET[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification
			}
		}

		if ( $raw === null ) {
			return null;
		}

		if ( ! is_array( $raw ) ) {
			$raw = [ $raw ];
		}

		// Mirrors the tail of sanitize_filter_value(), which we've bypassed.
		$values = array_map(
			function ( $value ) {
				return sanitize_text_field( rawurldecode( (string) $value ) );
			},
			$raw
		);

		$values = array_values(
			array_filter(
				$values,
				function ( $value ) {
					return $value !== '';
				}
			)
		);

		return $values ?: null;
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

		if ( $filter_action === 'sort' ) {
			return $this->build_sort_query_vars( $query_vars, $filter, $query_id, $filter_index );
		}

		/**
		 * 'per_page' is not handled and is deliberately left inert.
		 *
		 * It is gated the same way sorting was (query-filters.php:2127) and would
		 * need the same treatment. The Action control offers it because we inherit
		 * filter-select's controls wholesale; nothing in this plugin exposes it as
		 * a supported mode.
		 */
		if ( $filter_action !== 'filter' ) {
			return $query_vars;
		}

		// Undo sanitize_filter_value()'s collapse of multi-select arrays.
		if ( ! empty( $settings['zagMultiple'] ) && ! empty( $filter['filter_id'] ) ) {
			$recovered = self::recover_multi_value( $filter['filter_id'], $settings );

			if ( $recovered !== null ) {
				$filter['value'] = $recovered;
			}
		}

		/**
		 * Date filters route by element, not by source.
		 *
		 * They share wpField/customField as sources but need date_query and
		 * DATE/DATETIME meta comparisons rather than the generic equality
		 * handling those sources otherwise get.
		 */
		if ( element_map()[ $instance_name ] === 'filter-datepicker' ) {
			return $this->build_datepicker_query_vars( $query_vars, $filter, $query_id, $filter_index );
		}

		switch ( $settings['filterSource'] ?? false ) {
			case 'taxonomy':
				return $this->build_taxonomy_query_vars( $query_vars, $filter, $query_id, $filter_index );

			case 'wpField':
				return $this->build_wp_field_query_vars( $query_vars, $filter, $query_id, $filter_index );

			case 'customField':
				return $this->build_custom_field_query_vars( $query_vars, $filter, $query_id, $filter_index );
		}

		return $query_vars;
	}

	/**
	 * Whether a filter behaves as a multi-value input.
	 *
	 * The native builders branch on `instance_name === 'filter-checkbox'` to
	 * decide array-vs-scalar semantics. Our name never matches, so every such
	 * branch keys off this instead.
	 */
	private function is_multi( $settings ) {
		return ! empty( $settings['zagMultiple'] );
	}

	/**
	 * Record the resolved clause on the active filter.
	 *
	 * set_options_with_count() reads query_vars/query_type off sibling filters to
	 * compute counts that account for what else is active. Skipping this gives
	 * every other filter on the query wrong counts.
	 */
	private function record_clause( $query_id, $filter_index, $clause, $type, $extra = [] ) {
		if ( ! isset( \Bricks\Query_Filters::$active_filters[ $query_id ][ $filter_index ] ) ) {
			return;
		}

		\Bricks\Query_Filters::$active_filters[ $query_id ][ $filter_index ]['query_vars'] = $clause;
		\Bricks\Query_Filters::$active_filters[ $query_id ][ $filter_index ]['query_type'] = $type;

		foreach ( $extra as $key => $value ) {
			\Bricks\Query_Filters::$active_filters[ $query_id ][ $filter_index ][ $key ] = $value;
		}
	}

	/**
	 * Mirror of \Bricks\Query_Filters::build_sort_query_vars() (:2277).
	 *
	 * Sorting is gated twice over. generate_query_vars_from_active_filters()
	 * bails on any element that isn't filter-select or filter-radio
	 * (query-filters.php:2106) — and because it `continue`s, the dedicated
	 * `bricks/query_filters/sort_query_vars` hook two lines later is unreachable
	 * for us. So this rides the same general filter_query_vars hook as the rest.
	 *
	 * get_selected_sort_option() is public, so only the builder is reproduced.
	 * Values arrive as `<key>_<ORDER>`, e.g. `title_ASC` or `selling_price_DESC`.
	 */
	private function build_sort_query_vars( $query_vars, $filter, $query_id, $filter_index ) {
		$settings     = $filter['settings'] ?? [];
		$sort_options = ! empty( $settings['sortOptions'] ) ? $settings['sortOptions'] : false;

		if ( ! $sort_options ) {
			return $query_vars;
		}

		$selected = \Bricks\Query_Filters::get_selected_sort_option( $filter['value'] ?? '', $sort_options );

		if ( ! $selected ) {
			return $query_vars;
		}

		$key         = $selected['key'];
		$order       = $selected['order'];
		$sort_source = $selected['optionSource'] ?? false;

		if ( ! $sort_source ) {
			return $query_vars;
		}

		// Term and user sources carry a prefix the query vars must not include.
		$sort_source = str_replace( [ 'term|', 'user|' ], '', $sort_source );

		$is_custom_field = in_array( $sort_source, [ 'meta_value', 'meta_value_num' ], true )
			&& ! empty( $selected['optionMetaKey'] );

		$sort_query = [];

		if ( $is_custom_field ) {
			$sort_query['meta_key'] = $key;
		}

		if ( $sort_source === 'meta_value_num' ) {
			$sort_query['orderby']['meta_value_num'] = $order;
			$sort_query['meta_type']                 = 'NUMERIC';
		} else {
			$sort_query['orderby'][ $key ] = $order;
		}

		/**
		 * The native builder also rebuilds this as a string for term queries,
		 * which WP_Term_Query requires. Not reproduced: it reads
		 * Query_Filters::$generating_object_type, which is private, and Bricks
		 * supports post queries only for filtering in this version.
		 */

		// Sorting replaces any order the query already carried.
		foreach ( $sort_query as $var => $value ) {
			$query_vars[ $var ] = $value;
		}

		$this->record_clause( $query_id, $filter_index, $sort_query, 'sort', [ 'sort_option_info' => $selected ] );

		return $query_vars;
	}

	/**
	 * Mirror of \Bricks\Query_Filters::build_datepicker_query_vars() (:2799).
	 */
	private function build_datepicker_query_vars( $query_vars, $filter, $query_id, $filter_index ) {
		$settings     = $filter['settings'];
		$filter_value = $filter['value'];
		$field_source = $settings['filterSource'] ?? 'wpField';
		$custom_key   = $settings['customFieldKey'] ?? false;
		$mode         = isset( $settings['isDateRange'] ) ? 'range' : 'single';
		$operator     = $settings['fieldCompareOperator'] ?? 'is';
		$enable_time  = isset( $settings['enableTime'] );
		$provider     = $settings['fieldProvider'] ?? 'none';
		$query        = [];

		/**
		 * range: 2024-05-01,2024-05-15
		 * single: 2024-05-01
		 * with time: 2024-05-01 09:00,2024-05-15 13:30
		 */
		$parsed_dates = $this->parse_datepicker_date( $filter_value );

		// A half-entered range would otherwise build a nonsense query.
		if ( $mode === 'range' && count( $parsed_dates ) !== 2 ) {
			return $query_vars;
		}

		if ( $mode === 'single' && count( $parsed_dates ) !== 1 ) {
			return $query_vars;
		}

		if ( $field_source === 'wpField' ) {
			$field_key   = $settings['wpPostField'] ?? 'post_date';
			$date_values = [];

			// WP_Date_Query wants discrete components, not a string.
			$needed_keys = $enable_time
				? [ 'year', 'month', 'day', 'hour', 'minute' ]
				: [ 'year', 'month', 'day' ];

			foreach ( $parsed_dates as $key => $date ) {
				$date_values[ $key ] = array_intersect_key( $date, array_flip( $needed_keys ) );
			}

			if ( $mode === 'single' && isset( $date_values['start'] ) ) {
				$query = $operator === 'is'
					? $date_values['start']
					: [ $operator => $date_values['start'] ];
			} elseif ( $mode === 'range' && isset( $date_values['start'], $date_values['end'] ) ) {
				$query = [
					'after'     => $date_values['start'],
					'before'    => $date_values['end'],
					'inclusive' => true, // Include the selected dates, not just between them.
				];
			}

			// post_date is WP_Date_Query's default column.
			if ( $field_key !== 'post_date' ) {
				$query['column'] = $field_key;
			}

			if ( isset( $query_vars['date_query'] ) ) {
				$query_vars['date_query'][] = $query;
			} else {
				$query_vars['date_query'] = [ $query ];
			}

			$this->record_clause( $query_id, $filter_index, $query, 'date_query', [ 'parsed_dates' => $parsed_dates ] );

			return $query_vars;
		}

		if ( $field_source === 'customField' ) {
			$date_values = [];

			// Meta comparisons want a string, date-only unless time is enabled.
			foreach ( $parsed_dates as $key => $date ) {
				if ( ! isset( $date['string'] ) ) {
					continue;
				}

				$date_values[ $key ] = $enable_time
					? $date['string']
					: explode( ' ', $date['string'] )[0];
			}

			$type = $enable_time ? 'DATETIME' : 'DATE';

			if ( $mode === 'single' && isset( $date_values['start'] ) ) {
				$query = [
					'key'     => $custom_key,
					'value'   => $date_values['start'],
					'compare' => $operator === 'is' ? '=' : ( $operator === 'before' ? '<=' : '>=' ),
					'type'    => $type,
				];
			} elseif ( $mode === 'range' && isset( $date_values['start'], $date_values['end'] ) ) {
				foreach ( $date_values as $key => $value ) {
					$query[] = [
						'key'     => $custom_key,
						'value'   => $value,
						'compare' => $key === 'start' ? '>=' : '<=',
						'type'    => $type,
					];
				}
			}

			// Undocumented in Bricks, but third-party integrations rely on it.
			$query = apply_filters( 'bricks/query_filters/datepicker_custom_field_meta_query', $query, $filter, $provider, $query_id );

			if ( empty( $query ) ) {
				return $query_vars;
			}

			if ( isset( $query_vars['meta_query'] ) ) {
				$query_vars['meta_query'][] = $query;
			} else {
				$query_vars['meta_query'] = [ $query ];
			}

			$this->record_clause( $query_id, $filter_index, $query, 'meta_query', [ 'parsed_dates' => $parsed_dates ] );
		}

		return $query_vars;
	}

	/**
	 * Parse a datepicker value into date components.
	 *
	 * Mirror of \Bricks\Query_Filters::parse_datepicker_date() (:2949), factored:
	 * the original repeats the same component array three times, and its second
	 * regex branch is unreachable because the first pattern's trailing group is
	 * already optional.
	 *
	 * @return array Keyed 'start' and, for a range, 'end'. Empty if unparseable.
	 */
	private function parse_datepicker_date( $date_string ) {
		$pattern = '/^(\d{4}-\d{2}-\d{2})(?: (\d{2}:\d{2}))?(?:,(\d{4}-\d{2}-\d{2})(?: (\d{2}:\d{2}))?)?$/';

		if ( ! preg_match( $pattern, (string) $date_string, $matches ) ) {
			return [];
		}

		$result = [
			'start' => $this->parse_date_part( $matches[1], $matches[2] ?? null ),
		];

		if ( ! empty( $matches[3] ) ) {
			$result['end'] = $this->parse_date_part( $matches[3], $matches[4] ?? null );
		}

		return $result;
	}

	private function parse_date_part( $date, $time ) {
		$date_time = new \DateTime( $date . ( $time ? " $time" : '' ) );

		return [
			'year' => $date_time->format( 'Y' ),
			// Leading zeros stripped: WP_Date_Query wants integers.
			'month'  => ltrim( $date_time->format( 'm' ), '0' ),
			'day'    => ltrim( $date_time->format( 'd' ), '0' ),
			'hour'   => $time ? $date_time->format( 'H' ) : null,
			'minute' => $time ? $date_time->format( 'i' ) : null,
			'string' => $date_time->format( 'Y-m-d H:i' ),
			'object' => $date_time,
		];
	}

	/**
	 * Mirror of \Bricks\Query_Filters::build_wp_field_query_vars() (:2497).
	 */
	private function build_wp_field_query_vars( $query_vars, $filter, $query_id, $filter_index ) {
		$settings     = $filter['settings'];
		$filter_value = $filter['value'];
		$field_type   = $settings['sourceFieldType'] ?? 'post';

		$source_field_keys = [
			'post' => 'wpPostField',
			'user' => 'wpUserField',
			'term' => 'wpTermField',
		];

		$setting_key  = $source_field_keys[ $field_type ] ?? false;
		$source_field = $setting_key ? ( $settings[ $setting_key ] ?? false ) : false;

		if ( ! $source_field ) {
			return $query_vars;
		}

		switch ( $source_field ) {
			// POST
			case 'post_date':
				$key = 'date';
				break;

			case 'post_modified':
				$key = 'modified';
				break;

			case 'post_author':
				// Multi-value needs author__in; a single author is a scalar.
				$key = $this->is_multi( $settings ) ? 'author__in' : 'author';
				break;

			case 'post_id':
				$key = 'p';
				break;

			// USER
			case 'user_registered':
				$key = 'registered';
				break;

			case 'user_role':
				$key = 'role__in';
				break;

			case 'user_id':
				$key = 'include';
				break;

			// TERM
			case 'term_id':
				$key = 'include';
				break;

			default:
				$key = $source_field;
				break;
		}

		$wp_field_query = [
			$key => $filter_value,
		];

		$this->record_clause( $query_id, $filter_index, $wp_field_query, 'wp_query' );

		// These override rather than merge into the existing query vars.
		foreach ( $wp_field_query as $query_key => $value ) {
			$query_vars[ $query_key ] = $value;
		}

		return $query_vars;
	}

	/**
	 * Mirror of \Bricks\Query_Filters::build_custom_field_query_vars() (:2603).
	 */
	private function build_custom_field_query_vars( $query_vars, $filter, $query_id, $filter_index ) {
		$settings         = $filter['settings'];
		$filter_value     = $filter['value'];
		$field_type       = $settings['sourceFieldType'] ?? 'post';
		$custom_field_key = $settings['customFieldKey'] ?? false;
		$provider         = $settings['fieldProvider'] ?? 'none';
		$combine_logic    = $settings['filterMultiLogic'] ?? 'OR';
		$multiple         = $this->is_multi( $settings );

		if ( ! $field_type || ! $custom_field_key ) {
			return $query_vars;
		}

		if ( isset( $settings['fieldCompareOperator'] ) ) {
			$compare_operator = $settings['fieldCompareOperator'];
		} else {
			// Single select compares one value; multi compares a set.
			$compare_operator = $multiple ? 'IN' : '=';
		}

		$meta_query = [];

		if ( $combine_logic === 'AND' && $multiple && is_array( $filter_value ) ) {
			// One clause per value so all of them must match.
			foreach ( $filter_value as $value ) {
				$meta_query[] = [
					'key'     => $custom_field_key,
					'value'   => $value,
					'compare' => $compare_operator,
				];
			}

			$meta_query['relation'] = $combine_logic;
		} else {
			$meta_query = [
				'key'     => $custom_field_key,
				'value'   => $filter_value,
				'compare' => $compare_operator,
			];
		}

		// Undocumented in Bricks, but third-party integrations rely on it.
		$meta_query = apply_filters( 'bricks/query_filters/custom_field_meta_query', $meta_query, $filter, $provider, $query_id );

		if ( empty( $meta_query ) ) {
			return $query_vars;
		}

		if ( isset( $query_vars['meta_query'] ) ) {
			$query_vars['meta_query'][] = $meta_query;
		} else {
			$query_vars['meta_query'] = [ $meta_query ];
		}

		$this->record_clause( $query_id, $filter_index, $meta_query, 'meta_query' );

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
		$multiple      = $this->is_multi( $settings );

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

		$this->record_clause( $query_id, $filter_index, $tax_query, 'tax_query' );

		return $query_vars;
	}
}
