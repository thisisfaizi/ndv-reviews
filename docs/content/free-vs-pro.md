# Free vs Pro

The design rule: **the free version must be genuinely useful and WordPress.org-compliant** — no crippled core, no forced account. Pro is "automation, scale, AI, and channels."

## Free tier {badge:free:FREE}

Ships on WordPress.org. Includes:

- Multi-criteria ratings (up to 3 criteria).
- Star ratings, title, body, recommend (Yes/Neutral/No).
- Photo uploads with reviews.
- Verified-buyer badge.
- Full moderation **including edit** (approve / unapprove / spam / trash / edit).
- One reliable email review-reminder (Action Scheduler) with test-send + log.
- Aggregate summary, criteria bar graphs, rating breakdown.
- 2 display templates, basic color/typography.
- Google rich schema (Product + AggregateRating + Review).
- Filtering & sorting, helpful voting, reviews on custom post types.
- Shortcodes + Gutenberg blocks + Elementor widget + Divi/Oxygen/Bricks/Breakdance support.
- reCAPTCHA v3 + honeypot anti-spam.
- Import from native WooCommerce reviews and CSV; CSV/JSON export.
- QR code + shareable/tokenized review-collection link.
- Basic Reviews Marquee + classic widgets.
- 1 standalone testimonial form, manual topic filter pills.
- GDPR consent + WP export/erasure hooks.
- HPOS + block-checkout compatible. **No metering, ever.**

## Pro tier {badge:pro:PRO}

A separate, license-gated add-on. Adds:

- Unlimited criteria + per-category templates, video reviews.
- Coupon/store-credit-for-review with reward tiers.
- Multi-step automation across email + SMS + WhatsApp, smart rules, bulk campaigns.
- Google Business + Facebook review import & display.
- Admin reply (+ AI suggestions), saved replies, bulk reply.
- Product Q&A module.
- AI module (BYO key): summary, sentiment, tagging, topics, highlight, insights, translation.
- Anonymous/highlight/country, rating styles (stars/hearts/emoji/thumbs).
- Full widget catalog (18–20), social posting + feed embed.
- Google Shopping review feed (XML) + badges.
- Product grouping, reputation/levels, advanced analytics.
- More importers (ReviewX/Judge.me/Loox/Yotpo/Stamped/Site Reviews/Amazon).
- ESP connectors (Klaviyo/Mailchimp/Brevo/webhook), team roles.
- REST API v2 + webhooks + WP-CLI, multilingual deep integration.

## The boundary

The free plugin exposes a stable internal API (actions, filters, service container). Pro hooks into `ndv-reviews/loaded`, checks its license, and registers modules. **Removing Pro leaves free fully working.** The free zip contains no premium/locked/encrypted code.

> During development, Pro licensing is intentionally deferred — features are testable with the gate open until the build is complete.
