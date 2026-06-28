=== NDV Reviews ===
Contributors: nowdigiverse
Tags: reviews, woocommerce, ratings, testimonials, photo reviews
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.6.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reliable, self-hosted product reviews for WooCommerce: multi-criteria ratings, photo reviews, working reminders, and rich schema.

== Description ==

NDV Reviews is a fast, privacy-first reviews plugin for WooCommerce stores. Everything runs on your own server — there is no required account, no forced sign-up, and no external service call to use the free plugin. Your review data stays in your database.

**Why store owners switch to NDV Reviews**

* **Self-hosted, no account.** The free plugin works fully with zero external calls. You own 100% of your review data.
* **Reliable review reminders.** Reminder emails run on a battle-tested background queue with a visible log, retry, and test-send — not flaky cron.
* **Honest free tier.** Full review moderation, including editing reviews, is free.
* **Multi-criteria ratings.** Let customers rate up to three criteria (e.g. Quality, Value, Service).
* **Photo reviews.** Customers can attach photos to their reviews.
* **Verified-buyer badge.** Reviews from real purchasers are marked verified.
* **Rich schema for SEO.** Outputs valid Product, AggregateRating, and Review JSON-LD.

This plugin is compatible with WooCommerce High-Performance Order Storage (HPOS) and the block-based Cart and Checkout.

== Installation ==

1. Upload the `ndv-reviews` folder to `/wp-content/plugins/`, or install through the Plugins screen.
2. Activate the plugin through the Plugins screen.
3. Ensure WooCommerce is installed and active.
4. Configure options under the NDV Reviews settings screen.

== Frequently Asked Questions ==

= Does this plugin require an account or external service? =

No. The free plugin is fully self-hosted and makes no external calls by default. Optional features such as reCAPTCHA only run if you enable them with your own keys.

= Is it compatible with WooCommerce HPOS? =

Yes. NDV Reviews declares compatibility with High-Performance Order Storage and the block-based checkout.

== Changelog ==

= 0.6.3 =
* Fix: PSR-4 autoloader returned after the first matching prefix even when the file was absent, so the Pro add-on's NdvReviewsPro classes never loaded and Pro would not boot. Now continues to more specific prefixes.

= 0.6.2 =
* Added the ndv-reviews/criteria_name filter (Pro multilingual translates criteria labels). Docs through Phase 9.

= 0.6.1 =
* Added the ndv-reviews/after_summary hook so Pro can render the AI "Customers say" summary above the review list.

= 0.6.0 =
* Added stable extension hooks so the Pro add-on can plug in without editing free files (stars_html, review_items, review_item_after, review_form_fields).

= 0.5.0 =
* Integrations: shortcodes, Gutenberg blocks, Elementor widgets + dynamic tags, classic widgets, and the Reviews Marquee — all from one renderer.
* Importers (native WooCommerce, CSV) and CSV/JSON export.
* GDPR personal-data export/erasure + consent logging.
* Standalone testimonial form.

= 0.4.1 =
* Verified storefront rendering; fixed Most-helpful sort excluding unvoted reviews and filter/helpful button contrast.

= 0.4.0 =
* Review reminders on Action Scheduler: configurable trigger status and delay, customizable email, test-send, request log with retry, and a reliability health check.
* One-click unsubscribe.
* Tokenized, no-login multi-product review-collection link sent with the reminder; opens a prefilled review page and marks items as reviewed.

= 0.3.0 =
* Display: review summary box, star-distribution and per-criterion bars, photo thumbnails, verified badge.
* Filtering (star / verified / with-photos), sorting, and AJAX pagination — no page reload.
* Helpful voting on reviews.
* Moderation: dedicated Reviews admin screen with status filters, bulk actions, and full review editing (body, criteria, photos); Rating column on the Comments screen.
* SEO: Product/AggregateRating/Review JSON-LD with duplicate-avoidance (defers to WooCommerce/SEO plugins).

= 0.2.1 =
* Fix: review photo uploads failing with "Specified file failed upload test" (correct media handling; Windows temp-path fix).

= 0.2.0 =
* Core reviews: multi-criteria rating form (up to 3 criteria) with photo uploads, recommend, and consent.
* AJAX submission with honeypot, per-IP rate limiting, and optional reCAPTCHA v3.
* Verified-buyer detection, overall-rating caching synced with WooCommerce aggregates.
* Admin "Rating Criteria" screen with the free 3-criteria cap.

= 0.1.0 =
* Initial scaffolding: bootstrap, database schema, activation/deactivation, settings, WooCommerce HPOS and block-checkout compatibility declarations.

== Upgrade Notice ==

= 0.2.0 =
Adds the core multi-criteria review form, photo uploads, and anti-spam.
