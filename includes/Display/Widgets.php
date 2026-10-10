<?php
/**
 * Shared widget renderers reused by shortcodes, blocks, classic widgets,
 * and Elementor — a single source of truth for review UI.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Display;

use NdvReviews\Support\Settings;
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
	 * Settings (lazy).
	 *
	 * @var Settings|null
	 */
	private $settings;

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
	 * Enqueue only what a given widget needs (idempotent, conditional).
	 *
	 * - stars:   display.css (glyphs + rating-icon swap)
	 * - list:    display.css + display.js (filters, pagination, votes, lightbox)
	 * - marquee: display.css + marquee.css + marquee.js
	 *
	 * @param string $what stars|list|marquee.
	 * @return void
	 */
	public function enqueue( $what = 'list' ) {
		Renderer::register_assets();

		wp_enqueue_style( 'ndvr-display' );

		if ( 'list' === $what ) {
			wp_enqueue_script( 'ndvr-display' );
		} elseif ( 'marquee' === $what ) {
			wp_enqueue_style( 'ndvr-marquee' );
			wp_enqueue_script( 'ndvr-marquee' );
		}
	}

	/**
	 * Root class list for wrapped widget output (Design settings applied).
	 *
	 * @return string
	 */
	private function wrap_classes() {
		if ( null === $this->settings ) {
			$this->settings = new Settings();
		}

		return trim( 'ndvr-reviews-wrap ' . Design::classes( $this->settings ) );
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

		$this->enqueue( 'stars' );

		return View::render(
			'stars.php',
			array(
				'average' => $average,
				'count'   => $count,
			)
		);
	}

	/**
	 * Summary box (renders an empty state when the product has no reviews, so
	 * blocks/shortcodes never collapse to nothing).
	 *
	 * @param int    $post_id Product id (0 = current).
	 * @param string $surface Where it shows (summary_footer surface): summary, criteria or widget.
	 * @return string
	 */
	public function summary( $post_id = 0, $surface = 'summary' ) {
		$post_id = $this->resolve_id( $post_id );
		$this->enqueue( 'stars' );

		return sprintf(
			'<div class="%1$s">%2$s%3$s</div>',
			esc_attr( $this->wrap_classes() ),
			View::render( 'summary.php', array( 'summary' => $this->summary->for_product( $post_id ) ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- View::render() output is pre-escaped.
			self::summary_footer( $post_id, $surface ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- listener output, escaped by listeners.
		);
	}

	/**
	 * Criteria graph only (summary without the list).
	 *
	 * @param int $post_id Product id (0 = current).
	 * @return string
	 */
	public function criteria_graph( $post_id = 0 ) {
		return $this->summary( $post_id, 'criteria' );
	}

	/**
	 * Capture the summary_footer action (RR-03) for methods that return HTML.
	 *
	 * @param int    $post_id Product id.
	 * @param string $surface Surface.
	 * @return string
	 */
	public static function summary_footer( $post_id, $surface ) {
		ob_start();
		/** This action is documented in includes/Display/Renderer.php */
		do_action( 'ndv-reviews/summary_footer', (int) $post_id, (string) $surface );

		return (string) ob_get_clean();
	}

	/**
	 * A paginated review list.
	 *
	 * Each list is a self-contained instance: display.js finds it by the
	 * `ndvr-reviews-instance` class and reads its page size / order from the
	 * data attributes, so several lists can live on one page. Only the first
	 * instance carries the legacy `#ndvr-reviews` / `#ndvr-review-list` ids.
	 *
	 * @param array<string,mixed> $args product_id, per_page, orderby, show_summary.
	 * @return string
	 */
	public function reviews( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'product_id'   => 0,
				'per_page'     => 10,
				'orderby'      => 'recent',
				'show_summary' => false,
			)
		);
		$args['product_id'] = $this->resolve_id( $args['product_id'] );
		$args['per_page']   = Renderer::per_page( $args['per_page'] );
		$args['orderby']    = Renderer::orderby( $args['orderby'] );

		$this->enqueue( 'list' );

		$result = $this->query->paginate(
			array(
				'product_id' => $args['product_id'],
				'per_page'   => $args['per_page'],
				'orderby'    => $args['orderby'],
			)
		);

		$summary_html = '';
		if ( ! empty( $args['show_summary'] ) ) {
			$summary_html = View::render(
				'summary.php',
				array(
					'summary'    => $this->summary->for_product( $args['product_id'] ),
					'filterable' => true,
				)
			) . self::summary_footer( $args['product_id'], 'reviews' );
		}

		$list_html = View::render(
			'review-list.php',
			array(
				'result'     => $result,
				'vote_nonce' => wp_create_nonce( Votes::NONCE_ACTION ),
			)
		);

		$use_ids = Renderer::claim_ids();

		return sprintf(
			'<div%1$s class="%2$s ndvr-reviews-instance" data-product="%3$d" data-per-page="%4$d" data-orderby="%5$s">%6$s<div%7$s class="ndvr-review-list-wrap" aria-live="polite" aria-busy="false">%8$s</div></div>',
			$use_ids ? ' id="ndvr-reviews"' : '',
			esc_attr( $this->wrap_classes() ),
			(int) $args['product_id'],
			(int) $args['per_page'],
			esc_attr( $args['orderby'] ),
			$summary_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- View::render() output is pre-escaped.
			$use_ids ? ' id="ndvr-review-list"' : '',
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

		$items = $this->marquee_items( $args );
		if ( empty( $items ) ) {
			return '';
		}

		$this->enqueue( 'marquee' );

		$rows = max( 1, min( 2, (int) $args['rows'] ) );
		if ( 1 === $rows ) {
			return $this->marquee_wrap( $this->render_marquee_row( $items, $args ) );
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

		$row2_args            = $args;
		$row2_args['reverse'] = ! $args['reverse'];

		return $this->marquee_wrap(
			'<div class="ndvr-marquee-rows">'
			. $this->render_marquee_row( $row1_items, $args )
			. $this->render_marquee_row( $row2_items, $row2_args )
			. '</div>'
		);
	}

	/**
	 * Wrap marquee track(s) with a visible pause/play control (WCAG 2.2.2:
	 * moving content that lasts > 5s needs a way to stop it). The button sits
	 * outside the masked, animated element so it is never clipped or moving.
	 *
	 * @param string $inner Pre-escaped track markup.
	 * @return string
	 */
	private function marquee_wrap( $inner ) {
		return sprintf(
			'<div class="ndvr-marquee-wrap">%1$s<button type="button" class="ndvr-marquee-toggle" data-label-pause="%2$s" data-label-play="%3$s"><span class="ndvr-marquee-toggle-icon" aria-hidden="true"></span><span class="ndvr-marquee-toggle-text">%2$s</span></button></div>',
			$inner, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- View::render() output is pre-escaped.
			esc_attr__( 'Pause animation', 'rosette-reviews' ),
			esc_attr__( 'Play animation', 'rosette-reviews' )
		);
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
