# Code review: RR-06 follow-up reminder (free)

Reviewer: senior WP/WC plugin review (read-only) · Date: 2026-10-10
PRD: `.agents/prd/RR-06-follow-up-reminder.md` (rev 3) · Contracts: `.agents/CONTRACTS.md` "Follow-up reminder (RR-06, NDVR_API 6)" · Pipeline: RR-09 rev 3
Scope:
- `includes/Requests/Followups.php` (new)
- `includes/Requests/Mailer.php`
- `templates/email-request.php`
- `includes/Admin/RequestsPage.php`
- `includes/Plugin.php`
- `rosette-reviews.php`
- harness `.agents/qa/rr-06.php`
- the RR-06 hunks of `D:/.devcache/patches/RR-04-05-06.patch`

Also read for context:
- `Scheduler.php`, `RequestRepository.php`, `OrderActions.php`, `SettingsFields.php`, `Settings.php`, `Transparency.php`, `View.php`
- Pro `Automation/Engine.php`, `Automation/BulkCampaign.php`, `Esp/Dispatcher.php`, `Channel/Email.php`

> Note: RR-07 edits landed in `Mailer.php`, `RequestsPage.php` and `Plugin.php` while this review was running. Mailer.php gained a consent step at :166, which moves later lines down by about 5. Line numbers below are for the current tree. RR-07 is not reviewed here.

`php -l` (PHP 8.3 CLI) is clean on every changed file and on the harness. No PHP 7.4 binary is available, so I scanned the files by hand: no 8.x-only syntax (`match`, `?->`, `str_contains`, attributes, `mixed`/`static` return types). WP 6.0 and WC 8.0 APIs are used only (`wp_kses_post`, `wpautop`, `as_schedule_single_action`, `wc_get_order`).

## Verdict

**CHANGES REQUESTED.** No BLOCKER. One MAJOR: a PRD gap on manual-before-trigger ordering, which needs a fix or an owner waiver. Several MINORs.

The core is sound. These hold on every path I traced:
- the claim/dedupe/retry path;
- the scoping of the first line of `at_send()`;
- `cancel_pending_for_order()` touching only `origin='free'` rows;
- old Pro's `suppress_free_reminder()` and the ESP Dispatcher.

## What holds (evidence)

- **Once per order.**
  - The step-2 row is created only through `Scheduler::queue_for_order()` with dedupe key `o:{id}:free:2:followup` (Scheduler.php:215-216).
  - The insert is `INSERT IGNORE` on a UNIQUE key (RequestRepository.php:78-139). A duplicate returns the existing id and schedules no second action (Scheduler.php:357-359).
  - A second `request_sent` for the same row, a retried step 1, and repeated status events all end in one row and one action. Harness AC11 shows this.
- **Hook, not a direct call.** Scheduling hangs off `ndv-reviews/request_sent` (Followups.php:59; fired at Scheduler.php:534 after `set_status('sent')`). `process()` has no follow-up code, and AC16 passes.
- **Scheduling gates** (Followups.php:96-137):
  - `followup_enabled` on;
  - origin `free`, step 1, source `auto` or `manual`;
  - `meta.followup` true;
  - `order_id` > 0;
  - `should_send_reminder` true;
  - `order_already_requested` false.

  All match PRD §6.
- **Delay from `sent_at`.** The delay is computed from the row's `sent_at`, which `set_status()` stores as UTC via `current_time('mysql', true)` (RequestRepository.php:253). `$delay = due - now` (Followups.php:122-124), so a late listener or a late retry still lands at `sent_at + N days`. That matches AC1. (PRD §6 literally says `$days * DAY_IN_SECONDS`; the code is more precise and agrees with AC1 and CONTRACTS.)
- **Send-time rules touch only free follow-ups.**
  - The first line of `at_send()` returns `$eligible` unchanged unless `origin === 'free' && source === 'followup'` (Followups.php:150-152).
  - It also passes through at queue stage and when the decision is already an error (:153). Codes and messages match PRD §6. AC15 passes.
- **Pro rows are never cancelled by free code.**
  - `cancel_pending_for_order()` is `origin='free'` only (RequestRepository.php:388).
  - Pro `origin=pro` rows have `meta.followup` forced false (Scheduler.php:211-212).
  - Legacy and Pro BulkCampaign rows (`source='legacy'`, NULL meta) never qualify.
- **Old Pro (no RR-06P).**
  - `Engine::suppress_free_reminder()` (Pro Engine.php:59-73) returns false whenever automation or any ESP flag is on. `on_request_sent()` honours it (Followups.php:113), so a free manual send under Pro automation or an ESP gets no free follow-up (AC8).
  - Pro `Channel\Email` calls `send_for_order( $id )` without `request_id`. That runs the gate as `source=legacy, origin=pro` (Mailer.php:~249), so `at_send()` passes through and `request_sent` never fires: no follow-up.
  - BulkCampaign inserts legacy rows (`source='legacy'`): no follow-up.
  - The ESP Dispatcher doesn't touch the request pipeline.
- **Cooldown interplay.**
  - Queue-time cooldown runs only for `manual` (Mailer.php:~183), so the just-sent step 1 doesn't block queueing.
  - At send time the minimum 1-day delay is above the 20 h window.
  - A site cooldown filter longer than the delay ends `ndvr_cooldown`, as PRD §11 says.
  - A manual send between the two goes through `OrderActions::queue()`. That cancels the pending follow-up and clears its key (OrderActions.php:133, RequestRepository.php:388), and the manual send's own `request_sent` then queues a fresh follow-up from the new `sent_at`. Good.
- **Settings.**
  - The four keys are in the F6 registry on page `reminders`, card `followup` (Followups.php:200-241).
  - The rows render inside the table, and their markers are printed after `</table>` inside the form (RequestsPage.php:375, :396).
  - **Unticked checkbox:** the marker is posted and the key is absent, so `$raw = null` (SettingsFields.php:156) and `! empty( null )` stores `false`. Correct.
  - Delay: `absint` clamped to 1–60, so 0 → 1 and 99 → 60 (AC14).
  - Subject: `sanitize_text_field`. Body: `wp_kses_post`. Values are unslashed by the registry.
  - Nonce `ndvr_requests` and `Caps::manage('reminders')` are checked before save (RequestsPage.php:143-146).
- **Escaping.**
  - Default intro: `esc_html()` on the greeting and the text; the store name is decoded first, so there is no double-encoding (Mailer.php:732-740).
  - Custom body: merge-tag values are `esc_html`/`esc_url`'d, then `wp_kses_post`, then `wpautop` (Mailer.php:~677). It goes through `wp_kses_post` again in the template.
  - Subject: plain text with line breaks removed (Mailer.php:642-647).
  - Preview: subject shown with `esc_html` (RequestsPage.php:245). Body in `<iframe sandbox="" srcdoc>` with double-encoding (:256). `variant` goes through `sanitize_key` and is compared strictly (:218-219). Overrides are sanitized as at save (:221-226). Nonce and capability are checked first (:205-208).
- **Theme overrides.**
  - The template prints `$intro_html` whenever it is non-empty. The 1.0.0 committed template (`D:/.devcache/baseline-2026-10-10/rosette-reviews.tar`) already had that branch, so overrides copied from any released version show the follow-up copy.
  - `$is_followup` is passed (Mailer.php:702) and documented (email-request.php:18-19).
  - Overrides from 1.0.0 still use the old `ndv-reviews` text domain, which is a separate, pre-existing concern.
- **i18n.**
  - Every string uses `rosette-reviews`.
  - Each `sprintf` string with `%s` carries a `translators:` comment (Followups.php:73, :75; Mailer.php:650, :734, :736).
  - The two default strings have the same msgids in Followups and Mailer, so there is one translation each.
  - The settings placeholders use `sprintf( default, '{store_name}' )`.
- **API level.** `NDVR_API` is 6 (rosette-reviews.php:38). The service `followups` is constructed with `settings` + `scheduler` (Plugin.php:318) and registered in boot (:517). `default_texts()` returns `{subject, body}`.

## Acceptance criteria

| AC | Status | Notes |
|---|---|---|
| 1 | Pass | Harness checks one row, `sent_at`+7 d ±60 s, one pending action. |
| 2 | Pass | Subject and body match. |
| 3 | Pass | `ndvr_nothing_to_review` message, no mail. |
| 4 | Pass | Only the unreviewed product is listed. |
| 5 | Pass | Asserts the message text rather than the code; acceptable. |
| 6 | Pass | |
| 7 | Pass | |
| 8 | Pass (weak) | Uses `queue_for_order('manual')`, not the RR-04 `OrderActions` path (gap H3). |
| 9 | Pass | Both halves covered. |
| 10 | Pass | Doesn't assert `meta.followup=false` on the two rows (gap H8). |
| 11 | Pass | |
| 12 | Pass (weak) | Calls `Mailer::preview()`; the `render_preview()` route (GET `variant`, POST overrides, nonce) is not exercised (gap H2). |
| 13 | Pass (weak) | Uses the `ndv-reviews/template_path` filter; PRD §13.5 says copy into the theme's `ndv-reviews/` folder, so `locate_template()` isn't exercised (gap H4). |
| 14 | Pass | |
| 15 | Pass (weak) | Row half is good. The filter half calls `at_send()` directly instead of `apply_filters( 'ndv-reviews/request_eligible', … )` "with only this feature's listener attached" (gap H5). |
| 16 | Pass | |
| 17 | Pass (per LOG) | core 49/49 and the listed regressions, per `.agents/LOG.md:726-729`. |

## Findings

### MAJOR

**RR6-M1: a manual send before the trigger status gives a reminder before the "first" email.**

Files: Followups.php:96-137, Scheduler.php:389-427, OrderActions.php:123-137; PRD §5 gap.

Timeline with `reminder_status=completed`, `reminder_delay_days=7`, follow-up 7 days:
- **Day 0:** the order is `processing`. The merchant uses "Send review request" (RR-04 allows any non-ineligible status). The manual email is sent, and its `request_sent` queues the follow-up for day 7.
- **Day 3:** the order is completed. `on_order_status()` queues a free `auto` step 1 for day 10. Its queue-time check has no cooldown and no "already asked by free" check, and its dedupe key `o:X:free:1:auto` is new.
- **Day 7:** the follow-up is sent: "A few days ago we asked…".
- **Day 10:** the automatic first email is sent: "Thank you for your order… Could you tell other shoppers…". Its `request_sent` hits the sent follow-up's key, so there is no fourth email.

The customer gets three emails, and the reminder arrives before a "first" email. With `reminder_delay_days=0` the order is manual (day 0), auto (day 3), follow-up (day 7). The follow-up is then anchored to the manual send and arrives 4 days after the latest ask, not N.

RR-04 accepted manual followed by a later auto (code-RR-04-05.md:55). RR-06 adds the third email and the wrong order. PRD §5's double-send list doesn't cover this case.

Fix, cheapest free-only option:
- add a send-time rule for **free `auto` step-1** rows: cancel with `ndvr_already_requested` (already a skip code) when the order has a sent free `followup` row (or a sent free `manual` row);
- it can live in `Followups` behind the same `origin === 'free'` guard, so Pro rows stay untouched.

Optionally, in `on_request_sent()`, when the dedupe hit is a still-`scheduled` free follow-up, move it to the new `sent_at + N` (`set_scheduled_at()` + unschedule and reschedule the action), so the follow-up always trails the latest ask by N days.

Or: an owner waiver recorded in PRD §5/§11 and CONTRACTS, plus a harness case that pins the accepted behaviour.

### MINOR

**RR6-m1: `ndv-reviews/translate_setting` is not called for the follow-up texts.**

Files: Mailer.php:642 (subject), :~675 (body).

PRD §6 says the stored `followup_subject` / `followup_body` are read through `ndv-reviews/translate_setting` (keys `followup_subject`, `followup_body`). RR-08 PRD :44 lists RR-06 as a caller. Only `Transparency.php:293` calls the filter today.

It is a no-op until RR-08, but RR-08 will hook a call that doesn't exist.

Fix: wrap the settings read (not the preview override) as RR-03 does:

```php
apply_filters( 'ndv-reviews/translate_setting', $value, 'followup_subject', $lang )
```

Use `$lang = ''` until RR-08 provides the order language. Apply it before `replace_tokens()` / `wp_kses_post()`. Doing the same for `reminder_subject` / `reminder_body` keeps "exactly like the first email".

**RR6-m2: a follow-up cancelled at send time keeps its dedupe key, so the order can never get another follow-up.**

Files: Scheduler.php:545-548 (`finish_with_error()` → `set_status('cancelled')`), RequestRepository.php:246-266; only `cancel_pending_for_order()` clears keys (:388).

Scenario:
- a follow-up ends `ndvr_followup_disabled` (follow-ups switched off for a while), `ndvr_followup_filtered`, `ndvr_order_ineligible` (on hold, then back), or `ndvr_unsubscribed` (later re-subscribed);
- later the merchant re-enables follow-ups and sends a manual request;
- `OrderActions::queue()` only cancels `scheduled` rows, so the cancelled row keeps `o:X:free:2:followup`;
- the manual send's `request_sent` dedupes to that cancelled id and silently queues nothing.

A `failed` follow-up behaves the same way.

PRD §7 says keys are cleared "so a later legitimate follow-up can still be queued".

Fix: clear `dedupe_key` when a free `followup` row ends `cancelled` or `failed` at send time (for example a `RequestRepository::release_key( $id )` called from `finish_with_error()` for free follow-ups). Alternatively, amend §7 to "once ever per order, including cancelled rows".

**RR6-m3: static container call inside `Followups`.**

File: Followups.php:162, `\NdvReviews\Plugin::instance()->container()->get( 'request_repository' )`.

PRD §6 says the class "makes no static calls into other services".

Fix: inject `request_repository` (Plugin.php:318; update the constructor line in PRD §6 and CONTRACTS). Or have `process()` pass the row in the eligibility context, which is a pipeline change. Or record the exception in the PRD.

**RR6-m4: the transparency fact ignores `reminder_enabled`.**

File: Followups.php:187-192.

The RR-03 table (RR-03 PRD :79) defines `followup` as "`followup_enabled` and reminders on". The code uses `followup_enabled` only. The output is the same today, because the `requests` sentence is gated on the `reminders` fact. A consumer reading `facts['followup']` (Pro RR-06P's own sentence) would still see `true` with reminders off.

Fix (optional): `followup_enabled && reminder_enabled`.

**RR6-m5: the default copy is defined twice.**

Files: Followups.php:74/:76 and Mailer.php:651/:737.

The msgids are identical, so i18n is fine, but an edit to one copy silently desyncs the email from the placeholder and from Pro RR-06P's `default_texts()`.

Fix (optional): keep one source. For example, a public `Mailer::followup_defaults()` that `Followups::default_texts()` returns, with `mailer` injected.

**RR6-m6: the follow-up text placeholder omits the greeting.**

File: Followups.php:299.

The default follow-up intro adds "Hi {first name},", but the placeholder shows only the sentence. The first email's placeholder includes "Hi {customer_name},\n\n" (RequestsPage.php:367). A merchant who copies the placeholder loses the greeting.

Fix: make the placeholder `"Hi {customer_name},\n\n" . sprintf( … )`, or add "a greeting is added only when this is empty" to the description.

**RR6-m7: the PRD/UI mismatch on "step 2".**

PRD §3 says the log row shows "Source 'Follow-up', step 2 (RR-09 column)". The RR-09 log (RequestsPage.php:446-458; RR-09 §3 :34) has no Step column. Source "Follow-up" is shown.

Fix: correct the PRD wording, or add the column.

### Nits (optional)

- email-request.php:11: the `$intro_html` description ("The merchant's reminder text… or '' for the built-in copy") is stale for follow-ups. Add "or the follow-up text (never '' for a follow-up)".
- RequestsPage.php:240: the preview page title says "Reminder email preview" for both variants. It could say "Follow-up preview".
- Followups.php:214: `absint('-5')` gives 5, not 1. It is harmless, and the PRD literally says `absint`, unlike the strict RR-05 R5-2 sanitisers.
- Scheduler.php:376-379: without Action Scheduler, the fallback processes the follow-up synchronously inside step 1's `request_sent`, and it ends `ndvr_cooldown`. WC always bundles AS, so this is informational only.

### Notes for Pro RR-06P (no free change needed)

- `Mailer::subject()` / `body()` use the free `followup_subject` / `followup_body` for **any** row whose `meta.variant` is `followup`, including `origin=pro` rows. RR-06P must filter `ndv-reviews/request_email_texts` if its steps need their own copy.
- Rows that Pro queues with `origin=pro` pass through `at_send()` and are never scheduled by `on_request_sent()`. Free `cancel_pending_for_order()` never touches them. Nothing in free will cancel or double-send around Pro rows.
- `suppress_free_reminder()` ignores the order id and is global. Pending free follow-ups queued before Pro automation or an ESP was switched on still send. Pro's sequence starts only on a new status event, so this does not double-send the same order. PRD §11 already covers it.

## Harness gaps (.agents/qa/rr-06.php)

- **H1:** no positive manual-anchored follow-up. AC1 is `auto` only, and AC8 is manual with suppression.
- **H2:** the `render_preview()` route isn't exercised: GET `variant`, POST `followup_*`, nonce and capability. It exits, so run it in a child process as rr-09 does.
- **H3:** AC8 bypasses `OrderActions`. The RR-04 cancel-then-requeue of a pending follow-up is untested.
- **H4:** AC13 uses the `template_path` filter instead of an active-theme `ndv-reviews/email-request.php`.
- **H5:** the AC15 filter half calls `at_send()` directly instead of `apply_filters( 'ndv-reviews/request_eligible', true, $order, ['origin'=>'pro','source'=>'followup','step'=>3] )` with the other listeners detached.
- **H6:** the save with the follow-up checkbox unticked (marker present, key absent → `false`) isn't tested.
- **H7:** not covered:
  - a `request_cooldown` filter above the delay → `ndvr_cooldown`;
  - `utm_content=followup` with `reminder_utm` on;
  - the default follow-up subject (only the default body is asserted);
  - `$is_followup === true` reaching the template.
- **H8:** AC10 doesn't assert `meta.followup === false` on the campaign and Pro rows.
- **H9:** no escaping case: `<script>` or `"><img onerror>` in `followup_body`, `followup_subject` or the billing first name should come out inert in the mail and the preview.
- **H10:** the RR6-M1 timeline (manual before the trigger status) and the RR6-m2 re-enable case aren't tested.

## Required before approval

1. **RR6-M1:** fix (a free `auto` step-1 send-time rule, with optional re-anchoring), or an owner waiver recorded in PRD §5/§11 and CONTRACTS, plus a harness case for the chosen behaviour.
2. **RR6-m1:** call `ndv-reviews/translate_setting` for `followup_subject` / `followup_body`.
3. **RR6-m2:** release the dedupe key when a free follow-up ends `cancelled`/`failed` at send time, or amend PRD §7 to "once ever per order".
4. **RR6-m3:** inject the request repository into `Followups` (and update PRD §6 and CONTRACTS), or record the exception.
5. **Harness:** H6 (unticked checkbox) and H2 (preview route).

Optional: m4, m5, m6, m7, the nits, and H1, H3-H5, H7-H10.

**Verdict: CHANGES REQUESTED** (0 BLOCKER, 1 MAJOR, 7 MINOR).
