# CLAUDE.md — NDV Reviews (free) system constraints

> **This is the constraint set, not background reading.** Read it before touching code. `AGENTS.md` defines
> *how the team works*; this file defines *what already exists* and *what you must not break*.

## Project intent (original brief — preserved)
Private git for Pro, public repo for the free plugin; a cool readme. Do **not** implement licensing on the
Pro plugin during testing — licensing comes after the build is complete. Build phase by phase and keep
context/docs alongside so nothing is missed (md for LLM-readability). **The UI must be modern — not the old
WordPress look.**

---

## 1. What this plugin is
`ndv-reviews` — a self-hosted WooCommerce reviews plugin (multi-criteria ratings, photo reviews, verified-
buyer badge, working reminder queue, rich schema). Free tier is fully functional with **zero external
calls** by default. Destined for the WordPress.org directory. The paid add-on lives in the separate private
repo `ndv-reviews-pro` and extends this plugin **only through documented hooks**.

## 2. Architecture (how it boots)
- Entry `ndv-reviews.php`: defines the `NDVR_*` constants, registers a PSR-4 autoloader
  (`NdvReviews\ → includes/`), activation/deactivation hooks at file scope, the `before_woocommerce_init`
  HPOS + cart/checkout-blocks compat declaration, then boots `NdvReviews\Plugin` on `plugins_loaded` (only
  when WooCommerce is present; otherwise an admin notice, no fatal).
- `NdvReviews\Plugin` is a **DI container + service registry**. Services are pulled with
  `Plugin::instance()->container()->get('<id>')` — key ids: `review_query`, `widgets`, `settings`,
  `mailer`, `scheduler`, `token_repository`, `elementor_module`. Services implement
  `Support\Registerable::register()`. The registry is filterable via `ndv-reviews/services`.
- Pro boots on the `do_action('ndv-reviews/loaded', $plugin)` at the end of `Plugin::boot()`.

## 3. Shared layer (touch = auto-escalate, notify Pro)
- **Reviews:** `Reviews\ReviewRepository` (create/validate/save media — fires `review_created`,
  `should_approve`, `validate_review`, `review_media_status`), `Reviews\ReviewQuery` (`paginate()` +
  `to_view()` view-model + `review_items`/`review_author`/`review_query_args`), `Reviews\RatingCache` +
  `Reviews\AggregateStore` (Woo aggregate sync — the single source of truth for the product average),
  `Reviews\Pool` (`review_pool_id`), `Reviews\Criteria`/`CriteriaRepository`, `Reviews\PostTypes`
  (`reviewable_post_types`), `Reviews\Votes` (helpful vote + dedup), `Reviews\ReviewTags`.
- **Forms:** `Forms\ReviewForm`, `Forms\TestimonialForm`, `Forms\AntiSpam` (nonce + honeypot + per-IP
  rate-limit + opt-in reCAPTCHA), `Forms\Upload`.
- **Display:** `Display\Renderer` (Woo reviews tab + AJAX list `ndvr_list_reviews`), `Display\Widgets`
  (shared render used by shortcodes/blocks/classic widgets/Elementor — `stars`/`summary`/`reviews`/
  `marquee`), `Display\Html::stars()` (`stars_html` filter — Pro swaps hearts/emoji), `Display\Summary`,
  `Display\Design` (accent/rating-icon/typography inline CSS).
- **Support:** `Support\Db::table()` (the ONLY way to name a `ndvr_` table), `Support\View` (theme-
  overridable templates in `templates/`, `yourtheme/ndv-reviews/*`), `Support\Autoloader`, `Support\Settings`.
- **Integrations:** `Integrations\Shortcodes`, `Integrations\Blocks`, `Integrations\ClassicWidgets`,
  `Integrations\Elementor\Module` (registers the `ndv-reviews` category, widgets, dynamic tags, and the
  loop-safe `Module::current_product_id()`/`is_edit_mode()` statics Pro reuses).
- **Other:** `Schema\JsonLd` (Product/AggregateRating/Review + duplicate-avoidance), `Requests\*` +
  `Collection\*` (reminder queue on Action Scheduler group `ndv-reviews`, tokenized no-login collection
  landing), `Moderation\*`, `Privacy\Privacy` (GDPR export/erase), `Installer`/`Activator`/`Deactivator`.

The full public surface (hooks, options, meta, tables, AJAX, shortcodes, handles, constants) is enumerated
in `/.agents/CONTRACTS.md` — **that file is canonical.**

## 4. Landmines (things that look like cleanups but are data loss / breakage)
- **Never rename `ndvr_*` / `ndv_reviews_*` / `ndv-reviews/*`.** The rebrand deliberately kept the internal
  prefix; options, tables, meta, nonces, hooks, CSS classes and the Pro add-on all key off it.
- **Never persist a 0-rating review.** `RatingCache::recalc_review()` writes the `rating` meta only when
  `> 0`; a rating-less review is displayed but silently excluded from the Woo average — require a rating on
  submit (known open defect B1 in `PRODUCTION-PLAN.md`).
- **Reviews are `WP_Comment`s**, not posts — Elementor's post-query Loop Grid can't iterate them; Pro
  renders review loops itself via its own context.
- **`extract()` is banned** (WP.org) — `Support\View::render()` expands vars manually. Don't reintroduce it.
- **Reminder actions are Action Scheduler** (`ndvr_send_request`, group `ndv-reviews`), not `wp_schedule_
  event` — the `Deactivator` currently clears the wrong hook (open defect).
- Woo-compat duplicate meta is intentional: `rating`/`verified` alongside `_ndvr_overall_rating`/
  `_ndvr_verified`. Don't "dedupe" them.

## 5. Conventions
- `ndvr_` table prefix via `Db::table()`; PSR-4 `NdvReviews\`; DI container for services; `Registerable`
  interface; `phpcs:ignore` only with a stated reason; text domain literal `'ndv-reviews'`, Domain Path
  `/languages`; every file guarded by `defined('ABSPATH')`. Match the numbered/section style of neighbours.
- Version lives in **two places that must match**: the plugin header `Version:` and `NDVR_VERSION`.

## 6. Where work is tracked
`/.agents/TASKS.md` (board), `/.agents/CONTRACTS.md` (canonical contracts), `/.agents/CONTEXT.md`
(decisions), `/.agents/LOG.md` (append-only). Standing backlog + audit findings live in `PRODUCTION-PLAN.md`.
These dev docs are `export-ignore`d from the WP.org zip.
