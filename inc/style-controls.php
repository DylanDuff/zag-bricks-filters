<?php
/**
 * Style controls shared by the Zag filter elements.
 *
 * Each control writes a CSS custom property onto the element root, which
 * assets/css/zag-filters.css consumes through a var() fallback.
 *
 * Defaults here mirror the ref prototype, so the builder shows the real values.
 * The stylesheet must NOT also declare them: Bricks emits these as
 * `.brxe-<element> { --x: … }`, the same specificity as our own rule, so a
 * declared default would win on source order and no control would ever apply.
 *
 * Controls live on the Content tab beside the other Zag options rather than
 * under Style, so everything for the element reads in one place.
 *
 * @see docs/ref-parity.md
 */

namespace Zag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Accordion groups for the Content tab.
 *
 * Order here is the order they appear in the panel.
 */
function control_groups() {
	return [
		'zagFilter' => [
			'title' => esc_html__( 'Filtering', 'zag' ),
			'tab'   => 'content',
		],
		'zagFunction' => [
			'title' => esc_html__( 'Functionality', 'zag' ),
			'tab'   => 'content',
		],
		'zagStyle' => [
			'title' => esc_html__( 'Style', 'zag' ),
			'tab'   => 'content',
		],
	];
}

/**
 * Assign a group to every control that doesn't already declare one.
 *
 * Controls already carrying a group keep it — Bricks' own 'filter-active' group
 * is added by Filter_Element::set_controls_after() and shouldn't be absorbed.
 *
 * The tab is set explicitly: Elements::load_element() (elements.php:271)
 * defaults a tabless control to 'content', and a control whose tab doesn't match
 * its group's tab is silently dropped.
 */
function group_controls( $controls, $group ) {
	foreach ( $controls as $key => $control ) {
		if ( ! empty( $control['group'] ) ) {
			continue;
		}

		$controls[ $key ]['tab']   = 'content';
		$controls[ $key ]['group'] = $group;
	}

	return $controls;
}

/**
 * Build a control that sets a custom property on the element root.
 */
function css_var_control( $label, $type, $property, $default, $extra = [] ) {
	return array_merge(
		[
			'label'   => $label,
			'type'    => $type,
			'inline'  => true,
			'default' => $default,
			'css'     => [
				[
					'property' => $property,
					'selector' => '',
				],
			],
		],
		$extra
	);
}

/**
 * Palette and field controls, common to both elements.
 */
function shared_style_controls() {
	return [
		'zagPaletteSep' => [
			'label' => esc_html__( 'Palette', 'zag' ),
			'type'  => 'separator',
		],

		'zagAccent' => css_var_control(
			esc_html__( 'Accent', 'zag' ),
			'color',
			'--zag-accent',
			[ 'hex' => '#5eb4e1' ],
			[ 'description' => esc_html__( 'Selection, focus ring and indicators.', 'zag' ) ]
		),

		'zagText' => css_var_control(
			esc_html__( 'Text', 'zag' ),
			'color',
			'--zag-fg',
			[ 'hex' => '#0b1f3a' ]
		),

		'zagFieldBg' => css_var_control(
			esc_html__( 'Field background', 'zag' ),
			'color',
			'--zag-field',
			[ 'rgb' => 'rgb(247, 249, 250)' ]
		),

		'zagSurface' => css_var_control(
			esc_html__( 'Popover background', 'zag' ),
			'color',
			'--zag-surface',
			[ 'hex' => '#ffffff' ]
		),

		'zagBorder' => css_var_control(
			esc_html__( 'Border', 'zag' ),
			'color',
			'--zag-border',
			[ 'rgb' => 'rgba(197, 208, 219, 0.5)' ]
		),

		'zagBorderHover' => css_var_control(
			esc_html__( 'Border', 'zag' ) . ': ' . esc_html__( 'Hover', 'zag' ),
			'color',
			'--zag-border-hover',
			[ 'rgb' => 'rgba(147, 163, 181, 0.75)' ]
		),

		'zagFieldSep' => [
			'label' => esc_html__( 'Field', 'zag' ),
			'type'  => 'separator',
		],

		'zagFieldHeight' => css_var_control(
			esc_html__( 'Height', 'zag' ),
			'number',
			'--zag-field-height',
			'48px',
			[ 'units' => true ]
		),

		'zagFieldRadius' => css_var_control(
			esc_html__( 'Radius', 'zag' ),
			'number',
			'--zag-field-radius',
			'8px',
			[
				'units'       => true,
				'description' => esc_html__( 'Use a large value for a pill.', 'zag' ),
			]
		),

		'zagPopoverSep' => [
			'label' => esc_html__( 'Popover', 'zag' ),
			'type'  => 'separator',
		],

		/**
		 * Deliberately has no default.
		 *
		 * The ref shadow is two layers and this control only expresses one, so a
		 * default here could only be a worse version of what the stylesheet
		 * already applies. Left unset, the ref shadow stands; set, the single
		 * layer replaces it — which is a choice, not a silent downgrade.
		 */
		'zagPopoverShadow' => [
			'label'       => esc_html__( 'Shadow', 'zag' ),
			'type'        => 'box-shadow',
			'css'         => [
				[
					'property' => '--zag-popover-shadow',
					'selector' => '',
				],
			],
			'description' => esc_html__( 'Replaces the default two-layer shadow.', 'zag' ),
		],
	];
}

/**
 * Select-specific metrics.
 */
function select_style_controls() {
	return [
		'zagIconSep' => [
			'label'    => esc_html__( 'Icon', 'zag' ),
			'type'     => 'separator',
			'required' => [ 'zagIcon', '!=', '' ],
		],

		'zagIconSize' => css_var_control(
			esc_html__( 'Size', 'zag' ),
			'number',
			'--zag-icon-size',
			'40px',
			[
				'units'    => true,
				'required' => [ 'zagIcon', '!=', '' ],
			]
		),

		'zagIconRadius' => css_var_control(
			esc_html__( 'Radius', 'zag' ),
			'number',
			'--zag-icon-radius',
			'9999px',
			[
				'units'       => true,
				'description' => esc_html__( 'Defaults to a circle.', 'zag' ),
				'required'    => [ 'zagIcon', '!=', '' ],
			]
		),

		'zagIconBg' => css_var_control(
			esc_html__( 'Background', 'zag' ),
			'color',
			'--zag-icon-bg',
			[ 'hex' => '#ffffff' ],
			[ 'required' => [ 'zagIcon', '!=', '' ] ]
		),

		'zagMenuSep' => [
			'label' => esc_html__( 'Menu', 'zag' ),
			'type'  => 'separator',
		],

		'zagPopoverRadius' => css_var_control(
			esc_html__( 'Radius', 'zag' ),
			'number',
			'--zag-popover-radius',
			'12px',
			[ 'units' => true ]
		),

		'zagMenuPadding' => css_var_control(
			esc_html__( 'Padding', 'zag' ),
			'number',
			'--zag-menu-padding',
			'4px',
			[ 'units' => true ]
		),

		'zagOptionHeight' => css_var_control(
			esc_html__( 'Option height', 'zag' ),
			'number',
			'--zag-option-height',
			'48px',
			[ 'units' => true ]
		),

		'zagOptionRadius' => css_var_control(
			esc_html__( 'Option radius', 'zag' ),
			'number',
			'--zag-option-radius',
			'8px',
			[ 'units' => true ]
		),

		'zagOptionGap' => css_var_control(
			esc_html__( 'Option gap', 'zag' ),
			'number',
			'--zag-option-gap',
			'2px',
			[ 'units' => true ]
		),

		/**
		 * Both of these are ours rather than the ref's — its menu has no
		 * max-height and never scrolls, so it needed neither.
		 */
		'zagMenuMaxHeight' => css_var_control(
			esc_html__( 'Max height', 'zag' ),
			'number',
			'--zag-menu-max-height',
			'320px',
			[
				'units'       => true,
				'description' => esc_html__( 'The menu scrolls beyond this.', 'zag' ),
				'required'    => [ 'zagMenuUncapped', '=', '' ],
			]
		),

		'zagScrollbar' => css_var_control(
			esc_html__( 'Scrollbar', 'zag' ),
			'color',
			'--zag-scrollbar',
			[ 'rgb' => 'rgba(11, 31, 59, 0.18)' ],
			[ 'required' => [ 'zagMenuUncapped', '=', '' ] ]
		),
	];
}

/**
 * Date picker specific metrics.
 */
function datepicker_style_controls() {
	return [
		'zagCalendarSep' => [
			'label' => esc_html__( 'Calendar', 'zag' ),
			'type'  => 'separator',
		],

		'zagContentRadius' => css_var_control(
			esc_html__( 'Radius', 'zag' ),
			'number',
			'--zag-content-radius',
			'16px',
			[ 'units' => true ]
		),

		'zagContentPadding' => css_var_control(
			esc_html__( 'Padding', 'zag' ),
			'number',
			'--zag-content-padding',
			'20px',
			[ 'units' => true ]
		),

		'zagDaySize' => css_var_control(
			esc_html__( 'Day size', 'zag' ),
			'number',
			'--zag-day-size',
			'40px',
			[ 'units' => true ]
		),

		'zagDayRadius' => css_var_control(
			esc_html__( 'Day radius', 'zag' ),
			'number',
			'--zag-day-radius',
			'9999px',
			[
				'units'       => true,
				'description' => esc_html__( 'Defaults to a circle.', 'zag' ),
			]
		),

		'zagNavSize' => css_var_control(
			esc_html__( 'Nav button size', 'zag' ),
			'number',
			'--zag-nav-size',
			'34px',
			[ 'units' => true ]
		),

		'zagCellRadius' => css_var_control(
			esc_html__( 'Month/year radius', 'zag' ),
			'number',
			'--zag-cell-radius',
			'10px',
			[ 'units' => true ]
		),
	];
}
