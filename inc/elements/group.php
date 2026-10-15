<?php
/**
 * Zag - Group element.
 *
 * A nestable container that reads whatever Zag select/date picker elements are
 * dropped inside it and hands their current values to an arbitrary JS function
 * when told to submit. It never touches Bricks' filter runtime and never
 * targets a query — the nested elements can (and usually do, here) have no
 * target query of their own either. See src/select.js: a filter element with no
 * Bricks filterInstance still mounts, holds a value and reports it; it simply
 * never drives a query. That's what makes reusing the same elements as plain UI
 * controls work with no changes to them.
 *
 * Deliberately NOT a Bricks filter element and deliberately absent from
 * Zag\element_map(): it holds no filter value of its own, so none of the
 * impersonation, indexing or query-var machinery applies.
 *
 * Extends \Bricks\Element_Container rather than \Bricks\Element — the same
 * base Bricks' own Div/Block use — which is what makes it nestable and gives it
 * the children-rendering loop for free, plus the standard layout/background/
 * border style controls every container gets.
 *
 * Submission is triggered externally rather than by a button this element
 * renders — two ways, tried in order by src/group.js's single delegated
 * click listener:
 *
 * 1. A `<button type="submit">` (or a plain `<button>` with no `type` set,
 *    since that's the browser's own default) nested anywhere inside the
 *    group. No configuration at all; this is the expected case for most
 *    groups.
 * 2. The group's own "Trigger selector" setting, a plain CSS selector matched
 *    anywhere on the page — for a trigger that can't be nested inside the
 *    group, or markup you'd rather not restructure.
 *
 * @see src/group.js
 * @see docs/group.md
 */

namespace Zag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Element_Zag_Group extends \Bricks\Element_Container {
	public $category = 'filter';
	public $name     = 'zag-group';
	public $icon     = 'ti-package';
	public $nestable = true;

	/**
	 * One entry per group currently rendering, innermost last.
	 *
	 * Bricks renders a container's children inside its own render() call, so a
	 * nested zag-select can read whether it sits in a linking group off this
	 * stack, with no page-data lookup that would miss templates or components.
	 *
	 * @see Element_Zag_Select::link_config()
	 */
	private static $link_stack = [];

	/** Whether the innermost group being rendered links its selects. */
	public static function is_linking() {
		return ! empty( self::$link_stack ) && end( self::$link_stack );
	}

	public function get_label() {
		return esc_html__( 'Zag', 'zag' ) . ' - ' . esc_html__( 'Group', 'zag' );
	}

	public function get_keywords() {
		return [ 'zag', 'group', 'form', 'submit', 'callback', 'function' ];
	}

	public function enqueue_scripts() {
		wp_enqueue_script( 'zag-filters' );
		wp_enqueue_style( 'zag-filters' );
	}

	public function set_control_groups() {
		$this->control_groups = array_merge( $this->control_groups, control_groups() );
	}

	public function set_controls() {
		parent::set_controls();

		$this->controls['zagGroupInfo'] = [
			'group'   => 'zagFunction',
			'type'    => 'info',
			'content' => esc_html__( 'A submit button (type="submit", or a plain button with no type set) anywhere inside this group triggers it automatically. For a trigger elsewhere on the page, set Trigger selector below instead. Give a nested element a custom CSS ID (Advanced → CSS ID) for a friendlier key in the values object — otherwise it falls back to the element\'s own id.', 'zag' ),
		];

		$this->controls['zagFn'] = [
			'group'       => 'zagFunction',
			'type'        => 'text',
			'label'       => esc_html__( 'Function', 'zag' ),
			'inline'      => true,
			'placeholder' => 'myApp.onSubmit',
			'desc'        => esc_html__( 'Global function name, resolved off window when the group is submitted. Called as fn(values, meta).', 'zag' ),
		];

		$this->controls['zagTriggerSelector'] = [
			'group'       => 'zagFunction',
			'type'        => 'text',
			'label'       => esc_html__( 'Trigger selector', 'zag' ),
			'inline'      => true,
			'placeholder' => esc_html__( 'e.g. .my-submit-button', 'zag' ),
			'desc'        => esc_html__( 'Optional. A plain CSS selector — clicking anything matching it, anywhere on the page, submits this group.', 'zag' ),
		];

		/**
		 * Opt-in: it adds every term's post ids to each select's payload, which
		 * a group that only collects values has no use for.
		 */
		$this->controls['zagLinkSelects'] = [
			'group' => 'zagFunction',
			'type'  => 'checkbox',
			'label' => esc_html__( 'Link select options', 'zag' ),
			'desc'  => esc_html__( 'Choosing an option in one taxonomy select hides options in the others that share no posts with it. Applies to Zag - Select elements with a Taxonomy source.', 'zag' ),
		];
	}

	public function render() {
		$fn               = trim( (string) ( $this->settings['zagFn'] ?? '' ) );
		$link             = ! empty( $this->settings['zagLinkSelects'] );
		$trigger_selector = trim( (string) ( $this->settings['zagTriggerSelector'] ?? '' ) );

		// group.js's selectors all key off this — Bricks only ever adds
		// brxe-zag-group on its own, never a plain zag-group class.
		$this->set_attribute( '_root', 'class', 'zag-group' );
		$this->set_attribute(
			'_root',
			'data-zag-group',
			wp_json_encode(
				[
					'fn'              => $fn,
					'triggerSelector' => $trigger_selector,
				]
			)
		);

		if ( $link ) {
			$this->set_attribute( '_root', 'data-zag-link', 'true' );
		}

		self::$link_stack[] = $link;

		try {
			parent::render();
		} finally {
			array_pop( self::$link_stack );
		}
	}
}
