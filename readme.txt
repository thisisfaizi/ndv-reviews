=== NDV Reviews ===
Contributors: nowdigiverse
Tags: reviews, woocommerce, ratings, testimonials, photo reviews
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.9.8
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

== Screenshots ==

1. Modern review summary with star distribution, criteria bars, and verified-buyer badge.
2. Multi-criteria review form with custom photo upload zone and recommend pills.
3. Review card showing verified badge, criteria ratings, helpful button, and photo gallery.
4. Admin Reviews screen with bulk actions, star filter, and inline editing.
5. Settings → General: configure guest reviews, pagination, and sorting.
6. Settings → Rating Criteria: label and enable/disable each criterion.
7. Settings → Design: accent color, layout, and rating icon selector.

== Changelog ==

= 0.9.8 =
* Add [ndvr-qa] shortcode stub — returns empty unless NDV Reviews Pro is active,
  where it renders the full Q&A section for the current (or specified) product.

= 0.9.7 =
* Add six `ndv-reviews/show_*` filter hooks to the review card template so the
  Pro add-on (or themes) can hide individual card elements without editing
  template files.

= 0.9.6 =
* Fix: `pre_option_comment_registration` now returns an explicit '0' or '1'
  on product pages, making our plugin the sole authority over login-gating
  regardless of the site-wide WordPress Discussion setting.

= 0.9.5 =
* Fix: "Allow guest reviews" setting now enforced both via the
  `pre_option_comment_registration` filter (hides the form for non-logged-in
  visitors) and server-side AJAX validation on submission.
* Fix: "Must be logged in" message now reads "post a review" instead of
  "post a comment".

= 0.9.4 =
* Review form UI overhaul: clean open-grid criteria layout (no grey box),
  larger star icons, haze-fill inputs with focus ring, custom drag-and-drop
  photo zone, and a full-width green pill submit button.
* Recommend field now uses hidden radio inputs with CSS pill selection state.

= 0.9.0 =
* Reviews on custom post types; aggregate/pool substrate (variation pooling-ready); shopper-facing topic filter pills (manual tags) + admin topic assignment; QR code generation for review links (bundled, no network); Recent Reviews / Rating Badge / Top-Rated classic widgets; review Form block; typography options; a General Settings screen; and stable extension hooks for the Pro add-on.

= 0.8.2 =
* Fix Reviews list-table layout: override WordPress fixed-table layout that squeezed the Product column to one character (causing vertical text + huge row heights); vertically center cells; single-line row actions. Now consistent and professional.

= 0.8.1 =
* Admin polish: Reviews list table — visible row-action pills (no empty gaps), aligned filter toolbar, hidden duplicate footer header.

= 0.8.0 =
* New Design screen (NDV Reviews → Design): pick accent color, layout (list/grid), summary style, card style, and rating icon (stars/hearts/thumbs/emoji) — free, with modern card selectors. Applies live on the storefront.

= 0.7.0 =
* New "Trust Panel" design system: a modern, theme-safe visual identity across the summary, review list, form, marquee, collection page, and widgets — plus a modern admin skin. Distinctive, not the default WordPress look.

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
