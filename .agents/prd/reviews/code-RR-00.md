# Code review: RR-00 shared foundations

Reviewer: independent (read-only) · Date: 2026-10-10 · Spec: `RR-00-foundations.md` rev 4 + `PRD-00-conventions.md`
Method: diffed every changed file against `D:/.devcache/patches/pre-RR-00/`, read new files in full, `php -l` on all 24 changed files (clean, PHP 8.3 CLI), grep for PHP 8-only syntax (none), grep of Pro for every changed free API, inventory of every stored key in free vs `Uninstall::registry()`.

**Verdict: CHANGES REQUESTED.** No blockers. Five MAJOR findings: two are real bugs (the F4 bulk action does nothing, and the F3 migration can be lost), one is a data-loss trap in the F6 contract, and two are ACs that can't be shown passing as built (AC 1.3, AC 2.1). The lock, step stamping, downgrade guard, uninstall load-safety and escaping are sound.

The four known intentional deviations are not raised as findings: activation repair dbDelta when stored == code, the RequestsPage registry save, the `moderation_bulk_actions` filter, and the "Remove the saved key" checkbox.

---

## Confirmed clean (checked explicitly)

- **Uninstall without the plugin loaded.**
  - `uninstall.php` defines `NDVR_TABLE_PREFIX`, then `require_once`s the two files.
  - `Installer.php` at load time has only the `ABSPATH` guard, a `use` alias (compile-time only, no autoload) and class constants.
  - `Uninstall::run()`, `review_ids()`, `attachment_ids()`, `table_names()`, `delete_order_meta()` and `cancel_jobs()` touch only `$wpdb`, core functions and `NDVR_TABLE_PREFIX`. `NDVR_OPTION_DB_VERSION` and `NDVR_DB_VERSION` are used only in `is_current()`, `maybe_upgrade()`, `stored_version_uncached()` and `set_version()`, none of which uninstall calls.
  - No fatal path.
- **Lock (F3).**
  - `INSERT IGNORE` relies on the `option_name` UNIQUE key, and only `1 === (int) $inserted` owns the lock.
  - Stale takeover is a compare-and-swap `UPDATE … AND option_value = %s`, so exactly one taker wins.
  - Release deletes only with the owner's token, inside `finally`.
  - The cache is flushed after every write, and the lock is never read through `get_option()`.
  - The stored version is re-read uncached under the lock (`Installer.php:142`).
  - All of it is prepared SQL.
- **Steps.**
  - `ksort`, then run the window `(stored, code]` in order.
  - A thrown `\Throwable` and a non-`true` return both count as failure.
  - The version is stamped after each step (`:189`).
  - Failure leaves the last good version, stores the error (not autoloaded) and sets the 1 h backoff.
  - Activation bypasses the backoff. A downgrade returns before the lock and never lowers the version.
  - The `init`/`admin_init` hooks use `accepted_args = 0`, and `true === $from_activation` is strict, so `do_action`'s implicit `''` can't flip it.
- **PHP 7.4 / WP 6.0.** No typed properties, union types, `match`, `fn`, `?->` or `str_*` helpers. Array constants, `\Throwable`, `try/finally` and `update_option(…, bool)` are all fine.
- **Pro.**
  - Pro does not call `AntiSpam`, `ListTable`, free `SettingsPage`, `Installer` or `render_filter_bar`.
  - Pro's `review_item_after` and `review_author_badges` listeners and its `GridRenderer` (which includes `review-item.php`) are unaffected.
  - `Mailer` public signatures are unchanged.
  - `Settings::get()` only changes for a missing key with a `null` default.
  - Pro `SettingsPage::handle_save()` merges over the stored option, so the removed `auto_approve_min_stars` can't come back.
- **Theme overrides.** The new hooks are additive. An old `review-item.php` override still renders. Title and body output stay escaped (now `esc_html` → filter → `wp_kses_post`).
- **Escaping and kses.**
  - The email-notice template escapes every value.
  - `send_notice` runs `is_email`, kses on the body, a stripped subject and suppression for customer notices, and adds the unsubscribe link only when `marketing` is set.
  - Moderation column and row-action HTML goes through `wp_kses_post`.
  - View query args reach only `get_comments()`.
  - The secret is never printed into `value=`.
- **Prepared SQL.** Array args in `delete_order_meta()` and `cancel_jobs()` use one `%s` per element. `LIKE` uses `esc_like() . '%'`.
- **Checked and fine:**
  - `View::render()` returns a string, it doesn't echo.
  - `render_filter_bar()` has one call site, and it passes `$product_id`.
  - `ListTable.php` `require_once`s `class-wp-list-table.php`, so the static `ListTable::extra_bulk_actions()` called from `Page` (on `admin_init`) can't fatal.

---

## MAJOR

### M1. A custom bulk action on any view other than all/spam/trash is a silent no-op (F4)
`includes/Moderation/Page.php:231` · `includes/Moderation/ListTable.php:207-229`

`get_bulk_actions()` passes the **current** view (any extra slug, plus `approved` and `moderated`) to `extra_bulk_actions()`. `requested_bulk_action()` only recognises keys from `extra_bulk_actions()` for the views `all`, `spam` and `trash`.

**Scenario:** RR-14 adds `dismiss_reports` only for its `reported` view (`$view === 'reported'`).
1. The dropdown shows it.
2. The moderator ticks 10 reviews and submits.
3. `requested_bulk_action()` returns `''`, so no nonce check runs and `moderation_handle_action` never fires.
4. The page re-renders as if nothing happened.

The same happens for actions offered only on `moderated` or `approved`.

**Fix:** resolve against the view the form was submitted from. The bulk form already carries `status` as a hidden field (Page.php:513).
```php
$views = array_unique( array( 'all', 'approved', 'moderated', 'spam', 'trash', isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all' ) );
foreach ( $views as $view ) { $extra += ListTable::extra_bulk_actions( $view ); }
```
Also return `''` early when `$action` is `'-1'` or `''`, so the filter doesn't run on every list load.

### M2. A stale `Settings` cache can permanently undo the v3 migration (F3 step)
`includes/Support/Settings.php:108-112` · `includes/Installer.php:206-215` · `includes/Requests/Scheduler.php:66`

`step_foundations()` writes `captcha_provider` with a raw `update_option()` at `init`:5. The shared `settings` container instance has usually cached `all()` before that: `Scheduler::register()` reads `reminder_status` on `plugins_loaded`, on every request. `Settings::update()` merges over that stale `$this->cache`.

**Scenario:**
1. The first request after the upload is a POST that saves Settings, Reminders or Design. For example, the merchant had the Settings screen open while updating by FTP or deploy, or a cron/AS request calls `update()`.
2. At init:5 the step adds `captcha_provider`.
3. At admin_init, `handle_save()` → `update()` → `array_merge( stale_all, $values )` → `update_option()` drops `captcha_provider`.
4. The version is already 3, so the step never reruns.

RR-13 would then see no provider and reCAPTCHA would be silently off. This is a spam-protection regression. The same staleness also breaks F6's promise that unknown keys are never dropped whenever any other writer updates the option mid-request.

**Fix:** in `Settings::update()`, merge over a fresh read. It's served from the alloptions cache, so it costs nothing:
```php
$stored = get_option( NDVR_OPTION_SETTINGS, array() );
$merged = array_merge( wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() ), $values );
```
Optionally also have the step reset the container instance's cache.

### M3. F6 `sanitize_page()` resets every registered key its form didn't render
`includes/Admin/SettingsFields.php:114-136` · `includes/Admin/RequestsPage.php:152` · `includes/Admin/DesignPage.php:132` · `includes/Admin/SettingsPage.php:245-250`

A key that isn't posted is passed to its sanitize callback as `null` ("return the empty value"), and the result is saved. But only `SettingsPage`'s `trust` card renders registered fields:
- `RequestsPage` and `DesignPage` render none.
- A `settings` field on any other card is never printed.
- So is a field registered without `render` (which the registry allows, since `render` is optional).

**Scenario:**
1. RR-09 registers `consent_mode` with `page => reminders`. Per the known deviation, it "will only register keys".
2. The value is set, by its default or a migration.
3. The merchant clicks Save on Reminders.
4. `consent_mode` is overwritten with `sanitize(null)` (`''` or `false`), and the same happens on every later save.

This is silent data loss built into a frozen (`NDVR_API` 2) contract.

**Fix (both parts):**
1. Render registered fields on all three pages. For example, call `SettingsFields::render_card_fields( $page, $card, … )` in each card, or add one generic section per page.
2. Only process keys the form actually rendered. `render_card_fields()` prints `<input type="hidden" name="ndvr_fields[]" value="{key}">` for each field it renders, and `sanitize_page()` skips keys that aren't listed. `null` then means "unticked" only for fields that were on the form.

Also skip `secret` fields with no render when computing `page_defaults()` (already done) and document `render` as required.

### M4. AC 2.1 (uninstall dry run with the plugin not loaded) can't be shown
`uninstall.php:15-28`

`Uninstall::run()` returns its log, but `uninstall.php` discards it, and nothing writes it anywhere. The dry run also sits behind the `remove_data_on_uninstall` gate (line 17). The QA harness admits the gap (`rr-00.php:267` "NOTE: … runs separately") and only calls `Uninstall::run(true)` with the plugin loaded. That path doesn't prove the "not loaded" condition the AC is about.

**Fix:**
```php
$ndvr_dry = defined( 'NDVR_UNINSTALL_DRY_RUN' ) && NDVR_UNINSTALL_DRY_RUN;
if ( ! $ndvr_dry && empty( $ndvr_settings['remove_data_on_uninstall'] ) ) { return; }
// … define/require …
return \NdvReviews\Uninstall::run( $ndvr_dry );
```
`$log = include 'uninstall.php';` then works in a request where the plugin is deactivated. The dry run changes nothing, so skipping the opt-in gate for it is safe. Optionally `error_log()` each line when `WP_DEBUG_LOG` is on. Add that case to `rr-00.php`, run in a separate process with the plugin inactive.

### M5. AC 1.3 ("a test step returning WP_Error") can't be injected
`includes/Installer.php:73-77, 166-190`

`steps()` is a literal array with no seam. The harness substitutes a missing-table failure (`rr-00.php:174-188`). That exercises backoff, but never the per-step path: the stop-on-`WP_Error`, the non-`true` → error branch, and the "finished step is stamped even if a later one fails" rule (`:189`). Those are exactly the F3 guarantees later PRDs rely on.

**Fix:** apply a filter inside `run_locked()` only, for example `$steps = apply_filters( 'ndv-reviews/upgrade_steps', self::steps() )`. The spec's "no filters" rule covers the uninstall registry, not the runtime installer. Add QA cases:
- a step returning `WP_Error`, so the version stays 2;
- a pair of steps 3 (ok) + 4 (error) with the code version forced, so the version ends at 3.

If a public filter is unwanted, gate it on `defined( 'NDVR_QA' )`.

---

## MINOR

1. **A request that loses the race re-runs dbDelta** (`Installer.php:141-149`).
   - `run_locked()` doesn't know `$from_activation`, so it doesn't stop when the re-read stored version equals the code version.
   - Request B reads the autoloaded version 2. A finishes and releases. B then gets the lock, re-reads 3 and still runs `install()` (13 dbDelta passes), for nothing.
   - **Fix:** pass the flag through and `return true` when `! $fresh && ( $stored > $code || ( $stored === $code && ! $from_activation ) )`.
2. **The "per user" rate limit is per user and IP** (`AntiSpam.php:171-173`).
   - With `$subject` set, the key hashes `ip_hash() . '|' . $subject`. RR-15's `edit` bucket ("per user") resets whenever the user changes network.
   - The API freezes at `NDVR_API` 2, so fix it now: `$hash = wp_hash( 'subject|' . $subject );`, without the IP, when a subject is given.
3. **Secret emptiness is checked before sanitizing** (`SettingsFields.php:127`).
   - A whitespace-only or tag-only submission passes the `''` test, sanitizes to `''`, and wipes the stored secret.
   - The built-in reCAPTCHA path (`SettingsPage.php:119-124`) does this correctly.
   - **Fix:** sanitize first, then `continue` if the result is `''`.
4. **An extra view's `query_args` can drop `post_type`** (`ListTable.php:337`).
   - The forced keys are `type__in`, `number` and `offset`. A callable that returns a fresh array without `post_type` lists ordinary blog comments (`type__in` includes `comment`) on the All Reviews screen.
   - **Fix:** add `post_type` to the forced keys, or merge `array_merge( $args, $narrowed )` and then re-force the paging and type keys.
5. **Moderation check order** (`Page.php:145` vs `:160`/`:180`).
   - The capability is checked before the nonce. PRD-00 §2.2 and RR-00 Security F4 say nonce, then capability.
   - Both checks run before any work or `moderation_handle_action`, so this is not a hole.
   - For consistency with F6: keep the `page` isset test, call `check_admin_referer()` per branch, then `current_user_can()` with `wp_die( …, 403 )`.
6. **AC 1.4 fresh-install seeding isn't really tested** (`rr-00.php:224-231`). The test checks only that criteria are non-empty, and they already exist, so `seed_defaults()` no-ops. Truncate `ndvr_criteria` before the fresh-install case, then assert exactly the three defaults.
7. **Pro has no uninstall handler.** The new `ndv_reviews_pro_floor_notice` (`ProPlugin.php:160`), and `ndv_reviews_pro_settings`, are never removed. This is a pre-existing gap that RR-00 widens. Add Pro `uninstall.php` (or the Freemius `after_uninstall` hook) that deletes the `ndv_reviews_pro_*` options when free's `remove_data_on_uninstall` is on.
8. **`send_notice` subject entities** (`Mailer.php:160`). `wp_strip_all_tags()` doesn't decode entities, so a subject built from `esc_html()`'d or stored-encoded text (store names with `&`) arrives as `&amp;`. Wrap the result in `html_entity_decode( …, ENT_QUOTES, 'UTF-8' )` after stripping. It's plain text in a header, never HTML.
9. **Registry naming vs spec.** The spec says `SettingsPage::fields()`; the code has `Admin\SettingsFields::fields()`. CONTRACTS.md:60 documents the real name, so update the PRD text (or add a `SettingsPage::fields()` alias) so later PRDs don't call a method that doesn't exist.

## Nits
- `SettingsFields.php:92`: the docblock says the defaults are "merged into Settings::all()". They aren't; they're only consulted by `Settings::get()` for missing keys. Fix the comment, or actually merge them so `render_card_fields( …, $s->all() )` and `get()` agree.
- `SettingsFields.php:208`: prints `aria-describedby=""` when nothing is saved. Omit the attribute instead.
- `Settings::get()` fallback re-runs the `settings_fields` filter on every missing-key read with a `null` default. Cache `SettingsFields::defaults()` per request.
- `ListTable::extra_views()` runs `count` callables on every list load, even when the view links aren't shown. Fine for now; document that counts must be cheap.

---

## Acceptance criteria satisfiability

| AC | Status |
|---|---|
| 1.1 v2 → v3 from front end, once, under lock | Satisfiable: `init`:5 hook, lock, step stamping. Note M2 for the "first request is a save" edge |
| 1.2 Lock: fresh skip / stale takeover / notoptions cache / double takeover / stale release | Satisfiable, and covered by `rr-00.php` |
| 1.3 Test step WP_Error → v2, backoff, error; no dbDelta in backoff; stored 9 stays 9 | Backoff and downgrade parts are satisfiable. The **WP_Error step part can't be shown** (M5) |
| 1.4 Reactivation runs the step; fresh install stamps, seeds 3, runs no steps; 9 vs 3 runs no CREATE/ALTER | Satisfiable in code. The seeding check is vacuous in QA (MINOR 6) |
| 2.1 Uninstall dry run with plugin not loaded, no fatal | Load-safety is clean. **The log can't be observed** (M4) |
| 2.2 Comments removed before `review_media` drop | Satisfiable (steps 1–2 before step 3) |
| 2.3 Native WC review keeps `rating` | Satisfiable: `rating` is not in the registry, and native reviews are excluded by the id query |
| 3 Moderation view + column render; custom row action fires after nonce | Satisfiable. Custom **bulk** actions are broken outside all/spam/trash (M1) |
| 4 Four new template actions fire in order; old override renders | Satisfiable |
| 5 Blank reCAPTCHA secret keeps stored; secret absent from HTML | Satisfiable (built-in path). The registry path has MINOR 3 |
| 6 `rate_limit('report', 2)` blocks the 3rd; `submit` untouched | Satisfiable. The `submit` key shape is kept |
| 7 Pro: no min-stars rule; 1★ verified auto-approved | Satisfiable (`Plus.php`, migration and notice) |
| 8 Core flows pass | Not verified here (read-only review) |

**Out of scope here:** F8.1 (edits never unpublish) and F8.3 (`transparency_sentences`) belong to RR-15/22 and RR-03. No `transparency_sentences` exists yet, so mark F8.3 as deferred to RR-03 in TASKS.md rather than "done in RR-00".

## Privacy / uninstall completeness
- I inventoried every free write (`update_option`, `set_transient`, `update_*_meta`, `add_comment_meta`, `as_schedule_*`). All of these are in the registry:
  - **Options:** settings, db_version, unsubscribed, upgrade_lock, upgrade_error.
  - **Transients:** activated, upgrade_backoff, `ndvr_rl_` prefix (covers the new bucket keys).
  - **Comment meta:** the 10 `_ndvr_*` keys free writes onto native reviews.
  - **Post meta:** the 3 `_ndvr_*` aggregates.
  - **User meta:** the 2 dismiss keys.
  - **Action Scheduler:** `ndvr_send_request`.
- `_ndvr_country`, `_ndvr_admin_created` and `_ndvr_external_id` are written only by Pro, onto reviews it created. Those reviews are deleted by the id query, so no gap.
- `_wc_*` product aggregates stay stale by design, as documented.
- Transients held in a persistent object cache aren't swept. They expire within 1 h, which is acceptable.
- Multisite uninstall still cleans only the current site. This is pre-existing and not an RR-00 regression.
