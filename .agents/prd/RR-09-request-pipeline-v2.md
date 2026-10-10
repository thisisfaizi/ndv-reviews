# RR-09: Request pipeline v2 (queue API, eligibility, atomic sends, tracking)

Status: prd-ok (rev 4) · Plan: F · Inherits PRD-00 + RR-00 · **Schema `Installer::V_PIPELINE`** (planned v4, PRD-00 §4; final at merge) · Raises `NDVR_API` to 3 · Built right after RR-00.
Consumers: RR-04, 05, 06, 07, 08, 22 (free); RR-06P, RR-10 (Pro).

## 1. Problem
- **Double sends:** `Scheduler::on_order_status()` is check-then-insert and `process()` is check-then-send with no claim (Scheduler.php:95-114, 134-151). Concurrent Action Scheduler runs, a Retry click or duplicate status events can send twice.
- **No outcome tracking:** the dashboard's `converted` count is never set.
- **Scattered eligibility:** the checks live inside `Mailer::send_for_order()`, but new rules (consent, exclusions, cooldown) and Pro's ESP sender need one gate.
- **No shared queue API:** new features need one with origin and step semantics. It must be free, canonical API (AGENTS §3.9).

## 2. Scope
In scope:
- schema v4;
- `queue_for_order()` / `queue_for_email()`, the only creators of request rows;
- `check_eligibility()`, the single gate;
- an atomic claim with crash recovery;
- a send-time cooldown;
- tracking: link opened, reviewed, optional pixel and UTM tags;
- list recipients and the `list_link` source;
- erasure that cancels pending sends.

The upgrade machinery (init hook, lock, steps, `is_current`) is RR-00 F3.

Non-goals:
- other channels;
- click tracking of non-review links.

## 3. User experience
- **Review Reminders settings**, fieldset "Tracking":
  - "Add UTM tags to the review link" (off). Tags: `utm_source=rosette-reviews&utm_medium=email&utm_campaign=review-request`, plus `utm_content=followup` on follow-ups.
  - "Count email opens with a tracking image" (off). Help: "Adds a 1×1 image to the email. Some mail apps block it, and some privacy laws need consent for it. Link clicks and reviews are counted without it."
- **Request log:**
  - columns: Source (Automatic, Manual, Follow-up, Campaign, Pro automation, Earlier version); Link opened; Reviewed;
  - the status pill shows "Reviewed" for converted rows.
- **Overview:** Sent · Link opened · Reviewed · Conversion. Conversion is orders with at least one converted request divided by orders with at least one sent request, over the last 90 days.

## 4. Reuse map
`RequestRepository`, `Scheduler`, `Mailer`, `TokenRepository`, `Landing`, `RequestsPage`, `DashboardPage`, `Installer` (RR-00 F3), `Uninstall` registry (F2), `Privacy`.

## 5. Blast radius
- **Pro `Channel\Email`** calls `Mailer::send_for_order( $order_id )` (Channel/Email.php:44-46). Stays supported as the **legacy direct path**: it runs `check_eligibility( stage=send, origin=pro )` internally, needs no row and returns `true|WP_Error` unchanged. PRD-00 §5 is amended: "new code must use rows; the legacy direct call remains for older Pro."
- **Pro `Automation\BulkCampaign`** inserts rows directly without a source (BulkCampaign.php:125-131). Such rows get `source='legacy'` (column default), which never earns a free follow-up. `exists_for_order( $id )` with no argument keeps "any email row" (BulkCampaign.php:116, :178). Pro task (RR-06P) moves it to `queue_for_order`.
- **Pro `Automation\Engine`:** moved to `queue_for_order` by RR-06P, which keeps the legacy runner registered on an older free.
- **Pro `Esp\Dispatcher`:** RR-06P calls `check_eligibility()` when `NDVR_API >= 4` (RR-00 F3b, RR-00b E12). On an older free it keeps today's `is_suppressed()` check. There's no `method_exists` guard.

## 6. Contract delta

### 6.1 Schema v4, `ndvr_requests`
New columns:
- `source varchar(20) NOT NULL DEFAULT 'legacy'`: legacy|auto|manual|followup|campaign. Existing rows become `legacy`, shown as "Earlier version".
- `origin varchar(10) NOT NULL DEFAULT 'free'`: free|pro.
- `dedupe_key varchar(120) DEFAULT NULL`, plus `UNIQUE KEY dedupe (dedupe_key)`. NULL never collides.
- `token_id bigint(20) unsigned DEFAULT NULL`.
- `claimed_at datetime DEFAULT NULL`, `opened_at datetime DEFAULT NULL`, `reviewed_at datetime DEFAULT NULL`.
- `meta longtext DEFAULT NULL`. JSON: reserved keys `followup` (bool), `variant` (first|followup), `first_name`, `products` (int[]), plus caller keys under `ext`.

New keys:
- `KEY token_idx (token_id)`
- `KEY order_sent (order_id, sent_at)`
- `KEY email_sent (email(100), sent_at)`
- `KEY status_claim (status, claimed_at)`

Status values: `scheduled` → `sending` (claimed) → `sent` | `failed` | `cancelled`; `sent` → `converted`.

### 6.2 Services (instance methods, reached via `Plugin::instance()->container()->get('scheduler'|'mailer')`)
**`Scheduler::queue_for_order( int $order_id, array $args = [] ): int|WP_Error`** returns the request id. Args:
- `source` ('auto'), `origin` ('free'), `step` (1), `delay` (seconds, 0), `variant` ('first')
- `followup` (bool): true by default only for `origin=free` with source `auto`|`manual`; forced false otherwise
- `meta` (array, stored under `meta.ext`, reserved keys can't be overwritten)
- `skip_checks` (bool, false)

**`Scheduler::queue_for_email( string $email, string $first_name, int[] $products, array $args = [] ): int|WP_Error`** queues a list recipient (`order_id=0`, `source='campaign'`, `origin` from args). Requires `args.meta.campaign_id`.

**Order of operations:**
1. If `! Installer::is_current( Installer::V_PIPELINE )`, return `WP_Error( 'ndvr_not_ready' )`.
   - The free automatic path writes a legacy-shape row instead (the old `insert()` columns only), so no automatic request is lost during the upgrade window.
   - Pro callers reschedule themselves in 5 minutes.
2. Queue-time eligibility (unless `skip_checks`): `check_eligibility( stage=queue, … )`.
   - The cooldown step runs at queue time **only for `source=manual`**: a manual send inside the cooldown inserts **no row** and returns `ndvr_cooldown`.
   - Other sources skip the cooldown at queue time.
3. **Dedupe key:**
   - auto/followup rows: `"o:{order_id}:{origin}:{step}:{source}"`
   - campaign rows: `"c:{campaign_id}:" . md5( strtolower( email ) )`
   - manual rows: NULL (always insert; the cooldown protects)
4. `INSERT IGNORE`. If no row was inserted (duplicate key), `SELECT id WHERE dedupe_key=%s` and return that id **without scheduling a second action**.
   - An insert that fails for any other reason returns `WP_Error( 'ndvr_insert_failed' )`.
5. Schedule Action Scheduler `ndvr_send_request` (`request_id`) in group `ndv-reviews`, at `time() + delay`.

**`Mailer::check_eligibility( ?\WC_Order $order, array $context ): true|WP_Error`.** Context keys: `stage` (queue|send), `source`, `origin`, `step`, `request_id`, plus `email` and `products` for list rows.

Steps, in order:
1. The order exists (skipped for list rows).
2. The order status is eligible (existing filter; skipped for list rows).
3. The email is valid (order billing email, or `context.email`).
4. The email isn't suppressed.
5. Consent allows it (RR-07; skipped for list rows, which rely on the campaign attestation).
6. The customer role isn't excluded (RR-05; skipped for list rows).
7. Something is left to review:
   - orders: `Reviewable::for_order()` not empty;
   - list rows: start from `context.products`. Once RR-05 is built, pass them through `Reviewable::filter_excluded()`; until then there's no exclusion step. Then drop ids where `$reviewable->has_reviewed( $email, $product_id )` (PRD-00 §6; `$user_id` stays 0 because list rows have no account). The result must not be empty.
8. Cooldown: no `sent_at` within `ndv-reviews/request_cooldown` (default 20 h) for this order (`order_sent` index), or for this email when it's a list row (`email_sent` index). Status doesn't matter, so converted rows count too.
9. Filter `apply_filters( 'ndv-reviews/request_eligible', true|WP_Error $eligible, ?\WC_Order $order, array $context )`, applied last. A listener that doesn't apply returns `$eligible` unchanged.

**Codes** (constant `Scheduler::SKIP_CODES`, filter `ndv-reviews/request_skip_codes`; these end in `cancelled`, everything else is `failed`):
- `ndvr_no_order`, `ndvr_order_ineligible`, `ndvr_no_email`, `ndvr_unsubscribed`, `ndvr_no_consent`, `ndvr_customer_excluded`
- `ndvr_nothing_to_review`, `ndvr_cooldown`, `ndvr_followup_disabled`, `ndvr_followup_filtered`, `ndvr_already_requested`, `ndvr_erased`, `ndvr_expired`

**`Scheduler::process( $request_id )`:**
1. `RequestRepository::claim( $id )`: `UPDATE … SET status='sending', claimed_at=NOW() WHERE id=%d AND status IN ('scheduled','failed')`. Continue only when exactly one row changed.
2. `check_eligibility( stage=send, context from the row )`. On error, set status (cancelled or failed by code) and stop.
3. Send:
   - order rows: `Mailer::send_for_order( $order_id, [ 'request_id' => $id, 'variant' => meta.variant ] )`. When `request_id` is given, Mailer **skips its own eligibility call** (already done) and stores the token id via `set_token()`.
   - list rows: `Mailer::send_to_list_recipient( $email, meta.first_name, meta.products, $id )`.
4. Set `sent` + `sent_at`, then fire **`ndv-reviews/request_sent`** (int `$request_id`, object `$row`). RR-06 follow-ups and RR-06P step chaining listen to this action; `process()` calls neither directly.

**Crash recovery:** the recurring Action Scheduler action `ndvr_requests_recover` (hourly, group `ndv-reviews`) returns at once unless `Installer::is_current( Installer::V_PIPELINE )`. Otherwise it does three things:
- It resets rows with `status='sending' AND claimed_at < NOW() - 1 HOUR` to `failed`, with the error "Interrupted while sending; retry".
- **Orphan re-enqueue (round 3).** Deactivation unschedules every `ndvr_send_request` job, which leaves `scheduled` rows with no job.
  - The recover job selects up to 200 rows with `status='scheduled' AND scheduled_at < NOW() - 15 MINUTE` (`status_idx`).
  - It acts on each row with no pending job, checked with `as_has_scheduled_action( 'ndvr_send_request', array( 'request_id' => $id ), 'ndv-reviews' )`. The args are shaped exactly as in Scheduler.php:121.
  - **Too old:** when `scheduled_at` is older than `apply_filters( 'ndv-reviews/request_max_overdue_days', 14 )` days, it sets `cancelled` with code `ndvr_expired`, logged as "Not sent: it was due more than %d days ago." A store reactivated after months therefore doesn't email customers about old orders.
  - **Otherwise:** it schedules the row at `time() + 60 * $i` (`$i` = the row's position in the batch), so a backlog goes out spread across time instead of in one burst.
- **Held-media re-sweep (RR-00b E3).** Once RR-00b is built, the job also re-schedules any missing `ndvr_media_cleanup` jobs, as RR-00b §6.3 specifies.
- **Registration:**
  - `Activator::activate()` and `admin_init` schedule the hourly `ndvr_requests_recover` when `as_has_scheduled_action( 'ndvr_requests_recover' )` is false. Front-end `init` never checks, so it costs storefront requests nothing.
  - The job is in the Uninstall registry and is unscheduled on deactivation.
  - After reactivation, activation recreates it, and its first run (within the hour) handles the orphans.

**`on_order_status()`** keeps its `reminder_enabled`, `should_send_reminder` and billing-email guards (Scheduler.php:77-102). It replaces only two things:
- the `exists_for_order()` guard (:95);
- the insert and schedule (:104-125).

Both become `queue_for_order( $id, [ 'source' => 'auto', 'delay' => max( 0, (int) $this->settings->get( 'reminder_delay_days', 7 ) ) * DAY_IN_SECONDS ] )`. That keeps the merchant's "days after" setting. Dedupe happens through the key, so repeated status events are harmless.

**`NDVR_API` 3:** this PRD changes `define( 'NDVR_API', 2 )` to `3` (RR-00 F3b). Level 3 covers:
- `queue_for_order()`, `queue_for_email()` and `check_eligibility()`;
- `SKIP_CODES`, `request_sent`, `request_converted` and the `request_eligible` signature;
- `create_list_token()` and the `list_link` source.

**`Mailer::send_for_order( $order_id, array $args = [] ): true|WP_Error`:**
- Without `request_id` (the legacy direct path, used by older Pro), it runs `check_eligibility( stage=send, origin=pro )`, which includes the cooldown.
- With `request_id`, it does no eligibility call.

**`Mailer::send_to_list_recipient( $email, $first_name, array $products, $request_id ): true|WP_Error`:**
- Builds an unsaved `WC_Order` as `sample()` does.
- Template variable `$context='list'` switches the footer to "You're receiving this because {store} asked for your review."
- Adds the unsubscribe header.

**`TokenRepository`:**
- `create()`'s allowed types (TokenRepository.php:123) add `list`.
- `create_order_token_row()` and `create_list_token( $email, array $product_ids )` both return `array{raw,id}`. The old `create_order_token()` keeps its signature.

**`Landing`:**
- **Open:** `find_by_token()` → `mark_opened()`, first view only; test tokens are ignored.
- **Conversion:** `mark_reviewed()`, which sets `reviewed_at` the first time and status `converted`, then fires **`ndv-reviews/request_converted`** (request_id, comment_id).
- **List tokens:**
  - the email comes from the request row (`token_email()` can't resolve it, Landing.php:379-394);
  - the review source is **`list_link`** (it moves from RR-00b E7 into this PRD), so `PurchaseGating`'s `magic_link` exemption doesn't apply;
  - the forced verified override (Landing.php:311-313) applies only to `order`/`customer` tokens.

**`RequestRepository`:** `insert()` (accepts the new columns), `claim()`, `set_token()`, `find_by_token()`, `mark_opened()`, `mark_reviewed()`, `last_sent_at_for_order()`, `last_sent_at_for_email()`, `cancel_pending_for_order( $order_id, array $sources )`, `cancel_pending_for_email( $email, $code )`, `recover_stuck()`, `stats( $days )`.
- `cancel_pending_for_order` touches **only `origin='free'`** rows in `scheduled`, sets `cancelled`, and clears their `dedupe_key`, so a later legitimate automatic queue can happen.

**Filters:**
- `ndv-reviews/request_eligible`, `ndv-reviews/request_cooldown`, `ndv-reviews/request_skip_codes`
- `ndv-reviews/request_email_texts` (array `{subject, body}`, object row, WC_Order|null)
- `ndv-reviews/order_already_requested` (bool, WC_Order)

**Settings keys** (RR-00 F6 registry, page `reminders`, card "Tracking"):
- `reminder_utm` (bool, false) and `reminder_open_pixel` (bool, false).
- **Already built by RR-00 F6:** the Reminders `save` branch sanitizes the registry keys whose `page` is `reminders` and that its form rendered (`SettingsFields::sanitize_page()`), and merges them. RR-09 only registers the two keys (with `render` callbacks, card `tracking`) and prints the "Tracking" card on the Reminders form with `SettingsFields::render_card_fields( 'reminders', 'tracking', $settings->all() )`, which also prints the `ndvr_fields[]` markers. Without the markers the keys are not saved.
- **Version bumps in this PRD:** `NDVR_DB_VERSION` 4, `Installer::V_PIPELINE = 4`, `NDVR_API` 3; PRD-00 §4 "Built" set to yes for v3 and v4. `ndvr_requests_recover` goes into `Uninstall::registry()['scheduler_hooks']`, which is also what makes the Deactivator unschedule it.
- Each sanitize callback receives `wp_unslash( $_POST[ $key ] )`, or `null` when the key is absent (an unticked box or an empty multi-select); `null` gives the empty value (`false`, `[]`).
- RR-05, RR-06 and RR-07 only register keys.

UI: the two checkboxes and their copy are in §3, "Tracking".

**Pixel:**
- query var `ndvr_px=<id>.<hmac20>` on `parse_request` priority 1;
- an invalid HMAC does no DB work and returns the GIF;
- a valid one calls `mark_opened()`, once, as a single-column update;
- a documented waiver of PRD-00 §2.2.

### 6.3 Privacy
- **Exporter:** request rows with source, link opened, reviewed and `meta.first_name`.
- **Eraser:**
  1. `cancel_pending_for_email( $email, 'ndvr_erased' )`: every origin, status `scheduled` or `failed` (a Retry must not send). `process()` also cancels any row whose email is empty, and `retry()` refuses one (code review M3).
  2. Null `email`, `meta`, `opened_at`, `reviewed_at`. `order_id`/`customer_id` stay (WooCommerce keeps the order itself), which is documented.
- **Readme:** tracking described; pixel off by default.

## 7. Storage and upgrade
- **Schema:** `Installer::V_PIPELINE`, with the columns and keys from §6.1. dbDelta only, no data step.
- **Existing rows:** they become `source='legacy'` (the column default) and `origin='free'`, with `dedupe_key` NULL, so they never collide with new keys.
- **Before the upgrade runs:** `is_current( V_PIPELINE )` is false, and the legacy-shape path in §6.2 step 1 applies.
- **New stored state:** the settings keys `reminder_utm` and `reminder_open_pixel`.
- **Uninstall:** `ndvr_requests` is already in `table_names()`. The registry adds the Action Scheduler hook `ndvr_requests_recover`.

## 8. Security
- **Retry** (GET `ndvr_retry`): `check_admin_referer( 'ndvr_requests' )`, then `Caps::manage( 'reminders' )` (RequestsPage.php:122-127), as today.
- **Reminders save:** the existing `RequestsPage` nonce and `Caps::manage( 'reminders' )` (:143-144) run before any work. Registry keys pass their own sanitize callbacks.
- **Pixel:**
  - public, with no nonce: the documented waiver of PRD-00 §2.2;
  - the id goes through `absint`, and the HMAC (`wp_hash` of the id, first 20 hex chars) is compared with `hash_equals`;
  - a valid request makes one UPDATE and nothing else, and always answers with the same GIF.
- **Landing:** tokens still resolve through `TokenRepository::resolve()` (hashed and single purpose). The `request_id` linkage is read from the token row, never from the URL.
- **SQL:** every query goes through `prepare()` with `Db::table( 'requests' )`.
- **Output:** log columns are escaped at output.

## 9. Privacy
See §6.3. The pixel is off by default and described in the readme privacy notes.

## 10. Performance and assets
- The claim is one UPDATE.
- The cooldown is one indexed `MAX(sent_at)`.
- Dedupe uses the unique index.
- Recovery is an hourly indexed query, at most 200 rows per run. It's registered from activation and `admin_init` only.
- Stats are cached for 10 minutes and cleared on `mark_*`.
- No new assets.

## 11. Compatibility
- **HPOS:** orders are read only through `wc_get_order()`.
- **Old Pro:** keeps working through the legacy direct `send_for_order()` path (§5). BulkCampaign rows written without a `source` become `legacy`.
- **New Pro on an old free:** below `NDVR_API` 4 (the level Pro gates on, RR-00b E12), Pro keeps its own paths (RR-06P).
- **Platforms:**
  - PHP 7.4, WP 6.0 and WC 8.0: `INSERT IGNORE` and `as_has_scheduled_action()` (Action Scheduler 3.3+, bundled with WC 8.0).
  - Block and classic checkout behave the same, because the trigger is the order status.

## 12. Acceptance criteria (Playground, PHP)
1. **Upgrade:** a v3 site with the v4 columns dropped upgrades on a front-end `init`, and every column and key exists afterwards. Old rows have `source='legacy'`. Running `Installer::install()` a second time issues no `ALTER TABLE` (query recorder), so the `email(100)` prefix key and the UNIQUE `dedupe_key` match dbDelta's normalized form.
2. **Claim:** calling `process()` twice in sequence, with the first call stubbed to stop after the claim, sends one email. A row stuck in `sending` with `claimed_at` 2 h old becomes `failed` after `recover_stuck()`.
3. **Dedupe:** two `queue_for_order( $id, ['source'=>'auto'] )` calls return the same id, and exactly one Action Scheduler action exists.
   - With `reminder_delay_days = 7`, completing an order creates an `auto` row. Its `scheduled_at` and its pending `ndvr_send_request` action are both now + 7 days (±60 s).
4. **Cooldown:** a second send to the same order inside 20 h is cancelled `ndvr_cooldown`, even when the first row is `converted`. A manual queue inside the cooldown returns `ndvr_cooldown` and inserts nothing.
5. **Not ready:** with `is_current( Installer::V_PIPELINE )` false, `queue_for_order` returns `ndvr_not_ready`, and the free automatic path writes a legacy-shape row that still sends.
6. **Tracking:** opening the link sets `opened_at` once; submitting a review sets `reviewed_at` + `converted` and fires `request_converted`.
7. **List rows:**
   - `queue_for_email( 'a@example.net', 'Ana', [ P1, P2 ], [ 'meta' => [ 'campaign_id' => 9 ] ] )` with P2 excluded sends an email listing only P1, with the list footer;
   - the review saves through the link with source `list_link` and that email;
   - it's verified only if that email bought P1;
   - a second queue for the same email in campaign 9 returns the same id.
8. **UTM on:** the email's review link carries the three utm params, and the landing page still resolves the token.
9. **Pixel on:** the email HTML contains the pixel. A valid HMAC sets `opened_at`. An invalid HMAC changes no row (query count unchanged).
10. **Legacy path:** `send_for_order( $id )` (old Pro) still returns true for an eligible order and `ndvr_unsubscribed` for a suppressed one.
11. **Erasure:** a scheduled row for an email is cancelled `ndvr_erased` and never sends.
12. **Conversion** on the Overview is per order.
13. **Orphans:**
    - Deactivate with two scheduled rows, one due 30 days ago and one due 1 day ago. Reactivate, then run `ndvr_requests_recover`.
    - The first row becomes `cancelled` / `ndvr_expired` and never sends. The second gets one pending job and sends once.
    - After activation the recover job itself is pending.
    - A front-end request runs no `as_has_scheduled_action( 'ndvr_requests_recover' )` query.
14. **Settings:** saving the Reminders form with the UTM box ticked stores `reminder_utm = true` and leaves `transparency_enabled` (page `settings`) unchanged. Saving again with the box unticked stores `reminder_utm = false`.
15. **API:** `NDVR_API === 3` after this PRD (before RR-00b).
16. The core-flow regression passes.

## 13. Test plan
- `.agents/qa/rr-09.php`, run with `wp eval-file`.
  - Mail is captured with `pre_wp_mail`.
  - Action Scheduler actions are inspected with `as_get_scheduled_actions()`.
  - Rows are read with direct prepared queries.
  - The "not ready" AC forces the stored version below `V_PIPELINE`.
- The deactivate/reactivate AC calls `Deactivator::deactivate()` and `Activator::activate()`.
- Then run `.agents/qa/core-flows.php`.

## 14. Open questions
None.
