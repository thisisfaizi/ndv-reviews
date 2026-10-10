# Code review: RR-09 request pipeline v2

Reviewer: independent (read-only; `php -l`, grep and diff only) · Date: 2026-10-10
Scope: diff of `D:/.devcache/patches/pre-RR-09/` against the current free plugin. I also checked how the Pro APIs are used in `rosette-reviews-pro/includes` and the Pro PRDs that consume RR-09 (RR-06P, RR-10).
Spec: `RR-09-request-pipeline-v2.md` (rev 4), PRD-00, RR-00.

**Verdict: CHANGES REQUESTED.** There are no blockers and 3 majors. The minors can be fixed in the same pass or tracked.

`php -l` is clean on all 17 touched files.

---

## What I checked and found correct (coverage)

### MySQL vs SQLite
- **INSERT IGNORE.** `insert_unique()` decides on affected rows (`1 === $affected`), not `insert_id`. On a duplicate, MySQL returns 0 affected rows and the code falls back to `find_by_dedupe_key()`. A failure with a NULL key returns id 0, which becomes `ndvr_insert_failed`. wpdb treats `INSERT IGNORE` as an insert (regex `^\s*insert\s`), so `insert_id` is still populated.
- **UNIQUE on a nullable `varchar(120)`.** Multiple NULLs are allowed on InnoDB.
- **`email(100)`.** `email` is `varchar(191)`, so this is a true prefix (`Sub_part`=100, not NULL). dbDelta rebuilds both sides as `email(100),sent_at`. `UNIQUE KEY dedupe (dedupe_key)` matches the `Non_unique=0` normalisation, and `longtext DEFAULT NULL` has no quoted default for dbDelta to compare.
- **`COUNT(DISTINCT CASE … END)`** ignores NULLs on MySQL and MariaDB.
- **Not verified:** AC1's "no ALTER on the second install" was only proven through the SQLite translator. Run it once on real MySQL 8 and MariaDB 10.6 as a release gate. I don't expect a failure.

### Atomicity
- `claim()` is a single conditional UPDATE, and only `1 === changed` proceeds.
- Concurrent Action Scheduler runs, a double Retry, and a recover-vs-process race (recover re-enqueues a row whose job has just completed) all end with one send, because the second claim fails.
- A crash between insert and schedule leaves an orphan that `recover()` picks up.

### Time zones
- Every write uses UTC: `gmdate()` or `current_time( 'mysql', true )`.
- Every comparison passes the time as a value (never `NOW()`) and parses with `' UTC'`.
- The legacy rows' `scheduled_at`/`sent_at` were already UTC.

### Action Scheduler args
- `schedule_send()`, `recover()`'s `as_has_scheduled_action()`, the pre-RR-09 scheduler and Pro `BulkCampaign` (`$requests->insert()` returns `(int)`) all use `array( 'request_id' => int )`, so the JSON args match.
- The group is `ndv-reviews` everywhere.
- Deactivation's `as_unschedule_all_actions()` covers both hooks through the registry.

### Upgrade window (`is_current( V_PIPELINE )` false)
| Path | Behaviour |
|---|---|
| `on_order_status` | `queue_legacy()` (old columns plus the `exists_for_order` guard) |
| `process()` | `process_legacy()` |
| `retry()` | resets to `scheduled`, then the legacy process |
| `recover()` | returns |
| `queue_for_*` | `ndvr_not_ready` |
| `find_by_token` | null, so Landing doesn't track |
| Pixel | the HMAC is checked first, then `is_current` |
| `stats()` | zeros |
| `anonymize_email` | email only |
| Privacy export / RequestsPage | `isset` / `empty` guards |
| Cooldown queries | old columns only |

The exception is `cancel_pending_for_order` (m7).

### Security
- **Pixel:** `absint` id, `hash_equals` against `wp_hash` truncated to 20 hex characters. An invalid HMAC does no DB work, and every response is the same GIF.
- **Landing and list tokens:** the linkage comes from the resolved token row id, never the URL. A list token's email comes from the request row and must match the token's `email_hash` (`email_matches()`), so a list token can't post as another address. After erasure the row's email is NULL, and the submission answers 410.
- **Admin:** the Retry and Save paths keep nonce-then-capability. All new log columns are escaped.

### Privacy
- **Tokens for list emails:** `erase()` → `TokenRepository::delete_for_email()` deletes token rows by `email_hash`, whatever their type, so `list` tokens are erased with the rest.
- **Exporter:** it doesn't export token rows. That predates RR-09. Token rows hold only an HMAC of the email and product statuses, so they are pseudonymous, and I consider this acceptable under PRD-00 §2.9.
- **Request rows:** the exporter covers source, opened, reviewed and the list first name. The eraser nulls `email`, `meta`, `opened_at` and `reviewed_at` (guarded for the upgrade window). Unsent rows are covered only partly: see M3.

### Eligibility and outcomes
- The order follows PRD §6.2: order, status, email, suppression, (consent: RR-07), (role: RR-05), reviewable, cooldown, then the filter last.
- The cooldown runs at queue time only for `manual` and at send time always. It ignores status, so converted rows count.
- `SKIP_CODES` and `is_skip_code()` map to `cancelled`; `ndvr_mail_failed` and anything unknown map to `failed`.
- With `request_id`, the send skips eligibility. The legacy direct path runs `origin=pro, source=legacy` with the cooldown.

### Legal
No step uses a rating. `has_reviewed` counts any review, and the email copy is neutral. **No gating found.**

### Storefront performance
- Zero new queries on normal storefront requests: the pixel check is an `isset( $_GET )`, and the recover check is on `admin_init` only (see m3 for admin-ajax).
- The Landing page adds one indexed SELECT and one conditional UPDATE.

### Pro back-compat
- `Channel\Email::send()` → `send_for_order( $id )` keeps its signature and return type.
- `BulkCampaign` → `request_repository->insert()` / `exists_for_order()`, `Scheduler::SEND_HOOK` and `scheduler->process()` all still exist.
- `Engine` and `Esp\Dispatcher` → `token_repository->create_order_token()` keeps its signature.
- `reviewable->for_order()` and `mailer->is_suppressed()` are unchanged.
- Pro constructs no `Mailer`/`Landing` directly, so the new optional constructor arguments are safe.

### `order_already_requested`
- Nothing in RR-09's code applies it. `ndvr_already_requested` is only reserved in `SKIP_CODES`, and the `NDVR_API` 3 scope (§6.2) doesn't list the filter. Leaving it unbuilt is fine.
- However, LOG.md:611 says "its only consumer is RR-04's manual send". That's wrong: RR-06 also consumes it (RR-06 lines 45, 57, 75, 82, AC9), and RR-06P lists it in its reuse map. See m10.
- M1 is not about this filter. It is a regression of the old `exists_for_order` guard.

---

## MAJOR

### M1. Rows written before the update (and Pro BulkCampaign rows) no longer stop a second automatic request
**Where:** `includes/Requests/Scheduler.php:374-380` (`on_order_status` new path) and `:172-175` (dedupe key).

**Failure scenario:**
1. An order was completed before the update. Its reminder went out from a `legacy` row with `dedupe_key` NULL.
2. After the update the order re-enters the trigger status, for example completed → processing → completed after an edit, or a shipping or status plugin cycling statuses.
3. `on_order_status()` calls `queue_for_order( source=auto )`. The key `o:{id}:free:1:auto` doesn't collide with the legacy row, so a new row is inserted and scheduled `reminder_delay_days` ahead.
4. At send time, the only protection is the 20 h cooldown, which has long expired. The customer gets a second review request for the same order.

Before RR-09, `exists_for_order()` blocked this forever. The same happens for:
- rows written through `queue_legacy()` during the upgrade window;
- every Pro `BulkCampaign` / `send_one()` row, which keep using `insert()` and so are `legacy` until RR-06P ships.

RR-09's first goal is "no double sends", so this is a regression on upgraded stores.

**Fix:** keep a narrow version of the old guard for legacy rows. This needs no data step.
- Give `exists_for_order()` the optional argument that PRD §5 already implies ("`exists_for_order( $id )` with no argument keeps 'any email row'"): `exists_for_order( $order_id, ?array $sources = null )`. With no argument it keeps today's behaviour. With an argument it adds `AND source IN (…)`, guarded by `is_current( V_PIPELINE )`.
- In `queue_for_order()`, when `source === 'auto' && origin === 'free' && step === 1` and `$this->requests->exists_for_order( $order_id, array( 'legacy' ) )` is true, return the newest legacy row's id (or `WP_Error( 'ndvr_already_requested' )`) without inserting or scheduling.
- Add an AC: a legacy row exists for order X, and `on_order_status( X )` after the upgrade creates no row and no action.

### M2. `queue_for_order( source=campaign )` gets no dedupe key, which breaks the RR-06P contract this API level freezes
**Where:** `includes/Requests/Scheduler.php:173-175`, `:283`.

**Failure scenario:**
- RR-06P §5 moves `BulkCampaign::handle()` to `queue_for_order( $order_id, ['origin'=>'pro','source'=>'campaign','meta'=>['campaign_id'=>…]] )`. Its reuse map (RR-06P:48) relies on "for `campaign` rows `c:{campaign_id}:md5(email)`". It promises "a customer with two eligible orders in one campaign gets one request".
- Free builds a key only for `auto`/`followup`, so a campaign order row gets `dedupe_key` NULL. Two orders of the same customer in one campaign get two requests.
- If they are queued with the 30 s Pro spacing, the second is not stopped by the per-order cooldown, because the cooldown is per order, not per email, for order rows.
- A double-submitted campaign form also duplicates rows.

**Second problem:** `source` is only `sanitize_key()`ed. Any string is accepted. On MySQL, INSERT IGNORE silently truncates a source longer than 20 characters instead of failing. Unknown sources also get no key.

**Fix:**
- In `normalize_args()`, allow only `auto|manual|followup|campaign`; anything else returns `WP_Error( 'ndvr_bad_source' )`.
- In `queue_for_order()`, for `source === 'campaign'`, require `meta.campaign_id` (allow 0 for the `send_one()` path RR-06P describes). Build the key as `'c:' . $campaign_id . ':' . md5( strtolower( billing_email ) )`, the same formula as `queue_for_email()`. Force `followup` false, as the code already does for non-free-auto/manual.
- Add an AC: two orders with the same billing email in campaign 9 give one row and one action.

### M3. Erasure leaves `failed` rows one Retry away from emailing the erased person
**Where:** `includes/Requests/RequestRepository.php:386` (`cancel_pending_for_email` only touches `scheduled`), `:210` (`claim()` accepts `failed`), `includes/Requests/Mailer.php:150`, `:244` (order rows read the order's billing email, not the row's).

**Failure scenario:**
1. A request to `a@example.com` failed (SMTP outage), and the customer then asks for erasure.
2. The eraser nulls the row's email and leaves it `failed` with the "Retry" link.
3. Days later the merchant clicks "Retry all failed" one by one. `claim()` accepts `failed`, eligibility reads `$order->get_billing_email()` (WooCommerce keeps the order by default), and the email goes out to the erased address.
4. The same happens with a row that is `sending` at erasure time and is later reset to `failed` by `recover_stuck()`.

PRD §6.3 says "every status `scheduled`", but `failed` is just as pending, because the claim treats it as sendable. Erasure must make every unsent row unsendable.

**Fix:**
- In `cancel_pending_for_email()`, use `status IN ('scheduled','failed')`.
- In `process()`, after the claim, when `is_current` is true and the row's `email` is NULL, call `finish_with_error( ndvr_erased )`. This also covers the `sending` race.
- In `retry()`, refuse rows whose email is NULL.
- Amend PRD §6.3 to "scheduled or failed".
- Extend AC11 to cover a failed row: after erasure, `retry()` returns false or the row ends `cancelled`, and no mail is sent.

---

## MINOR

**m1. Overview conversion understated for 90 days after the update; Sent missing from the 90-day block.**
- Where: `RequestRepository.php:456-466`, `DashboardPage.php:550-570`.
- Rows sent before the update (`source='legacy'`, `token_id` NULL) count in `orders_sent` but can never get `reviewed_at`, so Conversion reads low until they age out. Every row Free sent through the new path has a `token_id`. Pro's legacy direct `send_for_order()` writes no row.
- PRD §3 asks for "Sent · Link opened · Reviewed · Conversion" over 90 days. The block shows no Sent. It sits next to the 30-day, scheduled-based Sent, so "Link opened" can exceed "Sent".
- Fix:
  - Add `AND token_id IS NOT NULL` to the stats WHERE.
  - Show `$tracking['sent']` as the first figure of the 90-day block.

**m2. Rows that are sent again change `token_id`.**
- Where: `Mailer.php:250-253`, `:298-301`; `TokenRepository.php:164-183`.
- A `failed` row (for example `wp_mail()` returned false although the relay accepted the mail, or `recover_stuck`) that is retried creates a new token and overwrites `token_id`.
  - For order rows, the first email's link then loses open and conversion tracking.
  - For list rows, it answers **410**, because `find_by_token()` can't find the row and Landing needs the row's email (`Landing.php:320-323`).
- Also, `create_row()` returns `$wpdb->insert_id` without checking `$wpdb->insert()`. When wpdb rejects the data before querying, `insert_id` is stale, for example the Action Scheduler log row inserted earlier in the same job. `set_token()` then links a wrong id, and the emailed link is dead.
- Fix:
  - Return `id => 0` when `insert()` is false, and have both send paths return `WP_Error( 'ndvr_token_failed' )` (retryable) instead of mailing a dead link.
  - Then either:
    - add `request_id bigint unsigned NULL` plus `KEY request_idx` to `review_tokens` in v4 (it hasn't shipped yet) and resolve the request from the token row;
    - or record earlier token ids in `meta.tokens` and look them up in `find_by_token()`.

**m3. The recover check runs on admin-ajax too.**
- Where: `Scheduler.php:97`, `:592-604`.
- `admin_init` fires on every `admin-ajax.php` request, including the Landing submit and theme or plugin front-end AJAX. AC13 says storefront requests never run `as_has_scheduled_action( 'ndvr_requests_recover' )`, but this one does, at most hourly.
- Without a persistent object cache, the throttle itself is one primed option query (`wp_prime_option_caches()` in `get_transient()`) on every admin page and heartbeat. That is about the cost of the lookup it avoids.
- Fix: return early when `wp_doing_ajax() || wp_doing_cron()` and not `$force`. Optionally, store the next-check time in an autoloaded option (for example `ndv_reviews_recover_next`, added to the registry) so the check costs zero queries.

**m4. Recovery backlog throughput.**
- Where: `Scheduler.php:568-582`; `set_scheduled_at()` (`RequestRepository.php:251`) is never called.
- Re-enqueued rows keep their original `scheduled_at`, so the next hourly run selects them again (oldest first). They are skipped because they have a pending job, but they still fill the 200-row window.
- After a long deactivation with more than 200 orphans, each run handles fewer new orphans.
- Fix: call `set_scheduled_at( $id, gmdate( 'Y-m-d H:i:s', $when ) )` when re-enqueuing, so the log also shows when the request will actually go out. The expiry check only runs on rows with no job, so this doesn't weaken it.

**m5. `{order_number}` prints a fake "1001" in real list emails.**
- Where: `Mailer.php:691`.
- A list recipient's custom subject or body containing `{order_number}` shows `1001`, a sample value meant for previews.
- Fix: return `''` when `get_id()` is 0 and the context isn't a preview. Pass a `$preview` flag from `preview()`/`send_test()`.

**m6. Free ignores WooCommerce's "verified owners only" setting for `list_link` reviews.**
- Where: `Landing.php:300-340`.
- A list token no longer proves purchase, but only Pro's `PurchaseGating` refuses non-buyers. With free alone and `woocommerce_review_rating_verification_required = yes`, a non-buyer on an uploaded list can still post (unverified), contrary to the store setting the onsite form enforces (`ReviewForm.php:386-387`).
- No free feature sends list rows yet (RR-10 is Pro), so this isn't a major. It should still be right before `NDVR_API` 4 exposes the feature.
- Fix: in `handle_submit()`, for `list` tokens, when that option is `yes` and `! wc_customer_bought_product( $email, 0, $product_id )`, answer 403 "Only verified owners can review this product." The landing template should show the same message.

**m7. `cancel_pending_for_order()` (an `NDVR_API` 3 API, used by RR-04) has no upgrade-window guard.**
- Where: `RequestRepository.php:352-371`.
- It writes `origin` and `dedupe_key`, so a call before v4 is a SQL error.
- Fix: `if ( ! Installer::is_current( Installer::V_PIPELINE ) ) return 0;`

**m8. `check_eligibility()` needs an extra `list` key that PRD §6.2 doesn't list.**
- Where: `Mailer.php:105`.
- The PRD's context keys are `stage, source, origin, step, request_id` "plus `email` and `products` for list rows". A caller following the PRD (`null` order, `email`, `products`) gets `ndvr_no_order`.
- Fix: when the `list` key is absent, infer it as `null === $order && '' !== $context['email']`. `process()` already passes it explicitly. Also document in CONTRACTS that a filter returning anything other than `true` or a `WP_Error` (for example `false`) is ignored.

**m9. PRD/contract drift to resolve in the docs.**
- PRD §6.2 says `insert()` "accepts the new columns", but it is legacy-shape only, and `insert_unique()` is the real writer. Either amend the PRD or let `insert()` pass `source`/`origin`/`meta` through when `is_current`.
- `create_list_token()` is a public level-3 API, but its token only works when a request row links it (Landing reads the email from the row). Say so in CONTRACTS ("create list tokens only through `queue_for_email()`").
- The PRD-00 §4 ledger row for v4 omits `dedupe_key`, `claimed_at`, `email_sent` and `status_claim`.
- The readme has no privacy-notes paragraph. It should say:
  - that erasure keeps `order_id`/`customer_id` (PRD §6.3 "which is documented");
  - that link-open tracking can count a mail scanner's prefetch as an open.

**m10. Correct LOG.md:611.**
- `order_already_requested` is consumed by RR-04 **and** RR-06 (and listed in RR-06P's reuse map).
- Record in TASKS that whichever of RR-04/RR-06 merges first builds the filter and its `ndvr_already_requested` mapping.
- No RR-09 change is needed.

**m11. Small performance items.**
- `cancel_pending_for_email`, `for_email` and `anonymize_email` use `LOWER(email) = %s`, which can't use `email_sent` and so scans the whole table. With the default `_ci` collations, `email = %s` is already case-insensitive. Keep `LOWER()` only if SQLite parity matters (erasure and export are rare, so this is low priority).
- `stats()` has no `sent_at` index and is invalidated by every `set_status()`, not only `mark_*` (PRD §10), so on a busy store the dashboard scans the table on most loads. Either flush only on `mark_*`/erasure and let the 10-minute TTL cover sends, or add `KEY sent_at (sent_at)`.
- The pixel's first hit also deletes two transients (`flush_stats()`). That's a slight deviation from "one UPDATE and nothing else" (§8); acceptable if it's noted in the waiver.

**m12. Nits.**
- `process()` sets `sent` unconditionally after `wp_mail()`. A review submitted in the seconds between delivery and that UPDATE would flip `converted` back to `sent`. Fix: `UPDATE … SET status='sent' … WHERE id=%d AND status='sending'`.
- `DashboardPage` builds `new RequestRepository()` instead of using the container service.
- The new `opened_at`/`reviewed_at` columns show raw UTC with no time-zone label, like the existing `scheduled_at`/`sent_at`. Consider `get_date_from_gmt()` for all four.

**m13. v4 can be stamped with the columns missing, and then every automatic reminder fails silently.**
- Where: `Installer.php` `run_locked()` / `missing_tables()`; `Scheduler.php:374-380`.
- `missing_tables()` checks tables, not columns. If dbDelta's `ALTER` silently fails (for example a hardened DB user without `ALTER`), v4 is still stamped.
- Every `queue_for_order()` then fails with `ndvr_insert_failed`, which `on_order_status()` discards. Automatic reminders stop with no log row and no notice. This is the only total failure mode that goes unnoticed. It's unlikely on mainstream hosts, so it stays MINOR.
- Fix:
  - probe `SELECT source, origin, dedupe_key, token_id, claimed_at, opened_at, reviewed_at, meta FROM {requests} LIMIT 0` in `run_locked()` after `install()`, and return a `WP_Error` when it fails (so the backoff, the error option and the legacy paths apply);
  - in `on_order_status()`, fall back to `queue_legacy()` on `ndvr_insert_failed`.

---

## Acceptance criteria against the code

| AC | Code | Status |
|---|---|---|
| 1 Upgrade / no 2nd ALTER | schema at Installer.php:437-465 | OK in code; prove on MySQL/MariaDB (release gate) |
| 2 Claim / stuck → failed | `claim()`, `recover_stuck()` | OK |
| 3 Dedupe, delay ±60 s | `insert_and_schedule()`, `on_order_status()` | OK (auto); campaign order rows see M2 |
| 4 Cooldown incl. converted; manual inserts nothing | `eligibility_steps()` | OK |
| 5 Not ready + legacy row sends | `queue_legacy()`/`process_legacy()` | OK; legacy rows then lose protection (M1) |
| 6 Open once, convert + action | Landing, `mark_*` | OK |
| 7 List rows | `queue_for_email()`, `send_to_list_recipient()`, Landing | OK; see m2 (retry), m5, m6 |
| 8 UTM | `tracked_link()` | OK |
| 9 Pixel | `Tracking` | OK |
| 10 Legacy direct path | `send_for_order()` without `request_id` | OK |
| 11 Erasure | `cancel_pending_for_email()` | OK for scheduled; failed rows see M3 |
| 12 Per-order conversion | `stats()` | OK; legacy rows dilute it (m1) |
| 13 Orphans, recover pending, no storefront check | `recover()`, Activator, `admin_init` | OK; admin-ajax caveat (m3) |
| 14 Settings save | Tracking fields + markers | OK |
| 15 `NDVR_API` 3 | rosette-reviews.php:38 | OK |
| 16 Core flows | (runtime evidence 49/49) | OK |

## Required before approval
M1, M2 and M3, each with a QA assertion added to `.agents/qa/rr-09.php`. In the same pass it's worth doing m7 and m2's insert check (both one-liners) and m13's column probe.
