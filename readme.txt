=== NDV Reviews ===
Contributors: nowdigiverse
Tags: reviews, woocommerce, ratings, testimonials, photo reviews
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reliable, self-hosted product reviews for WooCommerce: multi-criteria ratings, photo reviews, working review reminders, and rich schema. No account required.

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

= 0.1.0 =
* Initial scaffolding: bootstrap, database schema, activation/deactivation, settings, WooCommerce HPOS and block-checkout compatibility declarations.

== Upgrade Notice ==

= 0.1.0 =
First development release.
