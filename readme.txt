=== Rosette Reviews ===
Contributors: nowdigiverse
Tags: woocommerce reviews, product reviews, photo reviews, review reminder, rich snippets
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted WooCommerce product reviews with multi-criteria ratings, photo reviews, review reminder emails and review schema. No account required.

== Description ==

Rosette Reviews replaces the default WooCommerce reviews tab with a review section built for collecting and showing more reviews. It runs entirely on your own server: there is no account to create, no sending quota, and no review data leaves your site.

= Collect reviews =

* **Review reminder emails.** Sent automatically a set number of days after an order reaches the status you choose. Sends run on Action Scheduler (the queue WooCommerce itself uses), with a delivery log, retry for failed sends, a test send and a preview.
* **One link for the whole order.** Each reminder contains a signed link where the customer can review every product in the order without logging in. Reviews submitted this way are marked as verified purchases.
* **Editable email.** Change the subject and message, with merge tags for the customer name, store name and review link.
* **QR code and share link** for any product, for packaging inserts and receipts.
* **Testimonial form** shortcode for collecting a review on a page of your choice.
* **New review notifications** to the store admin.

= Show reviews =

* **Rating summary** with the average, total, star distribution (click a bar to filter), recommendation rate and verified-buyer count.
* **Multi-criteria ratings.** Customers rate up to three criteria such as Quality, Value and Service; each criterion gets its own bar.
* **Photo reviews** with a keyboard-accessible lightbox.
* **Verified buyer badge**, matched against the customer's own orders.
* **Filters and sorting:** by star rating, with photos, verified only, topic tags, most recent, highest, lowest and most helpful.
* **Helpful votes**, one per visitor.
* **Reviews marquee:** a scrolling strip of reviews for home and landing pages, with pause control.
* **Design settings:** accent color, rating icon (stars, hearts, thumbs, emoji) and its color, rating bar color, list or grid layout, card style, font and text size, with a live preview.

= Works with your site =

* Gutenberg blocks, shortcodes, classic widgets, and native Elementor widgets with dynamic tags.
* Product, AggregateRating and Review structured data added to WooCommerce's own product markup, so search engines see one consistent product.
* WooCommerce High-Performance Order Storage (HPOS) and Cart/Checkout blocks compatible.
* Assets load only on pages that show reviews.
* Theme-overridable templates (`yourtheme/ndv-reviews/`).

= Moderation and data =

* Review dashboard with the moderation queue, rating distribution, reminder statistics and your most and least reviewed products.
* Approve, unapprove, spam, trash and **edit** reviews, including criteria scores, title, tags and photos.
* Spam protection: honeypot and rate limiting by default, optional Google reCAPTCHA v3.
* Import existing WooCommerce reviews or a CSV file. Export to CSV or JSON at any time.
* GDPR: consent checkbox, and WordPress personal data export and erasure.
* Q&A (with an add-on that shows questions): the name a shopper gives, their account id, and, if they ask to be emailed when their question is answered, their email address. Exported and erased with the WordPress privacy tools.
* Reminder results: the request log records when a customer opened the review link and when they left a review, so the dashboard can show how many orders that got a request led to a review. Two optional extras are off by default: UTM tags on the review link, and a 1×1 image that counts email opens. The image is a form of tracking that some privacy laws require consent for; mention it in your privacy policy if you turn it on. Erasing a customer's personal data cancels any review request still waiting to be sent to them.

= Shortcodes =

* `[ndvr-reviews product_id="" per_page="10"]` – review list
* `[ndvr-summary product_id=""]` – rating summary
* `[ndvr-criteria-graph product_id=""]` – criteria bars
* `[ndvr-stars product_id=""]` – star rating
* `[ndvr-marquee limit="20" direction="left" rows="1"]` – reviews marquee
* `[ndvr-form product_id=""]` – review form

= Rosette Reviews Pro =

An optional paid add-on adds unlimited criteria, video reviews, admin replies, review-for-coupon rewards, product Q&A, AI review summaries (with your own API key), Google and Facebook review import, more display widgets and analytics. The free plugin is complete on its own and does not require it.

== External services ==

This plugin connects to one external service, and only if you turn it on.

**Google reCAPTCHA v3** (off by default). When enabled under Rosette Reviews → Settings with your own site and secret keys, the review forms load Google's reCAPTCHA script (`https://www.google.com/recaptcha/api.js`) in the visitor's browser on pages that show a review form; that script sends browser and interaction data to Google. On submit, your server sends the resulting reCAPTCHA token and your secret key to `https://www.google.com/recaptcha/api/siteverify` to check whether the submission is automated. Google's [Terms of Service](https://policies.google.com/terms) and [Privacy Policy](https://policies.google.com/privacy) apply.

No other data is sent anywhere. Avatars on review cards are generated locally from the reviewer's initials.

== Installation ==

1. Install and activate WooCommerce.
2. Install Rosette Reviews from Plugins → Add New, or upload the `rosette-reviews` folder to `/wp-content/plugins/`.
3. Activate it. Reviews appear in the product Reviews tab straight away.
4. Open **Rosette Reviews → Overview** and follow the setup checklist: turn on reminder emails, set your design and import existing reviews.

== Frequently Asked Questions ==

= Do I need an account or an API key? =

No. Everything runs on your WordPress site. reCAPTCHA is the only optional feature that uses an outside service, with your own keys.

= Are there limits on reviews or emails? =

No. The plugin does not count or cap reviews, reminder emails or photos. Emails are sent by your site's normal mail setup, so use an SMTP plugin if your host's mail delivery is unreliable.

= What happens to my existing WooCommerce reviews? =

They stay where they are and keep showing. Use Rosette Reviews → Import / Export to bring their ratings into the plugin's summary and filters. Deactivating or deleting Rosette Reviews leaves WooCommerce reviews untouched.

= Can customers review without an account? =

Yes, if guest reviews are allowed in Settings. Customers who follow a reminder email link never need to log in. You can also require a verified purchase using WooCommerce's own "Reviews can only be left by verified owners" setting.

= Why are reminder emails not sending? =

Reminders run on Action Scheduler, which relies on WP-Cron. If your site has little traffic or WP-Cron is disabled, set up a real server cron job. The Review Reminders screen shows each send and any error.

= Will it conflict with my SEO plugin's schema? =

Rosette Reviews adds its rating and review data to WooCommerce's product schema instead of printing a second product, which avoids duplicate rating markup.

= Can I change how reviews look? =

Use Rosette Reviews → Design for colors and layout, or copy a file from the plugin's `templates/` folder into `yourtheme/ndv-reviews/` and edit it. Keep the `do_action()` lines listed at the top of each template (for example `ndv-reviews/review_author_badges` and `ndv-reviews/marquee_author_badges`): labels such as the incentive disclosure are printed there.

= Is my data removed if I delete the plugin? =

Only if you turn on "Remove all data on uninstall" in Settings. Reviews created by Rosette Reviews are deleted then; native WooCommerce reviews are always kept.

== Screenshots ==

1. Review section: rating summary, criteria bars, filters and review cards with photos.
2. Review form with criteria ratings, photo upload and recommendation.
3. Overview dashboard with setup checklist and moderation queue.
4. All Reviews moderation screen with status views, filters and bulk actions.
5. Review reminder email settings and delivery log.
6. Design settings with live preview.
7. Reviews marquee.
8. Reminder email preview.

== Changelog ==

= Unreleased =
* Database updates now run on any request, not only in the admin, under a lock, so scheduled emails never meet an old database. A failed update retries after an hour and shows a notice.
* Reactivating after uploading a new version now runs pending database updates.
* Uninstall (when you opt in to data removal) now removes every option, setting, meta key and scheduled job the plugin stores.
* The reCAPTCHA secret key is no longer shown in the settings page; leave the field empty to keep it.
* Developers: new template, moderation-screen and settings hooks for add-ons.
* Review requests can no longer be sent twice: each one is claimed before it is sent, repeated order events create one request, and a request to the same order within 20 hours is skipped.
* The request log shows where each request came from, when the review link was opened and when a review was left. The dashboard shows how many orders that got a request led to a review.
* New, off by default: UTM tags on the review link, and an image that counts email opens.
* Reminders that were waiting when the plugin was deactivated are sent after reactivation, spread out; ones more than 14 days overdue are skipped.
* Erasing a customer's personal data cancels review requests still waiting to be sent to them.
* Products that share reviews now show the shared reviews in their list, and a review posted without JavaScript returns the shopper to the product they reviewed. Tools can move reviews back when products stop sharing.
* Photos of reviews marked as spam or moved to the trash are deleted after 7 days.
* Reviews that were rewarded (for example with a coupon) carry a disclosure label, and it stays even if the add-on that rewarded them is removed.
* Uploads that are too large now get a clear message instead of a generic error.
* Privacy export and erasure now cover questions and answers.

= 1.0.0 =
* Initial public release.

== Upgrade Notice ==

= 1.0.0 =
Initial public release.
