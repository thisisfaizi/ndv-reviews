# RR-00: Shared foundations (built before any feature)

Status: prd-ok (rev 4) · Plan: F (+ paired Pro tasks) · Inherits PRD-00 · Built first; every later PRD relies on it.

## Problem and who it's for
Review round 1 found the same gaps in many PRDs (batch B, X1–X10; batch A, X1/X3). This PRD fixes them once. It's for every later feature PRD and for the Pro add-on, which extends free only through documented hooks. No competitor feature maps to it.

## Contract delta (F1–F9 below)
Each F section lists its exact new hooks, options, methods and meta. CONTRACTS.md gets every one of them at build time.

## F1. Interactive-source allowlist (batch B X1)
**Facts from the code:**

| Source | Where | Path |
|---|---|---|
| `onsite` | ReviewForm.php:427, and the `create()` default (:185) | `create()` |
| `form` | TestimonialForm.php:281 | `create()` |
| `magic_link` | Landing.php:295 | `create()` |
| `import` | Csv.php:150, WooNative.php:74, Pro ProImporter.php:31 | `create()` |
| `admin` | Pro ManualReviews | `wp_insert_comment` directly |
| provider slugs (`google`, `facebook`, …) | Pro External | `wp_insert_comment` directly |

**New:**
- `Reviews\Sources::interactive(): string[]`, default `['onsite','form','magic_link','list_link']` (`list_link` = reviews via a Pro list-campaign link, RR-00b E7; customer-written, but verification is decided by `create()`), filter `ndv-reviews/interactive_sources`.
- `Sources::is_interactive( $source )`.
- `Sources::is_customer_editable( $comment_id )`: true only when `_ndvr_source` is interactive **and** there's no `_ndvr_external_id`.

Every customer-facing rule in RR-11/12/15/21/22 uses these. Nothing uses a denylist.

## F2. Uninstall and deactivation coverage (batch B X2)
- `uninstall.php` hardcodes its tables (uninstall.php:21-35) and sweeps only `ndvr_rl_` transients. Change it to drop `Installer::table_names()` (one list).
- Add `Uninstall::registry()` in `includes/Uninstall.php` that lists options (by exact name), option prefixes (per-id options such as `ndv_reviews_invite_lock_{id}`, swept with a prepared `LIKE` on the `esc_like` prefix), transient prefixes, comment, order and user meta keys, and Action Scheduler hooks. Every new feature appends to it; uninstall iterates it.
- **How uninstall gets the lists (round 3).** WordPress runs `uninstall.php` alone: the main plugin file, its constants and the autoloader aren't loaded.
  - `uninstall.php` defines `NDVR_TABLE_PREFIX` (`'ndvr_'`) if it's missing, then `require_once`s `includes/Installer.php` and `includes/Uninstall.php` directly.
  - Both files stay dependency-free at load time: only the `ABSPATH` guard, no other classes used, and no constants except `NDVR_TABLE_PREFIX`.
  - The registry is plain arrays of literals. No `apply_filters` (no listener is loaded at uninstall time anyway).
- The Deactivator unschedules every Action Scheduler hook in that registry (`as_unschedule_all_actions( $hook )`). The `scheduled` rows left behind are re-enqueued by RR-09's recover job after reactivation.
- **Direct SQL for order meta and jobs.** At uninstall time this plugin isn't booted, and WooCommerce may or may not be active. So `uninstall.php` doesn't rely on WooCommerce APIs:
  - **Order meta:** a prepared `DELETE FROM {prefix}wc_orders_meta WHERE meta_key IN (…)` when that table exists (`SHOW TABLES LIKE`), and the same on `{prefix}postmeta`.
  - **Action Scheduler jobs:** use `as_unschedule_all_actions()` when it exists. Otherwise, when `{prefix}actionscheduler_actions` exists, run a prepared `UPDATE … SET status='canceled' WHERE hook IN (…) AND status='pending'`.
- **Order of work** (the review-id query reads `_ndvr_*` meta and `review_media`, so it goes first):
  1. Capture the review ids and attachment ids, as today.
  2. Delete those comments and attachments.
  3. Drop `table_names()`.
  4. Sweep the registry: options, transient prefixes, user meta, order meta, Action Scheduler hooks, and the registry's `_ndvr_*` comment-meta keys from the surviving native reviews. WooCommerce's own `rating` meta is never touched.
- **Data stays unless opted in** (unchanged).

## F3. Migrations that run anywhere, once (batch A X1/X3, batch B X3)
- `Installer::maybe_upgrade()` runs on `init` priority 5 (as well as `admin_init`). `Plugin::boot()` runs on `plugins_loaded`, so the hook is in place in time. The routine runs under a lock: `Installer::acquire_lock(): string|false` and `Installer::release_lock( string $token )` (round 3, A1). `add_option()` isn't atomic: it reads first, then upserts (WP 7.1.3 option.php:1119-1124, :1142). So the lock uses raw SQL, the way core's `WP_Upgrader::create_lock()` does (class-wp-upgrader.php:1065).
  - **Acquire:**
    1. Build `$token = time() . '|' . wp_generate_password( 12, false )`.
    2. Run `$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES ('ndv_reviews_upgrade_lock', %s, 'no')", $token ) )`. The caller owns the lock only when this returns `1`.
    3. After any direct write, call `wp_cache_delete( 'ndv_reviews_upgrade_lock', 'options' )` and `wp_cache_delete( 'notoptions', 'options' )`. The lock is never read through `get_option()`.
  - **Stale takeover:**
    1. If the insert affected 0 rows, read the stored value with a direct prepared `SELECT option_value`.
    2. When its timestamp (the part before `|`) is older than 10 minutes, run `UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = 'ndv_reviews_upgrade_lock' AND option_value = %s`, with the new token and the stale value just read.
    3. Only a caller whose UPDATE affects exactly 1 row owns the lock. Otherwise `acquire_lock()` returns false and the run is skipped.
  - **Release:** `DELETE FROM {$wpdb->options} WHERE option_name = 'ndv_reviews_upgrade_lock' AND option_value = %s`, with the owner's token. A slow run that was taken over therefore can't delete the new owner's lock. It runs in `finally`, on success and on failure.
- **dbDelta check:** dbDelta reports no errors, so after it runs, every `table_names()` table must exist (`SHOW TABLES LIKE`). A missing table counts as a failed step (backoff below).
- **Hot path:** the only per-request cost is reading the autoloaded `ndv_reviews_db_version` and comparing it as an int.
- **Comparison:**
  - Versions compare as integers (`(int)`), replacing today's string equality (Installer.php:28).
  - A stored version **higher** than the code (a downgrade) does nothing: no dbDelta, and the stored version is never lowered. Re-upgrading later runs only the steps above the stored version.
- **New per-version step dispatcher:** `Installer::steps()` returns `version => callable`. After `dbDelta`, every step whose version is greater than the stored one and at most the code version runs in ascending order. Option-shape migrations (RR-13 captcha provider) live in steps.
  - Each step returns `true|WP_Error`; a thrown exception counts as an error.
  - After each successful step the stored version is set to that step's version, so a finished step never reruns. Steps must still be idempotent, because a crash between the work and the version write reruns them.
- **Failure backoff:**
  - A failed step stops the run and leaves the stored version at the last good step.
  - It stores the message in `ndv_reviews_upgrade_error` (option, not autoloaded) and sets the transient `ndvr_upgrade_backoff` for 1 hour.
  - While that transient exists, `maybe_upgrade()` returns at once, so a broken step can't rerun dbDelta on every request.
  - `HealthCheck` shows the stored error on our admin screens: "Rosette Reviews couldn't finish a database update: {message}. It will retry within an hour." A successful run deletes the option.
- **Fresh install:** a missing `ndv_reviews_db_version` means a fresh install. Every released version's Activator has written it, so an existing site always has one.
  - A fresh install runs dbDelta, **skips the steps** (they migrate existing data), calls `( new CriteriaRepository() )->seed_defaults()`, and stamps the code version.
  - `seed_defaults()` is already idempotent: it returns when any criterion exists (CriteriaRepository.php:286-289).
- **Activation goes through the same routine (round 3, A2):**
  - `Activator::activate()` no longer writes `NDVR_DB_VERSION` (Activator.php:26-27) and no longer calls `Installer::install()` or `seed_defaults()` directly. Today an upload-and-reactivate of an older site jumps the version without running steps.
  - It adds the settings option if missing (as today), then calls `Installer::maybe_upgrade()`. That:
    - stamps a fresh install;
    - runs dbDelta plus the pending steps for an older version;
    - does nothing for a stored version at or above the code, so a downgrade-and-reactivate never runs an older schema through dbDelta.
  - All of this happens under the lock.
  - **No race on a fresh install.** WordPress runs the activation hook before it adds the plugin to `active_plugins`, so no other request boots the plugin yet. On a reactivation, the lock can be held by another request. Activation then skips, and the tables and criteria already exist.
  - It ignores the backoff transient, so a merchant's reactivation retries a failed step immediately.
- **Uninstall registry:** lists the options `ndv_reviews_upgrade_lock` and `ndv_reviews_upgrade_error`, and the transient `ndvr_upgrade_backoff`.
- **Version rule (round 2, S1):** versions are numbered **strictly upward at merge time**. A schema-changing feature takes `max(shipped) + 1` when it merges, so a lower number never ships after a higher one. The PRD-00 §4 ledger lists the *planned* order. The number a PRD mentions is provisional until merge.
- **Constants in code:** `Installer` defines one constant per schema feature (for example `Installer::V_PIPELINE = 4`, `V_REPORTS`, `V_FIELDS`, `V_QA_EMAIL`, `V_CRITERIA_SCOPE`). Code checks `Installer::is_current( Installer::V_REPORTS )` (stored version ≥ constant), never a bare number.
- v3 = RR-00, constant `Installer::V_FOUNDATIONS = 3` (no schema; one step: captcha provider from `recaptcha_enabled`, RR-13). RR-03 and RR-13 cite `V_FOUNDATIONS`.
- **Guard reads:** any front-end read of a new table or column checks `is_current()` and treats the feature as off until the upgrade has run.

## F3b. Pro compatibility level (round 2, S6)
- Free defines `NDVR_API` (int) beside `NDVR_VERSION`.
  - Free 1.0.0 defines no `NDVR_API`, and an undefined constant counts as below every level.
  - The planned levels are: RR-00 sets **2** (Sources, F4 filters, F5 hooks, F6 registry, F7 rate limiter, `send_notice`, `is_current` and the `V_*` constants), RR-09 sets **3**, and RR-00b sets **4**.
  - Each later PRD that adds Pro-facing API takes the next level at merge (planned levels are listed in each PRD, final at merge, like schema versions).
  - Levels are cumulative.
- Pro gates every feature that needs new free API on `defined( 'NDVR_API' ) && NDVR_API >= N`. Below that the feature stays off, and Pro shows one admin notice on our screens: "Update Rosette Reviews to use {feature}."
- No scattered `method_exists` guards. Removing Pro still leaves free working.

## F4. Moderation list extension (batch B X4)
- `Moderation\ListTable` whitelists views (ListTable.php:54).
- **New filters:**
  - `ndv-reviews/moderation_views` (array slug => [label, count, query args callable])
  - `ndv-reviews/moderation_columns` (array)
  - `ndv-reviews/moderation_column_{name}` (string html, WP_Comment)
  - `ndv-reviews/moderation_row_actions` (array, WP_Comment)
- **New action** `ndv-reviews/moderation_handle_action` (string action, int[] ids) with nonce and capability checked by the core before firing.

## F5. Template hook points (batch B X5)
Features render through hooks, so overridden templates keep working when they keep the hooks.

**New actions in `templates/review-item.php`:**
- `ndv-reviews/review_meta_after` (after the date, inside `.ndvr-review-meta`)
- `ndv-reviews/review_body_after` (after the body, before criteria)
- `ndv-reviews/review_foot_end` (end of `.ndvr-review-foot`)

**Existing:** `review_author_badges` (documented now), `review_item_after`.

**New filters in `review-item.php`** (RR-16 search highlighting): `ndv-reviews/review_body_html` (string html, review) and `ndv-reviews/review_title_html` (string html, review). Overrides should keep them.

**Disclosure rule:** pills that must survive theme overrides render from listeners on `review_author_badges`, an action overrides already keep. That covers the incentive (RR-00b E9 / Pro RR-01) and imported-verified (Pro RR-02) pills. They are never hard-coded template blocks.

**New action in the filter bar:** `ndv-reviews/filter_bar_end` (int `$product_id`), in `Renderer::render_filter_bar()`.

**The `review-item.php` docblock** lists the hooks theme overrides should keep.

## F6. Settings card for "Reviews" (batch B X6)
- `Admin\SettingsPage` cards today: Collection, Spam Protection, SEO & Advanced (SettingsPage.php:164/209/230). One save handler rebuilds a fixed key list (:101-113).
- **New card "Reviews and trust"** for RR-03/11/12/14/15/21/22/23 settings.
- **New `Admin\SettingsFields::fields()` registry** (built as its own class; a save processes only the registered keys its form rendered, listed in the hidden `ndvr_fields[]`): key => [sanitize callback, default, `page`, card]. `page` is `settings` | `reminders` | `design`. Features append through the filter `ndv-reviews/settings_fields`.
  - **Each page's save handler processes only its own page's keys.** That covers SettingsPage, RequestsPage (RequestsPage.php:149-160) and DesignPage.
  - Handlers sanitize registered keys and **merge** (never drop unknown keys).
  - AC: saving Settings leaves `consent_mode` (page reminders) unchanged.
- **Secret fields:** an empty submitted value keeps the stored secret (batch B RR-13 finding). Fix the reCAPTCHA secret too. Secrets are never echoed back into `value=`: render a "•••• saved" placeholder plus a "Replace" field.

## F7. Reusable rate limiter (batch B X10)
- Add `AntiSpam::rate_limit( string $bucket, int $max_per_hour ): true|WP_Error`, public, keyed `ndvr_rl_{bucket}_{iphash}`.
- The existing submission limit becomes bucket `submit` (key shape kept for that bucket: `ndvr_rl_<hash>`).
- RR-14 (`report`), RR-15 (`edit`, per user), RR-16 (`list`) and the RR-09 pixel (none) use it.
- **Also new:** `AntiSpam::honeypot_ok( array $input ): bool`, and a public `AntiSpam::ip_hash()`.

## F8. Legal guardrails across features (batch B X7/X8)
1. **Edits never unpublish.** A customer's edit to a published review is stored as a pending revision (`_ndvr_pending_edit`) while the approved text stays live. The moderator applies or discards it. A published review then shows "Edited {date}". This changes RR-15 and RR-22.
2. **Approval never depends on rating.**
   - Free never auto-approves.
   - **Paired Pro task:** `Moderation\Plus::auto_approve` (Plus.php:52-73) must drop `auto_approve_min_stars`. It may use verified-only and other rating-independent rules. Reviews it doesn't auto-approve go to normal moderation. Pro settings migration: remove the key and show a notice explaining why (FTC 16 CFR 465.7 / UK DMCC).
3. **The transparency sentences stay true** (RR-03). Each feature that changes how reviews are handled supplies its keyed sentence via `transparency_sentences`:
   - RR-14 auto-hold after reports;
   - RR-21 high-risk hold;
   - Pro external reviews ("Some reviews come from Google/Facebook and are labelled");
   - Pro incentives.

   The free `source` sentence becomes "Reviews come from customers{ and from our profiles on other sites}".

## F9. Free notice email (batch B RR-22 dependency)
`Mailer::send_notice( $to, $subject, $inner_html, array $args )` and `templates/email-notice.php` are free (used by RR-22 free, and by Pro RR-17). They brand-wrap, add an unsubscribe link only when `args.marketing` is true (transactional notices to the merchant don't need one), and run suppression for customer-facing notices.

## Scope / non-goals
- **In scope:** F1–F9. These are internal foundations, and they change storefront output only through new, empty hook points.
- **Non-goals:**
  - No feature UI. RR-03 and later PRDs fill the "Reviews and trust" card and the hooks.
  - No schema change.
  - No change to how reviews are created, rated or displayed.

## User experience
- **Admin:**
  - The "Reviews and trust" settings card. It's empty until a feature registers fields, and it's hidden while it has no fields.
  - Secret fields show "•••• saved" with a "Replace" field.
  - The upgrade-error notice (F3) and the Pro "Update Rosette Reviews to use {feature}." notice (F3b).
- **Storefront and email:** no visible change.

## Reuse map
| Feature | Builds on |
|---|---|
| F1 | `ReviewRepository::create()` source handling (:185). |
| F2 | `uninstall.php`, `Deactivator`, `Installer::table_names()`. |
| F3 | `Installer` and `Activator`. |
| F4 | `Moderation\ListTable` and `Moderation\Page` (nonces at Page.php:131, :147). |
| F5 | `templates/review-item.php` and `Renderer::render_filter_bar()`. |
| F6 | `Admin\SettingsPage`, `Admin\RequestsPage` and `Admin\DesignPage` save handlers. |
| F7 | `Forms\AntiSpam`. |
| F9 | `Requests\Mailer` headers and brand wrap. |

## Blast radius
- **Free:**
  - Every caller of `Installer` (Plugin::boot, Activator, uninstall).
  - All three settings save handlers.
  - `Moderation\Page` and ListTable.
  - `review-item.php`, plus theme overrides of it (the new hooks are additive, so old overrides keep rendering).
  - `AntiSpam` callers: ReviewForm, TestimonialForm and Landing (the `submit` bucket keeps its key).
- **Pro:**
  - `Moderation\Plus::auto_approve` (the F8 paired task).
  - Pro listeners on `review_author_badges` and `review_item_after` (unchanged).
  - Pro settings saved through its own option, so F6 doesn't touch them.

## Storage and upgrade
- **New options:**
  - `ndv_reviews_upgrade_lock` (raw SQL, never autoloaded);
  - `ndv_reviews_upgrade_error` (not autoloaded).
- **New transient:** `ndvr_upgrade_backoff`.
- **Version:** `Installer::V_FOUNDATIONS = 3`, with one step: the captcha provider, from RR-13.
- **What an old site sees:** on the first `init` after the update, the step runs once and the stored version becomes 3.
- **Downgrade:** a later downgrade leaves everything as it was.

## Security
- **F4 moderation actions:**
  - Bulk actions check `check_admin_referer( 'bulk-ndvr_reviews' )` and row actions `check_admin_referer( 'ndvr_review_action' )` (Moderation/Page.php:131, :147). Then `current_user_can( 'moderate_comments' )`, before `moderation_handle_action` fires.
  - Ids go through `array_map( 'absint' )`.
  - View query args from `moderation_views` are passed only to `get_comments()`, never into SQL.
- **F6 settings saves:** each handler runs its existing nonce check (`ndvr_settings`, the RequestsPage `NONCE`, Design) **first**, then `Caps::manage()`, then the work (PRD-00 §2.2). Today `SettingsPage::handle_save()` checks the capability first (SettingsPage.php:87, nonce at :90); F6 swaps them, keeping the cheap "is this our form" `isset` test before both.
  - Each registered key passes through its own sanitize callback.
  - Secrets are never printed into `value=`.
- **F7 rate limiter:** `ip_hash()` is a salted `wp_hash()` of `REMOTE_ADDR`, never the raw IP.
- **F9 notice email:** `send_notice()` treats `$subject` as plain text (`wp_strip_all_tags`) and passes `$inner_html` through `wp_kses_post`. Recipients pass `is_email()`.
- **F3 upgrade:** the lock and steps use prepared SQL only. Steps run no user input.

## Privacy
- F7 rate-limit transients hold an IP hash for at most 1 hour. Uninstall sweeps them (`ndvr_rl_` prefix).
- F2 makes uninstall cover every registry entry.
- No other new personal data.

## Performance and assets
- **Per request:**
  - one autoloaded option read and an int compare (F3);
  - hook calls only (F5);
  - no new queries on the front end.
- **No new assets.**

## Compatibility
- **Old Pro with the new free keeps working:**
  - F6 merges unknown keys.
  - F5 only adds hooks.
  - F8 removes only the Pro min-stars rule (the paired Pro task).
- **New Pro with the old free:** free 1.0.0 doesn't define `NDVR_API`, so Pro's new features stay off (F3b).
- **Platform versions:**
  - PHP 7.4: `try`/`finally`, no typed properties.
  - WP 6.0: `wp_cache_delete`, `INSERT IGNORE`.
  - WC 8.0: no WooCommerce API is used by F2 or F3.
- **Multisite:** each site upgrades its own tables on its own `init`.

## Test plan
- Run `.agents/qa/rr-00.php` with `wp eval-file`.
- The uninstall AC runs `define( 'WP_UNINSTALL_PLUGIN', … ); include 'uninstall.php';` in a request where the plugin is deactivated, using the logging dry-run flag.
- The upgrade ACs force states by writing `ndv_reviews_db_version` and the lock row directly.
- Then run `.agents/qa/core-flows.php`.

## Acceptance criteria
1. **Upgrades.**
   1. An upgrade from v2, run from a front-end request, applies the v3 steps once, under the lock.
   2. **Lock.**
      - A fresh lock (under 10 minutes old) makes the run skip. A stale lock (11 minutes old) is taken over.
      - When the lock row is inserted by direct SQL and `wp_cache_set( 'notoptions', [ 'ndv_reviews_upgrade_lock' => true ], 'options' )` is set (a cache that wrongly says the lock is absent), `acquire_lock()` returns false.
      - Two takeover attempts on the same stale value: exactly one gets a token.
      - `release_lock()` with a stale token leaves the current lock in place.
   3. Upgrade edge cases:
      - A test step returning `WP_Error` leaves the version at 2, sets `ndvr_upgrade_backoff`, and stores the error. A second request in the same hour runs no dbDelta (counted with a `query` filter).
      - Stored version 9 with code version 3 changes nothing, and the stored value stays 9.
   4. Activation:
      - On a v2 site, deactivate, replace the code, and reactivate: the v3 step runs and the version becomes 3.
      - On a fresh install, activation stamps the code version, seeds the three default criteria, and runs no steps.
      - With stored version 9 and code version 3, `Activator::activate()` runs no `ALTER` or `CREATE` query (checked with a `query` filter), and the stored value stays 9.
2. **Uninstall.**
   - With the plugin not loaded (only `uninstall.php` included, the way `uninstall_plugin()` does it), the dry run (logging) lists every table in `table_names()` and every registry entry, with no fatal.
   - The run removes review comments before it drops `review_media`.
   - A native WooCommerce review keeps its `rating` meta.
3. A test filter adds a moderation view and a column, which render. A test custom row action fires the handler action after the nonce check.
4. The four new template actions fire in order; an old overridden template without them still renders.
5. Saving settings with a blank reCAPTCHA secret keeps the stored one. The secret isn't present in the page HTML.
6. `AntiSpam::rate_limit('report', 2)` blocks the third attempt within the hour without touching the `submit` bucket.
7. The Pro task: an auto-approve rule with min stars no longer exists, and a 1★ verified review is auto-approved under "verified only".
8. Core flows pass.

## Open questions
None.
