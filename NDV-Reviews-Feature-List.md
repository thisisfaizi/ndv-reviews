# NDV Reviews — Feature List (Free vs Pro), as shipped in 1.0.0

This list describes what the code does today, verified in the 2026-10 release audit
(`.agents/AUDIT-2026-10.md`). Planned work is listed separately at the end and is not a claim.

**Positioning against ReviewX (2.5.x):** ReviewX requires a cloud account for every user (free included),
syncs store data to its servers, refuses localhost, and caps free reminder emails at 20/month. NDV Reviews
runs entirely on the merchant's server: no account, no quota, no data leaves the site. ReviewX has no
product Q&A and no AI review summary.

---

## FREE (WordPress.org)

### Collect
- Review reminder emails on a chosen order status after N days — Action Scheduler queue, delivery log with
  pagination, retry for failed sends, skipped sends logged as "Not sent", real-template test send, preview.
- Editable subject and body with merge tags; branded template (store logo/name, accent button, store
  address, preheader); one-click `List-Unsubscribe`; unsubscribe requires confirmation.
- Signed multi-product review link (no login); guest-friendly name field; reviews marked verified.
- QR code + share link per product (local SVG, download), opens the review form.
- Testimonial form shortcode (`[ndvr-form]`/`[ndvr-testimonial]`).
- New-review email to the admin (off / all / awaiting approval).

### Display
- Summary: average, total, clickable star bars with percentages, recommend rate, verified-buyer count,
  "Write a review" button (form collapsed until opened).
- Up to 3 rating criteria with per-criterion bars.
- Photo reviews with accessible lightbox; initials avatars (no Gravatar request).
- Verified-buyer badge (own account's orders only); helpful votes (deduplicated).
- Filters: stars, photos, verified, topic tags; sort: recent, highest, lowest, most helpful.
- Reviews marquee (directions, two rows, pause button).
- Design: accent, list/grid, card style, rating icon (stars/hearts/thumbs/emoji with half states), font,
  text size — live preview on the Design screen; applied to tab, shortcodes, blocks and Elementor.
- 5 Gutenberg blocks, 6 shortcodes, 5 classic widgets, Elementor widgets + 2 dynamic tags.
- Schema: rating + reviews merged into WooCommerce's Product node (no duplicate Product).

### Manage
- Overview dashboard: setup checklist, KPIs, moderation queue, rating distribution, reminder stats,
  most/least reviewed products.
- All Reviews: approve/unapprove/spam/trash/restore/delete, search, full edit incl. criteria and photos.
- Anti-spam: honeypot + per-IP rate limit (skipped for signed links) + optional reCAPTCHA v3 (all forms).
- Import WooCommerce reviews and CSV (deduplicated, skip reasons reported); export CSV/JSON (all statuses,
  criteria, photos; formula-injection safe).
- GDPR export/erase covering reviews, photos, request log, tokens and the unsubscribe list.
- Opt-in uninstall cleanup that never deletes native WooCommerce reviews.

## PRO (Freemius)
- Unlimited criteria.
- Video reviews: oEmbed providers (no discovery) or direct .mp4/.webm; embed cached, never fetched per view.
- Store replies (with AI suggestion), highlight/pin, top-reviewer badge, anonymous reviews.
- Coupon reward: verified paid order containing the product, one coupon per order, single-use, expiring.
- Product Q&A: questions, answers (editable), votes, moderation with restore, QAPage schema (single question).
- AI with your own OpenAI or Anthropic key: summary (debounced, failure back-off), sentiment, spam score,
  tags, auto-publish of clearly positive pending reviews below the spam ceiling, reply suggestions,
  translation (language allowlist + daily budget). Imports never trigger AI calls.
- ESP connectors: Klaviyo, Mailchimp (transactional contacts), Brevo, webhook — async, respects unsubscribes.
- Automation: one delayed email step with smart rules; bulk campaign for past orders (deduplicated).
- External reviews: Google (API key / link-only aggregate), Facebook — deduplicated sync, no fake ratings,
  never marked verified, aggregates recalculated.
- Importer for Judge.me, Yotpo, ReviewX and generic CSV — BOM/header normalization, ID→SKU→handle→title
  matching, preview before import, skip reasons.
- Widgets: carousels, gallery, wall, badges, popup (closable, pausable), store rating badge, social feed.
- Google Shopping product review feed (XSD-valid, anonymity respected).
- Share buttons, generated share cards, auto-post to a Facebook page / webhook.
- Analytics: monthly volume/average, rating distribution, keywords, CSV export.
- REST API (read-only, public products only), signed webhooks with retries and delivery log, WP-CLI.
- Review Manager role (`ndvr_manage_reviews`) without WooCommerce settings access.
- Variation review pooling; verified-purchase gating; banned words (whole-word); auto-approve rules;
  low-rating alerts.

## Not built (do not advertise)
SMS/WhatsApp sequences (removed — needs approved templates, E.164, opt-in), per-category criteria
templates, multi-step drips, store credit rewards, Gemini, AI insight clustering, Instagram/X posting,
TranslatePress, form field builder, Divi/Bricks/Oxygen native elements (shortcodes work), agency features.
