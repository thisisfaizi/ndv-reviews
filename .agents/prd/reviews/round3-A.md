# PRD review round 3, batch A (2026-10-10): RR-00, RR-00b, RR-09, RR-03 to RR-08

Reviewer: adversarial senior WP/WC. Read-only; no PRD edited. BLOCKER and MAJOR findings only.
Already raised elsewhere and not repeated here: RR-03 `integrity` vs RR-21 (batch B), RR-03/Pro RR-02 imported-verified wording (Pro batch C, Pro supplies `import_verified`), RR-19 on `summary_footer` (Pro batch C).

## PASS (no blocker/major)
RR-03, RR-06, RR-07, RR-08.

Not passing: RR-00 (1 blocker, 2 major), RR-09 (4 major), RR-00b (1 major), RR-04 (1 major), RR-05 (1 major).

## Round 2 items: status
All round-2 items for these PRDs are resolved in the text: S1 to S8 (RR-00 F1 to F6, F3b, RR-00b E4/E12), RR-03 items 1 to 4, RR-05 item 1, RR-06 item 1 (origin/source guard, AC15, `request_sent`, container services), RR-07 items 1 to 4, RR-08 item 1. One exception: S8 is defined correctly in RR-00b §6.4, but RR-09 calls it with the wrong argument order (finding 5).

---

## 1. BLOCKER: RR-00 F3, the upgrade lock isn't a lock
**Defect.** F3 says: "The lock is `add_option( 'ndv_reviews_upgrade_lock', time(), '', 'no' )`; whoever adds it owns it." `add_option()` isn't atomic. It checks `get_option()` first (WP 7.1.3 `wp-includes/option.php:1119-1124`), then runs `INSERT … ON DUPLICATE KEY UPDATE` (`option.php:1142`). Two concurrent requests both see no option, both run the upsert, both get a truthy result, and both "own" the lock. That's the exact race F3 exists to stop: two concurrent dbDelta/step runs, and duplicate-column ALTER errors. The stale takeover ("delete it and retry the add once") has the same race: A takes over, then B deletes A's fresh lock and takes over too. Core avoids this with `INSERT IGNORE` (`wp-admin/includes/class-wp-upgrader.php:1065`, `WP_Upgrader::create_lock()`).

**Fix text (replace the three lock bullets in F3):**
> The routine runs under a lock, `Installer::acquire_lock(): string|false` / `release_lock( string $token )`:
> - **Acquire:** `$token = time() . '|' . wp_generate_password( 12, false )`, then `$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES ('ndv_reviews_upgrade_lock', %s, 'no')", $token ) )`. The caller owns the lock only when this returns `1`. Never use `add_option()`, which upserts (option.php:1142). After any direct write, `wp_cache_delete( 'ndv_reviews_upgrade_lock', 'options' )` and `wp_cache_delete( 'notoptions', 'options' )`.
> - **Stale takeover:** if the insert affects 0 rows, read the stored value with a direct prepared `SELECT option_value`, not the object cache. When its timestamp (before `|`) is older than 10 minutes, run `UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = 'ndv_reviews_upgrade_lock' AND option_value = %s` with the new token and the stale value read. Only a caller whose UPDATE affects exactly 1 row owns the lock. Otherwise return false.
> - **Release:** `DELETE … WHERE option_name = 'ndv_reviews_upgrade_lock' AND option_value = %s` with the owner's token, so a slow run that was taken over can't delete the new owner's lock. It runs on success and on failure (in `finally`).
>
> AC 1.2 add: "With the lock row inserted by direct SQL and `wp_cache_set( 'notoptions', [ 'ndv_reviews_upgrade_lock' => true ], 'options' )` (a cache that wrongly says it's absent), `acquire_lock()` returns false. Two takeover attempts on the same stale value: exactly one returns a token. `release_lock()` with a stale token leaves the current lock in place."

## 2. MAJOR: RR-00 F3, Activator bypasses the version rule and the lock
**Defect.** F3 says a downgrade (stored > code) "does nothing: no dbDelta", and that the lock serialises upgrades. But F3 also has `Activator::activate()` run `Installer::install()` (dbDelta) unconditionally, outside the lock, before `maybe_upgrade()`. Two problems follow:
- On a downgrade-and-reactivate, an older schema runs through dbDelta. dbDelta issues `ALTER TABLE … CHANGE COLUMN` when a column type differs, so it can narrow a column a later version widened.
- During activation, the unlocked dbDelta can run alongside a front-end `init` upgrade that holds the lock.

Also, the new options `ndv_reviews_upgrade_lock` and `ndv_reviews_upgrade_error` and the transient `ndvr_upgrade_backoff` are never added to `Uninstall::registry()` (PRD-00 §2.9/§2.10, F2).

**Fix text (replace the "Activation goes through the same routine" sub-bullets):**
> - `Activator::activate()` stops writing `NDVR_DB_VERSION` (Activator.php:26-27) and no longer calls `Installer::install()` directly. It calls `Installer::maybe_upgrade()`. For a fresh install that runs dbDelta and stamps the version; for an older stored version it runs dbDelta and the pending steps; for a stored version ≥ code it does nothing. All of this happens under the lock.
> - If `maybe_upgrade()` returned without running because the lock is held **and** the version option is missing (a fresh install racing a front-end request), activation polls up to 5 × 1 s for the version option before `seed_defaults()`. If the version is still missing after that, it skips seeding, and `maybe_upgrade()`'s fresh-install branch calls `( new CriteriaRepository() )->seed_defaults()` after dbDelta.
> - `Uninstall::registry()` lists the options `ndv_reviews_upgrade_lock` and `ndv_reviews_upgrade_error`, and the transient `ndvr_upgrade_backoff`.
>
> AC 1.3 add: "Stored version 9, code 3: `Activator::activate()` runs no `ALTER`/`CREATE` query (query filter) and the stored value stays 9."

## 3. MAJOR: RR-00 and RR-09 lack the PRD-00 §1 template sections
**Defect.** PRD-00 §1 says every PRD has the 14 sections, and the reviewer rejects a PRD that contradicts PRD-00.
- **RR-00** is F1 to F9 + AC + Open questions. It has no Scope, Reuse map, Blast radius, Storage and upgrade, Security, Privacy, Performance, Compatibility or Test plan section. Because Security is missing, the F4 `moderation_handle_action` nonce and capability are unnamed ("checked by the core").
- **RR-09** has §1 to §9 but no Storage and upgrade, Security, Compatibility or Test plan section. Privacy appears only as §6.3.

**Fix text.** Add the missing headings. Minimum content:
- **RR-00 Security:**
  > F4: bulk actions are checked with `check_admin_referer( 'bulk-ndvr_reviews' )` and row actions with `check_admin_referer( 'ndvr_review_action' )` (Moderation/Page.php:131, :147), then `current_user_can( 'moderate_comments' )`, before `moderation_handle_action` fires. The ids are passed through `absint`. F6: the existing nonces (`ndvr_settings`, `ndvr_requests`, design), checked before `Caps::manage()`. Note: today SettingsPage.php:87-90 checks the capability before the nonce; F6 puts the nonce first. F7: `ip_hash()` is a salted hash (`wp_salt`), never a raw IP. F9: `send_notice()` escapes `$subject` as plain text and runs `$inner_html` through `wp_kses_post`.
- **RR-00 Privacy:**
  > F7 rate-limit transients hold an IP hash for at most 1 hour, swept by uninstall (`ndvr_rl_` prefix). No other new personal data.
- **RR-00 Compatibility:**
  > An old Pro with the new free keeps working: F6 merges unknown keys, F5 adds hooks only, `NDVR_API` undefined in free 1.0.0. PHP 7.4: `try`/`finally` in steps.
- **RR-00 Test plan:**
  > `.agents/qa/rr-00.php` via `wp eval-file`. The uninstall AC runs `define( 'WP_UNINSTALL_PLUGIN', … ); include 'uninstall.php';` in a request where the plugin is deactivated.
- **RR-09 Security:**
  > Retry (GET `ndvr_retry`): `check_admin_referer( 'ndvr_requests' )`, then `Caps::manage( 'reminders' )` (RequestsPage.php:122-127). Pixel: public, no nonce (documented waiver), HMAC compared with `hash_equals`, id `absint`, one UPDATE and nothing else. Tracking settings: the Reminders save. All SQL through `prepare()` with `Db::table( 'requests' )`.
- **RR-09 Storage and upgrade:**
  > `Installer::V_PIPELINE` columns and keys (§6.1), dbDelta only, no step. Old rows become `source='legacy'`. Before the upgrade, `is_current( V_PIPELINE )` is false and the legacy-shape path in §6.2 step 1 applies.
- **RR-09 Compatibility:**
  > HPOS: order access through `wc_get_order()` only. An old Pro keeps working through the legacy direct `send_for_order()` path. BulkCampaign rows without `source` are `legacy`. PHP 7.4 / WP 6.0 / WC 8.0: `INSERT IGNORE` and `as_has_scheduled_action()` (Action Scheduler 3.3+, bundled with WC 8.0).
- **RR-09 Test plan:**
  > `.agents/qa/rr-09.php`, mail captured with `pre_wp_mail`, AS actions inspected with `as_get_scheduled_actions()`, rows with direct prepared queries.

## 4. MAJOR: RR-09 §6.2 automatic reminders would send immediately
**Defect.** `on_order_status()` becomes `queue_for_order( $id, [ 'source' => 'auto' ] )` (RR-09 line 123), and `queue_for_order`'s `delay` defaults to 0 (line 68). Today the automatic row is scheduled `reminder_delay_days` ahead (Scheduler.php:104-105, :121; Mailer.php:76-81 depends on that delay). As written, every automatic request goes out the moment the order completes, and the merchant's "days after" setting is ignored.

**Fix text (replace the `on_order_status()` paragraph):**
> **`on_order_status()`** keeps its `reminder_enabled`, `should_send_reminder` and billing-email guards (Scheduler.php:77-102). It replaces only the `exists_for_order()` guard (Scheduler.php:95) and the insert/schedule (:104-125) with `queue_for_order( $id, [ 'source' => 'auto', 'delay' => max( 0, (int) $settings->get( 'reminder_delay_days', 7 ) ) * DAY_IN_SECONDS ] )`. Dedupe happens through the key, so repeated status events are harmless.
>
> AC 3 add: "With `reminder_delay_days = 7`, completing an order creates an `auto` row whose `scheduled_at` and pending `ndvr_send_request` action are now + 7 days (±60 s)."

## 5. MAJOR: RR-09 §6.2 step 7 calls `has_reviewed()` with the wrong argument order
**Defect.** Step 7 for list rows says "drop ids where `has_reviewed( email, user_id, pool )`". The one definition (PRD-00 §6, RR-00b §6.4, Reviewable.php:81) is `has_reviewed( $email, $product_id, $user_id = 0 )`. List rows also have no user id (`order_id=0`, no customer). Step 7 also calls `Reviewable::filter_excluded()`, which RR-05 adds, but RR-09 is built before RR-05.

**Fix text (step 7, list rows):**
> list rows: `context.products`, then (once RR-05 is built) `Reviewable::filter_excluded()`, then drop ids where `$reviewable->has_reviewed( $email, $product_id )` (PRD-00 §6; `$user_id` is 0 because list rows have no account). The result must not be empty.

## 6. MAJOR: RR-09 contract delta is incomplete for its consumers
**Defects.**
- **(a) API level.** RR-00 F3b says "RR-09 sets **3**", and RR-00b builds on "RR-00 and RR-09 merge before this PRD". RR-09 never says it defines `NDVR_API` 3, so the builder of RR-09 won't bump it.
- **(b) Filter signature.** `ndv-reviews/request_eligible` has no signature. RR-06 §6 and AC15 assume `( true|WP_Error $eligible, ?WC_Order $order, array $context )`.
- **(c) Settings registry.** `reminder_utm` and `reminder_open_pixel` have no F6 `page`. RR-05 §6 says the registry-driven Reminders save is added by "whichever is built first (RR-06, RR-07 and RR-09's tracking keys)". RR-09 is built first and doesn't take it on. Under F6, the RequestsPage fixed list (RequestsPage.php:149-160) would never save the two new keys.

**Fix text (add to §6.2):**
> - **`NDVR_API` 3:** this PRD changes `define( 'NDVR_API', 2 )` to `3` (RR-00 F3b). Level 3 covers `queue_for_order()`, `queue_for_email()`, `check_eligibility()`, `SKIP_CODES`, `request_sent`, `request_converted` and `create_list_token()`.
> - **Filter signature:** `apply_filters( 'ndv-reviews/request_eligible', true|WP_Error $eligible, ?\WC_Order $order, array $context )`, applied last in `check_eligibility()`. A listener returns `$eligible` unchanged when it doesn't apply.
> - **Settings keys** (F6 registry, page `reminders`, card "Tracking"): `reminder_utm` (bool, false), `reminder_open_pixel` (bool, false). This PRD makes `RequestsPage::handle_actions()`'s `save` branch also sanitize every registry key whose `page` is `reminders`, and only those (the rule in RR-05 §6). RR-05, RR-06 and RR-07 only register keys.
>
> AC add: "Saving the Reminders form with the UTM box ticked stores `reminder_utm = true` and leaves `transparency_enabled` (page `settings`) unchanged."

## 7. MAJOR: RR-09 crash/orphan recovery mass-sends stale requests, and adds a per-request query
**Defect.** After deactivation, the recover job re-enqueues every `scheduled` row "at once if the row is overdue" (RR-09 line 119). A store reactivated after weeks or months sends the whole backlog in one burst, about old orders. The eligibility gate doesn't check age, and the token is minted fresh at send. The recurring job is also "registered on `init` when not already scheduled". `as_has_scheduled_action()` is a store query on every front-end request, which contradicts the hot-path budget in RR-00 F3 ("the only per-request cost").

**Fix text (replace the orphan bullet and the registration sentence):**
> - **Orphan re-enqueue.** The recover job selects up to 200 rows with `status='scheduled' AND scheduled_at < NOW() - 15 MINUTE` (`status_idx`). For each row with no pending `ndvr_send_request` (`as_has_scheduled_action( 'ndvr_send_request', [ 'request_id' => $id ], 'ndv-reviews' )`, args as in Scheduler.php:121):
>   - when `scheduled_at` is older than `apply_filters( 'ndv-reviews/request_max_overdue_days', 14 )` days, set `cancelled` with code `ndvr_expired` ("Not sent: it was due more than %d days ago."), which is added to `SKIP_CODES`;
>   - otherwise schedule it at `time() + 60 * $i` (`$i` = position in the batch), so a backlog is spread out.
> - **Registration:** `Activator::activate()` and `admin_init` schedule `ndvr_requests_recover` (hourly) when `as_has_scheduled_action( 'ndvr_requests_recover' )` is false. Front-end `init` doesn't check.
>
> AC replace: "Deactivate with one scheduled row due 30 days ago and one due 1 day ago, reactivate, run the recover job: the first is `cancelled` / `ndvr_expired` and never sends; the second gets one pending job and sends once."

## 8. MAJOR: RR-00b E3 (with RR-00 F2), held-media cleanup is lost on deactivation
**Defect.** E3 schedules one `ndvr_media_cleanup` per spam or trash transition, and only then (§6.3). RR-00 F2's Deactivator unschedules every registry hook, including `ndvr_media_cleanup` (§6.3 last bullet), and nothing ever schedules those jobs again. Leaving them pending doesn't help either. Action Scheduler fails an action whose hook has no callbacks ("will not be executed as no callbacks are registered", `action-scheduler/classes/actions/ActionScheduler_Action.php:67-80`), so jobs that fall due while the plugin is inactive fail too. After any deactivate/reactivate, uploads from spam reviews stay forever. That's the retention gap E3 was written to close (§6.3 "Why"), and a privacy issue (PRD-00 §2.9).

**Fix text (add to §6.3):**
> - **Re-sweep:** the hourly `ndvr_requests_recover` job (RR-09) also selects up to 200 `comment_id`s from `Db::table( 'review_media' )` joined to `{$wpdb->comments}` on `comment_approved IN ('spam','trash')`, where `as_has_scheduled_action( 'ndvr_media_cleanup', [ 'comment_id' => $id ], 'ndv-reviews' )` is false. It schedules each one at `time() + DAY_IN_SECONDS * held_media_days`. The job re-checks the status when it runs, as today. So after reactivation every held review gets its cleanup within one retention period plus one hour.
>
> AC 3 add: "Mark a review with media spam, deactivate and reactivate (its job is gone), run `ndvr_requests_recover`: one `ndvr_media_cleanup` is pending for it. Running it with `held_media_days` = 0 deletes the rows."

## 9. MAJOR: RR-05 AC3 contradicts the RR-09 queue-time gate
**Defect.**
- RR-05 AC3: "An order with only excluded products: the request row ends `cancelled` with 'No reviewable products in this order.'"
- RR-09 §6.2 step 2 runs `check_eligibility( stage=queue )` for every source, with only the cooldown limited to manual. Step 7 therefore returns `ndvr_nothing_to_review` and inserts **no row**.
- RR-05's own AC5 and AC8 say exactly that ("creates no row").

So AC3 fails as written. §3's sentence "Request log: a skipped order shows status 'Cancelled'" is wrong in the same way.

**Fix text:**
> AC3: "(a) An order with only excluded products, completed after the exclusion is saved: `queue_for_order()` returns `ndvr_nothing_to_review` and no row exists. (b) An order queued **before** its only products were excluded: `Scheduler::process()` ends its row `cancelled` with 'No reviewable products in this order.' and no email goes out."
>
> §3: "Request log: an order queued before the exclusion and skipped at send time shows 'Cancelled' with the reason … An order that is excluded when it qualifies gets no row."

## 10. MAJOR: RR-04 test plan disables the automatic path its ACs depend on
**Defect.** Test plan step 1 sets up "the plugin, reminders off". AC5, AC6, AC7 and AC12 rely on `update_status( 'completed' )` queueing an `auto` row. `on_order_status()` returns immediately when `reminder_enabled` is off (Scheduler.php:77-79), so under the stated harness those ACs can't pass. That blocks DoD (PRD-00 §3).

**Fix text (test plan step 1):**
> Playground with WooCommerce and the plugin, and three orders with fixtures as above. AC1 to AC4 and AC8 to AC11 run with reminders **off** (manual sends don't need them). AC5 to AC7 and AC12 run with reminders **on**, `reminder_status = completed` and `reminder_delay_days = 0`. The `scheduler` service is re-registered after the setting changes, because the status hook is bound in `register()` (Scheduler.php:66-67).

---

## Code citations spot-checked (36)
Correct:
- **Free, install and upgrade:** Installer.php:28; Activator.php:26-27, :29-31; uninstall.php:21-35, :55-68.
- **Free, moderation and settings:** ListTable.php:54, :334-341, :337; SettingsPage.php:101-113, :164/209/230; RequestsPage.php:121-127, :143-146, :149-160, :201-216, :242, :327-335, :351-352.
- **Free, requests:** Scheduler.php:91, :95-114, :121, :134-151, :160-163; Mailer.php:70-113, :99, :104.
- **Free, reviews and forms:** Reviewable.php:24-43, :36-37, :52-72, :81-97; ReviewForm.php:83-92, :108-132, :119-121, :351, :383, :386-388, :427-429; ReviewRepository.php:89, :131, :139-142, :144, :160, :185, :297, :321; Landing.php:193-205, :212-213, :232-237, :295-298, :311-313, :334, :379-394, :403-436; TestimonialForm.php:147-152, :281-283; VerifiedBuyer.php:48-56; Csv.php:29, :105-108, :150-151, :161-168; WooNative.php:74-81.
- **Free, display and other:** RatingCache.php:110; AggregateStore.php:83-85; Renderer.php:248, :256, :351, :355-357; ReviewQuery.php:60, :72-75, :314; Display/Summary.php:27; JsonLd.php:114-115, :146; review-item.php:38, :47, :105; marquee.php:44-46; Actions.php:43-48, :68; Exporter.php:111; DashboardPage.php:244-246; HealthCheck.php:63-70; Plugin.php:130-132, :259-264, :462-488; TokenRepository.php:123; rosette-reviews.php:34.
- **Pro:** Channel/Email.php:44-46; BulkCampaign.php:116, :125-131, :137-140; Engine.php:59-73, :81, :130, :150-151, :177; Dispatcher.php:112, :116, :131-138; Plus.php:39-41, :52; PurchaseGating.php:52; CouponReward.php:93, :117; TopReviewer.php:36; Highlight.php:43; ExternalReviews.php:876; ProImporter.php:31, :443; ManualReviews.php:164, :298-327.
- **WP 7.1.3:** comment.php:1365-1370, :1407; class-wp-locale-switcher.php:48-50, :75-83.
- **WC:** 11.2.0 is on disk.

Wrong, but the design doesn't change (fix while editing):
- RR-04 §5 `RequestRepository.php:238-244`: the file is 156 lines; `exists_for_order()` is at :67-73.
- RR-08 §6 "Landing.php:147": the `View::render( 'magic-landing.php' )` call is at :148.
- RR-03 §8 "nonce, then `Caps::manage()`, SettingsPage.php:86-90": the code checks the capability first (:87), then the nonce (:90). RR-00 F6 should put the nonce first (finding 3).

## Other checks (no finding)
- **Schema constants:** `V_FOUNDATIONS`, `V_PIPELINE`, `V_QA_EMAIL` are used consistently. Bare numbers appear only as "planned".
- **`NDVR_API` levels:** 2, (3: finding 6a), 4, 5 (RR-03), 6 (RR-06), 7 (RR-11). Pro gates on 4. No collisions.
- **Interactive sources:** `Sources::interactive()` includes `list_link`, assigned in RR-09.
- **Request pipeline:** RR-04, 05, 06, 07 and 08 insert no `ndvr_requests` rows and send no request mail outside RR-09. The legacy direct `send_for_order()` path is documented (RR-09 §5).
- **Prefixes:** all hooks use `ndv-reviews/`, AS hooks `ndvr_`, options `ndv_reviews_*`.
- **Settings `page`:** RR-03 `settings`; RR-05/06/07 `reminders`.
- **Legal:**
  - No feature gates or delays reviews on rating. The RR-03 native hold applies to every rating.
  - The RR-06 follow-up depends only on unreviewed products, never on sentiment.
  - The RR-00b incentive pill can't be switched off.
  - RR-07: silence is `not_objected`, opt-in honours only `yes`, and `no`/`erased` always block.
- **Uninstall ordering:** RR-00 F2 runs comments, then tables, then registry, and works without the plugin loaded or WooCommerce APIs.
- **Open questions:** "None" in every PRD.
