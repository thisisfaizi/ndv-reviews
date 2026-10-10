# PRD-00: rules every feature PRD inherits

Every PRD in `rosette-reviews/.agents/prd/` and `rosette-reviews-pro/.agents/prd/` follows this file. A PRD only states where it **differs** or **adds**. The reviewer rejects a PRD that contradicts anything here.

Sources of truth: free `CLAUDE.md`, `AGENTS.md` §0 and §3, `.agents/CONTRACTS.md`; Pro `CLAUDE.md` and `AGENTS.md`.

## 1. Template (every PRD has these sections)
1. **Problem and who it's for.** One paragraph, and which competitors have it.
2. **Scope / non-goals.**
3. **User experience.** Admin screens, storefront and email, with exact labels (copy in the brand voice: plain, specific, no hype).
4. **Reuse map.** The existing classes it builds on, from the free CLAUDE.md §3 and Pro CLAUDE.md §3–4.
5. **Blast radius.** Every module, both plugins, that reads or calls what changes.
6. **Contract delta.** New or changed hooks, options, setting keys, meta, tables, AJAX actions, nonces, handles, shortcodes, Action Scheduler hooks, endpoints. Exact names, frozen prefixes.
7. **Storage and upgrade.** Shape, where it lives, the `NDVR_DB_VERSION` routine if any, and what an old site sees.
8. **Security.** Every entry point with its nonce, capability, sanitize and escape.
9. **Privacy.** New personal data goes into the exporter, the eraser, opt-in uninstall and the readme privacy notes.
10. **Performance and assets.**
11. **Compatibility.** HPOS, block checkout, PHP 7.4, WP 6.0, WC 8.0, Elementor, theme overrides, Pro absent or present.
12. **Acceptance criteria.** Numbered and testable in Playground.
13. **Test plan.** The harness steps.
14. **Open questions.** Must be empty for `prd-ok`.

## 2. Hard rules (from the repos, restated so PRDs can cite "PRD-00 §2.x")
1. **Frozen prefixes.**
   - Options: `ndv_reviews_*`; free settings keys go inside `ndv_reviews_settings`, Pro's inside `ndv_reviews_pro_settings` (merge, never replace).
   - Tables: `ndvr_` via `Db::table()`. Meta: `_ndvr_*`.
   - Hooks: `ndv-reviews/*` (free), `ndv-reviews-pro/*` (Pro).
   - AJAX, nonces and handles: `ndvr_*`. Action Scheduler: group `ndv-reviews`, hooks `ndvr_*`.
   - Text domains: `'rosette-reviews'` and `'rosette-reviews-pro'`, as literals.
2. **Entry points.**
   - Every admin POST and AJAX handler checks the nonce, then the capability: `Support\Caps::manage()` for settings, `moderate_comments` for moderation. Only then does it do any work.
   - Public `nopriv` endpoints use `Forms\AntiSpam` (honeypot + per-IP rate limit).
   - Never `$_REQUEST`. Run `wp_unslash` before sanitizing, and escape at output.
3. **SQL.** Every `$wpdb` query is `prepare()`d. Table names come only from `Db::table()`. `phpcs:ignore` only with a written reason.
4. **Reviews are comments.**
   - Create through `ReviewRepository::create()`.
   - Change status through `wp_set_comment_status()` and the existing Moderation paths, so `RatingCache`/`AggregateStore` stay in sync. After any rating or approval change, recalc the pool with `$ratings->recalc_product( Pool::resolve_id( $product_id ) )`. `$ratings` is always the `rating_cache` container service (injected, or `Plugin::instance()->container()->get( 'rating_cache' )`): `recalc_product()` is an instance method (RatingCache.php:66), so a static `RatingCache::` call is a fatal error.
   - **Notation:** in PRDs, `Class::method()` in prose only names a method. Instance services (`reviews`, `reviewable`, `rating_cache`, `scheduler`, `mailer`, `request_repository`, `token_repository`, `settings`, …) are always called on the container instance. Only methods that are declared `static` in code (for example `Pool::resolve_id()`, `AggregateStore::get()`, `Sources::interactive()`, `Installer::is_current()`) are called statically.
   - Never store a review with no rating.
5. **Orders are HPOS-safe.** Use `wc_get_order()`, `wc_get_orders()` and `$order->get_meta()/update_meta_data()/save()`. Never use `get_post_meta` on orders, and never query `wp_posts` for orders.
6. **Free never references Pro.** Pro needs → new free hook, added to the canonical `CONTRACTS.md` and mirrored in Pro's `CONTRACTS.md`. Removing Pro leaves free working.
7. **Assets load conditionally.**
   - Enqueue only in a render path or behind a context guard.
   - New CSS/JS gets a `.min` build (`npm run build:assets`), served by `Support\Assets`.
   - New front-end CSS depends on `ndvr-tokens`, and uses tokens (`--ndvr-accent`, `--ndvr-danger`, `--ndvr-verdant`…) rather than hard-coded colours.
   - Storefront UI stays neutral (merchant's brand). Rose is never a status colour.
8. **External services.**
   - Off by default, with keys entered by the merchant.
   - Listed in `readme.txt` → "External services" with what is sent, when, and the terms and privacy links.
   - Calls use `wp_safe_remote_*` with a timeout.
9. **Personal data.** Anything that identifies a person (email, IP, name, free text about them, consent records, tracking events) must be:
   - exported by `Privacy\Privacy::export()`;
   - erased or anonymized by `erase()`;
   - removed by `uninstall.php` when `remove_data_on_uninstall` is on;
   - recorded in "readme privacy" notes.
10. **Upgrades.**
    - A new table or column goes into `Installer::schema()`. dbDelta only adds; drops are never needed.
    - Bump `NDVR_DB_VERSION` and add the table to `Installer::table_names()`.
    - Option-shape changes get a one-time migration keyed off the DB version.
    - Deactivation deletes nothing.
11. **Templates.**
    - New storefront markup lives in `templates/` and is rendered via `Support\View` (theme-overridable `yourtheme/ndv-reviews/`).
    - Escape each value, never a built blob. No `extract()`.
12. **i18n.** Every user-facing string is translatable, with a translators comment for placeholders. No HTML in strings unless it's run through `wp_kses`.
13. **Accessibility.**
    - Controls are real `<button>`/`<a>`/`<input>` with labels.
    - Visible focus.
    - `aria-live` for async results.
    - Works without JS where the base flow did, or degrades to a server round-trip.
14. **Versions.**
    - Don't touch `Version:`, `NDVR_VERSION`, `NDVR_PRO_VERSION` or `Stable tag`.
    - Changelog entries go under `= Unreleased =` in `readme.txt`.
15. **Imports never trigger** AI calls, coupons, webhooks, notifications or follow-ups (existing `_ndvr_source=import` rule).
16. **Docs.** Record the change in `CONTRACTS.md` (both repos when cross-plugin), `CLAUDE.md` (only for module or landmine changes), `TASKS.md` and `LOG.md` (with evidence).
17. **Legal positioning.**
    - No review gating: never hide, delay or suppress a review because of its rating, and never ask for reviews only from happy customers. This is the FTC 16 CFR 465 rule and the EU Omnibus rule.
    - Incentives never depend on sentiment, and rewarded reviews are disclosed.

## 3. Definition of done (per feature)
- Every PRD acceptance criterion is shown passing in Playground, recorded in `LOG.md`.
- The core-flow harness (`.agents/qa/core-flows.php`) passes.
- `php -l` is clean. PHPCS reports zero new errors in touched files against the baseline at `D:/.devcache/qa/`. The PHP 7.4 compatibility sniff is clean.
- An independent code review is approved, after its findings are fixed.
- Docs are updated (§2.16). A feature patch is saved to `D:/.devcache/patches/RR-xx.patch`.

## 4. Schema ledger (fix X1 from review round 1)
`Installer::maybe_upgrade()` runs only when the stored version < code version, so **a version number is never re-used or amended after it
has been built, and numbers only go up.** Each schema-changing feature takes `max(shipped)+1` *at merge* and records it here; the numbers
below are the planned order and are provisional until "Built" says yes. `Installer::schema()` is
cumulative (full dbDelta every bump), so extra bumps are cheap. Every new table goes into `Installer::table_names()` (uninstall).

| Version | Feature | Change | Built |
|---|---|---|---|
| 2 | (existing) | `question_votes` | yes |
| 3 | RR-00 | no schema; migration step: captcha provider from `recaptcha_enabled` (RR-13). Transparency needs no step: it defaults off everywhere (owner decision, RR-03) | yes |
| 4 | RR-09 | `ndvr_requests`: source, origin, token_id, opened_at, reviewed_at, meta; keys token_idx, order_sent | yes |
| 5 | RR-00b (E10, for Pro RR-17) | `ndvr_questions`: author_email, notified_at; key email_idx (`Installer::V_QA_EMAIL`) | yes |
| 6 | RR-14 (planned) | table `ndvr_review_reports` (user_id/ip_hash NOT NULL DEFAULT 0/'') | no |
| 7 | RR-11 (planned) | table `ndvr_review_fields` | no |
| 8 | RR-20 (planned) | `ndvr_criteria`: scope_ids | no |

Code checks versions through `Installer::V_*` constants (RR-00 F3), never bare numbers. Pro checks the free API level through `NDVR_API` (RR-00 F3b). Migration steps run through `Installer::steps()`; the upgrade also runs on `init`
under a lock. Front-end reads of new tables check `Installer::is_current( $version )` and treat the feature as off until then.
Uninstall drops `Installer::table_names()` and iterates `Uninstall::registry()` (RR-00 F2).

Interactive (customer-submitted) sources are `Reviews\Sources::interactive()` = onsite, form, magic_link, list_link (RR-00 F1). Never use a
denylist of "import" sources; Pro manual (`admin`) and external (provider slugs) reviews bypass `create()` entirely.

## 5. Shared request pipeline
All review-request sending (free and Pro) goes through RR-09: `Scheduler::queue_for_order()` to create, `Mailer::check_eligibility()`
to decide, `Scheduler::process()` (atomic claim) to send. No feature may insert `ndvr_requests` rows or call `wp_mail` for requests
any other way.

## 6. Shared definitions (round 2 S8)
- **`Reviewable::has_reviewed( $email, $product_id, $user_id = 0 )`** is the one "already reviewed" test. Every feature that asks the question calls it; none writes its own query.
  - **Posts:** `post__in = array_unique( [ $product_id, Pool::resolve_id( $product_id ) ] )`.
  - **Match:** a `count` comment query on `author_email` (when `is_email()`). If that finds nothing and `$user_id > 0`, a second query on `user_id`. The result is true when either finds one.
  - **Query args:** `type__in ['review','comment']`, status `all`, `number 1`, as in Reviewable.php:86-95.
  - RR-00b E4 and RR-15 both specify this. Whichever merges first writes the whole rule, and the second only references it.
- **Non-rating rejection reasons:** `ReviewRepository::non_rating_reasons()` (RR-15, an instance method on the `reviews` service) is the only list. Its five slugs are frozen: `spam`, `offensive`, `personal_info`, `off_topic`, `policy`. Pro RR-01 reads it when `NDVR_API` is at RR-15's level or higher. Below that, Pro uses a copy and keeps the slugs identical. Pro's own `spam_filter` reason stays local to Pro and is never added through `ndv-reviews/non_rating_reasons`. That filter may add reasons, but never one based on the rating.
- **Trusted customer email** (who may get customer notices about an existing review): RR-22 §8 is the only rule; Pro RR-17 references it.
