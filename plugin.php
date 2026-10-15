<?php
/**
 * Plugin Name: Zag Filters for Bricks
 * Description: Bricks query filter elements built on Zag.js state machines.
 * Version: 0.2.2
 * Requires PHP: 7.4
 * Author: Dylan
 * GitHub URI: https://github.com/DylanDuff/zag-bricks-filters
 * Text Domain: zag
 */

namespace Zag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZAG_FILE', __FILE__ );
define( 'ZAG_PATH', plugin_dir_path( __FILE__ ) );
define( 'ZAG_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin version.
 *
 * The `Version:` header above is the single source of truth. Read at runtime so
 * a release bump never needs a matching constant edit.
 */
function version() {
	static $version = null;

	if ( $version === null ) {
		$data    = get_file_data( ZAG_FILE, [ 'Version' => 'Version' ], 'plugin' );
		$version = $data['Version'] ?: '0.0.0';
	}

	return $version;
}

require_once ZAG_PATH . 'inc/element-map.php';
require_once ZAG_PATH . 'inc/style-controls.php';
require_once ZAG_PATH . 'inc/builder-preview.php';
require_once ZAG_PATH . 'inc/select-shared.php';
require_once ZAG_PATH . 'inc/query-filters-bridge.php';
require_once ZAG_PATH . 'inc/keyword-search.php';
require_once ZAG_PATH . 'inc/updater.php';

new Query_Filters_Bridge();

/**
 * Must follow the bridge: it reads the filter request the bridge captures.
 * Inert unless a smart search on the page opts into keyword matching.
 */
new Keyword_Search();

/**
 * Register elements on `init` priority 20.
 *
 * Bricks\Elements::init_elements() runs on `init` at the default priority 10 and
 * is what requires includes/elements/filter-base.php. Registering any earlier
 * fatals on the missing Bricks\Filter_Element parent class.
 */
add_action(
	'init',
	function () {
		if ( ! class_exists( '\Bricks\Elements' ) || ! class_exists( '\Bricks\Filter_Element' ) || ! class_exists( '\Bricks\Element_Container' ) ) {
			return;
		}

		// Pass name and class explicitly: the two-arg form falls back to
		// end( get_declared_classes() ), which is order-dependent and brittle.
		\Bricks\Elements::register_element(
			ZAG_PATH . 'inc/elements/filter-select.php',
			'zag-filter-select',
			__NAMESPACE__ . '\Element_Zag_Filter_Select'
		);

		\Bricks\Elements::register_element(
			ZAG_PATH . 'inc/elements/filter-datepicker.php',
			'zag-filter-datepicker',
			__NAMESPACE__ . '\Element_Zag_Filter_Datepicker'
		);

		/**
		 * Must follow the select: Element_Zag_Sort extends it, and
		 * register_element() requires the file there and then (elements.php:220),
		 * so the parent has to be declared by the time this line runs.
		 */
		\Bricks\Elements::register_element(
			ZAG_PATH . 'inc/elements/sort.php',
			'zag-sort',
			__NAMESPACE__ . '\Element_Zag_Sort'
		);

		// These two extend \Bricks\Element, not \Bricks\Filter_Element, so they
		// have no ordering requirement of their own — registered here to keep
		// every element in one place.
		\Bricks\Elements::register_element(
			ZAG_PATH . 'inc/elements/smart-search.php',
			'zag-smart-search',
			__NAMESPACE__ . '\Element_Zag_Smart_Search'
		);

		\Bricks\Elements::register_element(
			ZAG_PATH . 'inc/elements/active-filters.php',
			'zag-active-filters',
			__NAMESPACE__ . '\Element_Zag_Active_Filters'
		);

		// Same field as zag-filter-select (inc/select-shared.php), but with no
		// query and no filter — options are manual, taxonomy or post.
		\Bricks\Elements::register_element(
			ZAG_PATH . 'inc/elements/select.php',
			'zag-select',
			__NAMESPACE__ . '\Element_Zag_Select'
		);

		// Extends \Bricks\Element_Container, not \Bricks\Element like the
		// others above — nestable, and holds no filter value of its own either.
		\Bricks\Elements::register_element(
			ZAG_PATH . 'inc/elements/group.php',
			'zag-group',
			__NAMESPACE__ . '\Element_Zag_Group'
		);
	},
	20
);

/**
 * Cache-busting version for an asset.
 *
 * File mtime rather than plugin version: the bundle is rebuilt far more often
 * than the version is bumped, and a stale cached bundle during development looks
 * exactly like a bug that hasn't been fixed. Falls back to the plugin version if
 * the file is missing.
 */
function asset_version( $relative_path ) {
	$mtime = @filemtime( ZAG_PATH . $relative_path );

	return $mtime ? (string) $mtime : version();
}

/**
 * Mark the document as JS-capable before the body paints.
 *
 * The stylesheet uses `.zag-js` to dress each element's native control as a
 * skeleton of the mounted field, which is what stops the flash of an unstyled
 * <select> and the layout jump when the Zag UI replaces it. It has to land on
 * <html> before first paint, so it is inline in the head rather than in the
 * bundle, which loads in the footer.
 *
 * Printed unconditionally: Bricks renders elements during the body, so at
 * wp_head there is no reliable way to know whether this page has one. The cost
 * is a single attribute write.
 *
 * Deliberately not wrapped in a feature check — without JS the class is never
 * added and every native control stays exactly as rendered.
 *
 * Also flags the builder canvas here, for the same before-paint reason.
 *
 * Bricks' own interactive elements set `frontEndOnly: true` on their
 * BricksFunction runners to avoid fighting the canvas' click-to-select
 * handling, and zag-filters.js copies that (see src/zag-filters.js). Bricks
 * enforces it by checking `document.body.classList.contains('bricks-is-frontend')`
 * — a class that never exists in canvas (`bricks_is_frontend()` is literally
 * `!bricks_is_builder()`) — so the Zag bundle never mounts there either. That
 * collided with the line above: `.zag-js` still gets added in canvas, hiding
 * the native control via the pre-mount CSS, and nothing ever reveals it. The
 * elements were never invisible on the real frontend; only in canvas, and only
 * because of this interaction.
 *
 * `zag-builder` tells the stylesheet to skip the pre-mount hide by default, so
 * canvas shows the plain native control instead of nothing. Each element then
 * has its own "Preview in builder" checkbox (inc/builder-preview.php) that
 * renders a `data-zag-preview` attribute on that one instance — per element,
 * not page-wide, since an editor usually wants to check the one control
 * they're actively styling, not turn every Zag element on the page into a
 * live, request-firing control while they edit. The stylesheet and
 * src/builder-preview.js's shouldMount() both read that attribute together
 * with `.zag-builder` to decide, per element, whether to hide-until-ready
 * (preview on) or show the native fallback outright (preview off).
 */
add_action(
	'wp_head',
	function () {
		$is_builder_iframe = function_exists( 'bricks_is_builder_iframe' ) && bricks_is_builder_iframe();

		wp_print_inline_script_tag(
			"document.documentElement.classList.add('zag-js');"
			. ( $is_builder_iframe ? "document.documentElement.classList.add('zag-builder');" : '' ),
			[ 'id' => 'zag-js-flag' ]
		);
	},
	1
);

add_action(
	'wp_enqueue_scripts',
	function () {
		wp_register_script(
			'zag-filters',
			ZAG_URL . 'assets/js/zag-filters.js',
			[ 'bricks-filters' ],
			asset_version( 'assets/js/zag-filters.js' ),
			true
		);

		wp_register_style(
			'zag-filters',
			ZAG_URL . 'assets/css/zag-filters.css',
			[],
			asset_version( 'assets/css/zag-filters.css' )
		);
	}
);
