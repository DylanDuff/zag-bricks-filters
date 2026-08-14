<?php
/**
 * The smart search's optional keyword pass.
 *
 * Without this the search element never touches a query: it matches words
 * against the options its sibling filters offer and sets their values, and
 * Bricks builds the query from the filters alone. The typed text is a lookup
 * key, nothing more.
 *
 * The "Also search post content" setting changes that. The sentence becomes a
 * query condition of its own, so a word no filter covers — a practitioner's
 * name, a phrase in a post body — still finds its post. Results are the union:
 *
 *     posts matching the active filters  ∪  posts matching the typed words
 *
 * @see inc/elements/smart-search.php for the control.
 * @see docs/smart-search.md for the semantics and their cost.
 */

namespace Zag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Keyword_Search {

	/**
	 * Filter clause types that narrow nothing.
	 *
	 * Query_Filters::$active_filters records a query_type per filter once its
	 * clause is built. Sorting and paging are always set to something and never
	 * reduce the result set, so a page carrying only these has nothing for the
	 * words to be OR'd against.
	 */
	const NON_CONSTRAINING = [ 'sort', 'per_page', 'pagination' ];

	/**
	 * Query var carrying the words to match, and the marker that scopes the
	 * search clause below to our own queries.
	 *
	 * WP_Query keeps unrecognised vars, so this rides along to posts_search
	 * without a property to keep in sync with whichever query is running.
	 */
	const WORDS_VAR = 'zag_keyword_words';

	/**
	 * Shorter than this and a word matches too much to be worth OR-ing.
	 *
	 * Catches "in", "a", "of" without needing them enumerated, which is most of
	 * what makes a typed sentence unusable as an OR.
	 */
	const MIN_WORD_LENGTH = 3;

	/**
	 * Words dropped before matching.
	 *
	 * Only the ones long enough to survive MIN_WORD_LENGTH — this list exists to
	 * catch what that rule misses, not to repeat it. Filterable, because the
	 * useful list is site specific: a directory of practitioners wants
	 * "practitioner" in here, and nothing generic can know that.
	 */
	const STOPWORDS = [
		'the', 'and', 'for', 'with', 'who', 'you', 'your', 'are', 'was', 'from',
		'that', 'this', 'has', 'have', 'its', 'not', 'but', 'can', 'all', 'any',
		'near', 'about', 'into', 'they', 'their', 'them', 'there', 'these',
		'those', 'what', 'when', 'where', 'which', 'will', 'would', 'some',
		'like', 'need', 'want', 'looking', 'find', 'please',
	];

	/**
	 * Elements of the page being rendered, flattened across all three areas.
	 *
	 * Scanned once per request: every query loop on the page runs this class,
	 * and page data is three get_post_meta() calls.
	 */
	private $elements = null;

	/** Query IDs targeted by a Zag filter element on this page. */
	private $filtered_query_ids = null;

	/**
	 * Unions already computed this request, keyed by query and term.
	 *
	 * bricks/posts/query_vars is not fired once per page. Every filter element
	 * calls prepare_sources(), which renders {query_results_count:…} to force
	 * the target query to run, and the render itself runs it again. Without
	 * this the two ID queries would be paid on each of those, turning a fixed
	 * cost into one that scales with how many filters are on the page.
	 */
	private $unions = [];

	public function __construct() {
		/**
		 * Priority 1000, one past Bricks' own.
		 *
		 * Both the page-load path (query-filters.php) and the AJAX path
		 * (api.php) register their merge callback at 999 and comment that the
		 * filters' query vars must come last. Ours has to see the merged result
		 * to know what the filters resolved to, so it goes after that.
		 */
		add_filter( 'bricks/posts/query_vars', [ $this, 'apply' ], 1000, 3 );

		/**
		 * Registered once and scoped by WORDS_VAR rather than added and removed
		 * around each sub-query: the plain-`s` branch does not run a query of
		 * its own, it hands vars back to Bricks and the search happens later.
		 */
		add_filter( 'posts_search', [ $this, 'or_search_clause' ], 10, 2 );
	}

	/**
	 * Match any of the typed words rather than all of them.
	 *
	 * WP_Query::parse_search() joins its terms with AND, so "penrith in
	 * crossing" asks for a post containing all three. Nothing does, the union's
	 * keyword half comes back empty, and the filters look like they beat the
	 * search outright — which is what a sentence-shaped field does to the
	 * default behaviour every time it holds more than one word.
	 *
	 * Core's clause is replaced rather than extended: the AND is built into how
	 * it assembles the terms, so there is nothing to loosen from outside.
	 */
	public function or_search_clause( $search, $query ) {
		$words = $query->get( self::WORDS_VAR );

		if ( empty( $words ) || ! is_array( $words ) ) {
			return $search;
		}

		global $wpdb;

		$clauses = [];

		foreach ( $words as $word ) {
			$like = '%' . $wpdb->esc_like( $word ) . '%';

			$clauses[] = $wpdb->prepare(
				"({$wpdb->posts}.post_title LIKE %s) OR ({$wpdb->posts}.post_excerpt LIKE %s) OR ({$wpdb->posts}.post_content LIKE %s)",
				$like,
				$like,
				$like
			);
		}

		$clause = ' AND (' . implode( ' OR ', $clauses ) . ') ';

		// Carried over from core, which appends it after its own terms.
		if ( ! is_user_logged_in() ) {
			$clause .= " AND ({$wpdb->posts}.post_password = '') ";
		}

		return $clause;
	}

	/**
	 * The words worth matching in a typed sentence.
	 *
	 * OR-ing every word turns a sentence into a match-anything query — one
	 * "in" and the keyword half returns the whole post type — so the words that
	 * carry no meaning have to go before the OR, not after.
	 *
	 * @return array Lowercased, deduplicated, in the order typed.
	 */
	public static function search_words( $term ) {
		$words     = preg_split( '/[^\p{L}\p{N}]+/u', (string) $term, -1, PREG_SPLIT_NO_EMPTY );
		$stopwords = apply_filters( 'zag/keyword_search/stopwords', self::STOPWORDS );
		$minimum   = apply_filters( 'zag/keyword_search/min_word_length', self::MIN_WORD_LENGTH );
		$kept      = [];

		foreach ( (array) $words as $word ) {
			$word = function_exists( 'mb_strtolower' ) ? mb_strtolower( $word ) : strtolower( $word );

			if ( mb_strlen( $word ) < $minimum || in_array( $word, (array) $stopwords, true ) ) {
				continue;
			}

			// Keyed to dedupe, so "penrith penrith" is one clause not two.
			$kept[ $word ] = $word;
		}

		return array_values( $kept );
	}

	/**
	 * Add the keyword condition to a query the search is pointed at.
	 *
	 * Runs for every posts query on the page; everything below narrows that to
	 * the one (or ones) a keyword-enabled smart search targets.
	 */
	public function apply( $query_vars, $settings, $element_id ) {
		if ( ! class_exists( '\Bricks\Query_Filters' ) ) {
			return $query_vars;
		}

		$element_id = (string) $element_id;
		$search     = $this->search_settings_for_query( $element_id );

		if ( ! $search ) {
			return $query_vars;
		}

		$term  = $this->term( $search );
		$words = self::search_words( $term );

		// Nothing but stopwords and initials: no query to add.
		if ( $term === '' || ! $words ) {
			return $query_vars;
		}

		/**
		 * Record the pre-keyword vars where Bricks looks for a query's original
		 * state, before touching anything.
		 *
		 * base.php renders data-original-query-vars from
		 * Query_Filters::$query_vars_before_merge, and the frontend falls back
		 * to data-query-vars — the *final* vars, ours included — whenever that
		 * attribute is an empty array. Bricks only ever populates the property
		 * from its own merge callback, and add_active_filters_query_vars() does
		 * not register one when no filter is active.
		 *
		 * So a page loaded on ?zag-search=… alone shipped the keyword clause
		 * back as the query's "original" vars, and
		 * restore_original_query_vars_from_frontend() reinstated it on every
		 * later request: the search could not be cleared, and only ever from a
		 * refresh, because the AJAX path in api.php registers that callback
		 * unconditionally and gets this right by itself.
		 *
		 * Writing it here is what the property means — these are the vars
		 * before the merge — and it is also what option counts read for their
		 * 'original' source.
		 */
		if ( ! isset( \Bricks\Query_Filters::$query_vars_before_merge[ $element_id ] ) ) {
			\Bricks\Query_Filters::$query_vars_before_merge[ $element_id ] = $query_vars;
		}

		/**
		 * Nothing active to OR against.
		 *
		 * A union with an unconstrained filter set is every post, which would
		 * make the field do nothing on exactly the words it exists to catch. So
		 * with no filter narrowing the query, the words stand alone and this
		 * behaves like an ordinary search box.
		 */
		if ( ! self::has_constraining_filters( $element_id ) ) {
			return array_merge( $query_vars, $this->search_vars( $term, $words ) );
		}

		/**
		 * The union has to be computed rather than expressed.
		 *
		 * Taxonomy and custom field filters compile to INNER JOINs, so an
		 * `OR ID IN (…)` bolted onto the WHERE cannot rescue a row the join has
		 * already dropped. Two ID-only queries and a post__in is the only shape
		 * that gives the right answer.
		 */
		$base = \Bricks\Query_Filters::$query_vars_before_merge[ $element_id ] ?? $query_vars;

		$cache_key = $element_id . '|' . $term;

		if ( ! isset( $this->unions[ $cache_key ] ) ) {
			$this->unions[ $cache_key ] = array_values(
				array_unique(
					array_merge(
						$this->collect_ids( $query_vars ),
						$this->collect_ids( array_merge( $base, $this->search_vars( $term, $words ) ) )
					)
				)
			);
		}

		return self::union_query_vars( $base, $query_vars, $this->unions[ $cache_key ] );
	}

	/**
	 * Whether any active filter on this query actually narrows it.
	 *
	 * A filter left empty never gets a query_type, so its absence is the same
	 * answer as a sort: nothing to OR against.
	 */
	public static function has_constraining_filters( $query_id ) {
		$active = \Bricks\Query_Filters::$active_filters[ $query_id ] ?? [];

		foreach ( $active as $filter ) {
			$type = $filter['query_type'] ?? '';

			if ( $type === '' || in_array( $type, self::NON_CONSTRAINING, true ) ) {
				continue;
			}

			return true;
		}

		return false;
	}

	/**
	 * Rebuild the query around the union.
	 *
	 * Starts from the pre-merge vars — the query element's own settings — and
	 * drops the filters' clauses, because post__in already carries everything
	 * they resolved to. Re-applying them would AND the two halves back together
	 * and lose every post matched by words alone.
	 *
	 * The keys copied back off the merged set shape the page rather than decide
	 * what is on it: a sort filter's ordering has to survive, and so does the
	 * paging Bricks worked out.
	 *
	 * Static and public because it is a pure transformation and the thing most
	 * worth pinning down: getting the surviving key list wrong drops a sort or
	 * reinstates a filter, and both look like a query bug rather than this.
	 */
	public static function union_query_vars( $base, $merged, $union ) {
		$vars = $base;

		foreach ( [ 'orderby', 'order', 'meta_key', 'meta_type', 'paged', 'posts_per_page', 'offset' ] as $key ) {
			if ( isset( $merged[ $key ] ) ) {
				$vars[ $key ] = $merged[ $key ];
			}
		}

		// An empty post__in is ignored by WP_Query, which would return the lot.
		$vars['post__in'] = $union ? $union : [ 0 ];

		return $vars;
	}

	/**
	 * The query vars that turn a sentence into a search.
	 *
	 * `s` still has to be set — WP_Query only runs parse_search(), and so only
	 * reaches the posts_search filter, when it is — but what it holds no longer
	 * decides anything. WORDS_VAR does, and its presence is what scopes
	 * or_search_clause() to the queries this class owns.
	 */
	private function search_vars( $term, $words ) {
		return [
			's'             => $term,
			self::WORDS_VAR => $words,
		];
	}

	/**
	 * Every post ID a set of query vars matches.
	 *
	 * Unpaged and ID-only: this is a set operation, so the page window and the
	 * post objects are both waste. no_found_rows drops the SQL_CALC_FOUND_ROWS
	 * pass, which is the expensive half of an unbounded query.
	 *
	 * Bricks applies bricks/posts/query_vars from its own Query class, never
	 * from WP_Query, so nothing here re-enters apply().
	 */
	private function collect_ids( $query_vars ) {
		$vars = array_merge(
			$query_vars,
			[
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'nopaging'               => true,
				'paged'                  => 1,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		// Would slide the window on a set that has no window.
		unset( $vars['offset'] );

		$query = new \WP_Query( $vars );

		return array_map( 'intval', $query->posts );
	}

	/**
	 * The keyword-enabled smart search pointed at this query, if there is one.
	 *
	 * @return array|null The element's settings.
	 */
	private function search_settings_for_query( $query_id ) {
		foreach ( $this->page_elements() as $element ) {
			if ( ( $element['name'] ?? '' ) !== 'zag-smart-search' ) {
				continue;
			}

			$settings = $element['settings'] ?? [];

			if ( empty( $settings['zagKeyword'] ) || ! $this->targets_query( $settings, $query_id ) ) {
				continue;
			}

			return $settings;
		}

		return null;
	}

	/**
	 * Mirror of the element's client-side targeting.
	 *
	 * "Limit to query" set means that query and no other. Left empty the search
	 * drives every Zag filter on the page, so the queries it can search are the
	 * ones those filters point at — which is also what stops the words leaking
	 * into an unrelated loop that happens to share the page.
	 */
	private function targets_query( $settings, $query_id ) {
		$limit = $settings['zagQueryId'] ?? '';

		if ( $limit !== '' ) {
			return (string) $limit === $query_id;
		}

		return in_array( $query_id, $this->filtered_query_ids(), true );
	}

	private function filtered_query_ids() {
		if ( $this->filtered_query_ids !== null ) {
			return $this->filtered_query_ids;
		}

		$ids = [];

		foreach ( $this->page_elements() as $element ) {
			if ( ! array_key_exists( $element['name'] ?? '', element_map() ) ) {
				continue;
			}

			$target = $element['settings']['filterQueryId'] ?? '';

			if ( $target !== '' ) {
				$ids[] = (string) $target;
			}
		}

		$this->filtered_query_ids = array_values( array_unique( $ids ) );

		return $this->filtered_query_ids;
	}

	/**
	 * The page's elements, header and footer included.
	 *
	 * Same source Helpers::get_element_data() reads, minus its search for a
	 * single ID. Database::$active_templates is resolved by then on both paths:
	 * the AJAX one sets it from the posted postId before any query renders.
	 *
	 * Elements nested inside a component instance are not walked, so a smart
	 * search built into a component is not discovered here.
	 */
	private function page_elements() {
		if ( $this->elements !== null ) {
			return $this->elements;
		}

		$this->elements = [];

		foreach ( [ 'header', 'content', 'footer' ] as $area ) {
			$template_id = \Bricks\Database::$active_templates[ $area ] ?? 0;

			if ( ! $template_id ) {
				continue;
			}

			$elements = \Bricks\Database::get_data( $template_id, $area );

			if ( is_array( $elements ) ) {
				$this->elements = array_merge( $this->elements, $elements );
			}
		}

		return $this->elements;
	}

	/**
	 * What the visitor typed, as it reached this request.
	 *
	 * The search element is not a Bricks filter, so its value never rides in
	 * selectedFilters — the URL is the only channel it has. On a page load that
	 * is $_GET; on a filter request it is the urlParams payload, which carries
	 * the query string search.js wrote with replaceState before firing.
	 */
	private function term( $settings ) {
		$param = class_exists( __NAMESPACE__ . '\Element_Zag_Smart_Search' )
			? Element_Zag_Smart_Search::param_from_settings( $settings )
			: 'zag-search';

		$params = Query_Filters_Bridge::rest_url_params();

		/**
		 * A filter request answers for itself.
		 *
		 * The parameter being absent from the payload means the field was
		 * cleared, so $_GET must not be consulted as a fallback — on a REST
		 * request it describes the endpoint URL, not the page.
		 */
		if ( is_array( $params ) ) {
			$raw = $params[ $param ] ?? '';
		} elseif ( Query_Filters_Bridge::is_filter_request() ) {
			/**
			 * Older Bricks: no urlParams in the payload (added 2.3.8).
			 *
			 * The referrer is the page URL as replaceState left it, which is
			 * the same string, so the search survives an AJAX round trip on
			 * those versions too — unless a referrer policy strips it, in which
			 * case the words simply stop applying after the first interaction.
			 */
			$raw = $this->referrer_param( $param );
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search term, sanitised below.
			$raw = isset( $_GET[ $param ] ) ? wp_unslash( $_GET[ $param ] ) : '';
		}

		return is_string( $raw ) ? trim( sanitize_text_field( $raw ) ) : '';
	}

	private function referrer_param( $param ) {
		$referrer = wp_get_referer();

		if ( ! $referrer ) {
			return '';
		}

		$query = wp_parse_url( $referrer, PHP_URL_QUERY );

		if ( ! $query ) {
			return '';
		}

		parse_str( $query, $args );

		return $args[ $param ] ?? '';
	}
}
