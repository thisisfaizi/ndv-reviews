<?php
/**
 * Shareable review links (QR codes) land on the review form.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Collection;

use NdvReviews\Support\Registerable;

defined( 'ABSPATH' ) || exit;

/**
 * The QR / shareable link is `{product}?ndvr_review=1#reviews`. WooCommerce
 * opens its Reviews tab for the `#reviews` hash; this then scrolls the review
 * form into view and focuses its first field, so a customer who scanned a QR
 * code does not have to find the form under the existing reviews.
 */
class ReviewLinkFocus implements Registerable {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_footer', array( $this, 'print_script' ), 30 );
	}

	/**
	 * Print the small focus script, only on a product page opened with the flag.
	 *
	 * @return void
	 */
	public function print_script() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UI flag.
		if ( empty( $_GET['ndvr_review'] ) || ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$js = "(function(){function go(){var w=document.getElementById('ndvr-review-form-wrap')||document.getElementById('review_form_wrapper');if(!w){return;}"
			. "var t=document.querySelector('.reviews_tab a');if(t&&w.offsetParent===null){t.click();}"
			. "w.scrollIntoView({behavior:'smooth',block:'start'});"
			. "var f=w.querySelector('input:not([type=hidden]):not([tabindex=\"-1\"]),textarea');if(f){try{f.focus({preventScroll:true});}catch(e){f.focus();}}}"
			. "if(document.readyState==='complete'){setTimeout(go,150);}else{window.addEventListener('load',function(){setTimeout(go,150);});}})();";

		wp_print_inline_script_tag( $js );
	}
}
