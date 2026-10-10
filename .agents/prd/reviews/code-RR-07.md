# Code review: RR-07 (consent checkbox for review emails at checkout)

Reviewer: independent code review, 2026-10-10. Read-only: no code edits were made, the QA site wasn't run and nothing was installed. The build reviewed is the one described in the last LOG.md entry ("RR-07 checkout consent built (in_review)").

**Files read.** Free plugin:
- `includes/Requests/Consent.php` (new)
- `Requests/Mailer.php`
- `Requests/Scheduler.php`
- `Requests/OrderActions.php`
- `Plugin.php`
- `Admin/RequestsPage.php`
- `Admin/SettingsFields.php`
- `Support/Settings.php`
- `Uninstall.php`
- `Display/Transparency.php`
- `Integrations/Multilingual.php`
- `readme.txt`
- the harness `.agents/qa/rr-07.php`

Pro plugin:
- `Automation/Engine.php`
- `Automation/BulkCampaign.php`
- `Channel/Email.php`
- `Channel/Message.php`
- `Esp/Dispatcher.php`
- `Developer/Webhooks.php`
- `.agents/TASKS.md`

**Reference sources.** All WooCommerce paths below are 11.2.0 on the QA site and are relative to `wp-content/plugins/woocommerce/`. The WP core files are 7.1.3 (`wp-includes/l10n.php`).

**Static checks:**
- `php -l` (PHP 8.3 CLI) is clean on Consent.php, Mailer.php, Plugin.php, RequestsPage.php, Uninstall.php and rr-07.php.
- `vendor/bin/phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 7.4-` is clean on Consent.php and Mailer.php.
- The WPCS run on Consent.php gives 0 errors and 4 warnings. All four are the project-wide `ndv-reviews/` hook-name convention.

---

## Verdict

**CHANGES REQUESTED.** There are no code blockers.

The consent rules are correct and every free sender goes through the gate. Classic capture, uninstall and the RR-03 facts are correct.

Three majors remain:
- **M1, block capture.** On the Checkout block, the fallback can record an answer that the POST never carried. The value comes from WooCommerce's customer-to-order sync of our session mirror.
- **M2, erasure.** The eraser misses orders that WooCommerce's own order eraser has already anonymised.
- **M3, unverified older versions.** The "never pre-ticked" guarantee is proven only on WC 11.2.

Two items gate the release but need no free code change:
- **R1:** Build spike 5 has to be done on WC 8.9–10.7, or that support claim has to be narrowed.
- **R2:** RR-07 must not reach Pro sites that use ESP pushes before RR-06P ships.

---

## What was checked and is correct

**Consent rules.** `Consent::allows()` at Consent.php:141-164 matches PRD §6 exactly:

| Status | off | optout | optin |
|---|---|---|---|
| `no` / `erased` | false | false | false |
| `yes` | true | true | true |
| `not_objected` | true | true | **false** |
| `none` | true | true | false |
| `legacy` | true | true | `consent_legacy_orders === 'send'` |

- **Silence isn't consent.** `record()` at :225 can only write `yes` when the mode is opt-in and the box is ticked. An unticked opt-out box gives `not_objected`.
- **Opt-out → opt-in switch.** A `not_objected` order is false in opt-in mode (AC5).
- **No misreading of WooCommerce's raw tick.** `allows()` never reads `_wc_other/…`. An opt-out tick ('1' = "don't email") therefore can't be read as an opt-in yes, except through M1.
- **Legacy orders.** `status()` at :121-133 returns `legacy` only when there is no record and the order's `date_created` is earlier than `ndv_reviews_consent_enabled_at`.

**When `enabled_at` is written.** `on_settings_updated()` (:485-491) writes it when the mode goes from off (or absent, or invalid) to optin or optout. It's left alone when the mode moves between opt-in and opt-out.

The first-ever save is covered. On a site with no option yet, `update_option()` falls through to `add_option()`, which fires `add_option_ndv_reviews_settings`. Consent.php:79 hooks that to `on_settings_added()` (:500). This path is not tested (see Harness gaps).

If the mode is changed without `update_option` (an option filter, a direct DB write, a staging push of the settings row without the timestamp), the code fails closed. See m6.

**The send gate (step 5).** It sits at Mailer.php:166-169, after suppression and before the role step, and returns `ndvr_no_consent`. That code is in `Scheduler::SKIP_CODES` (:41) and is mapped to "didn't agree to review emails" for RR-04 (OrderActions.php:276-277). Every sender passes through it:

| Sender | Path to the gate |
|---|---|
| Free auto, queue stage | Scheduler.php:194 |
| Free auto, send stage | Scheduler.php:492 |
| Free manual / follow-up | Scheduler.php:267 |
| Free retry | goes through `process()` |
| Pre-v4 `process_legacy()` | Scheduler.php:562 → `send_for_order()` without `request_id` → full gate (Mailer.php:244-255) |
| Pro Engine | `run_step()` → `Channel\Email::send()` → `send_for_order( id )`, the legacy direct path (Email.php:45). Engine's only provider is email (Engine.php:189-195). |
| Pro BulkCampaign | `Scheduler::process()` (BulkCampaign.php:140, :189) |
| Pro ESP Dispatcher | **not gated** (Dispatcher.php:111-113 checks suppression only). See R2. |
| Pro webhooks | review events only, no review link pushed |

**Classic capture:**
- **Nonce.** `woocommerce_checkout_create_order` is reached only through `WC_Checkout::create_order()`. On the normal path, `process_checkout()` verifies the nonce first. Third-party callers of `create_order()` bring their own nonce. A forged POST could only set the answer on an order the victim is placing anyway. The reads use `wp_unslash`, `sanitize_text_field` and a strict `'1' ===` comparison.
- **Express flows.** Without `ndvr_review_consent_shown` nothing is recorded (:273-275).
- **Placement.** `woocommerce_after_order_notes` fires even when order notes are disabled (templates/checkout/form-shipping.php:67, outside the `! empty( $order_fields )` block). It is outside the fragments that checkout.js refreshes.
- **Unticked render.** `woocommerce_form_field( …, 0 )` gives `checked( 0, '1' )`, which is false.

**Is the label double-escaped?** No.
- On 11.2 the checkbox branch prints `wp_kses_post( $args['label'] )` (includes/wc-template-functions.php:3534).
- `esc_html()` encodes `& ' " < >` to valid entities and does not re-encode existing ones. `wp_kses_post()` keeps valid entities, so "Tom & Jerry's" renders correctly.
- On WC 8.x the checkbox label was concatenated raw, so the `esc_html()` is the necessary escape there. Keep it.

**Block field registration.** It happens on `woocommerce_init` (init:0):
- That is after `after_setup_theme`, so there is no WC 11 `_doing_it_wrong` (CheckoutFields.php:210-213) and no WP JIT-translation notice (l10n.php:1444).
- It is gated by mode, `block_field_supported( WC_VERSION )` and `function_exists`.
- `woocommerce_register_additional_checkout_field()` re-queues itself if `woocommerce_blocks_loaded` hasn't run yet (Domain/Services/functions.php:17-25).
- `register()` is wired on `plugins_loaded` (rosette-reviews.php:95-102), well before init.

**Block record and save timing (11.2):**
- **POST.** `update_order_from_request( $request, false )` persists the request fields (CheckoutTrait.php:212), then fires our action (:254). The order is saved later by `update_status( 'pending' )`. If something throws in between, the catch in `get_route_post_response()` saves the order. Our `update_meta_data()` therefore persists.
- **PATCH with an order in flight.** `persist = true`, so the order is saved right after our action.
- **Recorded label.** `$this->block_label` (AC16).

**Customer-side copy on 11.2 (Checkout.php:386-461, CheckoutTrait.php:306-316):**
- The copy lives in the session-backed `WC()->customer` (`WC_Customer_Data_Session`), whose meta is allowed via `CheckoutFields::add_session_meta_keys()` (:139-149). This applies to logged-in shoppers too.
- `on_update_draft()` mirrors the value, deletes the copy and saves, so the session snapshot drops it.
- `delete_user_meta()` covers any DB copy.
- The draft response reads `get_all_fields_from_object( customer )`. This hits our default-value filter for the missing key and returns the shopper's own choice (CheckoutFieldsStorage.php:150-166, CheckoutSchema.php:319-324).
- The order→customer syncs on 11.2 copy only contact/address fields (`is_customer_field()`, CheckoutFields.php:867-869; OrderController.php:129). An `order`-location field never reaches user meta.
- Within one checkout, the box state survives a PATCH. After a POST the mirror is cleared, so the next checkout starts unticked.
- Guests use the same session store and behave the same.

**Admin hiding of WooCommerce's copy:**
- `CheckoutFieldsAdmin::admin_order_fields()` keys fields by the bare id (CheckoutFieldsAdmin.php:146-158).
- Unsetting `FIELD_ID` at priority 20 removes WooCommerce's copy from both the view and the edit forms. WooCommerce's meta-box save then never overwrites the value.
- The order-preview modal goes through the same filter.
- The thank-you page and emails respect `show_in_order_confirmation` (CheckoutFields.php:920; CheckoutFieldsFrontend.php:100-111).

**Uninstall** (Uninstall.php:42-93, 275-291):
- The four `_ndvr_review_consent*` keys and `_wc_other/ndv-reviews/review-email-consent` are deleted from `wc_orders_meta` (HPOS) and `postmeta` (legacy storage and its sync copy).
- The same `_wc_other` key is deleted from user meta.
- The option `ndv_reviews_consent_enabled_at` is deleted.
- The deletes are WooCommerce-independent.

**RR-03 facts.**
- `consent` and `consent_legacy` follow the settings (Consent.php:511-517).
- Transparency.php:191 picks the "customers who agreed" sentence only for opt-in with skip, which is correct. With "Send", legacy orders get emails without an agreement.

**Exporter and eraser paging.** Both page 50 at a time with `done = count < 50`. Erasing doesn't change the billing-email match, so pages stay stable. User meta is removed on page 1 only. See M2 for the ordering issue.

**Compatibility.**
- **PHP and WP.** There is no PHP 8 syntax. `\Throwable` and static closures are fine on 7.4. Everything used (`has_block( name, id )`, the privacy filters, `update_option(..., false)`) exists in WP 6.0.
- **WC 8.0–8.8.** The block field isn't registered. The warning shows only when the checkout page has the block (AC13). The 8.9+ hooks (`update_draft`, `get_default_value_for_*`) simply never fire there.

**i18n.** All strings use the `rosette-reviews` domain. Translator comments sit on every `sprintf`. The labels go through RR-08 (`Multilingual::STRINGS` lists both keys, :45-46).

---

## Findings

### MAJOR

**M1. On the block path, the fallback records an answer the POST never carried.** Locations: Consent.php:328-333 and :373-383; WooCommerce OrderController.php:84 and :904, CheckoutFieldsStorage.php:150-166 and :197-207.

The mechanism, on every Store API POST:
- `create_or_update_draft_order()` (Checkout.php:813) calls `update_order_from_cart()`, which calls `update_addresses_from_cart()`, which calls `sync_order_additional_fields_with_customer( $order, wc()->customer )`.
- That sync copies **every** registered field from the customer onto the order. It checks `is_field()`, not `is_customer_field()`.
- It reads them through `get_all_fields_from_object( $customer, …, true )`, which falls back to our default-value filter for missing keys. Our `default_value()` returns the session mirror `ndvr_consent_draft`.
- So the order gets `_wc_other/…` = '1' or '0' from the mirror before our action runs.
- When the POST carries our key, `persist_additional_fields_for_order()` overwrites that value and all is well. When it doesn't (express buttons and other Store API clients, which is Build spike 3), `save_block()`'s fallback reads the synced value and records it as a `block` answer, with the current label and the current time.

Failure scenarios:
1. Opt-in mode. A shopper ticks the box on the checkout page; the PATCH sets the mirror to true. They abandon the checkout. Later in the same 48-hour session they buy something else with an express button on a product page that sends no `additional_fields`. That order is recorded `yes` "Agreed at checkout", for a flow that never showed the box.
2. The same flow after the merchant switches from opt-out to opt-in. A mirrored opt-out tick ('1', meaning "don't email me") is recorded as opt-in `yes`. That inverts the customer's answer.

PRD §11 and spike 3 say: "Flows that skip our field record nothing."

Fix, any one of these:
- (a) Drop the `_wc_other` fallback. On 11.2, WooCommerce writes `_wc_other` from the same request value, so the fallback is redundant where the key is present. It only adds risk where the key is absent. Spike 2's value-shape worry is already handled by `truthy()` on the request value. This needs a PRD §6 "Mirror" amendment.
- (b) Use the fallback only on PATCH, or only when the order already carried `_wc_other` before this request (a reused draft order on WC below 10.8). Never use it on a POST to an order created in this request.
- (c) Make `default_value()` return the mirror only for GET and PATCH on `/wc/store/v1/checkout`. Set a flag in `rest_request_before_callbacks`.

Whichever you pick, add a harness case: mirror set, POST-shaped request with no key, the sync run, then assert that nothing is recorded.

**M2. The eraser misses orders that WooCommerce's order eraser has already anonymised. It also misses account orders billed to another address.** Locations: Consent.php:705-723 and :784-812; WooCommerce class-wc-privacy.php:441 and :71-74, abstract-wc-privacy.php:63-77, class-wc-privacy-erasers.php:126-135, :227 and :236-262.

- WooCommerce registers its erasers at priority 10 when its file is included (`new WC_Privacy()` at load time). That is before our `plugins_loaded` boot, so WooCommerce's entries come first, and core runs each eraser to completion before the next.
- With WooCommerce's "Remove personal data from orders on request" enabled, `remove_order_personal_data()` has already replaced `billing_email` (with `deleted@site.invalid`) and cleared `customer_id`. Our eraser's `billing_email` query then finds nothing.
- The answer, its time and the checkbox wording stay on the anonymised order. The erasure report says nothing was removed.
- Separately, `orders_for()` matches by billing email only. WooCommerce's eraser and exporter also match `customer => [ email, user ID ]`, so an account's orders billed to a different email are missed by both of ours.

Fix:
- Hook `woocommerce_privacy_before_remove_order_personal_data` (fires per order before anonymising, :227) and run our per-order erase there.
- And/or register our eraser before WooCommerce's (priority 5 on the filter, inserting at the front).
- Query like WooCommerce does: `'customer' => array( $email, $user_id )`.

Add a harness case with `woocommerce_erasure_request_removes_order_data = yes`.

**M3. The "never pre-ticked" guarantee is proven only on 11.2, and the planned fallback hooks fire too early.** Locations: Consent.php:339-342 and :352-401; WooCommerce Checkout.php:599 (`process_customer` → OrderController.php:103-130). This is release gate R1.

- The protection works only if every place WooCommerce writes our field onto the customer is followed by our delete.
- On POST, the DB-backed `new WC_Customer( $id )` sync in `process_customer()` (step #6) runs **after** our action (step #5). On 11.2 it skips our field because of `is_customer_field()`. If any version from 8.9 to 10.7 lacks that check, the copy lands in user meta after our delete. The PRD's fallback hooks (`update_customer_from_request` and `rest_request_before_callbacks`) also run before it.
- The next checkout's GET would then show the box ticked. Because `default_value()` only runs for a missing key, the stale value wins. The first PATCH would mirror it, and an untouched box would be recorded `yes`.
- A pre-ticked box is not valid consent under GDPR (CJEU C-673/17 Planet49).

There is no read-time defence. Fix, beyond doing spike 5:
- Make the copy impossible. Remove our key in `woocommerce_customer_allowed_session_meta_keys` at a later priority, so it never enters the session snapshot. Short-circuit `add_user_metadata` / `update_user_metadata` for `_wc_other/ndv-reviews/review-email-consent` (return a non-null value), so it never reaches user meta.
- Or, at minimum, delete stale copies on `woocommerce_store_api_checkout_order_processed` (after `process_customer`) and at the start of GET `/wc/store/v1/checkout`, without mirroring.

The session mirror, which comes from our own `on_update_draft()`, is not affected by either change.

### MINOR

**m1. The recorded wording isn't "the exact label shown". Both checkouts add "(optional)".**
- **Classic.** `woocommerce_form_field()` appends `&nbsp;<span class="optional">(optional)</span>` to every non-required field (wc-template-functions.php:3415, printed at :3537).
- **Block.** The Form component renders a non-required checkbox with `optionalLabel` (`label:(e?.required?e?.label:e?.optionalLabel)` in assets/client/blocks/wc-cart-checkout-base-frontend.js). That defaults to `'%s (optional)'` (CheckoutFields.php:229).
- So the shopper sees "Email me a link to review my purchase (optional)", while `_ndvr_review_consent_text` stores the text without it. In opt-out mode, "Don't email me a link to review my purchase (optional)" reads oddly.

Fix:
- **Block.** Pass `'optionalLabel' => $this->block_label`. A caller-supplied value survives `wp_parse_args()` and `process_options()`.
- **Classic.** Strip the `<span class="optional">` for key `ndvr_review_consent` in the `woocommerce_form_field_checkbox` filter (:3618), or record the suffix too.

Check it in a browser.

**m2. The order-screen date is the UTC date.** Location: Consent.php:428.
- `new \WC_DateTime( $at, new \DateTimeZone( 'UTC' ) )` has offset 0. `wc_format_datetime()` then prints `date_i18n( getOffsetTimestamp() )` (class-wc-datetime.php:81-105), which is the UTC calendar day.
- Example: a store in UTC−5 records a 22:00 local order as "on {the next day}".

Fix: after constructing the date, call `setTimezone( new \DateTimeZone( wc_timezone_string() ) )`, or `set_utc_offset( wc_timezone_offset() )` when `timezone_string` is empty. This mirrors `wc_string_to_datetime()` at wc-formatting-functions.php:781-788.

When `_at` is empty, the sentence ends in "on ". Fall back to the plain status, for example "Agreed at checkout".

**m3. The exporter's "Date" is a raw GMT `Y-m-d H:i:s` with no zone** (Consent.php:761). Append " UTC" or format it in the site timezone.

**m4. `default_value()` returns a bool, but WooCommerce's single-field read expects the stored form.** Location: Consent.php:378.
- `get_field_from_object()` passes the default through `CheckboxFieldType::from_storage()`, which is `'1' === $value` (CheckboxFieldType.php:58-60). A mirrored `true` therefore reads as unticked on that path.
- On 11.2 that path is used only for address and contact fields (CheckoutFieldsFrontend.php:146, :173, :199), so nothing breaks today.
- Fix: return `$draft ? '1' : '0'`. The response path casts with `(bool)`, and `'0'` gives false, so both read paths agree.

**m5. The order-screen line shows on every order, even with consent never turned on.** Location: Consent.php:423-456.
- The default "off" stores, which are most stores, get "Review emails: No answer recorded" on every order. PRD §7 promises no change for sites that never change the mode.
- Fix: return early when the mode is off and the status is `none` or `legacy`. Keep the line for `yes`, `no`, `not_objected` and `erased` in every mode, because those still gate sends.

**m6. A missing `enabled_at` while the mode is on fails closed, silently.** Locations: Consent.php:126-129 and :485-491.
- Mode changes that bypass `update_option`, as listed above, leave the option at 0. `status()` then never returns `legacy`.
- Old orders say "No answer recorded", and "Orders placed before you turned this on: Send" does nothing.
- That is safe for privacy but confusing. Fix: show a notice on the Reminders screen when the mode isn't off and the option is missing, or initialise it lazily (with the earliest `_ndvr_review_consent_at` as a better seed than "now").

**m7. `forget_customer_copy()` runs `delete_user_meta()` on every block PATCH and POST for logged-in shoppers.** Location: Consent.php:398-400.
- That is a SELECT on each call, plus a DELETE when a row exists. PATCHes fire on most field changes. PRD §10 says "no extra query".
- Fix: guard it with `'' !== get_user_meta( $id, self::WC_META, true )`. The user meta cache is primed when `WC_Customer` loads, so the check costs nothing.
- While there, give `on_update_draft()` an early return when the mode is off.

**m8. Customer-facing display on WC 8.9–10.0 is unverified.** The `woocommerce_filter_fields_for_order_confirmation` filter is "@since 10.1.0" (CheckoutFields.php:917). If `show_in_order_confirmation` is ignored before that, the thank-you page and the order emails show "Email me a link … : Yes". The admin-fields key shape on 8.9 is also unverified. Cover both in the spike pass for R1.

**m9. The label translation timing is fragile for Polylang.** Location: Consent.php:296, Multilingual.php:193-195.
- The block label is fixed at `woocommerce_init` (init:0). On Store API REST requests, `pll_current_language()` can still be empty then, so the stored (untranslated) text is registered and recorded.
- The page render can show the translation, because the language is known earlier for URL-based detection.
- Classic records the label from the wc-ajax request's language.
- This follows the PRD's design (AC16), so it isn't a code defect. Log it for RR-08 and test with Polylang and WPML before claiming multilingual consent records.

**m10. List-recipient sends skip consent** (Mailer.php:167, `! $is_list`). Pro list campaigns can email someone who declined at checkout, if their address is on an imported list. That is defensible, since there's no order and the legal basis is different, but it is undocumented. Owner decision: either document it ("consent applies to order-based requests"), or in opt-in mode check whether the email has any `no` record.

**m11. Pro Engine** (Engine.php:155-163): `link()` mints an order token before the gate refuses the send. The refusal is then written as an order note ("Rosette Reviews automation (email): Customer didn't agree …") on every scheduled step. This is harmless but wasteful. It's on the Pro side; fold it into RR-06P.

**m12. Nits:**
- `Mailer` fetches `consent` through `Plugin::instance()->container()` (Mailer.php:167) rather than having it injected.
- The `ndvr_consent_draft` key and session customer meta aren't covered by uninstall or the eraser. They expire with the WooCommerce session (48 hours). Note this in the privacy text, or ignore it.
- The `esc_html()` on the classic label is not redundant on WC 8.x. Keep it.

### Release gates (no free code change)

**R1, Build spikes 1, 2, 5 and 6 on WC 8.9, 9.x and 10.0–10.7.** These cover persistence, value shape, the customer-copy path (including the step-#6 ordering in M3) and the draft round trip.

They are still open according to LOG.md (no Playground on this machine), and the AC15 GET round trip was skipped. Either run them on a machine that has Playground, or narrow the readme and FAQ support claim for the Checkout block to the versions actually verified. This is a **blocker for the 8.9–10.7 claim**, not for the code.

**R2, Pro ESP Dispatcher.** Dispatcher.php:111-113 pushes the customer's email and review link to Klaviyo, Mailchimp, Brevo or a webhook after checking suppression only. A customer who declined at checkout is still pushed.

RR-06P's TASKS row ("make the ESP Dispatcher call free `check_eligibility()` before pushing") covers consent, because step 5 is inside `check_eligibility()`. It is still `todo`. Until it ships, readme.txt:157 ("every review email respects it") is false on Pro sites that use an ESP.

Make RR-06P a hard release dependency for RR-07 on Pro sites: ship them together, or hold the Pro update.

---

## Harness gaps (`.agents/qa/rr-07.php`, 41/41)

1. **HPOS on/off repeat missing.** Test plan §13.6 asks for AC2 with HPOS on and off. rr-07.php has no HPOS switch; rr-04 shows the child-process pattern to copy.
2. **First-ever save untested.** AC11 always works on an existing option, so `add_option_ndv_reviews_settings` → `on_settings_added()` is untested. Delete `ndv_reviews_settings` and then save once.
3. **Block cases skip the real POST pipeline.** AC3 fires the action directly, so WooCommerce's customer→order sync never runs. Add the M1 case: mirror set, `OrderController::update_order_from_cart()` or the sync, a request with no key, then assert that nothing is recorded.
4. **AC15 GET round trip and spike 6 skipped.** The draft-response half could be tested without a browser: call `CheckoutSchema::get_draft_response( WC()->cart, WC()->customer )` directly, after setting the mirror.
5. **AC12 checks only `check_eligibility()`.** Add `send_for_order( $declined_id )` with no `request_id` (the Pro Engine legacy direct path), asserting `ndvr_no_consent` and no mail.
6. **No escaping or "(optional)" checks.** Render a label containing `Tom & Jerry's <b>` and assert the output. Add a browser check for the "(optional)" text (m1).
7. **No eraser test with WooCommerce's order-data removal enabled** (M2), and no account order with a different billing email.
8. **No non-UTC timezone case** for the order-screen date (m2).
9. **No mode-off order-line case** (m5).
10. **Uninstall covered only by rr-00's dry run.** A real run on a scratch copy, asserting that HPOS and postmeta rows for `_wc_other/…` are gone, would close the loop.

---

## Required before approval

| # | Item | Blocks |
|---|---|---|
| M1 | Stop the block fallback from recording synced or mirrored values on POSTs without our key (option a, b or c), with a PRD §6 amendment if the fallback is dropped. Add harness case 3. | approval |
| M2 | Erase consent per order before WooCommerce anonymises it, and match orders by email or user ID. Add harness case 7. | approval |
| M3 | Add a read-time or write-blocking defence against a stale customer copy (session allow-list removal plus a user-meta write short-circuit, or deletes after `process_customer` and at GET). | approval |
| m1 | Make the recorded text match what is shown (block `optionalLabel`; strip or record the classic "(optional)"). | approval (cheap) |
| m2 | Show the order-screen date in the site timezone; handle an empty `_at`. | approval (cheap) |
| m4 | Return `'1'`/`'0'` from `default_value()`. | approval (cheap) |
| m5 | Hide the "No answer recorded" line when the mode is off. | approval (cheap) |
| Harness | Cases 1, 2, 3 and 5 above. | approval |
| R1 | Spikes 1, 2, 5 and 6 on WC 8.9–10.7, or narrow the claim. | release (8.9–10.7 claim) |
| R2 | RR-06P (ESP Dispatcher → `check_eligibility()`) ships with or before RR-07 for Pro sites. | release (Pro + ESP) |

Recommended but not required: m3, m6, m7, m8 (in the R1 spike pass), m9 (RR-08 log), m10 (owner decision), m11 (RR-06P), m12.

**Verdict: CHANGES REQUESTED**
