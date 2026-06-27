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
 * Emits Product + AggregateRating + Review JSON-LD only when nothing else is
 * already providing it (WooCommerce core, or an SEO plugin) — preventing the
 * duplicate AggregateRating that Google flags.
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
	 * Decide whether and what to output.
	 *
	 * @return void
	 */
	public function maybe_output() {
		if ( ! $this->is_single_product_context() ) {
			return;
		}

		$mode = (string) $this->settings->get( 'schema_mode', 'auto' );
		if ( 'off' === $mode ) {
			return;
		}

		if ( 'auto' === $mode && ( $this->woo_structured_data_active() || $this->seo_plugin_active() ) ) {
			// Another provider already emits product/review schema. Defer to avoid duplicates.
			return;
		}

		$product_id = get_queried_object_id();
		$average    = (float) get_post_meta( $product_id, '_wc_average_rating', true );
		$count      = (int) get_post_meta( $product_id, '_wc_review_count', true );

		if ( $count < 1 || $average <= 0 ) {
			return;
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			return;
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

		$data = array(
			'@context'        => 'https://schema.org/',
			'@type'           => 'Product',
			'name'            => $product->get_name(),
			'aggregateRating' => array(
				'@type'       => 'AggregateRating',
				'ratingValue' => (string) round( $average, 2 ),
				'reviewCount' => (string) $count,
				'bestRating'  => '5',
				'worstRating' => '1',
			),
		);

		if ( ! empty( $review_nodes ) ) {
			$data['review'] = $review_nodes;
		}

		/**
		 * Filter the review JSON-LD before output.
		 *
		 * @param array $data       Schema data.
		 * @param int   $product_id Product id.
		 */
		$data = apply_filters( 'ndv-reviews/json_ld', $data, $product_id );

		echo "\n<script type=\"application/ld+json\">" .
			wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) .
			"</script>\n";
	}

	/**
	 * Whether WooCommerce's own structured data is generating product schema.
	 *
	 * @return bool
	 */
	private function woo_structured_data_active() {
		// WC_Structured_Data hooks generate_product_data on woocommerce_single_product_summary.
		$active = class_exists( '\WC_Structured_Data' );

		/**
		 * Filter whether WooCommerce structured data is considered active.
		 *
		 * @param bool $active Whether Woo emits product schema.
		 */
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
