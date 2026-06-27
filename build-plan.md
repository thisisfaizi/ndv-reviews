# NDV Reviews — WooCommerce Reviews Plugin: Complete Build Plan

> **Name:** `NDV Reviews` — a Nowdigiverse product. Naming, prefixes, namespace, and table prefix are centralized in §13 if anything needs to change later.
> **Audience for this document:** an autonomous AI software engineer (Claude Code). This is a build spec, not marketing copy. Build in the phase order of §15. Every feature lists a **Tier (Free/Pro)** and **Acceptance Criteria**.
> **Goal:** A faster, more reliable, privacy-first competitor to **ReviewX** (reviewx.io, self-hosted plugin) and **WiserReview** (wiserreview.com, metered SaaS) that ships a genuinely useful free version on WordPress.org and a Pro add-on with everything they charge for — plus the features they're missing or do badly.
> **Revision:** Plan v1.3. v1.1 added a WiserReview competitive pass (QR collection, Google/Facebook review import, standalone testimonial forms, topic filters, AI highlight, social auto-posting, bulk campaigns, expanded widgets, connectors, team roles — §19). v1.2 added Elementor + Loop Builder compatibility (§20). v1.3 adds the **tokenized multi-product review-collection link** with WooCommerce + Klaviyo + Mailchimp + Brevo + universal-webhook distribution (§21) and the **Reviews Marquee + traditional/classic widgets** (§22). All threaded into the split (§4), widgets (§9), integrations (§10), and phases (§15).

---

## 1. Positioning — How We Beat ReviewX & WiserReview

ReviewX has ~8,000 active installs and a 4.5 rating, but its public reviews expose four exploitable weaknesses. We design directly against them:

| ReviewX weakness (from real WP.org reviews & docs) | Our counter-design |
|---|---|
| **Review-reminder emails "never worked for years"** (multiple 1-star reviews). | Reminders run on **Action Scheduler** (battle-tested queue Woo already bundles) with a visible **email log, retry, and test-send**. Reliability is a headline feature, not an afterthought. |
| **Forces email + SaaS account just to use the FREE plugin**; data syncs to their external SaaS API. | **Self-hosted first. No account, no email, no phone-home required to use the free plugin.** All data stays in the user's DB. Optional cloud sync is opt-in only. |
| **"Manage Reviews" advertised as free, but Edit Review is locked behind Pro.** | Honest free tier. Full review moderation (approve/unapprove/spam/trash/**edit**) is **free**. We don't bait-and-switch core moderation. |
| **No Bricks / Breakdance / Gutenberg-block support; weak integrations.** | Native **Gutenberg blocks + Bricks + Breakdance + Elementor + Divi + shortcodes**. Plus Q&A, AI summaries, SMS/WhatsApp, and feeds ReviewX lacks. |

**One-line pitch:** *The reliable, self-hosted WooCommerce reviews plugin — multi-criteria ratings, photo/video reviews, working reminders, AI summaries, and Q&A — with no forced SaaS account.*

### 1.1 vs WiserReview (the metered-SaaS competitor)

WiserReview is feature-rich but it's a **cloud SaaS**: review data lives on their servers, widgets load from their CDN via embed script, and pricing is **metered** — the free plan caps at **10 reviews**, video reviews are capped per tier (2 / 20 / 100), AI is a paid tier, translation costs per-credit, and white-labeling costs extra. Our wedge:

| WiserReview (SaaS) | Our counter-design |
|---|---|
| **Metered:** free = 10 reviews; review-requests/month capped per tier; per-credit AI translation. | **No metering. Unlimited reviews, requests, and storage** — it's your server. One-time/lifetime Pro pricing is possible because we have no per-customer cloud cost. |
| **Your data lives on their servers**; widgets via remote embed script. | **You own 100% of the data** in your own DB; widgets render server-side/locally. Stronger GDPR story (data never leaves the store). |
| **Branding removal is a paid upgrade.** | No vendor branding on widgets, ever. White-label is for *agencies rebranding to clients*, not unlocking basics. |
| **AI uses their credits / paid tier.** | AI is **BYO-key** — user pays the model provider directly, no markup, no credit packs. |
| Page-speed depends on their remote script. | Conditional, local, async assets — no third-party render dependency. |

We still **match WiserReview's breadth** — that's what §19 adds: QR collection, Google/Facebook review import, standalone testimonial forms, shopper-facing topic filters, AI highlight, auto social posting, bulk past-customer campaigns, the full 18-20 widget catalog, and marketing-tool connectors.

> **Honest engineering caveat (read §19.5):** WiserReview-style "location/review-gating funnels" that route only happy customers to Google/Yelp and divert unhappy ones to a private form **violate Google's review policies and FTC/EU guidance**. We implement the public-review CTA **compliantly** — everyone is asked the same way, nothing is suppressed. Do not build a suppression gate.

---

## 2. Competitive Feature Inventory (ReviewX + WiserReview, for parity reference)

We must match or beat all of these.

**ReviewX Free:** multi-criteria rating (limited criteria), review reminder email, photo-with-review, review based on order status, advanced visual representation (graphs/bars), customizable themes (2 templates), Google rich schema, manage reviews (approve/delete), recommendation (Recommended/Neutral/Not), Elementor/Oxygen/Divi integration, extensive filtering, verified badge, advanced settings panel, test reminder email, reCAPTCHA, reviews on custom post types, import Judge.me reviews.

**ReviewX Pro:** review import from multiple sources, video reviews, **unlimited** criteria, share review on social media, review moderation/edit, voting (thumbs up/down), anonymous review, highlight review, admin reply, manage review approval (auto/manual), different rating styles (stars/likes/emoji), custom logo for admin reply, reviewer country flag, discount/coupon for review, instant admin notification, 24/7 support.

**ReviewX shortcodes to replicate:** `[rvx-stats]`, `[rvx-criteria-graph]`, `[rvx-summary]`, `[rvx-review-list]`, `[rvx_user_avatar]`, `[rvx-woo-reviews]`, `[rvx-star-count]`. We provide equivalents under our own prefix (see §9).

**WiserReview feature set (match these):** collect via email / SMS / WhatsApp / **QR code** / shareable form link; **import & display Google + Facebook business reviews** (keep verified badge); import from Judge.me / Loox / Yotpo / Stamped / CSV; **standalone testimonial & "location" review forms** (works for non-ecommerce too); **18-20+ display widgets** (product review section, star rating, nudges/inline snippet, wall of love, carousel, auto-slider, video carousel, trust badge, floating badge, avatar carousel, Q&A, floating popup, sidebar snippet, UGC gallery); smart automation sequences with **smart rules** (skip repeat customers, skip already-reviewed, prioritize high-value orders); **bulk past-customer / CSV request campaigns**; incentives (same or media-tiered discount, eligibility by rating); AI moderation (spam/fake/profanity, low-rating alerts), **AI review summary, AI smart topics (shopper-facing topic filters), AI product-review insights, AI highlight (key-phrase highlighting), AI sentiment auto-publish, AI auto-reply, AI auto-tagging, AI style generator**; **auto-post best reviews to social media + live social-feed embed**; SEO rich snippets + **Google Shopping reviews (PLAs)**; **product grouping** (combine reviews across similar products); multi-language email templates + auto-translation; **multiple-store / cross-store review sync**; **team members with roles** (Owner/Admin/Editor/Viewer); custom email domain; connectors for **Klaviyo, WATI/Interakt (WhatsApp), Zapier, webhooks**.

---

## 3. Our Differentiators (Features ReviewX Lacks)

These are the reasons a store switches to us. Most are Pro; a few headline ones seed the free tier.

1. **Product Q&A** — customer questions + merchant/community answers on the product page, with its own schema. (ReviewX has none.)
2. **AI module (BYO API key)** — AI review summary ("Customers say…"), sentiment scoring, auto-tagging/keywords, suggested admin replies, fake-review/spam scoring, one-click translation of reviews. No markup, no lock-in: user supplies their own OpenAI/Anthropic/Gemini key.
3. **Reliable multi-channel review requests** — Email **+ SMS + WhatsApp** (via Twilio/WhatsApp Cloud API), drip sequences, smart send-time, all on Action Scheduler with full logs. (ReviewX = email only, and it's flaky.)
4. **UGC galleries & shoppable photo wall** — Loox-style customer-photo gallery widget and a site-wide "Happy Customers" wall.
5. **Reviews carousel / floating badge / aggregate "all reviews" page** — site-wide social-proof widgets, not just the product tab.
6. **Google Shopping product-review feed (XML)** + Trustpilot/Google badge widgets — for Merchant Center, beyond just on-page schema.
7. **Shared reviews across variations & grouped/bundle products** — one rating pool for variants of the same product (configurable).
8. **Reviewer reputation & gamification** — reviewer levels, points, "Top Reviewer" badges; integrates with WooCommerce Points & Rewards / store credit as reward instead of only coupons.
9. **Native Gutenberg blocks + Bricks + Breakdance** (alongside Elementor/Divi/Oxygen).
10. **Developer platform** — clean REST API v2, webhooks, documented hooks/filters, CLI commands.
11. **Performance-first** — conditional asset loading (assets only enqueue where a review widget renders), no render-blocking, lazy-loaded media.
12. **Privacy/GDPR done right** — built-in WP data-export/erasure integration, consent logging, retention rules — not just a consent checkbox.
13. **Profanity/banned-word filter + honeypot + rate-limiting** anti-spam stack (beyond reCAPTCHA alone).
14. **Migration importers**: native Woo, ReviewX, Judge.me, Loox, Yotpo, Stamped, Site Reviews, Amazon/AliExpress CSV, generic CSV — and an **exporter** so users are never locked in.
15. **QR-code & shareable-link review collection** — print a QR on packaging/receipts/in-store → opens a prefilled review form. Channel-agnostic, works offline-to-online. (WiserReview has it; ReviewX doesn't.)
16. **External review import & display — Google Business + Facebook** — pull in and showcase reviews from Google Business Profile and Facebook Pages (verified badge retained), unified with on-site reviews.
17. **Standalone testimonial / feedback forms** — collect reviews independent of a WooCommerce order via hosted/embeddable forms, widening the plugin to service, SaaS, coaching, and local businesses — not just product PDPs.
18. **Shopper-facing topic filters + AI highlight** — AI clusters reviews into topics ("battery", "fit", "support") rendered as filter pills, and highlights key phrases inline — turning raw reviews into scannable, conversion-driving content.
19. **Automated social posting + social-feed embed** — auto-publish best reviews as image/video cards to FB/IG/X on a schedule, and embed a live social feed. (We do share buttons *and* outbound posting.)
20. **Bulk past-customer campaigns** — upload past orders / emails (CSV) and send batched review requests with smart rules (skip repeat buyers, skip already-reviewed, high-value-first).
21. **Full widget catalog (18-20)** — match WiserReview's breadth: nudges/inline snippet, auto-slider, video carousel, avatar carousel, sidebar snippet, floating popup, on top of carousel/gallery/badge/wall/Q&A.
22. **Marketing-tool connectors** — Klaviyo, WATI/Interakt (WhatsApp), Zapier, generic webhooks — so requests can flow through tools merchants already use, in addition to our built-in channels.
23. **No metering, you own the data** — the structural advantage over WiserReview: unlimited reviews/requests/storage, no per-credit AI, no paid white-label, data never leaves the store. This is positioning *and* an architectural constraint (never meter anything in code).

---

## 4. Product Strategy — Free vs Pro Split

Design rule: **the free version must be genuinely useful and WordPress.org-compliant** (no crippled core, no forced account). Pro is "automation, scale, AI, and channels."

### 4.1 Free tier (ships on WordPress.org)

- Multi-criteria ratings — **up to 3 criteria** (e.g. Quality / Value / Service).
- Star ratings, review title (optional), review body, recommend (Yes/Neutral/No).
- **Photo** uploads with reviews (configurable count/size).
- Verified-buyer badge.
- Full review **moderation incl. edit** (approve / unapprove / spam / trash / **edit**) — *deliberately free, unlike ReviewX.*
- One **email** review-reminder, fired on configurable order status, with test-send + log. **Reliable by design.**
- Aggregate summary + criteria bar graphs + rating breakdown on product page.
- 2 review-display templates, basic color/typography options.
- Google **rich schema** (Product + AggregateRating + Review).
- Filtering & sorting (most recent / highest / lowest / with photos / verified).
- Helpful **voting** (thumbs up) on reviews.
- Reviews on **custom post types**.
- **Shortcodes + Gutenberg blocks** for review list, summary, star count, badge.
- **Elementor** widget + **Divi/Oxygen/Bricks/Breakdance** shortcode support.
- reCAPTCHA v3 + honeypot anti-spam.
- Import from: native WooCommerce reviews, CSV.
- **QR code + shareable review-form link** — generate a QR/link that opens a prefilled review form (free seed feature; WiserReview gates collection volume, we don't).
- **Tokenized review-collection link (no login)** — a signed link customers open to review **one or more products from their order**, sent via WooCommerce's completed-order email; works for guests and account holders (full ESP distribution is Pro — see §21).
- **Reviews Marquee (basic) + classic `WP_Widget` widgets** — a Magic UI-style infinite-scroll review marquee (single-row, free) plus traditional sidebar/footer widgets for non-block themes (see §22).
- **Standalone testimonial form** (1 form) — collect a review without an order, for service/landing-page use.
- Topic **filter pills** on storefront (manual tags in free; AI-generated topics in Pro).
- GDPR consent checkbox + WP export/erasure hooks.
- HPOS-compatible, block-checkout compatible, fully self-hosted. **No metering of reviews, requests, or storage — ever.**

### 4.2 Pro tier (add-on plugin, license-gated)

- **Unlimited** review criteria + criteria templates per category.
- **Video** reviews (upload or link).
- **Discount/coupon or store-credit for review** (auto-generated, auto-expiring), with reward-for-photo/video tiers.
- **Multi-step review-request automation**: email + **SMS + WhatsApp**, drip sequences, smart send-time, segment by product/category/customer, **smart rules** (skip repeat buyers, skip already-reviewed, high-value-first), **bulk past-customer / CSV request campaigns**.
- **Collection channels+**: QR campaigns, multiple/branded testimonial + location forms, multi-language email templates (per customer locale), custom from-domain (SMTP/DKIM guidance).
- **External review import & display**: **Google Business Profile + Facebook Page reviews** (verified badge retained), unified with on-site reviews.
- **Admin reply** (with custom logo) + **saved-reply templates** + bulk reply + **AI suggested replies** + optional AI auto-reply to Google reviews.
- **Product Q&A** module.
- **AI module**: summary, sentiment (with auto-publish rule), auto-tag, **smart topics → shopper-facing topic filters**, **AI highlight (key-phrase highlighting)**, **AI product-review insights** (topic × sentiment × frequency clustering), reply suggestions, fake-review/spam + profanity scoring, translation, **AI widget-style generator** (all BYO key).
- **Anonymous reviews**, **highlight/pin review**, reviewer **country flag**, reviewer **reputation/levels**.
- Rating styles: **stars / hearts / emoji / thumbs**.
- **Full widget catalog (18-20)**: product review section, star rating, UGC photo gallery, reviews carousel, **auto-slider**, **video carousel**, **avatar carousel**, **nudges/inline snippet**, **sidebar snippet**, floating review badge, **floating popup**, trust badge, site-wide all-reviews page, testimonial/"wall of love", Q&A widget.
- **Social**: share buttons + auto-generate shareable review image/video cards + **scheduled auto-posting of best reviews** to FB/IG/X + **live social-feed embed**.
- **Google Shopping review feed (XML / PLAs)** + Google/Trustpilot badge widgets.
- **Product grouping**: pool reviews across variations, grouped/bundle children, **and arbitrary product groups** (e.g. same product in different SKUs).
- **Low-rating admin alerts** + **auto-approve rules** (e.g. auto-approve verified 4★+, hold the rest), profanity filter, banned-word list, image moderation hook.
- **Advanced analytics**: review rate, conversion lift, sentiment trend, criteria heatmap, **topic/insight clustering**, request→review funnel, CSV export.
- **More importers**: ReviewX, Judge.me, Loox, Yotpo, Stamped, Site Reviews, Amazon/AliExpress, **Google Business, Facebook**.
- **External review-collection link distribution** — push the tokenized multi-product review link into **Klaviyo** (`{{ event.review_url }}`), **Mailchimp** (`*|RVWURL|*`), **Brevo** (`{{ params.REVIEW_URL }}`), and any other ESP via **Zapier/Make/webhook** (see §21).
- **Reviews Marquee (advanced)** — vertical + multi-row opposite-direction, gradient fade edges, video review cards, advanced source filters (see §22).
- **Marketing-tool connectors**: Klaviyo, WATI/Interakt (WhatsApp), Zapier, generic webhooks.
- **Team roles**: map to WP capabilities (Owner/Admin/Editor/Viewer) + optional "Review Manager" role.
- **REST API v2 + webhooks + WP-CLI**.
- **WPML / Polylang / TranslatePress** deep integration + auto-translation.
- **Priority support**.

> **Optional Agency add-on (later):** multisite/network control, white-label branding, client license bundle, and **cross-store review sync** (Store A displays Store B's reviews while each keeps its own data — WiserReview charges for this; we offer it as an optional self-hosted sync). Out of scope for v1 core.

---

## 5. Technical Architecture

### 5.1 Stack & environment targets

- **PHP:** 7.4 → 8.3+ (test matrix all). Use typed properties cautiously for 7.4 compat.
- **WordPress:** 6.0 → latest. **WooCommerce:** 7.0 → latest.
- **WooCommerce HPOS** (High-Performance Order Storage) compatible — declare compatibility, never query `wp_posts` for orders directly; use the Woo CRUD/`OrdersTableQuery` APIs.
- **Cart/Checkout Blocks** compatible.
- **Frontend JS:** Preact (or vanilla + minimal) for the review widget — keep total JS < ~40 KB gzipped. **No jQuery dependency** in new code.
- **Admin JS:** React (aligns with WP/Woo admin) via `@wordpress/scripts`. Build with `wp-scripts`.
- **CSS:** scoped, prefixed (`ndvr-`), CSS variables for theming. No global resets that leak.
- **Build:** `@wordpress/scripts` (webpack) for blocks + admin app; PHP autoload via Composer PSR-4.
- **i18n:** all strings translatable, text-domain `ndv-reviews`, `.pot` generated.

### 5.2 Core principle — Self-hosted, no forced SaaS

The free plugin **must function fully with zero external calls** beyond:
- reCAPTCHA (only if the user enables it, user's own keys),
- WordPress.org update checks (standard).

No account, no email capture, no data sync as a precondition of use. Any cloud/AI/SMS feature is **opt-in** and uses the **user's own credentials**. This is both an ethical stance and our #1 marketing differentiator vs ReviewX.

### 5.3 Two-plugin architecture

```
ndv-reviews/            (FREE — WordPress.org)
ndv-reviews-pro/        (PRO  — sold/licensed separately, requires free as dependency)
```

- Free plugin exposes a **stable internal API** (actions, filters, service container) that Pro hooks into. Pro **never** edits free files.
- Free declares: `do_action('ndv-reviews/loaded', $plugin)`. Pro boots on that hook, checks license, registers Pro modules.
- Free must **degrade gracefully** if Pro is absent and **never** contain locked/obfuscated Pro code (WP.org rule). "Upsell" UI in free is plain, dismissible, and points to a URL — no bundled premium code.

### 5.4 Licensing (Pro)

- **Recommended:** integrate with the in-house **WP License Manager (WPLM)** licensing server (machine activation + subscription). Abstract behind a `License` interface so the provider can swap (WPLM / EDD Software Licensing / Lemon Squeezy).
- License gate is a single service: `License::is_pro_active(): bool` and per-feature `License::can('video_reviews')`. **Feature flags**, not scattered `if` checks.
- Updates for Pro delivered via the licensing server's update endpoint (not WP.org).

### 5.5 High-level module map

```
Core (free)
├── Reviews        (CRUD, criteria ratings, verified badge, voting)
├── Moderation     (approve/spam/trash/edit, bulk actions, list table)
├── Display        (templates, summary, graphs, filters, sorting)
├── Schema         (Product/Review/AggregateRating/QAPage JSON-LD)
├── Requests       (email reminder, Action Scheduler queue, logs, test-send)
├── Forms          (review form, photo upload, consent, anti-spam)
├── Integrations   (Elementor widget; shortcodes; Gutenberg blocks; CPT)
├── Importers      (Woo native, CSV)
├── Settings       (React admin app, REST-backed)
└── Privacy        (GDPR export/erasure, consent log, retention)

Pro (add-on)
├── Criteria+      (unlimited criteria, per-category templates)
├── Media+         (video reviews, image/video moderation hooks)
├── Incentives     (coupon/credit for review, reward tiers)
├── Automation     (multi-step email/SMS/WhatsApp, segments, smart timing)
├── Replies        (admin reply, saved replies, bulk reply, logo)
├── QandA          (product questions & answers)
├── AI             (summary, sentiment, tagging, reply-suggest, spam, translate)
├── Widgets+       (carousel, UGC gallery, floating badge, all-reviews page, wall)
├── Social         (share buttons, shareable image cards)
├── Feeds          (Google Shopping review XML, Google/Trustpilot badges)
├── Variations     (shared rating pools)
├── Reputation     (reviewer levels, points, country flag, anonymous, highlight)
├── Analytics      (dashboard, funnel, sentiment trend, export)
├── Importers+     (ReviewX/Judge.me/Loox/Yotpo/Stamped/Site Reviews/Amazon)
├── Multilingual   (WPML/Polylang/TranslatePress)
└── Developer      (REST v2, webhooks, WP-CLI)
```

---

## 6. Data Model

**Decision:** store the review itself as a WordPress **comment** with `comment_type = 'review'` (same as native Woo) for maximum compatibility, native moderation reuse, and trivial migration. Store everything extra in **custom tables**. This makes "import native Woo reviews" essentially free and keeps schema/SEO working even if our plugin is later disabled.

### 6.1 Custom tables (prefix `{$wpdb->prefix}ndvr_`)

```sql
-- Criteria definitions (global + per product/category override)
CREATE TABLE ndvr_criteria (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(191) NOT NULL,
  slug          VARCHAR(191) NOT NULL,
  scope         ENUM('global','category','product') DEFAULT 'global',
  scope_id      BIGINT UNSIGNED NULL,        -- term_id or product_id
  position      INT DEFAULT 0,
  status        ENUM('active','inactive') DEFAULT 'active',
  KEY scope_idx (scope, scope_id)
);

-- Per-review criteria scores (links to a comment_ID)
CREATE TABLE ndvr_review_criteria (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  comment_id    BIGINT UNSIGNED NOT NULL,
  criteria_id   BIGINT UNSIGNED NOT NULL,
  rating        DECIMAL(3,2) NOT NULL,       -- 0.00–5.00
  KEY comment_idx (comment_id),
  KEY criteria_idx (criteria_id)
);

-- Review media (photos/videos)
CREATE TABLE ndvr_review_media (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  comment_id    BIGINT UNSIGNED NOT NULL,
  type          ENUM('image','video','video_url') NOT NULL,
  attachment_id BIGINT UNSIGNED NULL,
  url           TEXT NULL,
  position      INT DEFAULT 0,
  status        ENUM('pending','approved','rejected') DEFAULT 'approved',
  KEY comment_idx (comment_id)
);

-- Review votes (helpful/not, one per user/IP)
CREATE TABLE ndvr_review_votes (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  comment_id    BIGINT UNSIGNED NOT NULL,
  user_id       BIGINT UNSIGNED NULL,
  ip_hash       CHAR(64) NULL,
  vote          TINYINT NOT NULL,            -- 1 up / -1 down
  created_at    DATETIME NOT NULL,
  UNIQUE KEY uniq_vote (comment_id, user_id, ip_hash)
);

-- Request/automation queue + log (PRO automation writes here; FREE email uses it too)
CREATE TABLE ndvr_requests (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id      BIGINT UNSIGNED NOT NULL,
  customer_id   BIGINT UNSIGNED NULL,
  email         VARCHAR(191) NULL,
  phone         VARCHAR(32) NULL,
  channel       ENUM('email','sms','whatsapp') DEFAULT 'email',
  step          INT DEFAULT 1,
  status        ENUM('scheduled','sent','failed','cancelled','converted') DEFAULT 'scheduled',
  scheduled_at  DATETIME NULL,
  sent_at       DATETIME NULL,
  error         TEXT NULL,
  KEY order_idx (order_id),
  KEY status_idx (status, scheduled_at)
);

-- Product Q&A (PRO)
CREATE TABLE ndvr_questions (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id    BIGINT UNSIGNED NOT NULL,
  user_id       BIGINT UNSIGNED NULL,
  author_name   VARCHAR(191) NULL,
  question      TEXT NOT NULL,
  status        ENUM('pending','approved','spam','trash') DEFAULT 'pending',
  votes         INT DEFAULT 0,
  created_at    DATETIME NOT NULL,
  KEY product_idx (product_id, status)
);
CREATE TABLE ndvr_answers (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  question_id   BIGINT UNSIGNED NOT NULL,
  user_id       BIGINT UNSIGNED NULL,
  author_name   VARCHAR(191) NULL,
  is_merchant   TINYINT DEFAULT 0,
  answer        TEXT NOT NULL,
  status        ENUM('pending','approved','spam','trash') DEFAULT 'pending',
  votes         INT DEFAULT 0,
  created_at    DATETIME NOT NULL,
  KEY question_idx (question_id, status)
);

-- AI enrichment cache (PRO)
CREATE TABLE ndvr_ai_meta (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  object_type   ENUM('review','product') NOT NULL,
  object_id     BIGINT UNSIGNED NOT NULL,
  sentiment     DECIMAL(3,2) NULL,           -- -1..1
  tags          TEXT NULL,                   -- JSON array
  summary       TEXT NULL,                   -- product-level "customers say"
  spam_score    DECIMAL(3,2) NULL,
  lang          VARCHAR(12) NULL,
  updated_at    DATETIME NOT NULL,
  KEY obj_idx (object_type, object_id)
);
```

### 6.2 Comment meta keys (on the review comment)

`_ndvr_recommend` (yes|neutral|no), `_ndvr_verified` (1|0), `_ndvr_anonymous` (1|0), `_ndvr_highlight` (1|0), `_ndvr_country` (ISO code), `_ndvr_helpful_up`, `_ndvr_helpful_down`, `_ndvr_overall_rating` (cached avg of criteria), `_ndvr_order_id`. Native Woo `rating` meta is kept in sync for compatibility.

### 6.3 Options

Single autoloaded option `ndv_reviews_settings` (array, versioned schema). Pro uses `ndv_reviews_pro_settings`. Avoid option sprawl.

---

## 7. Feature Specifications

Each feature: **Tier**, behavior, **Acceptance Criteria (AC)**.

### 7.1 Multi-criteria ratings — *Free (≤3), Pro (unlimited + templates)*
Admin defines criteria; form renders a star control per criterion; overall = mean of criteria (configurable: mean or primary). Per-category/product criteria templates are Pro.
**AC:** ☐ Criteria render on form & display. ☐ Overall computed & cached on save. ☐ Free hard-caps at 3 active criteria with an upsell notice. ☐ Aggregate per-criterion bars show on product page. ☐ Deleting a criterion recalculates affected averages.

### 7.2 Review form (photo/video, consent, anti-spam) — *Free (photo), Pro (video)*
Fields: rating(s), title (optional toggle), body, recommend, name/email (guests), photo upload (free), video upload/URL (Pro), consent checkbox. Anti-spam: reCAPTCHA v3 (free), honeypot (free), per-IP/email rate limit (free), profanity/banned-words (Pro).
**AC:** ☐ AJAX submit, no full reload. ☐ Client + server validation. ☐ Images validated (mime, size, count) and stored via WP media + `ndvr_review_media`. ☐ Honeypot + nonce + capability checks. ☐ Guest vs verified-buyer limits enforced. ☐ Works on block & classic product templates.

### 7.3 Verified-buyer badge — *Free*
Mark review verified if the email/user matches a completed order for that product.
**AC:** ☐ Verified flag set on submit and recomputed on import. ☐ Badge shown on display. ☐ Filter "verified only" works.

### 7.4 Moderation (incl. edit) — *Free*
Custom admin list table (filter by product/rating/status/has-media), row + bulk actions: approve, unapprove, spam, trash, **edit** (rating, criteria, body, media), reply (Pro). A "Rating" column added to native Comments screen.
**AC:** ☐ All single + bulk actions work and update caches/schema. ☐ Edit modal saves criteria + media. ☐ Pending reviews never leak to frontend. ☐ Capability `moderate_comments` enforced.

### 7.5 Display templates, summary, graphs, filtering, sorting — *Free*
2 templates (free) / +more (Pro). Summary block: overall avg, total count, star-distribution bars, per-criterion bars, recommend %, verified count. Filters: with-photos, verified, by star, by criterion. Sort: recent / helpful / highest / lowest. AJAX pagination, conditional asset loading.
**AC:** ☐ Summary numbers match DB. ☐ Filters/sort/paginate via AJAX without layout shift. ☐ Assets enqueue only where a widget/tab renders. ☐ Mobile responsive, passes basic a11y (labels, focus, keyboard).

### 7.6 Schema / SEO — *Free*
Valid JSON-LD: `Product` + `AggregateRating` + `Review` (and `QAPage` for Q&A in Pro). Avoid duplicate schema if theme/SEO plugin already outputs it (detection + setting). Pass Google Rich Results test.
**AC:** ☐ No duplicate AggregateRating. ☐ Validates in Google Rich Results test. ☐ Setting to disable if SEO plugin handles it.

### 7.7 Review-request email (reliability is the headline) — *Free (1 email), Pro (multi-step/SMS/WhatsApp)*
On configurable order status (default: completed), enqueue an `ndvr_requests` row + an **Action Scheduler** job. Customizable template (placeholders, product image, direct-from-email star links → prefilled form, auto-login token optional). **Test-send**, **email log** with status/retry, unsubscribe page + token. Pro: steps 2..n, channel = SMS/WhatsApp (Twilio / WhatsApp Cloud API, user creds), per-segment rules, smart send-time, coupon embed.
**AC:** ☐ Reminder reliably fires via Action Scheduler (not WP-cron-only) and is visible in the log. ☐ Retry on failure with backoff; failures surfaced in UI. ☐ Test-send works. ☐ Unsubscribe honored. ☐ One-click "leave a review from email" pre-fills the form. ☐ No duplicate requests per order/product.
*(This directly fixes ReviewX's most damaging, repeated complaint.)*

### 7.8 Incentives — coupon / store credit for review — *Pro*
Auto-generate single-use, auto-expiring WooCommerce coupon (or store credit) after an approved review; optional higher reward for photo/video; email delivery; fraud guard (one reward per order/product, only approved reviews).
**AC:** ☐ Coupon issued only after approval. ☐ Expiry + single-use enforced. ☐ Reward tier by media works. ☐ No double-rewarding.

### 7.9 Admin reply + saved replies — *Pro*
Threaded merchant reply under a review (custom logo, signature), saved-reply templates, bulk reply. Replies excluded from comment counts and schema review count.
**AC:** ☐ Reply renders nested, not as a separate review. ☐ Excluded from counts/schema. ☐ Saved templates insertable. ☐ Logo configurable.

### 7.10 Product Q&A — *Pro*
Questions tab on product page; community + merchant answers; voting; moderation list; `QAPage` schema; optional "notify me when answered."
**AC:** ☐ Ask/answer/vote with moderation. ☐ Merchant answers badged. ☐ QAPage schema validates. ☐ Spam protection shared with reviews.

### 7.11 AI module (BYO key) — *Pro*
Provider-agnostic adapter (OpenAI / Anthropic / Gemini), user supplies key. Functions: product-level **summary** ("Customers say…"), per-review **sentiment**, **auto-tags/keywords**, **suggested admin reply**, **fake-review/spam score**, **translate review**. All cached in `ndvr_ai_meta`; run async via Action Scheduler; manual "regenerate." Costs are the user's (no markup, no proxy).
**AC:** ☐ Works with at least 2 providers behind one interface. ☐ Graceful degradation if no key / API error (feature hidden, never fatal). ☐ Results cached; no per-pageview API calls. ☐ Summary renders above reviews; refreshes on new approved reviews (throttled).

### 7.12 Widgets+ (carousel, UGC gallery, floating badge, all-reviews page, wall) — *Pro*
Shortcodes + blocks + builder widgets for: reviews carousel, customer-photo gallery (clickable → review), floating "★ 4.8 (1,204 reviews)" badge, paginated site-wide all-reviews archive, testimonial wall.
**AC:** ☐ Each available as shortcode **and** Gutenberg block. ☐ Lazy-loads media. ☐ Configurable source (all / product / category / min-rating / with-media).

### 7.13 Social share + image cards — *Pro*
Share buttons (FB/X/WhatsApp/Pinterest/copy-link) per review; server-side generated branded review image card (rating + excerpt + product) for sharing.
**AC:** ☐ Share links prefill correctly. ☐ Image card generated and cached. ☐ Open Graph tags correct on shared review permalinks.

### 7.14 Feeds & badges — *Pro*
Google Shopping product-review **XML feed** endpoint (Merchant Center spec); Google/Trustpilot badge widgets.
**AC:** ☐ Feed validates against Google's product-review feed spec. ☐ Feed paginates/caches. ☐ Badge widgets render with live aggregate.

### 7.15 Shared reviews across variations/grouped — *Pro*
Setting to pool ratings/reviews across variations of a variable product (and optionally grouped/bundle children → parent).
**AC:** ☐ Aggregate pools correctly. ☐ Submitting on a variation attributes to the parent pool. ☐ Schema reflects pooled aggregate.

### 7.16 Reputation, anonymous, highlight, country, rating styles — *Pro*
Reviewer levels/points/"Top Reviewer" badge; anonymous option; pin/highlight a review to top; reviewer country flag (from order/IP geo, privacy-respecting); rating UI styles (stars/hearts/emoji/thumbs).
**AC:** ☐ Each toggle works independently. ☐ Highlighted review pinned in list + flagged in schema-safe way. ☐ Country derived without storing raw IP (hash/geo only). ☐ Rating style switch is purely presentational.

### 7.17 Analytics — *Pro*
Dashboard: review volume over time, average rating trend, request→review **conversion funnel**, sentiment trend, per-criterion heatmap, top keywords, CSV export.
**AC:** ☐ Queries are indexed/performant on 100k+ reviews. ☐ Date-range filter. ☐ CSV export matches on-screen data.

### 7.18 Importers/Exporters — *Free (Woo+CSV), Pro (the rest)*
Mappers for: native Woo (free), generic CSV (free); ReviewX, Judge.me, Loox, Yotpo, Stamped, Site Reviews, Amazon/AliExpress CSV (Pro). Batched via Action Scheduler with progress + rollback. **Exporter** (CSV/JSON) is **free** — no lock-in.
**AC:** ☐ Imports map ratings, criteria (where present), media, author, date, verified. ☐ Batched, resumable, idempotent (no dupes on re-run). ☐ Export round-trips losslessly.

### 7.19 Privacy/GDPR — *Free*
Register with WP Personal Data Exporter/Eraser; consent logging with timestamp/IP-hash; retention rules (auto-anonymize after N months, Pro for scheduling).
**AC:** ☐ Export/erase includes reviews, media refs, votes, requests. ☐ Consent recorded. ☐ Uninstall respects a "keep data" setting.

---

## 8. Email/Cron Reliability Design (critical)

ReviewX's flagship failure is unreliable reminders. We must be provably reliable:

1. **Action Scheduler** (bundled with Woo) is the queue — not raw `wp_cron` only. Persist intent in `ndvr_requests` so a missed cron can be recovered.
2. **Idempotency:** unique guard on `(order_id, product_id, channel, step)` — never double-send.
3. **Observability:** admin log table with status, timestamps, error, and a **Retry** button. A health check warns if Action Scheduler hasn't run recently (server cron misconfig is the usual root cause — surface it, link a fix).
4. **Test send** from settings to the admin's email.
5. **Deliverability guidance:** detect absence of an SMTP plugin and recommend one; never silently fail to PHP `mail()`.

---

## 9. Shortcodes & Blocks (parity + extensions)

Prefix `ndv-reviews` / short alias `ndvr`. Provide BOTH a shortcode and a Gutenberg block for each.

| Purpose | Shortcode | Block | Tier |
|---|---|---|---|
| Full review list | `[ndvr-reviews product_id=""]` | NDV Reviews: Reviews | Free |
| Summary box | `[ndvr-summary product_id=""]` | NDV Reviews: Summary | Free |
| Criteria graph | `[ndvr-criteria-graph product_id=""]` | NDV Reviews: Criteria | Free |
| Star rating + count | `[ndvr-stars post_id=""]` | NDV Reviews: Stars | Free |
| Review/submit form | `[ndvr-form product_id=""]` | NDV Reviews: Form | Free |
| Profile photo uploader | `[ndvr-avatar]` | — | Free |
| Reviews carousel | `[ndvr-carousel]` | NDV Reviews: Carousel | Pro |
| UGC photo gallery | `[ndvr-gallery]` | NDV Reviews: Gallery | Pro |
| Floating badge | `[ndvr-badge]` | NDV Reviews: Badge | Pro |
| All-reviews / wall | `[ndvr-wall]` | NDV Reviews: Wall | Pro |
| Q&A | `[ndvr-qa product_id=""]` | NDV Reviews: Q&A | Pro |
| Reviews marquee (Magic UI-style) | `[ndvr-marquee]` | NDV Reviews: Reviews Marquee | Free (basic) / Pro (advanced) |
| Multi-product review form (token link) | `[ndvr-collect]` (token-driven landing) | — | Free |

Each shortcode/block also ships as a **classic `WP_Widget`** (Appearance → Widgets) and, where listed, an **Elementor widget** — all sharing one renderer (see §20, §22).

Builder integrations: **Elementor** native widgets + **dynamic tags** with full Theme Builder + **Loop Builder (Loop Item) support** — see the dedicated spec in **§20** (free for core widgets/tags, Pro widgets gated), **Divi/Oxygen/Bricks/Breakdance** via shortcodes/elements. Provide a Bricks element + Breakdance element where their SDKs allow.

---

## 10. REST API v2, Webhooks, CLI — *Pro (read endpoints partly free)*

- Namespace `ndv-reviews/v2`. Endpoints: list/get/create reviews, criteria, votes, Q&A, summary, analytics.
- Auth via WP application passwords / nonce; capability-checked.
- **Webhooks:** `review.created`, `review.approved`, `request.sent`, `request.converted`, `question.created`.
- **WP-CLI:** `wp ndv-reviews import …`, `wp ndv-reviews recalc`, `wp ndv-reviews ai backfill`, `wp ndv-reviews requests run`.
**AC:** ☐ Documented OpenAPI. ☐ Capability + nonce enforced. ☐ Webhooks signed (HMAC).

---

## 11. Security, Performance, Accessibility, i18n

**Security:** nonces on every write; `current_user_can` on every admin/AJAX action; `$wpdb->prepare` for all queries; escape on output (`esc_html`/`esc_attr`/`esc_url`/`wp_kses_post`); validate/sanitize all input; mime/size checks on uploads; honeypot + rate-limit; no eval/obfuscation; follow the [WP Plugin Security guidelines]. Treat IP as PII (store hashed).

**Performance:** conditional enqueue (only where widgets render); cache aggregates in comment meta / transients; index every custom-table query path; AJAX pagination; lazy-load media; avoid `posts_per_page=-1`; batch heavy jobs via Action Scheduler; no synchronous external API calls on frontend pageviews.

**Accessibility:** WCAG 2.1 AA target — labeled star controls (radio semantics), keyboard-operable filters, focus management on modals, `aria-live` on AJAX updates, sufficient contrast.

**i18n/RTL:** all strings via `__()/_e()/esc_html__()` with `ndv-reviews` domain; `.pot` shipped; RTL CSS; tested with WPML/Polylang/TranslatePress (Pro deep integration); Loco-translatable.

---

## 12. WordPress.org Guidelines & Code Compliance (Free Plugin) — MANDATORY

The free plugin is manually reviewed by the WordPress.org Plugin Review Team and must pass automated **Plugin Check (PCP)**. Non-compliance = rejection or post-publish removal. This section is a **hard gate**: the engineer treats every item as a release blocker, not a suggestion. Compliance is checked at the end of **every phase** and exhaustively before the Phase 4 free release.

### 12.1 The 18 Detailed Plugin Guidelines → how we satisfy each
Authoritative source: `developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/`. Mapping for this plugin:

1. **GPL-compatible license** — ship **GPLv2-or-later**; every bundled file (code, images, libraries, fonts) must be GPL-compatible. Confirm licensing of all third-party assets before commit; if a library/API license can't be validated, don't bundle it.
2. **Developer responsible for contents/actions** — no doing anything on the user's site they didn't trigger.
3. **Stable version available from the directory** — the .org SVN copy is the canonical distributed build; keep it current.
4. **Code must be (mostly) human-readable** — **no obfuscation/minification-as-hiding** of PHP, no `$z12sdf` naming, no packer/uglify-mangle. Minified JS/CSS is fine **only if** unminified sources ship in the plugin (or via the documented `wp-scripts` build); include source maps or `/src`.
5. **Trialware is not permitted** — the free plugin must be **fully functional and not time-limited / feature-crippled-to-uselessness**. Pro features are simply absent in free, not "locked trials." (Our deliberately-free moderation/edit and working reminders satisfy this.)
6. **SaaS is permitted** — Pro may rely on external services, but a plugin that is *only* a thin UI to a paid SaaS with no local function isn't allowed in the directory. Our free plugin does real local work with **zero required external calls** — sails through.
7. **No tracking without consent** — **no analytics, telemetry, or "phone home" by default.** Any usage tracking is strictly opt-in via an explicit, unchecked consent control, with disclosure. (Default posture: we collect nothing.)
8. **No sending executable code via third-party systems** — the free plugin must **never fetch and run remote PHP/JS** (no loading Pro code from our server, no remote "addon installers" that execute code, no CDN-loaded executable logic). Updates come only through the standard WP update API. Pro is installed by the user as a separate plugin.
9. **Nothing illegal/dishonest/morally offensive** — n/a by design; also see the §19.5 anti-review-gating rule.
10. **No external links/credits on the public site without explicit opt-in** — **no "Powered by NDV Reviews" / backlinks injected into the front end** unless the admin explicitly enables it (default off).
11. **Don't hijack the admin dashboard** — upsell/onboarding is **contained to the plugin's own screens**, dismissible, and non-blocking. No site-wide nag banners, no admin-wide redirects, no interstitials on activation beyond a single dismissible welcome.
12. **Readme must not spam** — `readme.txt` is honest, not keyword-stuffed; tags ≤ 5 relevant terms; no competitor names or affiliate spam in tags/description.
13. **Use WordPress's default libraries** — use **WP-bundled** jQuery (if needed), Underscore, React/`@wordpress/element`, etc.; **do not re-bundle** your own copy of a library WP already ships. Use Action Scheduler (already bundled by Woo).
14. **Avoid frequent commits** — batch SVN commits per release; don't churn trunk.
15. **Increment version every release** — bump the version header + `Stable tag` on every push.
16. **Complete plugin at submission** — first submission must be functional and complete (Phase 4 build), not a stub.
17. **Respect trademarks/copyrights/project names** — see §12.4.
18. **Directory team's discretion** — keep contact info current; respond to the review team promptly.

### 12.2 Plugin Check (PCP) — zero errors gate
- Install the official **Plugin Check** plugin and run it locally + in CI on every build. **All "Errors" must be fixed**; "Warnings" reviewed and resolved or justified.
- PCP enforces: proper escaping/sanitization, no disallowed functions (`eval`, `create_function`, raw `error_reporting`, `base64_*`/`gzinflate` as code-hiding, direct `$_GET/$_POST` use without sanitize, etc.), i18n correctness, prefixing, readme validity, no `error_log`/`var_dump` left in, file-header correctness, and trademark checks. Make a clean PCP run part of "definition of done."

### 12.3 WordPress Coding Standards (enforced in CI)
- **PHPCS** with the **`WordPress`** standard (WPCS 3.x) + **`WordPress-Extra`**; **`PHPCompatibilityWP`** against `Requires PHP` (7.4) through 8.3. Build fails on any sniff error.
- Yoda conditions, WP brace/indentation style, no shorthand PHP tags, documented hooks/functions, no commented-out dead code.
- **ESLint** (`@wordpress/eslint-plugin`) + **stylelint** for JS/CSS.

### 12.4 Naming & trademark (specific to this plugin)
- Display name **"NDV Reviews"** is safe (NDV = Nowdigiverse's own mark; "Reviews" is generic). **Do not begin the name with another's trademark.**
- **Do NOT put "WooCommerce" or "WordPress" in the plugin name or slug** (e.g. not "NDV Reviews for WooCommerce" as the registered name). Describe WooCommerce/Woo compatibility in the **description** body instead. Never attempt look-alike workarounds ("WooReviews", "WuuCommerce") — the team rejects those.
- Slug = `ndv-reviews`; text domain must equal the slug (§13).

### 12.5 Security requirements (review-critical, also §11)
- `defined('ABSPATH') || exit;` at the top of **every** PHP file.
- **Sanitize on input** (`sanitize_text_field`, `sanitize_email`, `absint`, `wp_kses_post`, `esc_url_raw`), **escape on output / late escaping** (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`), **validate** types/ranges.
- **Every** form/AJAX/REST write is **nonce-verified** (`check_admin_referer`, `wp_verify_nonce`, REST nonce) **and** capability-checked (`current_user_can`).
- **All** DB access via `$wpdb->prepare()` (or `$wpdb` helper methods) — never interpolate untrusted input.
- File handling via the **`WP_Filesystem`** API; validate upload MIME/size/extension; store via `wp_handle_upload`/media APIs.
- No untrusted deserialization; treat user IP as PII (hash it).

### 12.6 Enqueuing, assets & external services
- Register/enqueue scripts & styles via `wp_enqueue_script/style` with versioning — **no hardcoded `<script>`/`<link>` tags**, no inline `<script>` blobs.
- **No remote-loaded assets** in the free plugin: bundle CSS/JS/fonts locally (no Google Fonts CDN, no remote jQuery). This is both a guideline and a privacy/perf win.
- **External-service disclosure:** any service the plugin can contact must be disclosed in `readme.txt` — what data is sent, when, and links to the service's Terms/Privacy. For the **free** plugin this is limited to **reCAPTCHA (only if the user enables it, with their keys)**. All Klaviyo/Mailchimp/Brevo/AI/SMS integrations live in **Pro** (off-directory) and use the **user's own credentials** — but document them there too.

### 12.7 Freemium architecture rules (so free passes review)
- Free zip contains **no premium/locked/encrypted code** and **no Pro code stubs that phone home**.
- Free may show a plain, dismissible upsell pointing to a URL — but must not **bundle, download, or execute** Pro code (see Guideline 8). Pro is a **separate user-installed plugin** updated via our own (off-.org) licensing server, never injected into the free plugin.
- The free↔Pro boundary is the documented action/filter API (§5.3); removing Pro must leave free fully working.

### 12.8 readme.txt, i18n, uninstall, compatibility
- Valid `readme.txt`: accurate `Stable tag`, `Requires at least`, `Tested up to`, `Requires PHP`, `License`, contributors, ≤ 5 honest tags, screenshots, changelog, FAQ. Validate with the readme validator.
- **i18n:** every user string translatable with text domain `ndv-reviews`; ship `.pot`; English source strings; load text domain correctly (WP 4.6+ auto-loads for .org plugins, but follow current guidance).
- **uninstall.php**/uninstall hook respects a "remove data on uninstall" setting (default: keep data).
- Declare **HPOS** and **Cart/Checkout Blocks** compatibility; test on classic + block checkout.

> **Release gate:** Phase 4 free release is blocked until PCP = 0 errors, PHPCS = 0 errors, readme validates, and §12.1 mapping is re-confirmed. Every other phase also ends with a clean PCP/PHPCS run on the code added.

---

## 13. Naming, Prefixing, Renaming

Single source of truth so the brand can change in one pass:
- Text domain: `ndv-reviews`
- PHP namespace: `NdvReviews\` (PSR-4)
- Function/hook prefix: `ndv_reviews_` / `ndvr_`
- CSS/JS handle + class prefix: `ndvr-`
- DB table prefix: `{$wpdb->prefix}ndvr_` (constant `NDVR_TABLE_PREFIX`)
- Option keys: `ndv_reviews_settings`, `ndv_reviews_pro_settings`
- Pro add-on: folder `ndv-reviews-pro`, namespace `NdvReviews\Pro\`, license-gated (see §5.3)
Define `NDVR_SLUG`, `NDVR_NAME`, `NDVR_TEXTDOMAIN` constants; reference everywhere. Renaming = change constants + slugs + readme + asset folder.

---

## 14. File / Folder Scaffold

```
ndv-reviews/
├── ndv-reviews.php                 # bootstrap, constants, activation/deactivation
├── readme.txt                      # WP.org
├── composer.json                   # PSR-4 autoload
├── package.json                    # wp-scripts build
├── uninstall.php
├── languages/ndv-reviews.pot
├── includes/
│   ├── Plugin.php                  # service container, hooks bootstrap
│   ├── Activator.php / Deactivator.php / Installer.php   # dbDelta schema
│   ├── Reviews/ (Review.php, ReviewRepository.php, Criteria*.php, Votes.php)
│   ├── Moderation/ (ListTable.php, Actions.php)
│   ├── Display/ (Renderer.php, Templates/, Summary.php, Filters.php)
│   ├── Schema/ (JsonLd.php)
│   ├── Requests/ (Scheduler.php, EmailRequest.php, Log.php, Unsubscribe.php)
│   ├── Forms/ (ReviewForm.php, Upload.php, AntiSpam.php)
│   ├── Integrations/ (Elementor/, Shortcodes.php, Blocks/, Cpt.php)
│   ├── Importers/ (WooNative.php, Csv.php, Exporter.php)
│   ├── Admin/ (SettingsApp.php, RestSettings.php, Pages.php)
│   ├── Privacy/ (Exporter.php, Eraser.php, Consent.php)
│   ├── Compat/ (Hpos.php, Blocks.php)
│   └── Support/ (Container.php, Hooks.php, Sanitizer.php, View.php)
├── assets/
│   ├── src/admin/  (React settings app)
│   ├── src/blocks/ (block.json per block)
│   ├── src/frontend/ (Preact widget, form)
│   └── build/      (compiled)
└── templates/      (overridable theme templates: review-list.php, summary.php, form.php …)

ndv-reviews-pro/
├── ndv-reviews-pro.php             # boots on ndv-reviews/loaded; license gate
├── includes/
│   ├── License/ (License.php, WplmProvider.php, FeatureFlags.php)
│   ├── Criteria/ Media/ Incentives/ Automation/ (Email,Sms,Whatsapp,SmartTime)/
│   ├── Replies/ QandA/ AI/ (Provider/, Summary.php, Sentiment.php, Tagger.php, …)
│   ├── Widgets/ Social/ Feeds/ Variations/ Reputation/ Analytics/
│   ├── Importers/ (ReviewX,Judgeme,Loox,Yotpo,Stamped,SiteReviews,Amazon)
│   ├── Multilingual/ Developer/ (Rest/, Webhooks/, Cli/)
└── assets/ (pro blocks, pro admin panels)
```

---

## 15. Build Phases (execute in this order)

Each phase ends shippable, tested, and demoable. Don't start a phase before the prior phase's AC pass.

**Phase 0 — Scaffolding (free)**
Bootstrap, constants, PSR-4 autoload, activation/deactivation, `dbDelta` schema, HPOS + block-checkout declarations, settings option scaffold, build pipeline, CI (PHPCS WP standard + Plugin Check + unit test runner).
*Done when:* plugin activates cleanly, tables created, Plugin Check passes.

**Phase 1 — Core reviews (free)**
Review form (photo, consent, anti-spam), multi-criteria (≤3), verified badge, store as comment + custom tables, overall rating cache.
*Done when:* a guest/customer can submit a multi-criteria photo review; it appears after moderation.

**Phase 2 — Moderation + display (free)**
Admin list table (filter/bulk/edit), 2 display templates, summary + criteria graphs, filtering/sorting/AJAX pagination, helpful voting, schema.
*Done when:* full moderation incl. edit works; product page shows summary, graphs, filterable list; Rich Results validates.

**Phase 3 — Requests email + reliability (free)**
Action-Scheduler queue, `ndvr_requests`, customizable template, test-send, log + retry, unsubscribe, health check.
*Done when:* reminder reliably sends on order status, visible in log, retryable, unsubscribe works.

**Phase 4 — Integrations + importers + privacy (free) → FREE v1.0 release**
Shortcodes + Gutenberg blocks + Elementor widgets + Divi/Oxygen/Bricks/Breakdance; CPT support; Woo-native + CSV import; CSV/JSON export; GDPR export/erase/consent; performance pass (conditional assets); i18n `.pot`.
*Done when:* Plugin Check clean, WP.org readme ready → **submit free to WordPress.org**.

**Phase 5 — Pro foundation**
Pro plugin bootstrap, license provider (WPLM) + feature flags, update channel, unlimited criteria + templates, video reviews, rating styles, anonymous/highlight/country, admin reply + saved replies.
*Done when:* Pro activates only with valid license; gated features appear; free still works without Pro.

**Phase 6 — Automation + incentives (Pro)**
Multi-step sequences, SMS (Twilio) + WhatsApp (Cloud API), segments, smart send-time, coupon/store-credit-for-review with reward tiers + fraud guard.
*Done when:* a configured drip across email/SMS/WhatsApp runs and issues a reward on approval.

**Phase 7 — Q&A + AI (Pro)**
Product Q&A (ask/answer/vote/moderate/schema); AI adapter (≥2 providers) with summary, sentiment, tagging, reply-suggest, spam score, translate — cached + async.
*Done when:* Q&A live; AI summary renders, all AI calls cached and degrade gracefully.

**Phase 8 — Widgets+, social, feeds, variations, reputation (Pro)**
Carousel/gallery/badge/wall, share + image cards, Google Shopping feed + badges, shared-variation pools, reviewer reputation/levels.
*Done when:* all widgets render as shortcode + block; feed validates; pooled aggregates correct.

**Phase 9 — Analytics, importers+, multilingual, developer (Pro) → PRO v1.0 release**
Analytics dashboard + export; ReviewX/Judge.me/Loox/Yotpo/Stamped/Site Reviews/Amazon importers; WPML/Polylang/TranslatePress; REST v2 + webhooks + CLI.
*Done when:* dashboards performant on 100k reviews; importers idempotent; API documented → **Pro v1.0**.

---

## 16. Testing Strategy

- **PHPUnit** (WP test suite) for repositories, schema calc, request idempotency, importers (round-trip), license gating.
- **Playwright/Cypress** E2E: submit review, moderate, filter/sort/paginate, email request fires (with a mailpit/MailHog catcher), Q&A flow.
- **Compatibility matrix:** PHP 7.4/8.0/8.1/8.2/8.3 × WP 6.0/latest × WC 7/latest × HPOS on/off × classic + block checkout × Elementor/Divi/Oxygen/Bricks/Breakdance smoke tests.
- **Static analysis:** PHPCS (WordPress + WooCommerce standards), PHPStan/Psalm level ≥5, ESLint.
- **Plugin Check (PCP)** must pass with zero errors before each free release.
- **Performance:** Query Monitor budget — no N+1 on product page; assets only where rendered.
- **Seed data:** WP-CLI command to generate 1k/10k/100k synthetic reviews for perf testing.

---

## 17. Coding Standards & Conventions (for the AI engineer)

- WordPress Coding Standards + WooCommerce standards; PSR-4 autoloading via Composer; namespaced classes; no global functions except a few prefixed helpers. **All WordPress.org directory rules in §12 are binding** — enforce PHPCS (`WordPress` standard) + Plugin Check in CI and fix every error before merging.
- **Every** DB write nonce- + capability-guarded; **every** query `$wpdb->prepare`'d; **every** output escaped.
- No direct file access (`defined('ABSPATH') || exit;` in every PHP file).
- Feature-flag all Pro gating through `License::can()` — no scattered conditionals.
- Use Woo CRUD/HPOS APIs for orders; never query `wp_posts` for orders.
- Templates overridable from theme (`yourtheme/ndv-reviews/...`) via a `View::locate()` resolver.
- All external calls (AI/SMS/WhatsApp) behind interfaces + adapters; user-supplied credentials; never hardcode endpoints/keys; always handle/log failures non-fatally.
- Conventional commits; one phase = one PR; AC checklist in each PR description.
- Ship `readme.txt` + `/docs` updates with every feature.

---

## 18. Deliverables Checklist

☐ `ndv-reviews` (free) — WP.org-ready, Plugin Check clean, `.pot` included, screenshots + readme.
☐ `ndv-reviews-pro` (add-on) — license-gated, update channel wired (WPLM).
☐ DB migration/installer with `dbDelta` + version upgrades.
☐ Importers (free: Woo, CSV) + exporter (CSV/JSON).
☐ Blocks + shortcodes + Elementor/Divi/Oxygen/Bricks/Breakdance integrations.
☐ Action-Scheduler request engine with log/retry/health-check.
☐ Pro: criteria+/video/incentives/automation(email+SMS+WhatsApp)/replies/Q&A/AI/widgets+/social/feeds/variations/reputation/analytics/importers+/multilingual/REST+webhooks+CLI.
☐ Test suites (PHPUnit + E2E) green on the compatibility matrix.
☐ Developer docs (hooks, filters, REST OpenAPI, template overrides) + user docs.

---

## 19. WiserReview-Inspired Additions (Plan v1.1)

New/expanded modules from the WiserReview competitive pass. Each lists **Tier**, where it slots, and **Acceptance Criteria (AC)**. Extra DB tables in §19.9.

### 19.1 QR + shareable-link collection — *Free (basic), Pro (campaigns)*
Generate a per-store and per-product **QR code + short link** that opens a prefilled review form (product preselected where applicable). Print on packaging/receipts/in-store. Pro adds tracked QR *campaigns* with source attribution.
**AC:** ☐ QR/link resolves to the form with product/order context prefilled. ☐ Works for logged-out users. ☐ Source recorded on resulting review (`qr`, `link`). ☐ Pro: per-campaign QR with scan→submit conversion stats.

### 19.2 Standalone testimonial / feedback forms — *Free (1 form), Pro (unlimited + branded)*
Hosted + embeddable (block/shortcode) forms to collect reviews/testimonials **without a WooCommerce order** — for services, landing pages, SaaS. Custom fields (text/rating/media/video), thank-you redirect.
**AC:** ☐ Build a form with custom fields and get an embed + hosted URL. ☐ Submissions land in the same moderation queue, flagged `source=form`. ☐ Free caps at 1 form; Pro unlimited + remove-fields/branding control. ☐ Spam stack (honeypot/reCAPTCHA/rate-limit) applies.

### 19.3 External review import & display — Google + Facebook — *Pro*
Connect Google Business Profile + Facebook Page (OAuth, user's own app/credentials — no middleman), import reviews on a schedule, display unified with on-site reviews, retain a verified-source badge. Optional AI auto-reply to Google reviews (BYO key).
**AC:** ☐ OAuth connect + scheduled sync via Action Scheduler. ☐ Imported reviews deduped + badged by source. ☐ Display widget can filter by source. ☐ Token refresh + failure surfaced in UI. ☐ No data routed through our servers.

### 19.4 Topic filters + AI highlight + product insights — *Free (manual tags), Pro (AI)*
Reviews grouped into **topics** shown as storefront **filter pills** ("fit", "battery", "support"). Free = manual admin tags; Pro = AI auto-topics. **AI highlight** colors key phrases inline. **AI product insights** dashboard clusters feedback by topic × sentiment × frequency.
**AC:** ☐ Topic pills filter the storefront list (AJAX). ☐ AI topics cached in `ndvr_ai_meta`, regen throttled. ☐ Highlight uses admin color settings, escapes safely. ☐ Insights dashboard performant on 100k reviews.

### 19.5 Public-review CTA / location forms — *Pro — COMPLIANT ONLY*
A post-review CTA inviting customers to also post on Google/Yelp/Facebook, plus "location" forms for multi-location/local SEO.
> **Hard rule for the engineer:** do **NOT** build review gating — i.e. do not route 5★ to public sites while diverting low ratings to a private form. That violates Google's policies and FTC/EU rules. Everyone sees the same public-review invitation regardless of rating. A private feedback option, if offered, is shown to **all** raters as an *additional* choice, never as a suppression branch.
**AC:** ☐ CTA shown identically for all ratings. ☐ No rating-conditional routing exists in code. ☐ Location forms link out with UTM, no suppression logic. ☐ Documented compliance note in admin UI.

### 19.6 Social auto-posting + feed embed — *Pro*
Schedule auto-posting of top reviews as generated image/video cards to FB/IG/X (user's own connected accounts); embed a live social feed widget.
**AC:** ☐ Connect account (OAuth, user creds), pick selection rule (e.g. new 5★ with photo), schedule via Action Scheduler. ☐ Card generated server-side + cached. ☐ Feed widget lazy-loads. ☐ Failures logged, non-fatal.

### 19.7 Bulk past-customer & CSV request campaigns — *Pro*
Upload past orders/emails (CSV) or select historical Woo orders → send batched requests across email/SMS/WhatsApp with smart rules (skip repeat buyers, skip already-reviewed, high-value-first), throttled to protect deliverability.
**AC:** ☐ CSV mapping + dedupe + suppression list. ☐ Batched/throttled via Action Scheduler with progress + cancel. ☐ Smart rules enforced. ☐ Per-campaign sent/opened/converted stats.

### 19.8 Team roles + expanded widget catalog + connectors — *Pro*
**Roles:** map to WP capabilities (Owner/Admin/Editor/Viewer) + optional "Review Manager" role with scoped caps. **Widgets:** complete the 18-20 catalog (auto-slider, video carousel, avatar carousel, nudges/inline snippet, sidebar snippet, floating popup) — each shortcode + block + builder element. **Connectors:** Klaviyo, WATI/Interakt, Zapier, generic webhooks for outbound review requests + inbound events.
**AC:** ☐ Caps enforced per role on every review action. ☐ Each new widget available as shortcode + Gutenberg block, conditionally enqueued. ☐ Each connector documented with auth + a working round-trip (trigger request / receive event).

### 19.9 Extra DB tables

```sql
-- Standalone collection forms (19.1/19.2)
CREATE TABLE ndvr_forms (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title       VARCHAR(191) NOT NULL,
  type        ENUM('product','testimonial','location','qr') DEFAULT 'testimonial',
  fields      LONGTEXT NULL,            -- JSON field schema
  settings    LONGTEXT NULL,            -- JSON (redirect, branding, locale)
  token       CHAR(32) NOT NULL,        -- public URL/QR token
  status      ENUM('active','inactive') DEFAULT 'active',
  created_at  DATETIME NOT NULL,
  UNIQUE KEY token_idx (token)
);

-- External connections: Google/Facebook/social/marketing tools (19.3/19.6/19.8)
CREATE TABLE ndvr_connections (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider    VARCHAR(40) NOT NULL,     -- google_business, facebook, instagram, x, klaviyo, wati, zapier
  account_ref VARCHAR(191) NULL,
  credentials LONGTEXT NULL,            -- encrypted token blob (never plaintext)
  meta        LONGTEXT NULL,
  status      ENUM('connected','error','disconnected') DEFAULT 'connected',
  last_sync   DATETIME NULL,
  KEY provider_idx (provider)
);

-- Outbound social/campaign jobs (19.6/19.7)
CREATE TABLE ndvr_campaigns (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type        ENUM('bulk_request','social_post') NOT NULL,
  config      LONGTEXT NULL,            -- JSON rules/selection/schedule
  status      ENUM('draft','running','done','cancelled') DEFAULT 'draft',
  stats       LONGTEXT NULL,            -- JSON (sent/opened/converted/posted)
  created_at  DATETIME NOT NULL
);
```
> Store all OAuth tokens encrypted at rest (e.g. libsodium / `sodium_crypto_secretbox` with a key in `wp-config.php`), never in plaintext. Mark `_ndvr_source` comment meta for every review (`onsite`, `qr`, `form`, `google`, `facebook`, `import`).

### 19.10 Phase placement (extends §15)

- **Phase 4 (free):** QR + shareable link (19.1 basic), 1 standalone testimonial form (19.2), manual topic pills (19.4 free).
- **Phase 6 (Pro automation):** bulk/CSV past-customer campaigns + smart rules (19.7), QR campaigns, multi-language email templates, Klaviyo/WATI/Zapier connectors (19.8 connectors).
- **Phase 7 (Pro AI):** AI topics → storefront filters, AI highlight, AI product insights, AI auto-reply (19.4 Pro).
- **Phase 8 (Pro widgets/social):** complete 18-20 widget catalog (19.8), social auto-posting + feed embed (19.6), public-review CTA / location forms (19.5 — compliant).
- **Phase 9 (Pro importers/dev):** Google Business + Facebook import & display (19.3), team roles (19.8), product grouping (arbitrary groups).

---

## 20. Elementor & Loop Builder Compatibility (Plan v1.2)

First-class Elementor support is a **Phase 4 (free)** requirement — many stores build their PDP and product grids with Elementor Theme Builder + Loop Builder, so review widgets must work there, not just on the default Woo template.

### 20.1 Native widgets — *Free*
Register via `elementor/widgets/register` (with back-compat fallback to `widgets_registered`) into a **"NDV Reviews"** widget category. Each widget exposes Controls-API style controls (star color/size, typography, gap, alignment, layout) and content controls (source: current product / specific ID / category / all; counts; filters).

Widgets: **Star Rating** (aggregate stars + count), **Review Summary** (avg + distribution + criteria bars), **Review List**, **Review Form**, **Review Section** (all-in-one — the analog to ReviewX's "Product Data Tabs" widget, for Theme Builder Single Product templates where native Woo tabs are removed). Pro adds: **Carousel, UGC Gallery, Floating Badge, Wall, Q&A, Avatar/Video carousel, Nudges/Inline snippet**.
**AC:** ☐ Widgets appear under the NDV Reviews category. ☐ Style + content controls work and live-update in the editor. ☐ Review Section renders the full review experience inside a Theme Builder Single Product template.

### 20.2 Dynamic Tags — *Free (this is what powers Loop Items)*
Register via `elementor/dynamic_tags/register`: **Product Rating Value** (`NUMBER_CATEGORY`), **Product Review Count** (`NUMBER_CATEGORY`), **Star Rating HTML** (`TEXT_CATEGORY` / `POST_META_CATEGORY`). These let a product's rating be bound onto *any* Elementor element inside a Loop Item template — not only our widgets (e.g. bind count into a Heading, or stars into an HTML/Icon element).
**AC:** ☐ All three tags selectable in dynamic-content pickers. ☐ Each resolves the correct value for the current loop product. ☐ Tags return safe, escaped output.

### 20.3 Loop context resolution (Loop Grid / Loop Carousel / Archive) — *Free*
Inside a loop item there is no `is_product()` / queried object — each iteration sets the current post. Resolve product with `wc_get_product( get_the_ID() )` from the active loop context (fall back to `global $product`, then the queried object on single PDP). Must work in: **Elementor Pro Loop Grid, Loop Carousel, Theme Builder Single Product, and Archive/Shop templates**.
**AC:** ☐ A Loop Grid of N products shows the correct per-card rating for each. ☐ Works in Loop Carousel and Archive templates. ☐ No leakage of one product's rating onto another card.

### 20.4 Editor & loop preview handling — *Free*
Detect editor/preview via `\Elementor\Plugin::instance()->editor->is_edit_mode()` and resolve the loop preview's sample product (Elementor sets a sample post when designing a Loop Item). When no real product context exists, render representative sample stars so the widget never appears empty or throws in the editor.
**AC:** ☐ Widgets render sample data in the editor and in the Loop Item designer. ☐ No PHP notices/fatals when editing a template with no product context. ☐ Live preview updates as controls change.

### 20.5 Schema safety in loops — *Free (correctness-critical)*
Only the **single-product context** emits `AggregateRating` / `Review` JSON-LD. Star widgets and dynamic tags inside **loop/archive grids are visual-only and emit NO per-item schema** — repeating AggregateRating per card produces duplicate/invalid markup that Google flags. Centralize a `is_single_product_context()` guard the schema module checks before output.
**AC:** ☐ A page with a 24-product loop grid emits zero extra AggregateRating blocks. ☐ Single PDP still emits exactly one valid AggregateRating. ☐ Validates in Google Rich Results test in both cases.

### 20.6 Performance & asset loading in Elementor — *Free*
Loop widgets/tags read the **cached** aggregate (`_ndvr_overall_rating`, synced with `_wc_average_rating`) — never run per-card rating queries (no N+1 in grids). Enqueue assets once via `elementor/frontend/before_render` (and `elementor/preview/enqueue_styles` for the editor); a render flag prevents duplicate enqueues across many widgets on one page.
**AC:** ☐ Query Monitor shows no per-card rating queries on a loop grid. ☐ Plugin CSS/JS enqueue once regardless of widget count. ☐ Editor styles load so widgets are styled while designing.

### 20.7 Dependency notes — *Free*
Our widgets require **Elementor (free)**. **Loop Builder (Loop Grid/Carousel) is an Elementor _Pro_ feature** — the user's Pro, not ours; we only plug into it. Theme Builder (Single Product / Archive templates) is also Elementor Pro. Document this so loop/theme-builder expectations are clear. Widgets must degrade gracefully (and our shortcodes/blocks remain available) if only Elementor free is installed.
**AC:** ☐ Widgets load with Elementor free; loop-specific docs state Elementor Pro is required for Loop/Theme Builder. ☐ No fatal if Elementor Pro is absent. ☐ Shortcode/block equivalents cover every Elementor widget for non-Elementor users.

---

## 21. Tokenized Review-Collection Links & Email-Platform Integrations (Plan v1.3)

This is the **collection backbone**: a single signed link a customer opens to review **one or more products from their purchase**, with no login required — usable from WooCommerce's own emails *and* from any external email platform (Klaviyo, Mailchimp, Brevo, and others). Verified-buyer status is proven by the token, so reviews collected this way are trusted.

### 21.1 The two link types — *Free*
1. **Per-order token link** — `/{review-base}/?k={token}`. Token = HMAC-SHA256-signed payload `{order_id, email, exp, nonce}` (secret in `wp-config.php`). Opens a page listing **all reviewable line items in that order**. Used by transactional/event channels (WooCommerce email, Klaviyo events, Brevo transactional/automation).
2. **Customer magic link** — `/{review-base}/?c={token}`. Resolves to the customer (by user ID/email) and lists **all unreviewed purchases across their orders**. Used by list/merge-field channels (Mailchimp), where the stored value is per-contact, not per-order.

Both: signed + optionally expiring, configurable single-use vs multi-use, rate-limited, revocable. The token *is* the auth — no login — but if the visitor is logged in we additionally attribute to their account.
**AC:** ☐ Tokens verify via HMAC + expiry; tampered/expired tokens show a safe "request a fresh link" page. ☐ Per-order link shows exactly that order's reviewable items; customer link shows all unreviewed items. ☐ Single-use/expiry/revocation enforced server-side. ☐ No PII leaks in the URL beyond the opaque token.

### 21.2 Multi-product review landing page — *Free*
The page opens with **each product's details** (image, name, variation, order date) and **enabled review fields per product** (criteria/stars, title, body, photo; video in Pro; recommend). The customer can **review several products in one session** — submit all at once or one at a time — with progress saved so a returning visitor sees only what's left. Mobile-first, lightweight (own template, minimal theme dependencies), full anti-spam stack, dedupe (no double review per order+product), verified-buyer auto-set.
**AC:** ☐ Renders N product blocks from the token with independent forms. ☐ Submit-all and submit-one both work via AJAX. ☐ Progress persists across visits; completed items hidden/locked. ☐ Reviews enter the normal moderation queue, flagged `source=magic_link`, verified=1. ☐ Honeypot + reCAPTCHA + rate-limit + nonce enforced. ☐ Works for guests and logged-in users.

### 21.3 WooCommerce-native distribution — *Free*
On configurable order status (default **completed**) generate the token and either (a) send our own review-request email via Action Scheduler, or (b) inject a `{ndvr_review_link}` placeholder / auto-appended CTA block into WooCommerce's own completed-order email. Reuses the §8 reliability engine (queue, log, retry, test-send, unsubscribe).
**AC:** ☐ Token link appears in the chosen email and resolves correctly. ☐ `{ndvr_review_link}` placeholder usable in Woo email templates. ☐ Fires reliably via Action Scheduler with log/retry. ☐ One request per order (idempotent).

### 21.4 Klaviyo integration — *Pro*
**Mechanism (verified):** on order complete, POST a **custom event** to Klaviyo's **Events API** (store's private key) — metric e.g. `Review Request`, profile = customer email, properties: `review_url` (per-order token link), `order_id`, and an `items` array `[{name, image, product_url}]`. Idempotency via `unique_id = order_id`. The merchant builds a Klaviyo **Flow** triggered by the `Review Request` metric and references **`{{ event.review_url }}`** (and can build a repeating block over **`{{ event.items }}`**). Optionally also settable as a profile property for campaign use.
**AC:** ☐ Event posts with `review_url` + `items`; visible in Klaviyo preview as `{{ event.review_url }}`. ☐ Idempotent (no duplicate events per order). ☐ Admin UI shows the exact merge tag to paste. ☐ Works with the user's own Klaviyo key; failures logged non-fatally.

### 21.5 Mailchimp integration — *Pro*
**Mechanism (verified):** upsert the audience member via the **Marketing API** (`PUT /lists/{list}/members/{hash}`) setting a **merge field** `merge_fields: { RVWURL: <customer magic link> }`; auto-create the merge field if missing. Merchant references **`*|RVWURL|*`** in a campaign / classic automation / Customer Journey email. Optionally POST a member **event** (`/members/{hash}/events`) to trigger a Journey.
> **Honest caveat (build around it):** Mailchimp merge fields are **per-contact and overwritten on each new order** — so for Mailchimp we store the **customer magic link** (resolves all unreviewed purchases), *not* a per-order link. This keeps it correct when a customer buys repeatedly. Document this clearly.
**AC:** ☐ Merge field created + populated with the customer magic link. ☐ `*|RVWURL|*` renders a working link in a test send. ☐ Optional Journey event fires. ☐ Repeat purchases don't produce a stale/incorrect link (magic link still resolves remaining items).

### 21.6 Brevo integration — *Pro*
**Mechanism (verified) — two supported paths:**
- **Transactional API** (`POST /v3/smtp/email`) with `templateId` + `params: { REVIEW_URL, items }`; the Brevo template uses **`{{ params.REVIEW_URL }}`** and a dynamic block over `items`. Per-order precise — preferred.
- **Event-triggered automation / contact attribute**: send a custom **event** (Track API) to trigger an automation, or set a **contact attribute** `REVIEW_URL` referenced as **`{{ contact.REVIEW_URL }}`** for campaigns. Auto-create the attribute/template guidance in admin.
**AC:** ☐ Transactional send renders `{{ params.REVIEW_URL }}`. ☐ Contact-attribute path renders `{{ contact.REVIEW_URL }}` in a campaign. ☐ Uses the user's own Brevo key; SMTP/automation choice documented. ☐ Failures logged non-fatally.

### 21.7 Universal fallback — any other ESP (ActiveCampaign, Omnisend, MailerLite, GetResponse, HubSpot, Drip, etc.) — *Pro*
So the link works **everywhere**, provide three generic mechanisms:
1. **Outbound webhook** — on order complete, POST `{ email, order_id, review_url, customer_magic_link, items[] }` to a user-defined webhook / **Zapier / Make** catch hook → map into any ESP field or trigger.
2. **Generic field-sync mapping** — "set custom field `X` = review link" via a configurable API connector (base URL + auth header + JSON path), reusing the `ndvr_connections` adapter pattern.
3. **Contact-attribute push** — push the customer magic link into a named contact field the ESP can render with its own email merge tag.
**AC:** ☐ Webhook fires with full payload + HMAC signature; works end-to-end through Zapier/Make. ☐ Generic field-sync maps the link into at least one non-built-in ESP in testing. ☐ Documented recipe for the top 3 "other" ESPs.

### 21.8 Where the link goes in each platform (reference)

| Platform | Trigger | How the link is exposed | Tier |
|---|---|---|---|
| WooCommerce email | order completed | `{ndvr_review_link}` placeholder / auto-CTA, or our own email | Free |
| Klaviyo | custom event (Events API) | `{{ event.review_url }}` + `{{ event.items }}` in a Flow | Pro |
| Mailchimp | merge field upsert (+ optional event) | `*|RVWURL|*` (customer magic link) in campaign/Journey | Pro |
| Brevo | transactional API or event | `{{ params.REVIEW_URL }}` or `{{ contact.REVIEW_URL }}` | Pro |
| Zapier / Make / other | outbound webhook / field-sync | mapped field `review_url` / `customer_magic_link` | Pro |

### 21.9 Architecture & DB

New module `Collection/MagicLink` (token sign/verify, landing controller, multi-product form) + module `Esp/` with a base `EspProvider` interface and adapters: `WooNative`, `Klaviyo`, `Mailchimp`, `Brevo`, `Webhook`, `GenericField`. Each implements `connect()`, `ensureFields()`, `pushReviewLink( $order, $links, $items )`. ESP credentials live in `ndvr_connections` (§19.9), encrypted. Scheduling/logging reuse `ndvr_requests` + Action Scheduler. Token state in a new table:

```sql
CREATE TABLE ndvr_review_tokens (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type          ENUM('order','customer') NOT NULL,
  order_id      BIGINT UNSIGNED NULL,
  customer_id   BIGINT UNSIGNED NULL,
  email_hash    CHAR(64) NOT NULL,          -- HMAC of email, not plaintext
  token_hash    CHAR(64) NOT NULL,          -- store hash, validate by recompute
  products      LONGTEXT NULL,              -- JSON: reviewable product IDs + status
  status        ENUM('active','used','revoked','expired') DEFAULT 'active',
  expires_at    DATETIME NULL,
  used_at       DATETIME NULL,
  created_at    DATETIME NOT NULL,
  UNIQUE KEY token_idx (token_hash),
  KEY order_idx (order_id),
  KEY customer_idx (customer_id)
);
```
**Security:** sign with a secret from `wp-config.php` (not the DB); store only token **hashes**; never put email/order in the querystring in cleartext; short default expiry (e.g. 60 days) with a "request fresh link" recovery flow; rate-limit the landing endpoint; all ESP API keys encrypted at rest.

### 21.10 Phase placement (extends §15)
- **Phase 3 (free):** token engine (21.1), multi-product landing page (21.2), WooCommerce-native distribution (21.3) — built alongside the reliability engine (§8).
- **Phase 6 (Pro automation):** Klaviyo (21.4), Mailchimp (21.5), Brevo (21.6), universal webhook/field-sync fallback (21.7), exposed as part of the connectors module (§19.8).

---

## 22. Reviews Marquee + Traditional Widgets (Plan v1.3)

### 22.1 Reviews Marquee (Magic UI-style) — *Free (basic), Pro (advanced)*
A smooth, infinite-scroll marquee of review cards — the Magic UI marquee pattern, **reimplemented in vanilla CSS/JS** (no React/Tailwind/Framer dependency) so it's light and theme-safe. Implementation: render the card set, **duplicate it `repeat` times**, animate with CSS `@keyframes` translating `calc(-100% - var(--gap))`, loop seamlessly; expose `--duration` (speed) and `--gap` as controls; pause via `animation-play-state: paused` on hover.

Controls: direction **horizontal/vertical**, **reverse**, **speed** (`--duration`), **gap**, **pauseOnHover**, **repeat**, **gradient fade edges** (edge color bound to theme/background), **rows** (1, or 2+ rows scrolling in opposite directions like the Magic UI testimonials demo), card style, and **source filters** (all / product / category / **min-rating** / with-media / verified-only). Card = avatar, name, star/criteria rating, body, optional photo/video, verified badge.

**Tier split:** Free = single-row horizontal marquee, pause-on-hover, speed/gap, basic card. Pro = vertical + multi-row opposite-direction, gradient edges, **video review cards**, advanced source filters, custom card templating.

**Accessibility (required):** honor `prefers-reduced-motion` (pause/disable animation, offer a static list); marquee is a labeled list (`aria-label`, e.g. "Customer reviews"); keyboard users can pause; never put critical-only info solely in motion.

**Delivery:** available as **shortcode `[ndvr-marquee]`**, **Gutenberg block** (NDV Reviews: Reviews Marquee), **Elementor widget** (per §20, with editor preview + conditional asset loading), and **classic widget** (per §22.2). Lazy-load media; assets enqueue once.
**AC:** ☐ Seamless infinite loop with no visible jump at the wrap point. ☐ Pause-on-hover + `prefers-reduced-motion` both work. ☐ Horizontal + vertical + multi-row render correctly and are responsive. ☐ Gradient edges match background. ☐ Source filters return the right reviews. ☐ Renders as shortcode, block, Elementor widget, and classic widget from one underlying component.

### 22.2 Traditional (classic) widgets — *Free*
Beyond blocks/Elementor, register **classic `WP_Widget` widgets** (Appearance → Widgets / legacy sidebars and any theme widget area) so non-block, non-Elementor themes are fully supported. Classic widgets to ship: **Star Rating/Summary**, **Recent Reviews list**, **Reviews Marquee**, **Rating Badge**, **Top-Rated Products**. Each maps to the same renderer as its block/shortcode counterpart (single source of truth) and respects conditional asset loading.
**AC:** ☐ Widgets appear in the classic Widgets screen and render in sidebars/footers. ☐ Each shares logic with its block/shortcode equivalent (no duplicated rendering code). ☐ Configurable (source, count, style) via standard widget form. ☐ Assets load only where a widget renders.

### 22.3 Phase placement
- **Phase 4 (free):** basic marquee (single-row) + all classic widgets.
- **Phase 8 (Pro widgets):** advanced marquee (vertical/multi-row/gradient/video/filters), added to the §7.12 / §19.8 widget catalog.

> Add `[ndvr-marquee]` (shortcode), **NDV Reviews: Reviews Marquee** (block), an **Elementor** widget, and a **classic widget** to the §9 catalog. The marquee is a flagship social-proof piece — make it the showcase widget on the plugin's demo page.

---

- Hand Claude Code **one phase at a time** (§15). Paste the phase + its features' AC from §7. Require the AC checklist to pass before moving on.
- Keep the free/Pro separation strict from Phase 0 — retrofitting it later is expensive.
- The two highest-ROI differentiators to nail first: **(a) reminder reliability** (§8) and **(b) no-forced-account self-hosting** (§5.2). Those alone win switchers from ReviewX.
- Against WiserReview, the wedge is **no metering + own-your-data** (§1.1). Never write code that caps reviews, requests, or storage, and keep all AI/SMS/social on the user's own credentials.
- The §19 modules are how we match WiserReview's breadth — build them in their phase slots (§19.10), and respect the **no-review-gating compliance rule** in §19.5.
- The **tokenized review-collection link** (§21) is the collection backbone: build the token engine + multi-product landing in Phase 3 (free), then the ESP adapters (Klaviyo/Mailchimp/Brevo/webhook) in Phase 6. Each ESP's exact merge tag is in the §21.8 table — surface it in the admin UI so merchants can copy-paste.
- The **Reviews Marquee** (§22) is the flagship social-proof widget — ship a basic version free in Phase 4, advanced in Phase 8, as shortcode + block + Elementor + classic widget from one renderer.
- **WordPress.org code compliance (§12) is a hard gate, not a final-polish step.** Wire Plugin Check (PCP) + PHPCS (`WordPress` standard) into CI in Phase 0 and end every phase with a zero-error run on the code added. The big rejection triggers to avoid from day one: any remote-loaded/executable code in the free plugin, default tracking/phone-home, "WooCommerce/WordPress" in the slug or name, trialware-style crippling, and unescaped output. Our self-hosted, no-account, no-external-calls-by-default posture is what makes review painless — don't undermine it.