# RR-07: Consent checkbox for review emails at checkout

Status: done (rev 4) · Plan: F (+ paired Pro task RR-06P) · Inherits PRD-00 + RR-00 + RR-09 (rev 3) · No schema change, no DB version · No Pro-facing API, so no `NDVR_API` change

Review findings applied: round 1 batch A X5 and RR-07 (all, including the "verify" items, resolved below or as Build spikes); round 2 RR-07 items 1 to 4 (two blockers).

Rev 4 (code review `reviews/code-RR-07.md`, 0 blockers, 3 majors, 12 minors):
- **M1:** the block listener records only the value in the request's `additional_fields`. The `_wc_other/…` order-meta fallback is dropped, because WooCommerce's customer-to-order sync can put a value there that this POST never carried. No key in the request means nothing is recorded (§6 "Mirror for block checkout" and AC3 amended).
- **M2:** the answer is also erased on `woocommerce_privacy_before_remove_order_personal_data`, before WooCommerce anonymises the order. The eraser and exporter match orders by billing email **and** by the account's customer id.
- **M3:** our field is never written to user meta (`update_user_metadata` / `add_user_metadata` short-circuit) and is removed from `woocommerce_customer_allowed_session_meta_keys`. That is a write-time defence on every WooCommerce version, on top of the draft-time move into `ndvr_consent_draft`.
- **m1:** the recorded text is what is shown. Block: `optionalLabel` = the label. Classic: the `(optional)` span is stripped for our key on `woocommerce_form_field_checkbox`.
- **m2:** the order-screen date is shown in the store's time zone; with no time it reads "Agreed at checkout".
- **m3:** the exporter's date ends in " UTC".
- **m4:** `default_value()` returns '1' / '0', the stored form.
- **m5:** no order-screen line when consent was never turned on and nothing is recorded.
- **m7:** the user-meta delete runs only when a row exists; the draft hook returns at once when the mode is off.
- **m6** (missing `enabled_at` fails closed) and **m9** (Polylang label timing) are logged for follow-up; m6 is safe for privacy.
- **m10:** owner decision recorded: consent applies to order-based requests. Pro list campaigns (no order) are a separate legal basis; documented in CONTRACTS.
- **m11** is fixed in Pro RR-06P (no token is minted before the gate).
- Release gates: **R1** (WC 8.9–10.7 spikes) is still open, because Playground isn't available on this machine; the readme claim stands only with that caveat in TASKS. **R2** is closed by RR-06P: the ESP Dispatcher runs `check_eligibility()` before pushing.

`Consent`, `Mailer` and `Scheduler` are instance services reached through the container (`consent`, `mailer`, `scheduler`; RR-09 §6.2). `Class::method()` below names the method, not a static call, except `Consent::block_field_supported()`, which is a pure static helper.

## 1. Problem and who it's for
Many EU and UK merchants, a core audience for us, want explicit consent before sending review requests. "Soft opt-in" covers existing customers in many cases but not all; it depends on the country and the merchant's legal advice. Without a consent box, cautious merchants leave reminders off. YITH offers a checkout consent checkbox; CusRev and Judge.me rely on the store's own terms. Who it's for: stores that want a recorded yes before emailing, and stores outside the EU that want a simple opt-out.

## 2. Scope / non-goals
In scope:
- An optional checkbox at checkout, on **classic** checkout and the **Checkout block**.
- Two modes: opt-in (send only to customers who ticked "Email me a link to review my purchase") and opt-out (send unless the customer ticked "Don't email me a link to review my purchase"). Neither mode uses a pre-ticked box, and the block checkout never shows a returning customer a box ticked from an earlier order.
- **Silence isn't consent.** In opt-out mode an unticked box is recorded as `not_objected`, never as `yes`. Only a ticked opt-in box is a `yes`, and opt-in mode sends only on `yes`.
- The answer is recorded on the order with the time and the exact label shown, and shown on the order screen.
- The gate sits in RR-09's `Mailer::check_eligibility()`, so automatic, manual, follow-up, Pro campaign, Pro automation and Pro ESP sends all respect it.

Non-goals:
- Double opt-in.
- Asking for consent after the order (retroactive collection).
- Consent for marketing email. This is only about review requests.

## 3. User experience
**Review Reminders → "Reminder settings"**, new rows under the heading "Consent" (rendered in RequestsPage's form table):
- "Ask for consent at checkout" (select):
  - "Off: don't show a checkbox" (default, today's behaviour)
  - "Opt-in: send only if the customer ticks the box"
  - "Opt-out: send unless the customer ticks the box"
- "Opt-in checkbox text" (default "Email me a link to review my purchase"), shown when the mode is opt-in.
- "Opt-out checkbox text" (default "Don't email me a link to review my purchase"), shown when the mode is opt-out.
- "Orders placed before you turned this on" (opt-in only): "Don't send" (default) / "Send".
- Help: "The answer is saved on each order with the time and the wording shown. Orders created without the checkout form, such as orders you add in the admin, have no answer: in opt-in mode they get no request."
- When the mode isn't off, WooCommerce is older than 8.9 and the checkout page uses the Checkout block, a warning on this screen: "Your checkout uses the Checkout block, which needs WooCommerce 8.9 or later to show the consent checkbox. Until you update, block checkout orders have no answer recorded."

**Checkout:**
- **Classic:** the checkbox renders in the "Additional information" area, after the order notes, as an ordinary unticked checkbox with the configured text.
- **Block:** an optional checkbox in the Checkout block's order section (WooCommerce "order" location), with the same text.

**Order screen**, below the billing address, a line "Review emails:" followed by one of:
- "Agreed at checkout on {date}"
- "Declined at checkout on {date}"
- "Didn't opt out at checkout on {date}"
- "No answer recorded"
- "Order placed before consent was turned on"
- "Answer erased on request"

**RR-04 notice reason:** "didn't agree to review emails".

## 4. Reuse map
- RR-09 API: `Mailer::check_eligibility()` step 5 "consent", skip code `ndvr_no_consent` (in `Scheduler::SKIP_CODES`).
- WooCommerce classic checkout (WC 11.2.0):
  - `woocommerce_after_order_notes` (templates/checkout/form-shipping.php:67) to render;
  - `woocommerce_checkout_create_order` (`$order`, `$data`) (includes/class-wc-checkout.php:487) to save, after the checkout nonce check in `process_checkout()` (:1364-1371).
- WooCommerce additional checkout fields (WC 11.2.0):
  - `woocommerce_register_additional_checkout_field()` (src/Blocks/Domain/Services/functions.php:13);
  - `woocommerce_store_api_checkout_update_order_from_request` (`$order`, `$request`), which fires after WooCommerce has stored the additional fields (src/StoreApi/Utilities/CheckoutTrait.php:212 persists, :254 fires);
  - `woocommerce_store_api_checkout_update_draft` (`$request`), since 10.8, fired by a PATCH with no order in flight, after the fields were written to the customer and before the response is built (src/StoreApi/Routes/V1/Checkout.php:459, response at :461);
  - the filter `woocommerce_get_default_value_for_{$key}` (since 8.9), consulted when an object has no stored value (CheckoutFieldsStorage.php:108, :158);
  - the stored value lives at order meta `_wc_other/<field id>`, '1'/'0' (src/Blocks/Domain/Services/CheckoutFieldsStorage.php:62-80, :93-96). We read it with `$order->get_meta()`, not through an internal class method.
- `woocommerce_admin_order_data_after_billing_address` (`$order`) (WC 11.2.0 includes/admin/meta-boxes/class-wc-meta-box-order-data.php:647) for the order-screen line.
- `Privacy\Privacy` exporter and eraser registration pattern (Privacy.php:82-104).
- RR-00 F2 uninstall registry, F6 registry with the Reminders save rule from RR-05 §6.

**Why not "before Place order":** the terms block and `woocommerce_review_order_before_submit` sit inside `.woocommerce-checkout-payment` (WC 11.2.0 templates/checkout/payment.php:49-51). checkout.js replaces that fragment on every `update_order_review` (assets/js/frontend/checkout.js:741, :795) and re-ticks only `#terms` (:766, :803-804). A box there would lose its tick whenever the customer changes their address. `woocommerce_after_order_notes` is outside the refreshed fragments.

## 5. Blast radius
- **Free senders:** all pass `check_eligibility()`: Scheduler (automatic, queue and send), RR-04 manual, RR-06 follow-up.
- **Pro Engine:** sends through `Channel\Email::send()` → `Mailer::send_for_order()` (Channel/Email.php:44-46). RR-09 rev 3 makes that legacy direct path run `check_eligibility( stage=send, origin=pro )` (RR-09 §5), so consent applies with no Pro change.
- **Pro BulkCampaign:** sends through `Scheduler::process()` (BulkCampaign.php:137-140), so consent applies at send time with no Pro change.
- **Pro ESP Dispatcher:** the real gap. It checks only suppression (Dispatcher.php:112) before pushing the customer's email and review link to Klaviyo, Mailchimp, Brevo or a webhook. RR-06P makes it call `check_eligibility( $order, ['stage'=>'send','origin'=>'pro'] )` first; the ESP check is an RR-06P acceptance criterion.
- **WooCommerce customer data:** on block checkout, WooCommerce copies additional fields to the customer (user meta for accounts, the session for guests) on the PATCH path (Checkout.php:473-482 → CheckoutTrait.php:306-316), and the checkout response reads them back for a customer (CheckoutSchema.php:282) and as a fallback for an order (:319-324). This feature deletes that customer-side copy (§6), so a returning customer never sees the box pre-ticked from an earlier order.
- **RR-03:** this feature sets the transparency facts `consent` (`off`/`optin`/`optout`) and `consent_legacy` (`consent_legacy_orders`). Only `optin` with `skip` selects the "we email customers who agreed to this at checkout" variant of the `requests` sentence: with "Send", older orders get emails without an agreement.
- **RR-08:** both checkbox texts are translatable strings (names `consent_label_optin`, `consent_label_optout`).
- **Checkout:** one more field on classic and block checkout. No change to totals, payment or validation.

## 6. Contract delta
- **New class** `Requests\Consent` (Registerable), container id `consent`, in `Plugin::boot()`'s service list:
  - `public function allows( \WC_Order $order ): bool`
  - `public function status( \WC_Order $order ): string` returns `yes|no|not_objected|erased|none|legacy`
  - `public static function block_field_supported( string $wc_version ): bool`: true at 8.9 or later, then passed through the filter `ndv-reviews/consent_block_supported` (bool `$supported`, string `$wc_version`). The block registration and the Reminders warning both call it with `WC_VERSION`.
  - `public function label( string $mode ): string`: the checkbox text for `optin` or `optout`, read once per request through `ndv-reviews/translate_setting` (RR-08) and memoized.
- **Rules in `allows()`**, in order:
  1. A recorded `no` or `erased` returns false in every mode, including off. A recorded refusal is never ignored.
  2. Mode `off`: true.
  3. Mode `optout`: true (a `yes`, `not_objected` or no record).
  4. Mode `optin`, which honours only an explicit `yes`:
     - recorded `yes` → true;
     - recorded `not_objected` → false (silence in opt-out mode was never an agreement, so switching to opt-in doesn't turn it into one);
     - no record and the order was created before `ndv_reviews_consent_enabled_at` → `consent_legacy_orders === 'send'`;
     - otherwise false.
  5. The result passes through the filter `ndv-reviews/review_consent_allows` (bool, WC_Order).
- **Eligibility:** step 5 returns `WP_Error( 'ndvr_no_consent', __( "Customer didn't agree to review emails.", 'rosette-reviews' ) )` when `allows()` is false.
- **Settings keys** (F6 registry, page `reminders`):
  - `consent_mode` ('off'|'optin'|'optout', default 'off')
  - `consent_label_optin` (string, '' = default text)
  - `consent_label_optout` (string, '' = default text)
  - `consent_legacy_orders` ('skip'|'send', default 'skip')
- **Option** `ndv_reviews_consent_enabled_at` (int, GMT timestamp, autoload no). Written by a listener on `update_option_ndv_reviews_settings` whenever `consent_mode` changes from `off` (or absent) to another value. It's a separate option so the settings save can't reset it.
- **Order meta** (through `$order->update_meta_data()`):
  - `_ndvr_review_consent` (`yes`|`no`|`not_objected`|`erased`)
  - `_ndvr_review_consent_at` (GMT `Y-m-d H:i:s`)
  - `_ndvr_review_consent_text` (the label shown, max 200 characters)
  - `_ndvr_review_consent_via` (`classic`|`block`)
- **WooCommerce-owned meta written for our field:** order meta `_wc_other/ndv-reviews/review-email-consent`. On WooCommerce 10.8 and later, the deferred-draft PATCH path also stores it on the customer (user meta of the same key for accounts, the WooCommerce session for guests; WC 11.2.0 Checkout.php:473-482 → CheckoutTrait.php:306-316). The checkout response then reads it back (CheckoutSchema.php:282, and as the order fallback at :319-324), which would show a returning customer the box ticked.
- **Customer-side copy is never kept:**
  - `on_update_draft( $request )` on `woocommerce_store_api_checkout_update_draft` (Checkout.php:459, before the response at :461): if `WC()->customer` holds our key, copy its value into `WC()->session` key `ndvr_consent_draft`, then `WC()->customer->delete_meta_data( '_wc_other/ndv-reviews/review-email-consent' )` and `save()`.
  - `default_value()` on `woocommerce_get_default_value_for_ndv-reviews/review-email-consent` (CheckoutFieldsStorage.php:108, :158) returns the session value for a `WC_Customer` in the same session, so the open checkout keeps the box as the customer set it. A new session has no value, so the box starts unticked.
  - The mirror listener (below) records the answer, then deletes the same customer meta (and saves), and unsets `ndvr_consent_draft`.
- **Block field:** id `ndv-reviews/review-email-consent`, `location => 'order'`, `type => 'checkbox'`, `required => false`, `show_in_order_confirmation => false`, `label => $this->label( $mode )`. Registered on `woocommerce_init` only when the mode isn't off and `block_field_supported( WC_VERSION )`. The exact label passed is kept in `$this->block_label`. The "order" location replaced "additional" in 8.9 (WC 11.2.0 CheckoutFields.php:423-425). Since 11.0, WooCommerce warns when fields are registered before `after_setup_theme` (CheckoutFields.php:210-213); `woocommerce_init` runs later.
- **Classic POST fields:**
  - `ndvr_review_consent` (checkbox, value `1`);
  - `ndvr_review_consent_shown` (hidden, value `1`, so a checkout that didn't show the box, for example a third-party express flow calling `create_order()`, records nothing instead of a false "no").
- **Mirror for block checkout:** on `woocommerce_store_api_checkout_update_order_from_request`, if `$request['additional_fields']` contains our id, record its boolean. Otherwise record nothing (rev 4, M1: the `_wc_other/…` order meta is never read, as WooCommerce may have synced it from the customer). The recorded text is `$this->block_label`, the label registered on `woocommerce_init`; the listener never calls `translate_setting` again during the Store API request.
- **Recording:** opt-in: ticked → `yes`, unticked → `no`. Opt-out: ticked → `no`, unticked → `not_objected`. The classic path records `$this->label( $mode )` from the same request.
- **Privacy:**
  - exporter key `ndv-reviews-consent` ("Rosette Reviews: review email consent"), group `ndvr_review_consent`, label "Review email consent";
  - eraser key `ndv-reviews-consent`.
- **Uninstall registry** (RR-00 F2):
  - order meta (both storage engines): the four `_ndvr_review_consent*` keys and `_wc_other/ndv-reviews/review-email-consent`;
  - user meta `_wc_other/ndv-reviews/review-email-consent`;
  - option `ndv_reviews_consent_enabled_at`.
- **CONTRACTS.md:** all of the above.

## 7. Storage and upgrade
- Order meta through `WC_Order` methods only, so it works on HPOS and legacy storage.
- No table, column or DB version. Old orders have no meta; the opt-in legacy rule decides them.
- A site that never changes the mode sees no checkout change and no send change.

## 8. Security
- **Settings:** the Reminders save (nonce `ndvr_requests`, then `Caps::manage( 'reminders' )`, RequestsPage.php:143-146). `consent_mode` and `consent_legacy_orders` are compared against their allowed values (anything else falls back to the default). Labels are `sanitize_text_field`, max 200 characters. At checkout each label is read by `label()` through `apply_filters( 'ndv-reviews/translate_setting', $value, 'consent_label_optin' | 'consent_label_optout', '' )` (RR-08; unchanged without it). `_ndvr_review_consent_text` records the text shown: the rendered label on classic, the registered `$this->block_label` on block.
- **Classic save:** `$_POST['ndvr_review_consent']` and `$_POST['ndvr_review_consent_shown']` are read inside `woocommerce_checkout_create_order`, which `process_checkout()` reaches only after `wp_verify_nonce( …, 'woocommerce-process_checkout' )` (WC 11.2.0 class-wc-checkout.php:1364-1371). Each read uses `wp_unslash` and a strict `'1' ===` comparison, with `phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by WC_Checkout::process_checkout()`.
- **Block save:** the Store API validates its nonce and the field (WooCommerce's checkbox type). We only cast to bool. The draft and default-value callbacks touch only our own key on `WC()->customer` and `WC()->session`.
- **Output:** checkout label through `esc_html` (classic, via `woocommerce_form_field()` args) and as a plain string to the block API. The order-screen line uses `esc_html` and `wc_format_datetime()`.
- **Privacy endpoints:** core privacy tools only (`manage_privacy_options`, core nonces).

## 9. Privacy
- **Personal data:** the answer, its time, the wording shown and the channel, linked to an order and so to a person (PRD-00 §2.9).
- **Exporter:** paged by the order billing email (`wc_get_orders( ['billing_email'=>$email,'limit'=>50,'page'=>$page] )`). One item per order with an answer: Order number, Answer ("Agreed", "Declined", "Did not object", "Erased"), Date, Checkbox text.
- **Eraser** (paged the same way), per order:
  - set `_ndvr_review_consent` to `erased`;
  - delete `_at`, `_text`, `_via` and `_wc_other/ndv-reviews/review-email-consent`;
  - save the order.
  It also deletes the user meta for the account with that email. `erased` blocks sending in every mode, so an erasure can never turn a "no" into a send.
- **Opt-in uninstall:** removes all the keys in the registry, from both order storage engines.
- **Readme privacy notes:** "If you turn on checkout consent, Rosette Reviews saves the customer's answer, the time and the checkbox wording on the order. They are included in WordPress personal data exports and erasures."

## 10. Performance and assets
- Checkout: one field; no extra query, no JS, no CSS (WooCommerce styles the checkbox).
- Send time: `allows()` reads order meta already loaded with the order, plus one option.
- Settings screen: no new asset; the mode-dependent rows show and hide with plain `<select>` and server-rendered rows (all rows visible without JS).

## 11. Compatibility
- **HPOS and legacy:** `WC_Order` meta API only.
- **WC 8.0 to 8.8:** classic checkout works; the block field isn't registered (warning above). In opt-in mode, block-checkout orders on those versions have no answer and get no request.
- **WC 8.9 and later:** block field registered.
- **Theme overrides of `form-shipping.php`** that drop `woocommerce_after_order_notes` hide the classic box. Documented in the readme FAQ.
- **Express payment buttons:** flows that use the Store API checkout record the answer when they send `additional_fields`. Flows that skip our field record nothing (see Build spike 3).
- **PHP 7.4, WP 6.0:** no newer syntax.
- **Pro absent:** free gate only. **Pro present:** Engine and BulkCampaign covered now, Dispatcher with RR-06P.

### Build spikes (verify at build start; on-disk evidence is WooCommerce 11.2.0 only)
1. **Meta persistence in the block flow, WC 8.9 to 10.x.** On 11.2.0 the POST route calls `update_order_from_request( $request, false )` (src/StoreApi/Routes/V1/Checkout.php:597), so the action at CheckoutTrait.php:228 runs without a save, and the order is saved later by `update_status( 'pending' )` (Checkout.php:675). Verify the same on 8.9 and 9.x by placing a block-checkout order in Playground. Fallback: call `$order->save_meta_data()` in our listener.
2. **Value shape and timing on 8.9 to 9.x.** Check that `$request['additional_fields'][ id ]` is a boolean for checkboxes, and that WooCommerce persists additional fields before this action, as it does in 11.2.0 (persist at :212, action at :228). Fallback: read only `_wc_other/<id>`, normalising '1', '0', true and false.
3. **Express payment buttons** (WooPayments, Stripe) on the Checkout block. Check whether they send `additional_fields`. If they don't, those orders have no answer: in opt-in mode they get no request, and the settings help text already says so. No code fallback needed.
4. **`show_in_order_confirmation => false`.** Confirm it also keeps WooCommerce's editable copy of the field off the admin order screen, so the merchant isn't shown two answers. Fallback: register with `hidden` admin display if available, otherwise accept WooCommerce's line and label ours "Review emails (recorded at checkout)".
5. **Customer-side copy on 8.9 to 10.x.** On 11.2.0 the copy comes only from the no-order PATCH path; the order paths sync only address and contact fields to the customer (CheckoutTrait.php:280-293 → CheckoutFieldsStorage.php:176-187, `is_customer_field()` at CheckoutFields.php:867-869), so an `order`-location field isn't copied there. `woocommerce_store_api_checkout_update_draft` exists only from 10.8. Verify on 8.9, 9.x and 10.0 to 10.7, by placing two block-checkout orders as one account in Playground, where WooCommerce writes the customer copy (if anywhere) and whether the second checkout shows the box ticked. Fallback: also delete the customer copy on `woocommerce_store_api_checkout_update_customer_from_request` (Checkout.php:954), and on `rest_request_before_callbacks` for the `/wc/store/v1/checkout` route.
6. **Draft round trip.** Confirm the Checkout block doesn't untick the box when a PATCH response carries our field. With the session mirror and default-value filter above, the response should carry the customer's own choice. Fallback: if the block ignores the default-value filter for customers, skip `on_update_draft()` and rely on the record-time delete, which still clears the copy before the next checkout.

## 12. Acceptance criteria
All in Playground through PHP.
1. **Mode off:** `do_action( 'woocommerce_after_order_notes', WC()->checkout() )` prints nothing; the block field id is not in `Package::container()->get( CheckoutFields::class )->get_additional_fields()`; sends are unchanged.
2. **Opt-in, classic:**
   - the render prints an unticked checkbox `ndvr_review_consent` with the default text, plus the hidden `ndvr_review_consent_shown`;
   - with both POST values set, `do_action( 'woocommerce_checkout_create_order', $order, [] )` then `$order->save()` stores `yes`, a GMT time, the text and `classic`, and completing the order queues and sends a request;
   - with only `ndvr_review_consent_shown`, it stores `no`, and `queue_for_order()` returns `ndvr_no_consent` (no row).
3. **Opt-in, block (WC 8.9 or later):** a built `WP_REST_Request` with `additional_fields` `{ "ndv-reviews/review-email-consent": true }` passed to `do_action( 'woocommerce_store_api_checkout_update_order_from_request', $order, $request )`, followed by `$order->save()` (as the POST route does), records `yes` / `block`; `false` records `no`; no key records nothing, even when `_wc_other` order meta is present (rev 4).
4. **Opt-out:** ticked records `no` and the order is skipped; unticked records `not_objected` (never `yes`) and sends; an order with no record sends.
5. **Switching opt-out → opt-in:** an order recorded `not_objected` under opt-out, then the mode set to opt-in (with legacy "Send" as well as "Don't send"): `allows()` is false and `queue_for_order()` returns `ndvr_no_consent`. An order recorded `yes` under opt-in still sends.
6. **Legacy and admin orders (opt-in):**
   - an order dated before `ndv_reviews_consent_enabled_at` with no record is skipped with "Don't send" and sent with "Send";
   - an order created after it with `wc_create_order()` (no record) is skipped (`ndvr_no_consent`).
7. A recorded `no` still blocks after the mode is switched to off.
8. An RR-04 manual send to a declined order reports "didn't agree to review emails" and inserts no row.
9. `do_action( 'woocommerce_admin_order_data_after_billing_address', $order )` prints the matching "Review emails:" line for each of the six states, including "Didn't opt out at checkout on {date}" for `not_objected`. The exporter labels `not_objected` "Did not object".
10. **Privacy:**
   - the `ndv-reviews-consent` exporter lists one item per answered order for that email;
   - the eraser sets `erased`, deletes the other three keys and the `_wc_other` order meta;
   - afterwards the order is skipped even with legacy "Send".
11. `ndv_reviews_consent_enabled_at` is set when the mode changes from off to opt-in, unchanged when it changes from opt-in to opt-out, and set again on off → opt-in.
12. `check_eligibility( $declined_order, ['stage'=>'send','origin'=>'pro'] )` on the `mailer` service returns `ndvr_no_consent`. (The ESP Dispatcher half of this check is RR-06P's acceptance criterion.)
13. `Consent::block_field_supported( '8.8.0' )` is false and `( '8.9.0' )` true. With a stub `ndv-reviews/consent_block_supported` filter returning false and a checkout page containing `<!-- wp:woocommerce/checkout /-->`, the Reminders screen shows the WooCommerce 8.9 warning.
14. Saving `consent_mode = 'yes_please'` stores `off`; a 300-character label is cut to 200.
15. **No pre-ticked box for a returning customer (WC 10.8 or later):** as a logged-in customer in opt-in mode, `WC()->customer->update_meta_data( '_wc_other/ndv-reviews/review-email-consent', '1' )` then `do_action( 'woocommerce_store_api_checkout_update_draft', $request )` leaves no such user meta (`get_user_meta()` returns ''). After the AC3 record step and with `ndvr_consent_draft` unset (a new session), a second `rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/checkout' ) )` returns `additional_fields['ndv-reviews/review-email-consent'] === false`.
16. **Recorded label:** with a stub `ndv-reviews/translate_setting` returning "A" on `woocommerce_init` and "B" afterwards, the AC3 block record stores `_ndvr_review_consent_text` = "A".
17. Core flows pass, with consent off and with opt-in on.

## 13. Test plan
1. Playground with WooCommerce 8.9 or later, the plugin, reminders on, a product, and both a classic (shortcode) and a block checkout page.
2. **Classic:** create an order with `wc_create_order()`, set `$_POST`, fire `woocommerce_checkout_create_order`, save.
3. **Block:** build a `WP_REST_Request` and fire the Store API action directly. A full `/wc/store/v1/checkout` POST needs a cart session, so it's used only for Build spike 1.
4. Complete orders with `update_status( 'completed' )`, run requests with `Scheduler::process()`, capture mail with `pre_wp_mail`.
5. Privacy: call the exporter and eraser callbacks from `wp_privacy_personal_data_exporters` / `_erasers` directly.
6. Repeat AC2 with HPOS on and off.
7. Run core flows; record results and the Build spike outcomes in `LOG.md`.

## 14. Open questions
None.
