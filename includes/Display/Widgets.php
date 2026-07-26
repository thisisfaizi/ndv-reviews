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

		wp_enqueue_style( 'ndvr-display', NDVR_URL . 'assets/css/display.css', array( 'ndvr-tokens' ), NDVR_VERSION );
		wp_enqueue_style( 'ndvr-marquee', NDVR_URL . 'assets/css/marquee.css', array( 'ndvr-tokens' ), NDVR_VERSION );
		wp_enqueue_script( 'ndvr-display', NDVR_URL . 'assets/js/display.js', array(), NDVR_VERSION, true );
		wp_enqueue_script( 'ndvr-marquee', NDVR_URL . 'assets/js/marquee.js', array(), NDVR_VERSION, true );

		$accent_css = Design::inline_css( new \NdvReviews\Support\Settings() );
		if ( '' !== $accent_css ) {
			wp_add_inline_style( 'ndvr-display', $accent_css );
		}

		wp_localize_script(
			'ndvr-display',
			'ndvrDisplay',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'action'     => Renderer::AJAX_ACTION,
				'nonce'      => wp_create_nonce( Renderer::NONCE ),
				'voteAction' => Votes::AJAX_ACTION,
				'i18n'       => array(
					'photo' => __( 'Customer photo', 'ndv-reviews' ),
					'close' => __( 'Close', 'ndv-reviews' ),
					'prev'  => __( 'Previous photo', 'ndv-reviews' ),
					'next'  => __( 'Next photo', 'ndv-reviews' ),
				),
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
		$post_id = \NdvReviews\Reviews\Pool::resolve_id( $this->resolve_id( $post_id ) );
		$agg     = \NdvReviews\Reviews\AggregateStore::get( $post_id );
		$average = (float) $agg['average'];
		$count   = (int) $agg['count'];

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

		$list_html = View::render(
			'review-list.php',
			array(
				'result'     => $result,
				'vote_nonce' => wp_create_nonce( Votes::NONCE_ACTION ),
			)
		);

		// display.js's delegated click handler (helpful vote, photo lightbox,
		// pagination) only attaches when #ndvr-reviews exists, and its AJAX
		// re-fetch on pagination/filter only runs when #ndvr-review-list also
		// exists — without these two wrapper ids, every review-list.php
		// consumer (this shortcode/block, and the classic widget below) would
		// silently render a dead list: the vote button, the pagination
		// buttons, and the photo lightbox all no-op with no visible error.
		return sprintf(
			'<div id="ndvr-reviews" data-product="%d"><div id="ndvr-review-list">%s</div></div>',
			(int) $args['product_id'],
			$list_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- View::render() output is pre-escaped.
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
				'rows'       => 1,
			)
		);

		// Normalize direction: accept left|right|up|down (new) plus the legacy
		// horizontal|vertical + reverse. left/right = horizontal; up/down =
		// vertical; right/down = reversed scroll.
		$dir      = strtolower( (string) $args['direction'] );
		$vertical = in_array( $dir, array( 'up', 'down', 'vertical' ), true );
		$reverse  = in_array( $dir, array( 'right', 'down' ), true ) ? true : ! empty( $args['reverse'] );

		$args['direction'] = $vertical ? 'vertical' : 'horizontal';
		$args['reverse']   = $reverse;

		$this->enqueue();

		$items = $this->marquee_items( $args );
		if ( empty( $items ) ) {
			return '';
		}

		$rows = max( 1, min( 2, (int) $args['rows'] ) );
		if ( 1 === $rows ) {
			return $this->render_marquee_row( $items, $args );
		}

		// Double row: split the set across two independent tracks. Too few items
		// to split meaningfully (< 4) — reuse the full set for both rows rather
		// than starving the second one. The second row reverses direction by
		// default for the classic crisscross "wall of love" look.
		if ( count( $items ) >= 4 ) {
			$mid        = (int) ceil( count( $items ) / 2 );
			$row1_items = array_slice( $items, 0, $mid );
			$row2_items = array_slice( $items, $mid );
		} else {
			$row1_items = $items;
			$row2_items = $items;
		}

		$row2_args             = $args;
		$row2_args['reverse']  = ! $args['reverse'];

		return '<div class="ndvr-marquee-rows">'
			. $this->render_marquee_row( $row1_items, $args )
			. $this->render_marquee_row( $row2_items, $row2_args )
			. '</div>';
	}

	/**
	 * Render one marquee track (the `rows=2` variant calls this twice).
	 *
	 * @param array<int,array<string,mixed>> $items Resolved review items for this row.
	 * @param array<string,mixed>            $args  Display args (direction/reverse/etc already normalized).
	 * @return string
	 */
	private function render_marquee_row( array $items, array $args ) {
		/**
		 * Filter how many times the marquee card set repeats for a seamless loop.
		 * Default scales with the item count so the track always overflows the
		 * viewport (few reviews → more copies) — otherwise an empty band shows.
		 *
		 * @param int                             $repeat Repeat count.
		 * @param array<int,array<string,mixed>>  $items  Resolved review items.
		 */
		$auto_repeat = (int) max( 2, min( 8, (int) ceil( 12 / max( 1, count( $items ) ) ) + 1 ) );
		$repeat      = (int) apply_filters( 'ndv-reviews/marquee_repeat', $auto_repeat, $items );

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
			// Server-side (DB) filter, not a post-fetch PHP filter — narrows the
			// result set BEFORE per_page/limit cuts it off, so a few recent
			// reviews below min_rating can never starve the marquee when enough
			// qualifying reviews exist further back.
			'min_rating' => (float) $args['min_rating'],
		);

		if ( 'product' === $args['source'] && $args['product_id'] ) {
			$query_args['product_id'] = $this->resolve_id( $args['product_id'] );
		} elseif ( 'category' === $args['source'] && ! empty( $args['category'] ) ) {
			$query_args['category'] = $args['category'];
		}

		$result = $this->query->paginate( $query_args );

		return $result['items'];
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
