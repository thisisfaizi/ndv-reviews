<?php
/**
 * Shared widget renderers reused by shortcodes, blocks, classic widgets,
 * and Elementor — a single source of truth for review UI.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Display;

use NdvReviews\Support\View;
use NdvReviews\Reviews\ReviewQuery;
use NdvReviews\Reviews\Votes;

defined( 'ABSPATH' ) || exit;

/**
 * Produces the HTML for each review widget. Every surface (shortcode, block,
 * classic widget, Elementor) calls these methods so markup never diverges.
 */
class Widgets {

	/**
	 * Summary service.
	 *
	 * @var Summary
	 */
	private $summary;

	/**
	 * Review query.
	 *
	 * @var ReviewQuery
	 */
	private $query;

	/**
	 * Whether display assets have been requested for this request.
	 *
	 * @var bool
	 */
	private $assets_enqueued = false;

	/**
	 * Constructor.
	 *
	 * @param Summary     $summary Summary service.
	 * @param ReviewQuery $query   Review query.
	 */
	public function __construct( Summary $summary, ReviewQuery $query ) {
		$this->summary = $summary;
		$this->query   = $query;
	}

	/**
	 * Resolve a target post/product id from an attribute or the current context.
	 *
	 * @param int $post_id Explicit id, or 0 to auto-detect.
	 * @return int
	 */
	public function resolve_id( $post_id = 0 ) {
		$post_id = absint( $post_id );
		if ( $post_id ) {
			return $post_id;
		}

		if ( function_exists( 'wc_get_product' ) ) {
			global $product;
			if ( $product instanceof \WC_Product ) {
				return $product->get_id();
			}
		}

		return (int) get_the_ID();
	}

	/**
	 * Ensure the display CSS/JS are enqueued (idempotent, conditional).
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( $this->assets_enqueued ) {
			return;
		}
		$this->assets_enqueued = true;

		wp_enqueue_style( 'ndvr-display', NDVR_URL . 'assets/css/display.css', array(), NDVR_VERSION );
		wp_enqueue_style( 'ndvr-marquee', NDVR_URL . 'assets/css/marquee.css', array(), NDVR_VERSION );
		wp_enqueue_script( 'ndvr-display', NDVR_URL . 'assets/js/display.js', array(), NDVR_VERSION, true );
		wp_enqueue_script( 'ndvr-marquee', NDVR_URL . 'assets/js/marquee.js', array(), NDVR_VERSION, true );

		wp_localize_script(
			'ndvr-display',
			'ndvrDisplay',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'action'     => Renderer::AJAX_ACTION,
				'nonce'      => wp_create_nonce( Renderer::NONCE ),
				'voteAction' => Votes::AJAX_ACTION,
			)
		);
	}

	/**
	 * Aggregate star rating + count.
	 *
	 * @param int $post_id Product id (0 = current).
	 * @return string
	 */
	public function stars( $post_id = 0 ) {
		$post_id = $this->resolve_id( $post_id );
		$average = (float) get_post_meta( $post_id, '_wc_average_rating', true );
		$count   = (int) get_post_meta( $post_id, '_wc_review_count', true );

		$this->enqueue();

		return View::render(
			'stars.php',
			array(
				'average' => $average,
				'count'   => $count,
			)
		);
	}

	/**
	 * Summary box.
	 *
	 * @param int $post_id Product id (0 = current).
	 * @return string
	 */
	public function summary( $post_id = 0 ) {
		$post_id = $this->resolve_id( $post_id );
		$this->enqueue();

		return View::render( 'summary.php', array( 'summary' => $this->summary->for_product( $post_id ) ) );
	}

	/**
	 * Criteria graph only (summary without the list).
	 *
	 * @param int $post_id Product id (0 = current).
	 * @return string
	 */
	public function criteria_graph( $post_id = 0 ) {
		return $this->summary( $post_id );
	}

	/**
	 * A paginated review list.
	 *
	 * @param array<string,mixed> $args product_id, per_page, orderby, etc.
	 * @return string
	 */
	public function reviews( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'product_id' => 0,
				'per_page'   => 10,
				'orderby'    => 'recent',
			)
		);
		$args['product_id'] = $this->resolve_id( $args['product_id'] );

		$this->enqueue();

		$result = $this->query->paginate( $args );

		return View::render(
			'review-list.php',
			array(
				'result'     => $result,
				'vote_nonce' => wp_create_nonce( Votes::NONCE_ACTION ),
			)
		);
	}

	/**
	 * Reviews marquee (Magic UI-style infinite scroll). Free = single row.
	 *
	 * @param array<string,mixed> $args source filters + display options.
	 * @return string
	 */
	public function marquee( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'product_id' => 0,
				'source'     => 'all',  // all | product | category.
				'category'   => 0,
				'min_rating' => 0,
				'with_media' => false,
				'verified'   => false,
				'limit'      => 20,
				'speed'      => 40,
				'gap'        => 16,
				'direction'  => 'horizontal',
				'reverse'    => false,
				'pause'      => true,
			)
		);

		$this->enqueue();

		$items = $this->marquee_items( $args );
		if ( empty( $items ) ) {
			return '';
		}

		/**
		 * Filter how many times the marquee card set repeats for a seamless loop.
		 *
		 * @param int $repeat Repeat count.
		 */
		$repeat = (int) apply_filters( 'ndv-reviews/marquee_repeat', 2 );

		return View::render(
			'marquee.php',
			array(
				'items'  => $items,
				'args'   => $args,
				'repeat' => max( 2, $repeat ),
			)
		);
	}

	/**
	 * Resolve review view-models for the marquee from its source filters.
	 *
	 * @param array<string,mixed> $args Args.
	 * @return array<int,array<string,mixed>>
	 */
	private function marquee_items( array $args ) {
		$query_args = array(
			'product_id' => 0,
			'per_page'   => max( 1, min( 50, (int) $args['limit'] ) ),
			'orderby'    => 'recent',
			'verified'   => ! empty( $args['verified'] ),
			'with_media' => ! empty( $args['with_media'] ),
		);

		if ( 'product' === $args['source'] && $args['product_id'] ) {
			$query_args['product_id'] = $this->resolve_id( $args['product_id'] );
		}

		$result = $this->query->paginate( $query_args );
		$items  = $result['items'];

		$min = (float) $args['min_rating'];
		if ( $min > 0 ) {
			$items = array_values(
				array_filter(
					$items,
					static function ( $r ) use ( $min ) {
						$rating = $r['overall'] ? $r['overall'] : $r['rating'];
						return $rating >= $min;
					}
				)
			);
		}

		return $items;
	}

	/**
	 * Recent reviews across the store (for classic widgets / wall).
	 *
	 * @param int $limit Max items.
	 * @return array<int,array<string,mixed>>
	 */
	public function recent( $limit = 5 ) {
		$result = $this->query->paginate(
			array(
				'product_id' => 0,
				'per_page'   => max( 1, min( 20, (int) $limit ) ),
				'orderby'    => 'recent',
			)
		);

		return $result['items'];
	}
}
