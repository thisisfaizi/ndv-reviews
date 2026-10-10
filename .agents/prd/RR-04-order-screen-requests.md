# RR-04: Send a review request from the order screen

Status: prd-ok (rev 4) · Plan: F · Inherits PRD-00 + RR-00 + RR-09 (rev 3) · No schema change, no DB version · Built after RR-09 · No Pro-facing API, so no `NDVR_API` change

Review round 1 findings applied: batch A X2, X3, X4, X6 and RR-04 (all). Round 2: passed; rev 3 only updates references to the shared rules (instance services, version constants, RR-09 rev 3).

`Scheduler`, `Mailer` and `RequestRepository` are instance services reached through the container (`scheduler`, `mailer`, `request_repository`; RR-09 §6.2). `Class::method()` below names the method, not a static call.

## 1. Problem and who it's for
Merchants can't ask one specific customer for a review: reminders are automatic or nothing. Typical cases:
- a VIP or wholesale-sized order;
- an order completed before the plugin was installed;
- a resend after the customer said they missed the email.

CusRev (free) and YITH offer a manual send from the order screen; WiserReview, Yotpo and Judge.me offer manual or list sends. Who it's for: every store owner or shop manager who runs reminders, and stores that keep reminders off but want to ask by hand.

## 2. Scope / non-goals
In scope:
- An "Order actions" item "Send review request" on the single order screen.
- A bulk action "Send review request" on the orders list.
- Both on HPOS and legacy storage, and in sync mode.
- Every request goes through the RR-09 pipeline (`Scheduler::queue_for_order()` → `Mailer::check_eligibility()` → `Scheduler::process()`), so the log, cooldown, suppression, consent, exclusions and tracking all apply. No `ndvr_requests` insert and no `wp_mail` outside it (PRD-00 §5).

Non-goals:
- Choosing products per order: all reviewable items not yet reviewed are included (`Reviewable::for_order()`).
- A per-order custom message.
- Sending to customers without an order (Pro RR-10 list sends).

## 3. User experience
**Single order (edit screen):** "Order actions" select → "Send review request" → "Update".
- Success notice after reload: "Review request queued for order #1234. It goes out within a few minutes."
- If another sender already asked (RR-09 filter `ndv-reviews/order_already_requested` is true), the success notice adds: "This order already had a review request from another sender."
- Skipped notice: "No review request for order #1234: {reason}." Reasons below.
- Order note (private, attributed to the current user by WooCommerce): "Review request queued from the order screen."
- The item is not listed for users without `Caps::manage( 'reminders' )`, or for an order without a valid billing email.

**Bulk (orders list):** "Send review request" in "Bulk actions".
- Notice: "Queued 18 review requests. Skipped 3: 2 already reviewed everything, 1 unsubscribed."
- Bulk skips orders that already have any review request (free, campaign, or reported by `order_already_requested`), with the reason "already asked".
- More than 200 selected: the first 200 are processed and the notice adds "Only the first 200 orders were processed. Select up to 200 at a time."

**Reasons** (short labels, mapped from RR-09 codes; all translatable):

| Code (RR-09) | Label |
|---|---|
| `ndvr_unsubscribed` | unsubscribed |
| `ndvr_nothing_to_review` | already reviewed everything |
| `ndvr_order_ineligible` | order status not eligible |
| `ndvr_no_email` | no valid email |
| `ndvr_no_consent` | didn't agree to review emails |
| `ndvr_customer_excluded` | customer role excluded |
| `ndvr_cooldown` | asked in the last %d hours (from `ndv-reviews/request_cooldown`, default 20) |
| `ndvr_already_requested` | already asked (bulk only) |
| anything else | the WP_Error message |

**Works when reminders are off:** manual sends don't need `reminder_enabled`. They reuse the saved subject, text and branding. The `should_send_reminder` filter is not applied: it gates the automatic path only (Scheduler.php:91), and the merchant asked explicitly.

**Request log:** the row shows Source "Manual" (RR-09 column).

## 4. Reuse map
- RR-09 API: `Scheduler::queue_for_order( $order_id, ['source'=>'manual','origin'=>'free','delay'=>…] )`, `Mailer::check_eligibility()` (called inside `queue_for_order` at stage `queue`, and again by `process()` at stage `send`), `RequestRepository::cancel_pending_for_order()`, `RequestRepository::exists_for_order()` (unchanged), filter `ndv-reviews/order_already_requested`, `Scheduler::SKIP_CODES`.
- `Support\Caps::manage( 'reminders' )` (Caps.php:25; the context the Reminders screen uses, RequestsPage.php:104).
- WooCommerce order actions meta box and both list tables (verified below).
- RR-00 F2 uninstall registry for the notice transient.

## 5. Blast radius
- **`Scheduler::on_order_status()`** (automatic path): unchanged by this PRD. After RR-09 it queues with the dedupe key `o:{order_id}:{origin}:{step}:{source}` for `auto`/`followup` rows (manual rows have none), so a manual row never blocks the automatic one. If the order reaches the trigger status after a manual send, the automatic request is still queued, and RR-09's send-time cooldown (keyed on `sent_at`, whatever the status) stops it only within the cooldown window. This is the behaviour the reviewer kept (AC5).
- **Pending free rows:** a successful manual queue calls `cancel_pending_for_order( $order_id, ['auto','followup'] )`, so a scheduled automatic or follow-up row for the same order can't send next to the manual one (X3). `cancel_pending_for_order()` cancels only `origin='free'` rows and clears their `dedupe_key` (RR-09 rev 3 §6.2), so Pro step rows (RR-06P, also `source=auto`) are never cancelled by a free manual send.
- **RR-06 follow-up:** a sent manual row schedules the follow-up exactly as an automatic one does (RR-09 `followup` default true for free manual rows), subject to RR-06's own rules.
- **`exists_for_order( $id )`** keeps "any email row" with no argument (RequestRepository.php:67-73). Pro BulkCampaign relies on it (BulkCampaign.php:116, :178), so a manual send makes later Pro campaigns skip that order. That's intended.
- **Pro Engine:** a manual send doesn't stop a pending `ndvr_pro_automation_step` job. RR-06P decides whether to, using `ndv-reviews/request_sent`. Noted in Pro TASKS.
- **Pro ESP Dispatcher:** unaffected.
- **`Admin\RequestsPage`:** log rows appear with Source "Manual"; Retry works through `process()` (RequestsPage.php:121-127).
- **`Requests\HealthCheck`:** manual rows are ordinary `ndvr_send_request` actions, so overdue detection covers them.

## 6. Contract delta
- **New service class** `Requests\OrderActions` (Registerable), container id `order_actions`, added to the service list in `Plugin::boot()` (Plugin.php:462-488).
- **Order action key** `ndvr_send_review_request`, label "Send review request":
  - filter `woocommerce_order_actions` (array `$actions`, `WC_Order|null` `$order`). WooCommerce passes `$order` since 5.8 and it may be `null` (WC 11.2.0 class-wc-meta-box-order-actions.php:214-227). Handle `null` by adding nothing.
  - action `woocommerce_order_action_ndvr_send_review_request` (`WC_Order`), fired from `WC_Meta_Box_Order_Actions::save()` (WC 11.2.0 :182-184), which runs on `woocommerce_process_shop_order_meta` at priority 50, after the order data save at 40 (WC 11.2.0 src/Internal/Admin/Orders/Edit.php:104-107). The legacy screen registers the same callbacks through the same method (WC 11.2.0 includes/admin/class-wc-admin-meta-boxes.php:51). A status change and the action in the same "Update" therefore queue the automatic row first; the manual queue then cancels it.
- **Bulk action key** `ndvr_send_review_request`:
  - HPOS: `bulk_actions-woocommerce_page_wc-orders` and `handle_bulk_actions-woocommerce_page_wc-orders` (`$redirect_to`, `$action`, `int[] $ids`) (WC 11.2.0 src/Internal/Admin/Orders/ListTable.php:1522).
  - Legacy: `bulk_actions-edit-shop_order` and `handle_bulk_actions-edit-shop_order` (WordPress core `edit.php`).
- **Filter** `ndv-reviews/manual_bulk_limit` (int, default 200).
- **Transient** `ndvr_order_action_notice_{user_id}` (120 s), shown and deleted on `admin_notices` on the screens `woocommerce_page_wc-orders`, `shop_order` and `edit-shop_order`.
- **No new** skip codes, cooldown filter or repository method: the old `manual_request_cooldown` filter and `recent_sent_for_order()` are dropped in favour of RR-09's `ndv-reviews/request_cooldown` and `last_sent_at_for_order()`.
- **Uninstall registry** (RR-00 F2): transient prefix `ndvr_order_action_notice_`.
- **CONTRACTS.md:** the action keys, the service id, the limit filter, and the note "manual sends don't apply `should_send_reminder`".

**Queue behaviour per entry point:**
- Single: `queue_for_order( $id, ['source'=>'manual','origin'=>'free','delay'=>0] )`.
  - `WP_Error` → nothing inserted; the reason is shown.
  - `ndvr_cooldown` at queue stage means "not queued", for manual sends too. RR-09 rev 3 does this: a manual queue inside the cooldown inserts no row and returns `ndvr_cooldown` (RR-09 §6.2, order of operations step 2), so the merchant gets the reason instead of a second email.
  - Success → `cancel_pending_for_order()`, order note, notice.
- Bulk: for each id (at most the limit), skip with "already asked" when `exists_for_order( $id )` or `order_already_requested` is true; otherwise `queue_for_order()` with `'delay' => 2 * $i` seconds (spreads mail volume), then `cancel_pending_for_order()` on success. Reasons are counted by label.

## 7. Storage and upgrade
Request rows with `source='manual'`, `origin='free'` (RR-09 schema, `Installer::V_PIPELINE`; planned v4, provisional per PRD-00 §4). No new storage, no DB version, no migration. An old site sees two new menu items and nothing else.

## 8. Security
- **Order action:** WooCommerce verifies the order-save nonce before the action fires: HPOS `check_admin_referer()` in `Edit::handle_order_update()` (WC 11.2.0 Edit.php:304), legacy `woocommerce_meta_nonce` (WC 11.2.0 includes/admin/class-wc-admin-meta-boxes.php:228). Our callback then checks `current_user_can( Caps::manage( 'reminders' ) )` before any work, so an order editor without plugin rights can't send, even by posting `wc_order_action` directly.
- **Bulk:** WooCommerce verifies `bulk-orders` and `edit_others_shop_orders` before the filter (WC 11.2.0 ListTable.php:1437-1441). Core verifies `bulk-posts` on the legacy screen. We re-check `Caps::manage( 'reminders' )`, compare `$action` strictly, `absint` every id, and cap the count.
- **Notice:** stored per user, every value `esc_html` at output. Order numbers come from `$order->get_order_number()`.
- No `$_REQUEST`; the bulk ids come from the filter argument.

## 9. Privacy
No new personal data. The order note contains no personal data (the author is the WooCommerce user record). Request rows are already in the exporter and eraser (Privacy.php:205-229, :316).

## 10. Performance and assets
- Single: one `queue_for_order()` (eligibility checks plus one insert plus one `as_schedule_single_action`).
- Bulk: at most 200 × (one `wc_get_order()`, one `exists_for_order()` query, one queue). Measured target under 10 s for 200 orders in Playground.
- No assets.

## 11. Compatibility
- **HPOS on, HPOS off, sync mode:** both screens through the hooks above.
- **WC 8.0:** `woocommerce_order_actions` already passes `$order` (since 5.8); the HPOS list table and its `handle_bulk_actions-woocommerce_page_wc-orders` filter are verified on 11.2.0 only (Build spike below).
- **Block checkout:** not involved.
- **PHP 7.4, WP 6.0:** no newer syntax.
- **Pro absent or present:** same behaviour; Pro's own sends are reported only through `order_already_requested`.

### Build spike (on-disk evidence is WooCommerce 11.2.0 only)
Run once on WooCommerce 8.0 in Playground before building:
1. The HPOS list table fires `bulk_actions-woocommerce_page_wc-orders` and `handle_bulk_actions-woocommerce_page_wc-orders` with `( $redirect_to, $action, $ids )`, after the `bulk-orders` nonce check.
2. On both the HPOS and the legacy edit screen, `WC_Meta_Box_Order_Data::save` runs at priority 40 and `WC_Meta_Box_Order_Actions::save` at 50 on `woocommerce_process_shop_order_meta`.

Fallbacks:
- If (1) differs, hook the HPOS bulk handler on `admin_action_ndvr_send_review_request` with our own `check_admin_referer( 'bulk-orders' )`.
- If (2) differs, the order-action handler only records the request, and the queueing runs from a one-shot `woocommerce_process_shop_order_meta` callback at priority 60. That callback runs after the data save whatever WooCommerce's own priorities are, so AC12 holds.

## 12. Acceptance criteria
All in Playground through PHP, using `pre_wp_mail` to capture mail.
1. **HPOS single:** as admin, `do_action( 'woocommerce_order_action_ndvr_send_review_request', $order )` creates one row (`source=manual`, `origin=free`, `status=scheduled`) and an order note. `Scheduler::process( $id )` sends one email and the row becomes `sent`.
2. **Legacy single:** the same with HPOS switched off (`woocommerce_custom_orders_table_enabled = no`).
3. **Bulk:** three orders (one fully reviewed, one unsubscribed, one eligible). `apply_filters( 'handle_bulk_actions-woocommerce_page_wc-orders', $url, 'ndvr_send_review_request', $ids )` stores a notice reading "Queued 1 review request. Skipped 2: 1 already reviewed everything, 1 unsubscribed." (counts use `_n()`). Exactly one email goes out after processing the queue.
4. **Cooldown:** with the first manual row's `sent_at` back-dated 1 hour, a second manual send inserts nothing and the notice says "asked in the last 20 hours". With `sent_at` back-dated 21 hours, it queues.
5. **Automatic still runs (outside the cooldown):** manual send on a processing order, row `sent_at` back-dated 2 days, then `$order->update_status( 'completed' )`: an `auto` row is queued and `process()` sends it.
6. **No double send inside the cooldown:** manual sent 1 hour ago (back-dated), then completion queues the `auto` row; `process()` on it marks it `cancelled` with `ndvr_cooldown`.
7. **Pending rows cancelled:** an order with a pending `auto` row; a manual queue sets that row to `cancelled`, and only the manual row sends.
8. **Capability:** as a shop manager whose `ndv-reviews/manage_capability` filter maps to a capability they lack, the order action adds no row, the bulk filter returns the redirect unchanged with no rows, and the dropdown omits the item (`apply_filters( 'woocommerce_order_actions', [], $order )`).
9. **Null order:** `apply_filters( 'woocommerce_order_actions', $actions, null )` returns `$actions` unchanged, with no PHP warning.
10. **Limit:** 250 ids → 200 processed, notice includes the "first 200" sentence.
11. **Bulk already-asked:** an order with any prior email row is skipped as "already asked"; a stub `order_already_requested` filter returning true does the same.
12. **Same-Update status change:** setting the status to completed and choosing the action in one save (simulate: `update_status('completed')`, then the action) leaves the `auto` row cancelled and one manual row scheduled.
13. Pro `Channel\Email` (if present) still sends; core flows pass.

## 13. Test plan
1. Playground with WooCommerce, the plugin and three orders with fixtures as above. AC1 to AC4 and AC8 to AC11 run with reminders **off** (manual sends don't need them). AC5 to AC7 and AC12 run with reminders **on**, `reminder_status = completed` and `reminder_delay_days = 0`. The `scheduler` service is re-registered after the setting changes, because the status hook is bound in `register()` (Scheduler.php:66-67).
2. Call the handlers directly as admin and as a restricted user (`wp_set_current_user`).
3. Back-date `sent_at` with a direct `$wpdb->update` on `Db::table('requests')`.
4. Run each request with `Scheduler::process( $id )`; capture mail with `pre_wp_mail` returning true.
5. Switch HPOS through the `woocommerce_custom_orders_table_enabled` option and repeat AC1.
6. Run core flows; record results in `LOG.md`.

## 14. Open questions
None.
