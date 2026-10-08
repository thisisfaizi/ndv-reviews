<?php
/**
 * Gutenberg blocks (server-rendered).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Integrations;

use NdvReviews\Support\Registerable;
use NdvReviews\Display\Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Registers dynamic blocks whose output is produced by PHP (shared with the
 * shortcodes/widgets), with a dependency-free editor script that previews them
 * via wp.serverSideRender.
 */
class Blocks implements Registerable {

	/**
	 * Shared widget renderer.
	 *
	 * @var Widgets
	 */
	private $widgets;

	/**
	 * Constructor.
	 *
	 * @param Widgets $widgets Shared widget renderer.
	 */
	public function __construct( Widgets $widgets ) {
		$this->widgets = $widgets;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_blocks' ) );
	}

	/**
	 * Register all blocks and the shared editor script.
	 *
	 * @return void
	 */
	public function register_blocks() {
		// The editor previews (ServerSideRender) need the storefront styles.
		\NdvReviews\Display\Renderer::register_assets();

		wp_register_script(
			'ndvr-blocks',
			NDVR_URL . 'assets/js/blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ),
			NDVR_VERSION,
			true
		);
		wp_set_script_translations( 'ndvr-blocks', 'ndv-reviews', NDVR_DIR . 'languages' );

		$common = array(
			'product_id' => array(
				'type'    => 'number',
				'default' => 0,
			),
		);

		register_block_type(
			'ndv-reviews/summary',
			array(
				'api_version'     => 2,
				'editor_script'   => 'ndvr-blocks',
				'editor_style'    => 'ndvr-display',
				'attributes'      => $common,
				'render_callback' => array( $this, 'render_summary' ),
			)
		);

		register_block_type(
			'ndv-reviews/stars',
			array(
				'api_version'     => 2,
				'editor_script'   => 'ndvr-blocks',
				'editor_style'    => 'ndvr-display',
				'attributes'      => $common,
				'render_callback' => array( $this, 'render_stars' ),
			)
		);

		register_block_type(
			'ndv-reviews/reviews',
			array(
				'api_version'     => 2,
				'editor_script'   => 'ndvr-blocks',
				'editor_style'    => 'ndvr-display',
				'attributes'      => array_merge(
					$common,
					array(
						'per_page' => array(
							'type'    => 'number',
							'default' => 10,
						),
						'orderby'  => array(
							'type'    => 'string',
							'default' => 'recent',
						),
					)
				),
				'render_callback' => array( $this, 'render_reviews' ),
			)
		);

		register_block_type(
			'ndv-reviews/form',
			array(
				'api_version'     => 2,
				'editor_script'   => 'ndvr-blocks',
				'editor_style'    => 'ndvr-display',
				'attributes'      => $common,
				'render_callback' => array( $this, 'render_form' ),
			)
		);

		register_block_type(
			'ndv-reviews/marquee',
			array(
				'api_version'     => 2,
				'editor_script'   => 'ndvr-blocks',
				'editor_style'    => 'ndvr-marquee',
				'attributes'      => array(
					'source'     => array(
						'type'    => 'string',
						'default' => 'all',
					),
					'product_id' => array(
						'type'    => 'number',
						'default' => 0,
					),
					'category'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'min_rating' => array(
						'type'    => 'number',
						'default' => 0,
					),
					'speed'      => array(
						'type'    => 'number',
						'default' => 40,
					),
					'direction'  => array(
						'type'    => 'string',
						'default' => 'horizontal',
					),
					'limit'      => array(
						'type'    => 'number',
						'default' => 20,
					),
					'verified'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'with_media' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'gap'        => array(
						'type'    => 'number',
						'default' => 16,
					),
					'pause'      => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'rows'       => array(
						'type'    => 'number',
						'default' => 1,
					),
				),
				'render_callback' => array( $this, 'render_marquee' ),
			)
		);
	}

	/**
	 * Render the summary block.
	 *
	 * @param array<string,mixed> $attr Attributes.
	 * @return string
	 */
	public function render_summary( $attr ) {
		return $this->wrap( $this->widgets->summary( isset( $attr['product_id'] ) ? (int) $attr['product_id'] : 0 ) );
	}

	/**
	 * Render the stars block.
	 *
	 * @param array<string,mixed> $attr Attributes.
	 * @return string
	 */
	public function render_stars( $attr ) {
		return $this->wrap( $this->widgets->stars( isset( $attr['product_id'] ) ? (int) $attr['product_id'] : 0 ) );
	}

	/**
	 * Render the reviews-list block.
	 *
	 * @param array<string,mixed> $attr Attributes.
	 * @return string
	 */
	public function render_reviews( $attr ) {
		return $this->wrap(
			$this->widgets->reviews(
				array(
					'product_id' => isset( $attr['product_id'] ) ? (int) $attr['product_id'] : 0,
					'per_page'   => isset( $attr['per_page'] ) ? (int) $attr['per_page'] : 10,
					'orderby'    => isset( $attr['orderby'] ) ? sanitize_key( $attr['orderby'] ) : 'recent',
				)
			)
		);
	}

	/**
	 * Render the review form block (delegates to the [ndvr-form] shortcode).
	 *
	 * @param array<string,mixed> $attr Attributes.
	 * @return string
	 */
	public function render_form( $attr ) {
		$product_id = isset( $attr['product_id'] ) ? (int) $attr['product_id'] : 0;

		return $this->wrap( do_shortcode( '[ndvr-form product_id="' . $product_id . '"]' ) );
	}

	/**
	 * Render the marquee block.
	 *
	 * @param array<string,mixed> $attr Attributes.
	 * @return string
	 */
	public function render_marquee( $attr ) {
		$category = isset( $attr['category'] ) ? (string) $attr['category'] : '';
		$category = is_numeric( $category ) ? (int) $category : sanitize_title( $category );

		$html = $this->widgets->marquee(
			array(
				'source'     => isset( $attr['source'] ) ? sanitize_key( $attr['source'] ) : 'all',
				'product_id' => isset( $attr['product_id'] ) ? (int) $attr['product_id'] : 0,
				'category'   => $category,
				'min_rating' => isset( $attr['min_rating'] ) ? (float) $attr['min_rating'] : 0,
				'speed'      => isset( $attr['speed'] ) ? (int) $attr['speed'] : 40,
				// Accepts left|right|up|down (preferred) or legacy horizontal|vertical.
				'direction'  => isset( $attr['direction'] ) ? sanitize_key( $attr['direction'] ) : 'left',
				'limit'      => isset( $attr['limit'] ) ? (int) $attr['limit'] : 20,
				'verified'   => ! empty( $attr['verified'] ),
				'with_media' => ! empty( $attr['with_media'] ),
				'gap'        => isset( $attr['gap'] ) ? max( 0, min( 60, (int) $attr['gap'] ) ) : 16,
				'pause'      => ! isset( $attr['pause'] ) || ! empty( $attr['pause'] ),
				'rows'       => isset( $attr['rows'] ) ? (int) $attr['rows'] : 1,
			)
		);

		return $this->wrap( $html );
	}

	/**
	 * Wrap block output in the block wrapper (alignment, custom class, spacing
	 * supports). Empty output stays empty.
	 *
	 * @param string $html Pre-escaped block body.
	 * @return string
	 */
	private function wrap( $html ) {
		if ( '' === trim( (string) $html ) ) {
			return '';
		}

		return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-escaped attributes + pre-escaped body.
	}
}
