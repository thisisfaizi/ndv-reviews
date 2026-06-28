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
		wp_register_script(
			'ndvr-blocks',
			NDVR_URL . 'assets/js/blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ),
			NDVR_VERSION,
			true
		);

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
				'attributes'      => $common,
				'render_callback' => array( $this, 'render_summary' ),
			)
		);

		register_block_type(
			'ndv-reviews/stars',
			array(
				'api_version'     => 2,
				'editor_script'   => 'ndvr-blocks',
				'attributes'      => $common,
				'render_callback' => array( $this, 'render_stars' ),
			)
		);

		register_block_type(
			'ndv-reviews/reviews',
			array(
				'api_version'     => 2,
				'editor_script'   => 'ndvr-blocks',
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
			'ndv-reviews/marquee',
			array(
				'api_version'     => 2,
				'editor_script'   => 'ndvr-blocks',
				'attributes'      => array(
					'source'     => array(
						'type'    => 'string',
						'default' => 'all',
					),
					'product_id' => array(
						'type'    => 'number',
						'default' => 0,
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
		return $this->widgets->summary( isset( $attr['product_id'] ) ? (int) $attr['product_id'] : 0 );
	}

	/**
	 * Render the stars block.
	 *
	 * @param array<string,mixed> $attr Attributes.
	 * @return string
	 */
	public function render_stars( $attr ) {
		return $this->widgets->stars( isset( $attr['product_id'] ) ? (int) $attr['product_id'] : 0 );
	}

	/**
	 * Render the reviews-list block.
	 *
	 * @param array<string,mixed> $attr Attributes.
	 * @return string
	 */
	public function render_reviews( $attr ) {
		return $this->widgets->reviews(
			array(
				'product_id' => isset( $attr['product_id'] ) ? (int) $attr['product_id'] : 0,
				'per_page'   => isset( $attr['per_page'] ) ? (int) $attr['per_page'] : 10,
				'orderby'    => isset( $attr['orderby'] ) ? sanitize_key( $attr['orderby'] ) : 'recent',
			)
		);
	}

	/**
	 * Render the marquee block.
	 *
	 * @param array<string,mixed> $attr Attributes.
	 * @return string
	 */
	public function render_marquee( $attr ) {
		return $this->widgets->marquee(
			array(
				'source'     => isset( $attr['source'] ) ? sanitize_key( $attr['source'] ) : 'all',
				'product_id' => isset( $attr['product_id'] ) ? (int) $attr['product_id'] : 0,
				'min_rating' => isset( $attr['min_rating'] ) ? (float) $attr['min_rating'] : 0,
				'speed'      => isset( $attr['speed'] ) ? (int) $attr['speed'] : 40,
				'direction'  => isset( $attr['direction'] ) && 'vertical' === $attr['direction'] ? 'vertical' : 'horizontal',
				'limit'      => isset( $attr['limit'] ) ? (int) $attr['limit'] : 20,
				'verified'   => ! empty( $attr['verified'] ),
			)
		);
	}
}
