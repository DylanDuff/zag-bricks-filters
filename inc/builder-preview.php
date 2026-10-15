<?php
/**
 * "Preview in builder" — the per-element opt-in for a live mount in canvas.
 *
 * Every Zag element's BricksFunction runner sets `frontEndOnly: false` and
 * gates itself on this instead (src/builder-preview.js's shouldMount()). Off
 * by default: on the real frontend nothing here ever runs
 * (bricks_is_builder_iframe() is false), and in canvas an element with the
 * box unchecked shows its plain native fallback rather than mounting the
 * real, request-firing UI while someone is just trying to edit the page.
 *
 * A checkbox on each element rather than a page-wide toggle: an editor
 * usually wants to preview the one control they're currently styling, not
 * turn every Zag element on the page live at once.
 *
 * @see src/builder-preview.js
 */

namespace Zag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function zag_builder_preview_control() {
	return [
		'group' => 'zagFunction',
		'type'  => 'checkbox',
		'label' => esc_html__( 'Preview in builder', 'zag' ),
		'desc'  => esc_html__( 'Mount the real Zag UI in the builder canvas instead of the plain native fallback. Fully interactive while editing, including firing real filter requests on click.', 'zag' ),
	];
}

/**
 * Sets data-zag-preview on this instance's root when it has opted in, inside
 * the builder canvas only. Call before the element renders its attributes.
 */
function apply_builder_preview_attribute( \Bricks\Element $element ) {
	if ( function_exists( 'bricks_is_builder_iframe' ) && bricks_is_builder_iframe() && ! empty( $element->settings['zagBuilderPreview'] ) ) {
		$element->set_attribute( '_root', 'data-zag-preview', 'true' );
	}
}
