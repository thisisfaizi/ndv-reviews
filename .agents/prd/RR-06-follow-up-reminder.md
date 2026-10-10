# RR-06: Follow-up reminder

Status: prd-ok (rev 4: code-review decisions below, reviews/code-RR-06.md) · Plan: F (free sends one follow-up; Pro's multi-step version is `RR-06P` in the Pro repo) · Inherits PRD-00 + RR-00 + RR-09 (rev 3) · No schema change, no DB version · Built after RR-09 · Raises `NDVR_API` to **6** (RR-00 F3b; planned build order, final at merge)

Review findings applied: round 1 batch A X2 (blocker), X3, X4 and RR-06 (all); round 2 RR-06 item 1 (blocker).

`Scheduler`, `Mailer` and `Followups` are instance services reached through the container (`scheduler`, `mailer`, `followups`; RR-09 §6.2). `Class::method()` below names the method, not a static call.

## Rev 4 — code-review decisions (2026-10-10, PRD owner)
- **Manual send before the trigger status (M1):** a free automatic step-1 row is cancelled at send time with `ndvr_already_requested` ("The customer already got a review request and a reminder for this order.") when the order already has a sent free follow-up. A follow-up still pending when the automatic email sends keeps its time (one per order).
- **Keys (m2):** any request cancelled at send time gives its dedupe key back (`RequestRepository::set_status( 'cancelled' )` sets it NULL), as `cancel_pending_for_order()` already did; a later legitimate follow-up can be queued.
- `Followups` receives the request repository (no container calls); the transparency fact `followup` needs reminders on too; the follow-up placeholder shows the greeting; the default texts live only in `Followups::default_texts()` (Mailer reads them).
- The log shows the follow-up as Source "Follow-up" (there is no Step column).

## 1. Problem and who it's for
One email misses customers who were busy that day. A second, polite email is the most-requested collection feature in the gap list (count 4). Yotpo (free plan), CusRev Professional, WiserReview and Judge.me all send at least one reminder. Who it's for: stores using free reminders or manual sends that want more reviews without a second tool.

## 2. Scope / non-goals
In scope:
- One optional follow-up email N days after a free request **was sent**, only while the customer still has products to review.
- Its own subject and text, with built-in defaults.
- Never sent after an unsubscribe, an ineligible status change, a withdrawn consent, a full review, or when another sender owns requests for that order.

Non-goals:
- More than one follow-up (Pro RR-06P).
- Other channels.
- Follow-ups for Pro-origin rows, Pro campaign rows or list recipients.

## 3. User experience
**Review Reminders → "Reminder settings"**, new rows after "Email text" (RequestsPage.php:327-333):
- "Follow-up": checkbox "Send one reminder if they haven't reviewed" (off by default).
- "Days after the first email": number, 1 to 60, default 7.
- "Follow-up subject": text; placeholder shows the default "A quick reminder: how was your order from {store_name}?"
- "Follow-up text": textarea; placeholder shows the default text below. Same merge tags as the first email.
- Help: "Applies to automatic and manual requests sent after you turn this on. Not sent if the customer has reviewed everything, has unsubscribed, or another sender handles review requests for the order."
- Button "Preview follow-up", next to "Preview email" (RequestsPage.php:351-352), opening the existing preview with the follow-up texts as typed.

**Default follow-up text** (used as the email intro when "Follow-up text" is empty), with the first email's greeting rule ("Hi {first name}," or "Hello," when there is no name, email-request.php:62):
"A few days ago we asked how your order from {store_name} went. If you have a minute, your review helps other shoppers choose."

**Email:** the same template, products, button, branding and unsubscribe link as the first email. The products are the ones still not reviewed, so a partial reviewer sees only the rest.

**Request log:** the follow-up is its own row: Source "Follow-up", step 2 (RR-09 column).

## 4. Reuse map
- RR-09 API:
  - the action **`ndv-reviews/request_sent`** (int `$request_id`, object `$row`), fired by `process()` after a row is marked `sent` (RR-09 §6.2, `process()` step 4). `process()` never calls follow-up code directly;
  - `queue_for_order()` with its dedupe key for `auto`/`followup` rows;
  - `Mailer::check_eligibility()` and the filter `ndv-reviews/request_eligible`;
  - `Scheduler::SKIP_CODES` (`ndvr_followup_disabled`, `ndvr_followup_filtered`, `ndvr_already_requested`);
  - filters `ndv-reviews/order_already_requested` and `ndv-reviews/request_email_texts`;
  - `Mailer::send_for_order( $order_id, ['request_id'=>…, 'variant'=>…] )`.
- `Mailer::subject()` / `body()` / `preview()` (Mailer.php:261-271, :284-328, :155-163).
- `Admin\RequestsPage::render_preview()` (RequestsPage.php:201-216).
- `templates/email-request.php` (intro at :55-70).
- RR-00 F6 registry and the Reminders save rule defined in RR-05 §6.

## 5. Blast radius
**Double sends between free and Pro (X2), closed by these rules:**
- **Pro BulkCampaign** rows are `source=campaign`, `followup=false` (RR-09 paired Pro task), so a past-order campaign never gets a free follow-up.
- **Pro automation (RR-06P)** rows are `origin=pro`, and RR-09 forces `followup=false` for them, so Pro's step 1 never triggers the free follow-up.
- **Free manual send while Pro automation or an ESP is on** (RR-04 skips `should_send_reminder` by design): `on_request_sent()` checks `apply_filters( 'ndv-reviews/should_send_reminder', true, $order_id )` and doesn't schedule when it is false. Pro's `suppress_free_reminder()` returns false whenever automation or any ESP is on (Pro Engine.php:59-73), so the customer gets the manual email and Pro's sequence, but no third free email.
- **Pro already asked** (`order_already_requested` true, for Pro's `SENT_META`/`PUSHED_META`): not scheduled, and re-checked at send time.
- **Send-time rules touch only free follow-ups.** The `request_eligible` listener returns `$eligible` unchanged unless `$context['origin'] === 'free' && $context['source'] === 'followup'`. Pro's step 2 and 3 rows (RR-06P, `origin=pro`) are never cancelled by `followup_enabled`, `should_send_followup` or this feature's `order_already_requested` check, whatever their `source`.

Other effects:
- **Scheduler::process():** unchanged by this PRD. Follow-ups hang off `ndv-reviews/request_sent`, which RR-09 fires.
- **Mailer:** subject and intro choose the follow-up texts when `variant=followup`.
- **RequestsPage:** new rows, preview variant, registry-driven save.
- **RR-03:** sets the transparency fact `followup`, which selects the "and send one reminder if they haven't reviewed yet" variant of the `requests` sentence.
- **RR-09 overview:** conversion is per order, so follow-ups don't deflate it.
- **Theme overrides of `email-request.php`:** keep working (section 11).

## 6. Contract delta
- **New class** `Requests\Followups` (Registerable), container id `followups`, in `Plugin::boot()`'s service list. Its constructor receives the `settings` and `scheduler` services from the container; it makes no static calls into other services.
  - `public function on_request_sent( int $request_id, object $row ): void`: the listener on `ndv-reviews/request_sent` (priority 10, 2 args). It schedules only when all of these hold:
    - the row's `origin` is `free`, `step` is 1, `source` is `auto` or `manual`, and its `meta.followup` (RR-09 reserved key) is true;
    - `order_id` > 0 (list rows never get a free follow-up);
    - `followup_enabled` is on;
    - `should_send_reminder` is true for the order;
    - `order_already_requested` is false.
    Then it calls `$this->scheduler->queue_for_order( $order_id, ['source'=>'followup','origin'=>'free','step'=>2,'delay'=> $days * DAY_IN_SECONDS,'variant'=>'followup','followup'=>false] )`. A `WP_Error` from queue-time eligibility (consent, nothing to review…) means no row.
  - `public function default_texts(): array` returns `{subject, body}`, the built-in follow-up copy before translation. RR-06P reads it as the default for its later steps.
  - `register()`: adds the `request_sent` and `request_eligible` listeners and the transparency fact.
- **Send-time rules** (listener on `ndv-reviews/request_eligible`; first line `if ( 'free' !== ( $context['origin'] ?? '' ) || 'followup' !== ( $context['source'] ?? '' ) ) { return $eligible; }`):
  - `followup_enabled` off → `WP_Error( 'ndvr_followup_disabled', 'Follow-ups were turned off after this one was scheduled.' )`;
  - `should_send_followup` false → `ndvr_followup_filtered` ('Skipped by a site filter.');
  - `order_already_requested` true → `ndvr_already_requested` ('Another sender already asked this customer.').
  The rest come from `check_eligibility()`'s normal steps: status, email, suppression, consent (RR-07), role exclusion (RR-05), nothing to review, cooldown.
- **Filter** `ndv-reviews/should_send_followup` (bool `$send`, int `$order_id`, object `$request`), where `$request` is the follow-up row.
- **Settings keys** (F6 registry, page `reminders`):
  - `followup_enabled` (bool, false)
  - `followup_delay_days` (int, 7; `absint`, clamped to 1–60)
  - `followup_subject` (string, ''; `sanitize_text_field`)
  - `followup_body` (string, ''; `wp_kses_post`)
- **Mailer:**
  - `send_for_order()` arg `variant` ('first'|'followup', default 'first');
  - `preview( $overrides )` accepts `variant`, `followup_subject` and `followup_body`;
  - for `followup`, `subject()` uses `followup_subject` or the default, and `body()` always passes a non-empty `intro_html` (custom text through `replace_tokens()` + `wpautop( wp_kses_post() )`, or the built-in default);
  - the stored follow-up texts are read through `ndv-reviews/translate_setting` with the order language (keys `followup_subject`, `followup_body`; RR-08), exactly like the first email's texts;
  - both texts pass through RR-09's `ndv-reviews/request_email_texts` before rendering.
- **Template variable** `$is_followup` (bool) in `email-request.php`, documented in its docblock (:7-16).
- **Preview:** `admin-post.php?action=ndvr_reminder_preview&variant=followup` (same nonce `ndvr_requests` and capability as today).
- **No new** Action Scheduler hook (uses `ndvr_send_request`), option, meta or table.
- **`NDVR_API` 6** covers `Followups::default_texts()` and the four settings keys, which RR-06P reads. Pro calls `default_texts()` only when `NDVR_API >= 6`; below that it uses its own built-in copy.
- **CONTRACTS.md:** the class, the `request_sent` listener and `default_texts()`, the filter, the template variable, the scheduling rules and the API level.

## 7. Storage and upgrade
- A step-2 row in `ndvr_requests` (RR-09 schema v4): `source=followup`, `origin=free`, `step=2`, `meta.variant=followup`, `scheduled_at = sent_at + delay`, with an `ndvr_send_request` action at that time.
- **Once per order:** RR-09's dedupe key `o:{order_id}:free:2:followup` with `INSERT IGNORE` replaces the old check-then-insert (X3). A retried or duplicated step 1, or a second `request_sent` for the same row, returns the existing id and schedules no second action. `cancel_pending_for_order()` clears the key of the rows it cancels (RR-09 §6.2), so a later legitimate follow-up can still be queued.
- **Enabling later:** step-1 emails sent before the setting was turned on get no follow-up, because scheduling happens only on the step-1 `request_sent`. The help text says so.
- Old sites: the setting defaults off, so nothing changes until enabled. No DB version.

## 8. Security
- **Settings:** the Reminders save (nonce `ndvr_requests`, then `Caps::manage( 'reminders' )`, RequestsPage.php:143-146), then the sanitizers listed above.
- **Preview:** `check_admin_referer( 'ndvr_requests' )` and the capability check as today (RequestsPage.php:202-205); `variant` through `sanitize_key` and compared strictly to `followup`; overrides sanitized as at save. The email HTML stays in the sandboxed iframe (:242).
- **Email:** subject plain text with line breaks removed (Mailer.php:266); body `wp_kses_post`; merge-tag values escaped (Mailer.php:347-351).
- No new public entry point (RR-00 F7 not needed).

## 9. Privacy
The same data as the first email (request row, token). The follow-up row is covered by the request-log exporter and eraser (Privacy.php:205-229, :316). Unsubscribe and consent are checked at send time. No readme change beyond the feature description.

## 10. Performance and assets
At most one extra row and one Action Scheduler action per order. The schedule-time checks reuse already-loaded order data. No assets.

## 11. Compatibility
- **Theme overrides of `email-request.php`:** an override without `$is_followup` still shows the follow-up copy, because the template prints `$intro_html` whenever it is non-empty (email-request.php:55-56) and the Mailer always fills it for follow-ups.
- **HPOS:** order access through `wc_get_order()` only.
- **PHP 7.4, WP 6.0, WC 8.0:** no newer APIs.
- **Pro absent:** free rules only. **Pro present:** the X2 rules above. **Pro removed later:** pending free follow-up rows still send under free rules; Pro rows are RR-06P's concern.
- **Cooldown:** RR-09 rev 3 runs the cooldown at queue time only for `source=manual` (RR-09 §6.2, order of operations step 2), so the just-sent step 1 doesn't block queueing the follow-up. At send time the follow-up is at least one day later, outside the default 20-hour window. A site that raises `ndv-reviews/request_cooldown` above the delay gets the follow-up cancelled with `ndvr_cooldown`.

## 12. Acceptance criteria
All in Playground through PHP. Tests call `Scheduler::process( $id )` on the step-2 row directly, because `process()` doesn't wait for `scheduled_at`.
1. Follow-up on, delay 7: processing a step-1 `auto` row (sent) creates one step-2 row (`source=followup`, `origin=free`) with `scheduled_at` = step-1 `sent_at` + 7 days (±60 s), and a pending `ndvr_send_request` action at that time.
2. `process()` on the step-2 row sends an email whose subject is the follow-up subject and whose body contains the follow-up text.
3. The customer reviews every product between the sends: step 2 becomes `cancelled` with "No reviewable products in this order." and no email goes out.
4. They review 1 of 2 products: the follow-up lists only the other product.
5. Unsubscribe between sends: step 2 `cancelled` (`ndvr_unsubscribed`).
6. Setting turned off after scheduling: step 2 `cancelled` (`ndvr_followup_disabled`).
7. A stub `should_send_followup` returning false: `cancelled` (`ndvr_followup_filtered`).
8. A stub `should_send_reminder` returning false (simulating Pro automation): an RR-04 manual send is sent, and no step-2 row is created.
9. A stub `order_already_requested` returning true: no step-2 row; if set after scheduling, step 2 is `cancelled` (`ndvr_already_requested`).
10. A `campaign` row and an `origin=pro` row, both sent: no step-2 row.
11. Step 1 fails, is retried with `retry()` and sends; firing `do_action( 'ndv-reviews/request_sent', $id, $row )` again for the same row: exactly one step-2 row exists and one pending `ndvr_send_request` action for it.
12. Preview with `variant=followup` and unsaved follow-up texts shows those texts; without `variant` it shows the first email.
13. A theme override copied from the current `email-request.php` (no `$is_followup`) renders the follow-up text for a follow-up send.
14. Saving `followup_delay_days` = 0 stores 1; 99 stores 60.
15. **Pro steps untouched:** with `followup_enabled` off and a stub `should_send_followup` returning false, rows inserted as `origin=pro`, `step=2` (one with `source=auto`, one with `source=followup`, each on its own eligible order with no earlier send) and processed with `process()` are sent, not `cancelled`; `apply_filters( 'ndv-reviews/request_eligible', true, $order, ['origin'=>'pro','source'=>'followup','step'=>3] )` returns `true` with only this feature's listener attached.
16. **No direct call:** with `remove_all_actions( 'ndv-reviews/request_sent' )`, processing a step-1 row creates no step-2 row.
17. Core flows pass.

## 13. Test plan
1. Playground with reminders on, follow-up on, an order with two products.
2. Queue step 1 through `Plugin::instance()->container()->get( 'scheduler' )->queue_for_order()`, run `process()` on the same service, inspect rows with a direct query on `Db::table('requests')` and `as_get_scheduled_actions()`. Pro-origin rows for AC15 are inserted with `RequestRepository::insert()`.
3. Capture mail with `pre_wp_mail`.
4. Between runs: create a review through `ReviewRepository::create()`, call `Mailer::suppress()`, toggle the setting, add stub filters.
5. Theme override: copy the template into the active theme's `ndv-reviews/` folder.
6. Run core flows; record results in `LOG.md`.

## 14. Open questions
None.
