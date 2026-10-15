<?php
/**
 * Shared UI between the two Zag select elements.
 *
 * `zag-filter-select` (a Bricks query filter) and `zag-select` (a plain
 * dropdown with no query involved, for use inside a zag-group or standalone)
 * render an identical field — label, icon, native <select> fallback, caption
 * — and offer identical label/caption/icon/placement/multi-select controls.
 * Only how each one builds its option list and current value differs, which
 * is genuinely element-specific and stays in each element's own file.
 *
 * Extracted verbatim out of Element_Zag_Filter_Select rather than rewritten,
 * so this must keep producing byte-identical markup and settings keys for
 * that element — see docs/select.md.
 */

namespace Zag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controls both select elements share: label, caption, icon, multi-select,
 * counter label, placeholder, placement, menu sizing.
 *
 * Callers still merge in shared_style_controls()/select_style_controls()
 * themselves, same as every other element in this plugin.
 */
function zag_select_controls() {
	$controls = [];

	$controls['zagLabel'] = [
		'group'  => 'zagFunction',
		'type'   => 'text',
		'label'  => esc_html__( 'Label', 'zag' ),
		'inline' => true,
		'desc'   => esc_html__( 'Leave empty to use the placeholder as a hidden accessible name.', 'zag' ),
	];

	$controls['zagLabelHidden'] = [
		'group' => 'zagFunction',
		'type'  => 'checkbox',
		'label' => esc_html__( 'Hide label visually', 'zag' ),
		'desc'  => esc_html__( 'Still read by screen readers.', 'zag' ),
	];

	$controls['zagCaption'] = [
		'group' => 'zagFunction',
		'type'  => 'checkbox',
		'label' => esc_html__( 'Show caption', 'zag' ),
		'desc'  => esc_html__( 'A status line below the field showing the current selection.', 'zag' ),
	];

	$controls['zagCaptionEmpty'] = [
		'group'       => 'zagFunction',
		'type'        => 'text',
		'label'       => esc_html__( 'Caption: empty', 'zag' ),
		'inline'      => true,
		'placeholder' => esc_html__( 'Nothing selected', 'zag' ),
		'required'    => [ 'zagCaption', '!=', '' ],
	];

	/**
	 * Leading icon.
	 *
	 * The ref pairs this with its pill variant, but a pill is just a large
	 * Field radius — no display mode needed, and the user keeps full control
	 * of the shape. The icon is the part that couldn't be expressed as a
	 * style value, so it's the only piece that became a control.
	 */
	$controls['zagIcon'] = [
		'group' => 'zagFunction',
		'type'  => 'icon',
		'label' => esc_html__( 'Icon', 'zag' ),
		'desc'  => esc_html__( 'Shown at the start of the field.', 'zag' ),
	];

	$controls['zagMultiple'] = [
		'group' => 'zagFunction',
		'type'  => 'checkbox',
		'label' => esc_html__( 'Multi-select', 'zag' ),
		'desc'  => esc_html__( 'Allow selecting more than one option.', 'zag' ),
	];

	/**
	 * Shown after the count once more than one option is selected, e.g.
	 * "3 locations". Only ever appears in the plural, since a single
	 * selection shows its own label instead — so no singular form is needed.
	 */
	$controls['zagCounterLabel'] = [
		'group'       => 'zagFunction',
		'type'        => 'text',
		'label'       => esc_html__( 'Counter label', 'zag' ),
		'inline'      => true,
		'placeholder' => esc_html__( 'selected', 'zag' ),
		'desc'        => esc_html__( 'Follows the count, e.g. "3 locations".', 'zag' ),
		'required'    => [ 'zagMultiple', '!=', '' ],
	];

	$controls['placeholder'] = [
		'group'  => 'zagFunction',
		'type'   => 'text',
		'label'  => esc_html__( 'Placeholder', 'zag' ),
		'inline' => true,
	];

	$controls['zagPlacement'] = [
		'group'       => 'zagFunction',
		'type'        => 'select',
		'label'       => esc_html__( 'Placement', 'zag' ),
		'inline'      => true,
		'options'     => [
			'bottom-start' => esc_html__( 'Below', 'zag' ),
			'top-start'    => esc_html__( 'Above', 'zag' ),
		],
		'placeholder' => esc_html__( 'Below', 'zag' ),
	];

	/**
	 * Phrased as the exception, not the default.
	 *
	 * A checkbox only has set and unset, and `default => true` is a builder-UI
	 * affordance that never reaches $settings — so an unchecked box and an
	 * untouched one are indistinguishable, and a default-on option can never
	 * be turned off. Negative options avoid that entirely: unset is the
	 * default behaviour.
	 */
	$controls['zagMenuUncapped'] = [
		'group' => 'zagFunction',
		'type'  => 'checkbox',
		'label' => esc_html__( 'Uncapped menu height', 'zag' ),
		'desc'  => esc_html__( 'Grow to fit every option instead of scrolling.', 'zag' ),
	];

	// Negative option for the same reason as above.
	$controls['zagAutoWidth'] = [
		'group' => 'zagFunction',
		'type'  => 'checkbox',
		'label' => esc_html__( 'Auto menu width', 'zag' ),
		'desc'  => esc_html__( 'Size the menu to its content rather than the field.', 'zag' ),
	];

	// See inc/builder-preview.php.
	$controls['zagBuilderPreview'] = zag_builder_preview_control();

	return $controls;
}

/**
 * Render the field: data-zag-select config, label, icon, native <select>
 * fallback, and caption.
 *
 * $options is a list of ['value' => string, 'label' => string, 'disabled' =>
 * bool, 'depth' => int]. $current_value is a list of selected value strings.
 * Everything else this needs comes off $element->settings, which both
 * elements populate identically via zag_select_controls().
 *
 * $source_type / $source_slug describe where the options came from — a Zag
 * value alone (a term id, a post id) doesn't say what kind of thing it is, so
 * anything consuming it (chiefly zag-group's callback, which hands the raw
 * value to arbitrary code with no other context) can't tell a term from a
 * post without this. Each caller passes its own vocabulary through
 * unchanged: zag-select's is 'manual' | 'taxonomy' | 'post' (Element_Zag_Select::source_type()),
 * filter-select's is Bricks' own filterSource — 'taxonomy' | 'wpField' |
 * 'customField'. Both land in data-zag-select as-is, and select.js stores
 * the whole config on the mounted instance, so group.js's collectValues()
 * reads sourceType/sourceSlug off instance.config with no wiring of its own.
 * Left as '' (not passed) for the date picker, which is a date range, not a
 * post/term reference — there's nothing meaningful to report.
 *
 * $extra_config is merged into data-zag-select as-is, for keys only one
 * caller has (zag-select's `link`).
 */
function render_zag_select( \Bricks\Element $element, array $options, array $current_value, bool $multiple, string $input_name, string $placeholder, string $source_type = '', string $source_slug = '', array $extra_config = [] ) {
	$settings = $element->settings;

	apply_builder_preview_attribute( $element );

	$config = [
		'multiple'     => $multiple,
		'placement'    => $settings['zagPlacement'] ?? 'bottom-start',
		'sameWidth'    => empty( $settings['zagAutoWidth'] ),
		'placeholder'  => $placeholder,
		'counterLabel' => ! empty( $settings['zagCounterLabel'] )
			? $element->render_dynamic_data( $settings['zagCounterLabel'] )
			: esc_html__( 'selected', 'zag' ),
		'name'         => $input_name,
		'value'        => $current_value,
		'items'        => $options,
		'sourceType'   => $source_type,
		'sourceSlug'   => $source_slug,
	] + $extra_config;

	if ( ! empty( $settings['zagMenuUncapped'] ) ) {
		$element->set_attribute( '_root', 'data-zag-menu-uncapped', 'true' );
	}

	$element->set_attribute( '_root', 'class', 'zag-select' );
	$element->set_attribute( '_root', 'data-zag-select', wp_json_encode( $config ) );

	if ( $multiple ) {
		$element->set_attribute( '_root', 'data-zag-multiple', 'true' );
	}

	echo "<div {$element->render_attributes('_root')}>";

	/**
	 * Always rendered, even with no label text.
	 *
	 * Zag's trigger carries aria-labelledby pointing at its label part. With
	 * no label element that reference dangles and the control has no
	 * accessible name at all, so fall back to the placeholder and hide it.
	 */
	$empty_caption = ! empty( $settings['zagCaptionEmpty'] )
		? $element->render_dynamic_data( $settings['zagCaptionEmpty'] )
		: esc_html__( 'Nothing selected', 'zag' );

	$label_text   = ! empty( $settings['zagLabel'] ) ? $element->render_dynamic_data( $settings['zagLabel'] ) : '';
	$label_hidden = $label_text === '' || ! empty( $settings['zagLabelHidden'] );

	printf(
		'<label class="%s__label%s" data-zag-label>%s</label>',
		'zag-select',
		$label_hidden ? ' is-hidden' : '',
		esc_html( $label_text !== '' ? $label_text : $placeholder )
	);

	/**
	 * Rendered here rather than passed through the config JSON so the icon
	 * markup never round-trips as a string: select.js relocates this node
	 * into the trigger, so no arbitrary HTML is ever assigned via innerHTML.
	 */
	if ( ! empty( $settings['zagIcon'] ) ) {
		printf(
			'<span class="zag-select__icon" data-zag-icon>%s</span>',
			$element::render_icon( $settings['zagIcon'] )
		);
	}

	/**
	 * Progressive enhancement baseline.
	 *
	 * Rendered as a working native control so the field is usable before
	 * (and if) the Zag bundle runs. zag-filters.js hides it on mount and
	 * mirrors every selection back into it, which also keeps Bricks' own
	 * value plumbing pointed at a real form control.
	 */
	$select_name = $multiple ? $input_name . '[]' : $input_name;

	printf(
		'<select class="zag-select__native" name="%s" data-zag-native%s>',
		esc_attr( $select_name ),
		$multiple ? ' multiple' : ''
	);

	if ( ! $multiple ) {
		printf(
			'<option value="" %s>%s</option>',
			selected( $current_value === [] || $current_value === [ '' ], true, false ),
			esc_html( $placeholder )
		);
	}

	foreach ( $options as $option ) {
		printf(
			'<option value="%s"%s%s>%s</option>',
			esc_attr( $option['value'] ),
			in_array( $option['value'], $current_value, true ) ? ' selected' : '',
			$option['disabled'] ? ' disabled' : '',
			esc_html( $option['label'] )
		);
	}

	echo '</select>';

	// Live region: the text is rewritten client-side as the value changes.
	if ( ! empty( $settings['zagCaption'] ) ) {
		printf(
			'<p class="%s__caption" data-zag-caption data-empty-text="%s"><span class="%s__dot"></span><span data-zag-caption-text>%s</span></p>',
			'zag-select',
			esc_attr( $empty_caption ),
			'zag-select',
			esc_html( $empty_caption )
		);
	}

	echo '</div>';
}
