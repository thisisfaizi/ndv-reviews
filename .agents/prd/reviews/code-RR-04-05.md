# Code review: RR-04 (order-screen requests) and RR-05 (request exclusions)

Reviewer: independent code review, 2026-10-10 · Read-only (no code edits) · Builds as described in LOG.md 2026-10-10 entries.

Scope read: `includes/Requests/OrderActions.php`, `Requests/Scheduler.php`, `Requests/RequestRepository.php`, `Requests/Mailer.php`, `Requests/Exclusions.php`, `Collection/Reviewable.php`, `Collection/Landing.php`, `Collection/TokenRepository.php` (mark_product), `Admin/RequestsPage.php`, `Admin/SettingsFields.php`, `Support/Settings.php`, `Support/Caps.php`, `Plugin.php`, `Uninstall.php`, `Installer.php` (schema defaults), `templates/magic-landing.php`, harnesses `.agents/qa/rr-04.php` / `rr-05.php`; Pro `Automation/Engine.php`, `Automation/SmartRules.php`, `Automation/BulkCampaign.php`, `Esp/Dispatcher.php`, `Channel/Email.php`; WooCommerce 11.2.0 and WP core on the QA site.

`php -l` (PHP 8.3 CLI, the only one here): clean on all 11 changed files. A grep for PHP 8-only syntax found nothing: str_contains/str_starts_with, nullsafe, match, arrow fn, union types, `mixed`/`static` return types, catch without a variable, trailing commas in parameter or `use` lists. The PHPCS PHP 7.4 compatibility sniff was **not** run by this review. LOG.md says "no new issues in touched lines"; confirm the 7.4 sniff was part of that.

---

## Verdicts

- **RR-04: CHANGES REQUESTED.** It uses the pipeline correctly and has no blocker or major. The items required before approval are small: i18n composition, a double full stop, the cancel-semantics harness proof, and recording the WC 8.0 spike or an owner waiver.
- **RR-05: CHANGES REQUESTED.** Two majors:
  - Exclusion runs after the pool dedupe, so a non-excluded product can lose its request.
  - The AC9 sanitiser turns `-3` into `3` and turns nested input into id `1`.
  - It also has one HTML-validity minor (`<h3>` inside `<th>`).

---

## RR-04: Send a review request from the order screen

### Security flow (verified on disk, WC 11.2.0 / WP core)
Each nonce and capability check runs before our hook fires:

| Entry | WooCommerce/core check before our hook | Our hook fires |
|---|---|---|
| HPOS single (order save) | `check_admin_referer( order edit nonce )` src/Internal/Admin/Orders/Edit.php:304 | `do_action( 'woocommerce_process_shop_order_meta' )` :318 → `WC_Meta_Box_Order_Actions::save` (prio 50, Edit.php:107) → `woocommerce_order_action_{sanitize_title($action)}` class-wc-meta-box-order-actions.php:184 |
| Legacy single | `woocommerce_meta_nonce` / `woocommerce_save_data` includes/admin/class-wc-admin-meta-boxes.php:228, `edit_post` :238 | :262 |
| HPOS bulk | `edit_others_posts` ListTable.php:1437, `check_admin_referer( 'bulk-orders' )` :1441 | `handle_bulk_actions-{$screen}` :1522 (`@since 7.2.0`) |
| Legacy bulk | `check_admin_referer( 'bulk-posts' )` wp-admin/edit.php:77 | :222 |

- WooCommerce fires `woocommerce_order_action_*` for **any** posted `wc_order_action` string, whether or not it was in the dropdown. That makes the `Caps::manage( 'reminders' )` re-check in `handle_single()` (OrderActions.php:146) load-bearing. It is present and runs before any work.
- Bulk checks: the action is compared strictly, the capability re-checked, ids `absint`ed and de-duplicated, the count capped (:184-196).
- No `$_REQUEST` is read.
- The notice is per user and escaped at output (:329-333).

### Cancel-pending semantics (the main question)
`OrderActions::queue()` (:123-137) calls `cancel_pending_for_order( $id, ['auto','followup'] )` only after a successful queue. The SQL (RequestRepository.php:388) is `… WHERE order_id = %d AND origin = 'free' AND status = 'scheduled' AND source IN ('auto','followup')`.

| Row | Cancelled by a manual send? | Correct? |
|---|---|---|
| The new manual row (`source=manual`) | No | Yes |
| An earlier, still-scheduled manual row | No | See R4-5 |
| Free auto / follow-up, `scheduled` | Yes, dedupe key cleared | Yes (AC7, AC12) |
| Pro rows (`origin=pro`, incl. RR-06P `source=auto`) | No | Yes (PRD §5) |
| Campaign rows | No | Yes |
| `legacy` rows: pre-RR-09 free auto rows **and** Pro BulkCampaign `insert()` rows (schema default `source='legacy'`, Installer.php:510) | No | Acceptable (the two can't be told apart). See R4-6 |
| Free auto row in `sending` | No | Safe: the manual row is stopped by the send-time cooldown once the auto row's `sent_at` is set |
| Free auto row in `failed` | No | Minor: a Retry more than 20 h later sends a second email (R4-6) |

The code is correct against PRD §5/§6. Nothing in the harness proves the "never cancels Pro / legacy / campaign" half (R4-3).

### Cooldown at queue time
`Mailer::check_eligibility()` (Mailer.php:176-193) checks the cooldown at queue stage only when `source === 'manual'`, against `last_sent_at_for_order()` (MAX(sent_at), any source and origin). Inside the window it returns `ndvr_cooldown` before any insert, so no row is created (AC4 holds). After a manual send, an automatic row is still queued and stopped only at send time inside the window (AC5 and AC6, as the PRD intends).

### Null order, HPOS vs legacy
- `add_order_action()` handles `null` and non-orders (:92). `handle_single()` handles WooCommerce passing `false` when `wc_get_order()` fails (:146). `order_already_requested()` guards with `instanceof` (Scheduler.php:131).
- Bulk: `wc_get_order()` returning a refund or nothing gives "order not found" (:203-204).
- HPOS screen id `woocommerce_page_wc-orders` covers both the list and the edit screen. Legacy screens are `edit-shop_order` and `shop_order`. All three are in `render_notice()` (:319).
- The HPOS list reverses the selected ids (ListTable.php, `array_reverse`), so "the first 200" are the oldest selected. That's harmless.

### Findings

**R4-1 · MINOR (required) · i18n: the skipped-reason list has hard-coded word order.**
- Where: OrderActions.php:225-228, `number_format_i18n( $n ) . ' ' . $reason` joined with `implode( ', ', … )`.
- Failure: translators can't reorder count and label, can't choose the plural of the label, and can't change the list separator. This breaks PRD-00 §2.12.
- Fix: `sprintf( /* translators: 1: count, 2: reason label */ _x( '%1$s %2$s', 'skipped count and reason', 'rosette-reviews' ), … )`, and join with `wp_sprintf_l( '%l', $parts )` or a translatable separator.
- Same file, :239: "Only the first %1$s orders were processed. Select up to %1$s at a time." is plural-sensitive (the limit is filterable, so "first 1 orders" can happen). Use `_n()`. The translators comment at :238 says `%s` where the string uses `%1$s`.

**R4-2 · MINOR (required) · Double full stop on unmapped reasons.**
- Where: :157 `'No review request for order #%1$s: %2$s.'`; `reason()` (:252-256) falls back to the WP_Error message.
- Failure: RR-09 messages already end with ".", so the notice reads "No review request for order #12: The review-request database update has not finished yet.." The same happens for `ndvr_no_order`, `ndvr_insert_failed`, `ndvr_bad_source` and any `request_eligible` filter error. The bulk "Skipped 1: 1 …." string has the same problem.
- Fix: `rtrim( wp_strip_all_tags( $msg ), '. ' )` in `reason()`.

**R4-3 · MINOR (required) · Harness gap: cancel semantics.**
- Where: rr-04.php AC7/AC12 only show that a free `auto` row is cancelled.
- Fix: before a manual send, seed scheduled rows for:
  - (a) `origin=pro, source=auto`
  - (b) `source=campaign`
  - (c) `source=legacy` (raw `insert()`)
  - (d) free `followup`
  - (e) free `auto` in status `failed`

  Assert that (a), (b), (c) and (e) are untouched and (d) is cancelled.

**R4-4 · MINOR (required, process) · WC 8.0 build spike not run.**
- PRD §11 requires the spike before building, and the LOG says it was not run. On-disk evidence is partial: `@since 7.2.0` on the HPOS `handle_bulk_actions-{$screen}` docblock, and `@since 5.8.0` for the `$order` argument of `woocommerce_order_actions`.
- The code relies on nothing newer, so this is not a code blocker. AC12, though, depends on the 40/50 priorities in WC 8.0's `Edit::add_save_meta_boxes()`.
- Required: a Playground run, or an owner waiver recorded in TASKS/LOG.

**R4-5 · MINOR · A double submit or two admins create two manual rows.**
- Where: `handle_single()` → `queue_for_order()`. Manual rows have no dedupe key, and the queue-time cooldown only reads `sent_at`.
- Failure: two "Update" posts before the job runs give two `scheduled` manual rows, two order notes and two "queued" notices. The second row ends "Not sent" (`ndvr_cooldown`) at send time.
  - With Action Scheduler's default single concurrent batch, only one email goes out.
  - With `action_scheduler_queue_runner_concurrent_batches` above 1, both rows can pass the send-time cooldown check before either sets `sent_at`.
- Fix: in `handle_single()`, if a `scheduled` free `manual` row exists for the order, report "A review request for order #%s is already queued." and don't queue. A `first_id_for_order( $id, ['manual'] )` call plus a status check would do it, or add a small repository helper.

**R4-6 · MINOR · Rows that a manual send leaves able to send again later.**
- (a) A free `legacy` row still scheduled from before the v4 upgrade, with a delay of up to N days. It sends after the manual email, outside the cooldown. It can't be cancelled safely, because Pro BulkCampaign rows have the same `legacy/free` shape. Document it in CONTRACTS/LOG, or skip the single send with "already scheduled" when a scheduled `legacy` row exists.
- (b) A `failed` free auto row: a Retry more than 20 h later sends a second email. Optional: include `failed` in the cancel for manual sends.

**R4-7 · MINOR · A cancelled auto row has an empty Note in the log.**
- Where: :133 passes no `$reason`, so `error=''`.
- Failure: the Request log shows "Not sent" with a blank Note. The merchant can't tell why the automatic request didn't go.
- Fix: pass `__( 'Replaced by a manual request.', 'rosette-reviews' )`.

**R4-8 · MINOR · The cooldown label can disagree with the enforced cooldown.**
- Where: :282 re-applies `ndv-reviews/request_cooldown` with context `['source'=>'manual']` only. Mailer.php:189 passes the full context (`stage`, `origin`, `step`, `list`, …).
- Failure: a context-aware filter shows a different number of hours. A filter that reads `$context['list']` raises an "undefined index" warning. A sub-hour cooldown rounds to "asked in the last 0 hours".
- Fix: Mailer adds `array( 'seconds' => $cooldown )` as the WP_Error data, and `label()` reads it with `max( 1, (int) ceil( $s / HOUR_IN_SECONDS ) )`.

**R4-9 · MINOR · Bulk performance: wasted work.**
- `cancel_pending_for_order()` runs an UPDATE for every queued order, even though bulk has just confirmed via `exists_for_order()` that the order has no email rows, so nothing can match. Skip the cancel in bulk: 200 queries saved.
- `wc_get_order()` runs twice per id (OrderActions :202 and Scheduler :190). That's cheap with the HPOS order cache.
- Estimated cost per order: about 8-12 queries plus one Action Scheduler insert (has_reviewed ×1-2, term cache, last_sent, insert, cancel, exists). 200 orders is about 2-3k queries, plausibly within the 10 s target. **No real 200-order run was measured** (see harness gaps).

**R4-10 · MINOR (RR-05 interplay) · The `ndvr_nothing_to_review` label is now misleading.**
- Where: :269 "already reviewed everything". Since RR-05, the same code also means "every item is excluded".
- Failure: "No review request for order #12: already reviewed everything." for an order holding only a gift card.
- Fix: amend the PRD table to "nothing left to review", or similar.

**R4-11 · NIT.**
- Notices are per user, not per order or screen, so a notice from order #1 can appear on the orders list in another tab. That's acceptable within the 120 s TTL.
- With a persistent object cache, the uninstall LIKE sweep for `ndvr_order_action_notice_` finds nothing in `wp_options`. The transients expire in 120 s anyway.
- Bulk adds no order note, which is fine because the PRD asks for it on single sends only. An optional note would help the order history.

### AC check (RR-04)
| AC | Status |
|---|---|
| 1 HPOS single | Pass (child process on HPOS, row in `wc_orders`) |
| 2 Legacy single | Pass (child process on legacy) |
| 3 Bulk wording | Pass: the exact string matches. Composition is not translatable (R4-1) |
| 4 Cooldown | Pass |
| 5 / 6 / 7 / 12 | Pass |
| 8 Capability | Pass (via the `manage_capability` filter) |
| 9 Null order | Pass |
| 10 Limit | Pass on non-existent ids only (see gaps) |
| 11 Already asked | Pass |
| 13 Pro and core flows | LOG: core 49, Pro rr-01/rr-02 pass with Pro active. Not in this harness |

### Harness gaps (rr-04.php)
- AC10 uses ids 900000000+, so the real queue path, the `2*$i` spreading and the <10 s target (PRD §10) never run. Add one timed run with 200 real orders, or at least 50 with an extrapolation, and assert that `scheduled_at` is spread.
- The cancel matrix (R4-3).
- A second single send while the first row is still scheduled (R4-5).
- `notice_render()` only checks `edit-shop_order` and `dashboard`. Add `woocommerce_page_wc-orders` and `shop_order`, and check that user B never sees user A's notice.
- `bulk_actions-edit-shop_order` listing, and the legacy bulk path in the parent process.
- `ac5_to_7_12_with_reminders()` calls `scheduler->register()` a second time, so `process` and `on_order_status` are hooked twice. The dedupe key hides this. Prefer `remove_all_actions` on the status hook first.

---

## RR-05: Request exclusions

### Findings

**R5-1 · MAJOR · Exclusion runs after the pool dedupe, so a reviewable product can be dropped.**
- Where: Reviewable.php:166-188. The loop claims `$seen[$pool_id]` for the first product of a pool. `filter_excluded()` only runs at :188, on the survivors.
- Failure: an order with product A (in an excluded category) and then product B (not excluded) that share one pool (RR-00b E4 pooling, for example grouped or linked products). A claims the pool and B is skipped. A is then excluded, so the pool gets **no** request, though B is reviewable. Every sender inherits this, because they all read `for_order()`: the free queue, Landing via the token, Pro Engine, SmartRules and the ESP Dispatcher.
- Fix:
  - Before the loop, collect the item product ids and call `update_object_term_cache( $ids, 'product' )` once.
  - Inside the loop, `continue` when `$this->is_excluded_product( $product_id )`, **before** the pool check.
  - Keep `filter_excluded()` for Landing and list rows.
  - This also stops the `has_reviewed()` comment queries (one or two per item) for excluded items.
- Add a harness case: two products pooled through the `ndv-reviews/review_pool_id` filter, the first excluded, assert that `for_order()` returns the second.

**R5-2 · MAJOR · The AC9 sanitiser accepts negative and nested input.**
- Where: Exclusions.php:78 and :101, `array_map( 'absint', … )`; :120 `array_map( 'strval', $raw )`.
- Failure:
  - `absint( '-3' )` is `3`, so a posted `-3` is stored whenever product_cat term 3 or product 3 exists. AC9 lists `-3` as a bogus value that must not be stored. The harness passes only because id 3 is neither a product_cat term nor a product on the QA site.
  - A crafted nested value (`reminder_exclude_cats[][]=x`) becomes `absint( array )` = 1.
  - `strval()` on an array raises an "Array to string conversion" warning on PHP 8.
- Fix: before casting, keep only scalars matching `/^\d+$/` (ids) or `is_string` values (roles). For example: `array_filter( $raw, static function ( $v ) { return is_scalar( $v ) && ctype_digit( (string) $v ); } )`.
- Harness: post `'-' . $this->id['parent']` and a nested array, and assert that neither is stored.

**R5-3 · MINOR (required) · Invalid form HTML in the heading row.**
- Where: Exclusions.php:146-150 puts `<h3>` inside `<th>`. The HTML spec forbids heading content in `th`.
- Fix: use `<td colspan="2">`, or close the form-table, print the `<h3>` and help text, and open a second `<table class="form-table">` for the three rows and "From".
- Also MINOR (a11y, PRD-00 §2.13): the roles `<fieldset>` (:248) has no `<legend>`, and its `<th>` text isn't associated with it. Add `<legend class="screen-reader-text">` with the same text.
- Otherwise the form is valid: rows inside the existing table before "From" (RequestsPage.php:361-365), save markers after `</table>` inside the `<form>` (:382), a single marker per key (the harness checks this), labels on the selects, and `name="…[]"` on all three.

**R5-4 · MINOR · Test email and preview ignore exclusions.**
- Where: `Mailer::sample()` (Mailer.php ~549-566) filters products through `PostTypes::is_reviewable()` only.
- Failure: right after excluding "Gift cards", the merchant's preview and test email still show the gift card. That contradicts the new help text. The test link's landing page *does* filter it, so the email and the page disagree.
- Fix: `$this->reviewable->filter_excluded( array_values( $products ) )` in `sample()`. Fall back to sample data when the result is empty.

**R5-5 · MINOR · Landing for a fully excluded token.**
- The behaviour is correct per the PRD. `pending_products()` returns `[]` (Landing.php:227-239), `$valid` stays true because the token still resolves, and the template takes the `empty( $products )` branch (magic-landing.php:38). So the "All done" state shows. A submit for the excluded product gets 400 "This product is not part of your review link." (:270-271).
- Copy note: the state says "You have already reviewed everything from this order." to a customer who reviewed nothing. Consider a neutral variant ("There's nothing left to review from this order."), or pass an `excluded_only` flag to the template.
- Side effect: the excluded item stays `pending` in the token JSON, so `mark_product()` never flips a partly excluded token to `used` (TokenRepository.php:287-306). The token stays `active` until it expires. That's harmless, because Landing filters it, but worth a line in CONTRACTS.

**R5-6 · MINOR · Cache and performance.**
- **Static cache.** The rules are cached per PHP process (Reviewable.php:27, 44-65) and reset on `update_option_`/`add_option_ndv_reviews_settings` (Exclusions.php:33-34).
  - A persistent object cache can't serve stale rules, because `get_option` and `{taxonomy}_children` (`get_term_children` → `_get_term_hierarchy`) are invalidated by core.
  - The only stale case is a long-running worker (the WP-CLI Action Scheduler runner) when another process changes the settings. The `Settings` instance cache (Settings.php:71-77) has the same staleness, so this is existing behaviour. Note it only.
  - The only direct writer outside `Settings::update()` is the Installer migration (Installer.php:243), which is fine.
- **Term cache.** `filter_excluded()` calls `update_object_term_cache()` (:110) even when no category is excluded, one query per call. `for_order()` is called up to three times per send (queue-stage eligibility, send-stage eligibility, `send_for_order`), plus once per Landing view. Skip it when `rules()['cats']` is empty.
- `get_the_terms()` uses that cache (it's not `wp_get_post_terms`). The role check is one cached `get_userdata()`. Term expansion runs once per request. All as the PRD specifies.

**R5-7 · MINOR · `request_excluded_product` receives the parent id for variations.**
- Where: Reviewable.php:77-78, :98.
- The contract says `( bool, int $product_id )`. Document that a variation id is resolved to its parent before the filter runs, or pass the original id as a third argument.

**R5-8 · NIT.**
- `ordered_terms()` stops at depth 10 (Exclusions.php:202), so deeper categories are silently missing from the picker.
- `sanitize_roles()` lowercases through `sanitize_key`, so a plugin role with uppercase letters in its slug can never be saved.
- `Exclusions` isn't a container service (Plugin.php:503). A `ndv-reviews/services` filter that drops it also drops the cache reset. Acceptable; CONTRACTS doesn't promise a container id.

### Pro add-on (D:/NDV Reviews/rosette-reviews-pro) — nothing breaks
- **Engine::run_step()** (:150-151) and **link()** (:176-177), **SmartRules::should_request()** (:33-34), and **Dispatcher** (:116) all call `->for_order( $order )` on the container instance. The signature and return type (int[]) are unchanged, and there's no new constructor argument (`new Reviewable()` with no arguments, Plugin.php:259-264).
- **Channel\Email** → `send_for_order()` without `request_id`. This runs the gate (`origin=pro, source=legacy`), so a role-excluded customer gets `ndvr_customer_excluded`. Engine then writes an order note per step and keeps scheduling. That noise stays until RR-06P and is expected.
- **BulkCampaign** uses `exists_for_order()` (unchanged).
- **ESP role exclusion** waits for RR-06P, as the PRD states.
- R5-1 affects Pro too: it is a pooled-product correctness bug for every sender.

### AC check (RR-05)
| AC | Status |
|---|---|
| 1 | Pass |
| 2 | Pass: via reflection on `token_products()`, not a real `handle_submit()` |
| 3a / 3b | Pass |
| 4 | Pass |
| 5 | Pass: `queue_for_order` is called directly, not by completing the order with reminders on |
| 6 | Pass |
| 7 | Pass |
| 8 | First half passes; the second half waits for RR-06P |
| 9 | **Passes vacuously for `-3`** (R5-2) |
| 10 | Pass (via `Settings::update()`) |
| 11 | LOG: core flows pass |

### Harness gaps (rr-05.php)
- Pooled products with one excluded (R5-1).
- A negative id equal to a real id, and nested arrays (R5-2).
- A real `Landing::handle_submit()` for the excluded product (400 "not part of your review link"), and a `maybe_render()` / template render of a fully excluded token showing "All done".
- A variation line item whose parent is excluded. An excluded product id given as a variation id.
- The three filters (`reviewable_order_products`, `request_excluded_product`, `request_excluded_customer`).
- `enqueue()` only on the `ndv-reviews-reminders` hook suffix.
- AC5 through `update_status( 'completed' )` with reminders on.

---

## Compatibility summary
- **PHP 7.4.** No 8.0-only syntax found in the changed files (grep). `??`, nullable parameter types and static closures are all fine on 7.4. The 7.4 sniff is unconfirmed by this review.
- **WP 6.0.** `wp_roles()->get_names()`, `translate_user_role()`, `get_term_children()`, `update_object_term_cache()`, `wp_get_post_parent_id()`, `set_transient()` and `get_current_screen()` all exist.
- **WC 8.0.** `woocommerce_order_actions` `$order` since 5.8; HPOS `handle_bulk_actions-{$screen}` since 7.2; `wc-enhanced-select` and `woocommerce_admin_styles` are registered on every admin page by `WC_Admin_Assets` (same pattern as ToolsPage). The HPOS save priorities in 8.0 are unverified (R4-4).
- **Uninstall.** The `ndvr_order_action_notice_` transient prefix is registered (Uninstall.php:59). The three RR-05 keys live inside `ndv_reviews_settings`, which uninstall already removes. Nothing is missing.

---

## Required before approval

**RR-04**
1. R4-1: translatable skipped-reason composition, and `_n()` for the "first %s orders" sentence.
2. R4-2: strip the trailing full stop from WP_Error messages in `reason()`.
3. R4-3: harness cancel matrix (Pro, campaign, legacy and failed rows survive; free follow-up is cancelled).
4. R4-4: WC 8.0 spike run in Playground, or an owner waiver recorded in TASKS/LOG.

Recommended, not blocking: R4-5 (already-queued guard), R4-7 (cancel reason text), R4-8 (cooldown hours from the error data), R4-9 (skip the cancel in bulk), R4-10 (label wording), a real-order bulk timing run.

**RR-05**
1. R5-1: check exclusion inside the `for_order()` loop, before the pool dedupe, plus a harness case.
2. R5-2: digits-only, scalar-only sanitising for cats and products; scalar strings for roles; a harness case for a negative real id and nested input.
3. R5-3: move the heading out of `<th>`, and add a legend to the roles fieldset.

Recommended, not blocking: R5-4 (preview and test respect exclusions), R5-5 (neutral "All done" copy for an excluded-only link), R5-6 (skip the term-cache prime when no category is excluded), R5-7 (document the filter id), plus the remaining harness gaps.
