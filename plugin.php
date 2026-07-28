<?php
/**
 * Plugin Name: Zag Filters for Bricks
 * Description: Bricks query filter elements built on Zag.js state machines.
 * Version: 0.1.0
 * Requires PHP: 7.4
 * Author: Dylan
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

/**
 * Element names this plugin registers, mapped to the native Bricks filter
 * element each one impersonates for indexing and query building.
 *
 * Bricks gates its filter pipeline on hardcoded element-name lists in
 * includes/query-filters.php (filter_controls_elements, indexable_elements,
 * dynamic_update_elements). Custom names are invisible to all three, so every
 * integration point needs to know which native element to stand in for.
 *
 * @see Query_Filters_Bridge
 */
function element_map() {
	return [
		'zag-filter-select'     => 'filter-select',
		'zag-filter-datepicker' => 'filter-datepicker',
	];
}

require_once ZAG_PATH . 'inc/style-controls.php';
require_once ZAG_PATH . 'inc/query-filters-bridge.php';

new Query_Filters_Bridge();

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
		if ( ! class_exists( '\Bricks\Elements' ) || ! class_exists( '\Bricks\Filter_Element' ) ) {
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
