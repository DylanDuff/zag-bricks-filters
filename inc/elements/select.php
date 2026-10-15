<?php
/**
 * Zag - Select element.
 *
 * The same Zag select UI as zag-filter-select, but with no query and no
 * filter involved: options come from a manual list, a taxonomy or a post
 * type, chosen directly on this element. Built for use inside a zag-group
 * (dropdowns whose selections feed a JS callback), though nothing stops it
 * being used standalone.
 *
 * zag-filter-select can't serve this case: it unconditionally requires a
 * Source (taxonomy/wpField/customField) before it renders anything, because
 * that's also what a real filter needs to build its query. See
 * inc/elements/filter-select.php:281-287 (mirroring Bricks' own
 * filter-select.php:744-749) — with no source it shows only "No filter source
 * selected.", which is what a zag-select dropped in a group used to show too,
 * before this element existed.
 *
 * Deliberately NOT a Bricks filter element and deliberately absent from
 * Zag\element_map(): it holds no filter value, targets no query, and is
 * never indexed.
 *
 * Shares its field markup and label/caption/icon/placement/multi-select
 * controls with zag-filter-select via inc/select-shared.php — see that file
 * before changing either element's field rendering, so the two don't drift.
 *
 * @see src/select.js — mounts this element too: it reads data-zag-select and
 *      never asks how the options were produced.
 * @see docs/select.md
 */

namespace Zag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Element_Zag_Select extends \Bricks\Element {
	public $category = 'filter';
	public $name     = 'zag-select';
	public $icon     = 'ti-angle-down';

	public function get_label() {
		return esc_html__( 'Zag', 'zag' ) . ' - ' . esc_html__( 'Select', 'zag' );
	}

	public function get_keywords() {
		return [ 'select', 'dropdown', 'zag', 'combobox', 'options', 'group' ];
	}

	public function enqueue_scripts() {
		wp_enqueue_script( 'zag-filters' );
		wp_enqueue_style( 'zag-filters' );
	}

	/** Same setting key and meaning as Element_Zag_Filter_Select::is_multiple(). */
	public function is_multiple() {
		return ! empty( $this->settings['zagMultiple'] );
	}

	public function set_control_groups() {
		$shared = control_groups();

		$this->control_groups = array_merge(
			$this->control_groups,
			[
				'zagSource'   => [
					'title' => esc_html__( 'Source', 'zag' ),
					'tab'   => 'content',
				],
				'zagFunction' => $shared['zagFunction'],
				'zagStyle'    => $shared['zagStyle'],
			]
		);
	}

	private function source_type() {
		$type = $this->settings['zagSourceType'] ?? '';

		return in_array( $type, [ 'taxonomy', 'post' ], true ) ? $type : 'manual';
	}

	public function set_controls() {
		$this->controls['zagSourceType'] = [
			'group'       => 'zagSource',
			'type'        => 'select',
			'label'       => esc_html__( 'Source', 'zag' ),
			'inline'      => true,
			'options'     => [
				'manual'   => esc_html__( 'Manual', 'zag' ),
				'taxonomy' => esc_html__( 'Taxonomy', 'zag' ),
				'post'     => esc_html__( 'Posts', 'zag' ),
			],
			'placeholder' => esc_html__( 'Manual', 'zag' ),
		];

		$this->controls['zagOptions'] = [
			'group'         => 'zagSource',
			'type'          => 'repeater',
			'label'         => esc_html__( 'Options', 'zag' ),
			'titleProperty' => 'zagOptionLabel',
			'required'      => [ 'zagSourceType', '!=', [ 'taxonomy', 'post' ] ],
			'fields'        => [
				'zagOptionLabel' => [
					'type'           => 'text',
					'label'          => esc_html__( 'Label', 'zag' ),
					'inline'         => true,
					'hasDynamicData' => false,
				],
				'zagOptionValue' => [
					'type'           => 'text',
					'label'          => esc_html__( 'Value', 'zag' ),
					'inline'         => true,
					'hasDynamicData' => false,
					'desc'           => esc_html__( 'Defaults to the label when empty.', 'zag' ),
				],
			],
		];

		$this->controls['zagTaxonomy'] = [
			'group'       => 'zagSource',
			'type'        => 'select',
			'label'       => esc_html__( 'Taxonomy', 'zag' ),
			'options'     => \Bricks\Setup::$control_options['taxonomies'] ?? [],
			'placeholder' => esc_html__( 'Select', 'zag' ),
			'required'    => [ 'zagSourceType', '=', 'taxonomy' ],
		];

		$this->controls['zagHideEmpty'] = [
			'group'    => 'zagSource',
			'type'     => 'checkbox',
			'label'    => esc_html__( 'Hide empty terms', 'zag' ),
			'required' => [ 'zagSourceType', '=', 'taxonomy' ],
		];

		/**
		 * Shown for a taxonomy source too, where it is optional and only read
		 * when linking: it limits which posts count when this select narrows its
		 * siblings in a group (link_config()). Linked selects must agree on it.
		 */
		$this->controls['zagPostType'] = [
			'group'       => 'zagSource',
			'type'        => 'select',
			'label'       => esc_html__( 'Post type', 'zag' ),
			'options'     => $this->post_type_options(),
			'placeholder' => esc_html__( 'Select', 'zag' ),
			'desc'        => esc_html__( 'For a taxonomy source: optional, and only used by a group with Link select options on. Selects linked together must share a post type.', 'zag' ),
			'required'    => [ 'zagSourceType', '=', [ 'taxonomy', 'post' ] ],
		];

		// Shared with zag-filter-select. See inc/select-shared.php.
		$this->controls = array_merge( $this->controls, zag_select_controls() );

		$this->controls = array_merge(
			$this->controls,
			group_controls(
				array_merge( shared_style_controls(), select_style_controls() ),
				'zagStyle'
			)
		);
	}

	private function post_type_options() {
		$options = [];

		foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $post_type ) {
			$options[ $post_type->name ] = $post_type->labels->singular_name ?: $post_type->name;
		}

		return $options;
	}

	/**
	 * Normalised [{value, label}] regardless of source, built fresh on every
	 * render — there's no index or history to read back, unlike a filter.
	 */
	private function get_options() {
		$settings = $this->settings;

		switch ( $this->source_type() ) {
			case 'taxonomy':
				return $this->options_from_taxonomy( $settings );

			case 'post':
				return $this->options_from_posts( $settings );

			default:
				return $this->options_from_manual( $settings );
		}
	}

	private function options_from_manual( $settings ) {
		$rows    = is_array( $settings['zagOptions'] ?? null ) ? $settings['zagOptions'] : [];
		$options = [];

		foreach ( $rows as $row ) {
			$label = trim( (string) ( $row['zagOptionLabel'] ?? '' ) );

			// A row with nothing to show can't produce a usable option.
			if ( $label === '' ) {
				continue;
			}

			$value = trim( (string) ( $row['zagOptionValue'] ?? '' ) );

			$options[] = [
				'value' => $value !== '' ? $value : $label,
				'label' => $label,
			];
		}

		return $options;
	}

	private function options_from_taxonomy( $settings ) {
		$taxonomy = $settings['zagTaxonomy'] ?? '';

		if ( ! $taxonomy ) {
			return [];
		}

		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => ! empty( $settings['zagHideEmpty'] ),
			]
		);

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return [];
		}

		return array_map(
			static fn( $term ) => [
				'value' => (string) $term->term_id,
				'label' => $term->name,
			],
			$terms
		);
	}

	private function options_from_posts( $settings ) {
		$post_type = $settings['zagPostType'] ?? '';

		if ( ! $post_type ) {
			return [];
		}

		$posts = get_posts(
			[
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'numberposts'    => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'fields'         => 'ids',
			]
		);

		return array_map(
			static fn( $post_id ) => [
				'value' => (string) $post_id,
				'label' => get_the_title( $post_id ),
			],
			$posts
		);
	}

	/**
	 * Whether a surrounding zag-group asked for its selects to be linked.
	 *
	 * Read off the group's render stack, which only holds entries while a group
	 * is rendering its children. The builder renders elements one at a time,
	 * so linking never applies there.
	 */
	private function is_linking() {
		return class_exists( __NAMESPACE__ . '\Element_Zag_Group' ) && Element_Zag_Group::is_linking();
	}

	/**
	 * Published post ids per term, keyed by term id string.
	 *
	 * Two queries however many terms there are: the ids of every published
	 * post carrying any term of the taxonomy, then their term relationships in
	 * one go. With no post type set, every type the taxonomy is registered for
	 * counts.
	 *
	 * @return array<string, int[]>
	 */
	public static function posts_by_term( $taxonomy, $post_type ) {
		$taxonomy_object = get_taxonomy( $taxonomy );

		if ( ! $taxonomy_object ) {
			return [];
		}

		$ids = get_posts(
			[
				'post_type'        => $post_type !== '' ? $post_type : $taxonomy_object->object_type,
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'tax_query'        => [
					[
						'taxonomy' => $taxonomy,
						'operator' => 'EXISTS',
					],
				],
			]
		);

		if ( empty( $ids ) ) {
			return [];
		}

		$terms = wp_get_object_terms( $ids, $taxonomy, [ 'fields' => 'all_with_object_id' ] );

		if ( is_wp_error( $terms ) ) {
			return [];
		}

		$posts = [];

		foreach ( $terms as $term ) {
			$posts[ (string) $term->term_id ][] = (int) $term->object_id;
		}

		return $posts;
	}

	public function render() {
		$settings = $this->settings;
		$type     = $this->source_type();

		if ( $type === 'taxonomy' && empty( $settings['zagTaxonomy'] ) ) {
			return $this->render_element_placeholder(
				[
					'title' => esc_html__( 'No source selected.', 'zag' ),
				]
			);
		}

		if ( $type === 'post' && empty( $settings['zagPostType'] ) ) {
			return $this->render_element_placeholder(
				[
					'title' => esc_html__( 'No source selected.', 'zag' ),
				]
			);
		}

		$multiple    = $this->is_multiple();
		$placeholder = ! empty( $settings['placeholder'] )
			? $this->render_dynamic_data( $settings['placeholder'] )
			: esc_html__( 'Select', 'zag' );

		$options = [];

		foreach ( $this->get_options() as $option ) {
			if ( $option['label'] === '' ) {
				continue;
			}

			$options[] = [
				'value'    => (string) $option['value'],
				'label'    => wp_strip_all_tags( $option['label'] ),
				'disabled' => false,
				'depth'    => 0,
			];
		}

		$source_slug = '';

		if ( $type === 'taxonomy' ) {
			$source_slug = (string) ( $settings['zagTaxonomy'] ?? '' );
		} elseif ( $type === 'post' ) {
			$source_slug = (string) ( $settings['zagPostType'] ?? '' );
		}

		$extra = [];

		if ( $type === 'taxonomy' && $this->is_linking() ) {
			$post_type = (string) ( $settings['zagPostType'] ?? '' );
			$posts     = self::posts_by_term( $source_slug, $post_type );

			foreach ( $options as &$option ) {
				$option['posts'] = $posts[ $option['value'] ] ?? [];
			}
			unset( $option );

			$extra['link'] = [ 'postType' => $post_type ];
		}

		// No query, no filter state — nothing to restore a selection from.
		render_zag_select( $this, $options, [], $multiple, "form-field-{$this->id}", $placeholder, $type, $source_slug, $extra );
	}
}
