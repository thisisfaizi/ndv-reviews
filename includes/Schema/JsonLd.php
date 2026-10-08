<?php
/**
 * Review JSON-LD output with duplicate-avoidance.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Schema;

use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;
use NdvReviews\Reviews\ReviewQuery;

defined( 'ABSPATH' ) || exit;

/**
 * Review structured data without duplicates.
 *
 * When WooCommerce outputs its own Product node (the default on product
 * pages), we enrich that node through `woocommerce_structured_data_product`
 * — aggregateRating from AggregateStore (pool-aware) and reviews from our
 * view-model (respects `review_author`, e.g. Pro anonymization). We emit a
 * standalone Product node only when WooCommerce output none, so a page never
 * carries two AggregateRatings.
 *
 * Modes (setting `schema_mode`):
 * - auto:   enrich WooCommerce's node; standalone only if Woo emitted none and
 *           no SEO plugin is active (SEO plugins usually own product schema).
 * - plugin: same, but also emit the standalone node when an SEO plugin is active.
 * - off:    output nothing and leave WooCommerce's node untouched.
 */
class JsonLd implements Registerable {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Review query.
	 *
	 * @var ReviewQuery
	 */
	private $query;

	/**
	 * Set when WooCommerce generated a Product node for the current product
	 * during this request (our enrich filter ran for it).
	 *
	 * @var bool
	 */
	private $woo_emitted = false;

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings Settings.
	 * @param ReviewQuery $query    Review query.
	 */
	public function __construct( Settings $settings, ReviewQuery $query ) {
		$this->settings = $settings;
		$this->query    = $query;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_structured_data_product', array( $this, 'enrich_woo_product' ), 20, 2 );
		// Woo prints its structured data on wp_footer (10); by 20 we know
		// whether it produced a Product node for this page.
		add_action( 'wp_footer', array( $this, 'maybe_output' ), 20 );
	}

	/**
	 * Only single-product context emits per-product schema. Loop/archive grids
	 * must not (duplicate AggregateRating per card is invalid).
	 *
	 * @return bool
	 */
	private function is_single_product_context() {
		// is_product() is true only on a single product's main view — exactly the
		// context that may emit AggregateRating. Loop/archive grids return false here.
		return function_exists( 'is_product' ) && is_product();
	}

	/**
	 * Current schema mode.
	 *
	 * @return string auto|plugin|off
	 */
	private function mode() {
		$mode = (string) $this->settings->get( 'schema_mode', 'auto' );

		return in_array( $mode, array( 'auto', 'plugin', 'off' ), true ) ? $mode : 'auto';
	}

	/**
	 * Replace WooCommerce's aggregateRating/review on its Product node with ours.
	 *
	 * @param array<string,mixed> $markup  Woo product markup.
	 * @param \WC_Product         $product Product.
	 * @return array<string,mixed>
	 */
	public function enrich_woo_product( $markup, $product ) {
		if ( 'off' === $this->mode() || ! $this->is_single_product_context() || ! is_object( $product ) || ! is_array( $markup ) ) {
			return $markup;
		}

		$queried    = \NdvReviews\Reviews\Pool::resolve_id( get_queried_object_id() );
		$product_id = \NdvReviews\Reviews\Pool::resolve_id( (int) $product->get_id() );
		if ( $product_id !== $queried ) {
			// Some other product rendered on the page — not the main node.
			return $markup;
		}

		$this->woo_emitted = true;

		unset( $markup['aggregateRating'], $markup['review'] );
		$markup = array_merge( $markup, $this->rating_nodes( $product_id ) );

		/** This filter is documented in maybe_output() below. */
		return (array) apply_filters( 'ndv-reviews/json_ld', $markup, $product_id );
	}

	/**
	 * Build aggregateRating + review nodes for a product, or [] when it has no
	 * rated reviews (an AggregateRating with zero reviews is invalid).
	 *
	 * @param int $product_id Pool-resolved product id.
	 * @return array<string,mixed>
	 */
	private function rating_nodes( $product_id ) {
		$agg     = \NdvReviews\Reviews\AggregateStore::get( $product_id );
		$average = (float) $agg['average'];
		$count   = (int) $agg['count'];

		if ( $count < 1 || $average <= 0 ) {
			return array();
		}

		$reviews = $this->query->paginate(
			array(
				'product_id' => $product_id,
				'per_page'   => 10,
				'orderby'    => 'recent',
			)
		);

		$review_nodes = array();
		foreach ( $reviews['items'] as $review ) {
			$rating = $review['overall'] ? $review['overall'] : $review['rating'];
			if ( $rating <= 0 ) {
				continue;
			}
			$review_nodes[] = array(
				'@type'         => 'Review',
				'reviewRating'  => array(
					'@type'       => 'Rating',
					'ratingValue' => (string) $rating,
					'bestRating'  => '5',
					'worstRating' => '1',
				),
				'author'        => array(
					'@type' => 'Person',
					'name'  => $review['author'],
				),
				'datePublished' => mysql2date( 'c', $review['date'] ),
				'reviewBody'    => wp_strip_all_tags( $review['content'] ),
			);
		}

		$nodes = array(
			'aggregateRating' => array(
				'@type'       => 'AggregateRating',
				'ratingValue' => (string) round( $average, 2 ),
				'reviewCount' => (string) $count,
				'bestRating'  => '5',
				'worstRating' => '1',
			),
		);

		if ( ! empty( $review_nodes ) ) {
			$nodes['review'] = $review_nodes;
		}

		return $nodes;
	}

	/**
	 * Emit a standalone Product node when WooCommerce did not output one.
	 *
	 * @return void
	 */
	public function maybe_output() {
		if ( ! $this->is_single_product_context() ) {
			return;
		}

		$mode = $this->mode();
		if ( 'off' === $mode ) {
			return;
		}

		if ( $this->woo_structured_data_active() ) {
			// WooCommerce's node already carries our rating (enrich_woo_product).
			return;
		}

		if ( 'auto' === $mode && $this->seo_plugin_active() ) {
			// An SEO plugin is likely emitting product schema. Defer to avoid duplicates.
			return;
		}

		$product_id = \NdvReviews\Reviews\Pool::resolve_id( get_queried_object_id() );
		$product    = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			return;
		}

		$nodes = $this->rating_nodes( $product_id );
		if ( empty( $nodes ) ) {
			return;
		}

		$data = array_merge(
			array(
				'@context' => 'https://schema.org/',
				'@type'    => 'Product',
				'@id'      => get_permalink( $product->get_id() ) . '#product',
				'name'     => $product->get_name(),
				'url'      => get_permalink( $product->get_id() ),
			),
			$nodes
		);

		/**
		 * Filter the review JSON-LD before output. Also applied to WooCommerce's
		 * Product node when we enrich that instead.
		 *
		 * @param array $data       Schema data.
		 * @param int   $product_id Product id.
		 */
		$data = apply_filters( 'ndv-reviews/json_ld', $data, $product_id );

		// JSON_HEX_TAG (+ the accompanying HEX flags) escape </script>-breakout
		// sequences at the JSON layer itself — defense in depth alongside the
		// sanitization already applied to author/content at save and render
		// time, rather than depending entirely on it holding for every field
		// forever (e.g. $product->get_name() gets no treatment at all).
		echo "\n<script type=\"application/ld+json\">" .
			wp_json_encode(
				$data,
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			) .
			"</script>\n";
	}

	/**
	 * Whether WooCommerce output a Product node for this product on this page.
	 *
	 * @return bool
	 */
	private function woo_structured_data_active() {
		/**
		 * Filter whether WooCommerce structured data is considered active (i.e.
		 * Woo emitted the product node, which we enriched).
		 *
		 * @param bool $active Whether Woo emitted product schema on this page.
		 */
		$active = $this->woo_emitted;

		// A common snippet removes Woo's footer printer but leaves generation in
		// place — then the node we enriched is never printed.
		if ( $active && function_exists( 'WC' ) && isset( WC()->structured_data ) ) {
			$active = false !== has_action( 'wp_footer', array( WC()->structured_data, 'output_structured_data' ) );
		}

		return (bool) apply_filters( 'ndv-reviews/woo_structured_data_active', $active );
	}

	/**
	 * Whether a known SEO plugin is active (likely emitting product schema).
	 *
	 * @return bool
	 */
	private function seo_plugin_active() {
		$active = defined( 'WPSEO_VERSION' )            // Yoast.
			|| defined( 'RANK_MATH_VERSION' )           // Rank Math.
			|| defined( 'SEOPRESS_VERSION' )            // SEOPress.
			|| defined( 'AIOSEO_VERSION' );             // All in One SEO.

		/**
		 * Filter whether an SEO plugin is considered to handle schema.
		 *
		 * @param bool $active Whether an SEO plugin handles product schema.
		 */
		return (bool) apply_filters( 'ndv-reviews/seo_plugin_active', $active );
	}
}
