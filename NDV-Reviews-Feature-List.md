# NDV Reviews — Master Feature List (Free vs Pro)

Consolidated from the competitor analysis of **ReviewX** and **WiserReview** plus the gap features neither offers. This is the at-a-glance feature catalog; full engineering specs, acceptance criteria, and architecture live in the build plan (`NDV-Reviews-Build-Plan.md`).

**Legend**
- **[NEW]** — differentiator **neither ReviewX nor WiserReview** offers (our edge).
- **[EDGE]** — we beat a competitor on it: free where they lock it behind paid, or we fix a known failure of theirs.
- *(untagged)* — parity feature we match because customers expect it.

**Guiding principles baked into every feature:** self-hosted (data never leaves the store), **no metering** (unlimited reviews/requests/storage), no forced account, BYO-key for AI/SMS (no markup), and WordPress.org code-compliant.

---

## FREE (WordPress.org plugin)

### Core reviews
- Multi-criteria ratings — **up to 3 criteria** (e.g. Quality / Value / Service)
- Star rating + optional review title + body
- Recommendation field (Recommended / Neutral / Not recommended)
- **Photo uploads** with reviews
- **Verified-buyer badge** (matched to a completed order)
- Helpful **voting** (thumbs up) on reviews
- Reviews on **custom post types** (not just products)
- **[EDGE]** Full review moderation **including Edit** — approve / unapprove / spam / trash / **edit** *(ReviewX locks Edit behind Pro)*

### Collection & reminders
- **[EDGE]** Reliable **email review-reminder** on order status — Action Scheduler queue, test-send, delivery **log + retry**, unsubscribe *(fixes ReviewX's long-broken reminders)*
- **Tokenized multi-product review link (no login)** — one signed link to review one or more products from an order; sent via WooCommerce's completed-order email; works for guests and account holders
- **QR code + shareable review-form link** (basic) — opens a prefilled review form
- **Standalone testimonial form** (1) — collect a review without a WooCommerce order

### Display & widgets
- Aggregate **summary** (average, total, star distribution, recommend %, verified count)
- Per-criterion **rating bar graphs**
- **Filtering & sorting** — most recent / highest / lowest / with photos / verified
- 2 display templates + basic color & typography options
- Topic **filter pills** (manual tags)
- **[NEW]** **Reviews Marquee** (basic single-row, Magic UI-style infinite scroll, pause-on-hover)
- **Traditional/classic widgets** (`WP_Widget`) for sidebars & footers — Star Rating, Recent Reviews, Marquee, Badge, Top-Rated

### Builders & compatibility
- **Gutenberg blocks** + **shortcodes** for every core widget (list, summary, stars, form, marquee)
- **Elementor** native widgets + **dynamic tags** with **Theme Builder + Loop Builder (Loop Item)** support — core widgets/tags free
- **Divi / Oxygen / Bricks / Breakdance** via shortcodes/elements
- **HPOS** + **Cart/Checkout Blocks** compatible
- Conditional asset loading (assets enqueue only where a widget renders)

### Trust, SEO & anti-spam
- **Google rich schema** — Product + AggregateRating + Review (duplicate-schema safe)
- **reCAPTCHA v3 + honeypot + rate-limiting** anti-spam stack

### Import / export & privacy
- Import from **native WooCommerce reviews** and **CSV**
- **[EDGE]** **Export** to CSV / JSON — no lock-in
- **GDPR** consent checkbox + WP data-export / erasure hooks
- **[NEW]** Fully self-hosted, **no forced account/email**, **no metering** of reviews/requests/storage

---

## PRO (license-gated add-on)

### Criteria & media
- **Unlimited** review criteria + **per-category criteria templates**
- **Video reviews** (upload or link)
- Rating styles — **stars / hearts / emoji / thumbs**

### Incentives
- **Coupon or store-credit for a review** — auto-generated, auto-expiring, single-use
- **Media-tiered rewards** (higher reward for photo/video) + fraud guard (one reward per order, approved reviews only)

### Automation & multi-channel collection
- **Multi-step review-request sequences** — **email + SMS + WhatsApp**
- **Drip timing + smart send-time** + segment by product / category / customer
- **Smart rules** — skip repeat buyers, skip already-reviewed, prioritize high-value orders
- **Bulk past-customer / CSV request campaigns**
- **Tracked QR campaigns** + **multiple / branded testimonial + location forms**
- **Multi-language email templates** (per customer locale) + custom from-domain (SMTP/DKIM guidance)

### Review-link distribution to email platforms
- **WooCommerce** native (built-in, also in Free)
- **Klaviyo** — custom event → `{{ event.review_url }}` in a Flow
- **Mailchimp** — merge field `*|RVWURL|*` (customer magic link)
- **Brevo** — transactional `{{ params.REVIEW_URL }}` or campaign `{{ contact.REVIEW_URL }}`
- **[NEW]** **Universal fallback** — outbound webhook / Zapier / Make / generic field-sync so the link works in **any** ESP (ActiveCampaign, Omnisend, MailerLite, HubSpot, etc.)

### Replies
- **Admin reply** with custom logo + **saved-reply templates** + **bulk reply**
- **[NEW]** **AI suggested replies** + optional AI auto-reply to Google reviews

### Q&A
- **[NEW]** **Product Q&A** — customer questions + merchant/community answers, voting, moderation, QAPage schema

### AI suite (BYO API key — OpenAI / Anthropic / Gemini)
- **[NEW]** AI **review summary** ("Customers say…")
- **[NEW]** **Sentiment** scoring (with auto-publish rule)
- **[NEW]** **Auto-tagging / keywords**
- **[NEW]** **Smart topics → shopper-facing topic filters**
- **[NEW]** **AI highlight** — key-phrase highlighting inline
- **[NEW]** **AI product-review insights** — topic × sentiment × frequency clustering
- **[NEW]** **Fake-review / spam + profanity scoring**
- **[NEW]** One-click **review translation**
- **[NEW]** **AI widget-style generator** (brand-matched styling)

### External reviews
- **[NEW]** Import & display **Google Business Profile + Facebook Page reviews** (verified-source badge retained, unified with on-site reviews)

### Display & widgets+
- **Full 18–20 widget catalog** — product review section, star rating, UGC photo gallery, carousel, **auto-slider**, **video carousel**, **avatar carousel**, **nudges / inline snippet**, **sidebar snippet**, floating badge, **floating popup**, trust badge, site-wide all-reviews page, **wall of love**, Q&A
- **[NEW]** **Reviews Marquee (advanced)** — vertical + multi-row opposite-direction, gradient fade edges, video review cards, advanced source filters
- **Anonymous reviews**, **highlight / pin review**, reviewer **country flag**, reviewer **reputation / levels**

### Social
- Share buttons + auto-generated **shareable review image/video cards**
- **[NEW]** **Scheduled auto-posting** of best reviews to FB / IG / X
- **[NEW]** **Live social-feed embed**

### SEO & feeds
- **Google Shopping product-review feed (XML / PLAs)**
- **Google / Trustpilot badge widgets**

### Product relationships
- **Shared reviews across variations / grouped / bundles** + **[NEW]** arbitrary product groups

### Moderation+
- **Auto-approve rules** (e.g. auto-approve verified 4★+, hold the rest)
- **Low-rating admin alerts**
- **Profanity / banned-word filter** + image-moderation hook

### Public-review CTA
- **[EDGE]** **Compliant** public-review CTA / location forms — everyone asked the same way, **no review gating** *(WiserReview-style funnels that suppress negatives violate Google/FTC rules — we don't)*

### Analytics
- **Advanced dashboard** — review rate, **conversion lift**, sentiment trend, criteria heatmap, topic/insight clustering, **request→review funnel**, CSV export

### Importers+
- Import from **ReviewX, Judge.me, Loox, Yotpo, Stamped, Site Reviews, Amazon / AliExpress, Google Business, Facebook**

### Team, developer & i18n
- **Team roles** — Owner / Admin / Editor / Viewer + optional Review-Manager role
- **Marketing-tool connectors** — Klaviyo, WATI / Interakt, Zapier, generic webhooks
- **[NEW]** **REST API v2 + webhooks + WP-CLI**
- **WPML / Polylang / TranslatePress** deep integration + auto-translation
- **Priority support**

### Agency add-on (optional, later)
- Multisite / network control
- White-label branding + client license bundle
- **[NEW]** **Cross-store review sync** (Store A shows Store B's reviews, each keeps its own data)

---

## Headline differentiators (why a store switches to us)
1. **Reliable reminders** — the thing ReviewX has failed at for years.
2. **No forced account, fully self-hosted** — ReviewX makes you sign up; we don't.
3. **No metering, own your data** — WiserReview caps reviews/credits in the cloud; we never meter.
4. **Tokenized multi-product review link** that drops into WooCommerce, Klaviyo, Mailchimp, Brevo, or any ESP.
5. **AI suite on your own API key** — summaries, topics, highlights, insights, translation — no per-credit markup.
6. **Product Q&A** — neither competitor offers it.
7. **Magic UI-style Reviews Marquee** as the flagship social-proof widget.
8. **Elementor Loop Builder + dynamic tags** done correctly (right rating per card, schema-safe grids).
9. **Honest by design** — no review gating, WordPress.org code-compliant, no lock-in (full export).
