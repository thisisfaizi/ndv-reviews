# RR-05: Request exclusions (categories, products, customer roles)

Status: prd-ok (rev 4) · Plan: F (+ paired Pro task RR-06P) · Inherits PRD-00 + RR-00 + RR-09 (rev 3) · No schema change, no DB version · No Pro-facing API, so no `NDVR_API` change

Review findings applied: round 1 batch A X5 and RR-05 (all); round 2 RR-05 item 1.

`Scheduler`, `Mailer` and `Reviewable` are instance services reached through the container (`scheduler`, `mailer`, `reviewable`; RR-09 §6.2). `Class::method()` below names the method, not a static call.

## 1. Problem and who it's for
Merchants need to leave some products and customers out of review requests:
- gift cards, samples, services and digital add-ons;
- B2B, wholesale and staff accounts.

Without it, customers get asked to review a gift card, which looks careless, and wholesale buyers get consumer emails. CusRev (free), Judge.me and YITH offer this. Who it's for: any store with non-reviewable line items or trade accounts.

## 2. Scope / non-goals
In scope:
- **Excluded product categories**, including their child categories: items in them are left out of every request email and every review link page.
- **Excluded products:** the same.
- **Excluded customer roles:** an order whose customer account has any listed role gets no request from any sender that uses the RR-09 gate.
- Exclusions also apply to review links sent **before** the setting changed, because the landing page filters the products stored on the token.

Non-goals:
- Excluding by order total (Pro smart rules have a minimum value) or by country.
- Excluding from the on-page review form. These are request rules; a customer can still review on the product page.
- Matching guests to accounts by email. A guest order has no roles, so it is never role-excluded.

## 3. User experience
**Review Reminders → "Reminder settings"**, new rows under the heading "Who and what to ask about" (rendered by `Admin\RequestsPage::render()`, in the existing form table, before the "From" row at RequestsPage.php:334-335):
- "Don't ask about products in these categories": a multi-select of product categories (`<select multiple class="wc-enhanced-select">`), listing `get_terms( ['taxonomy'=>'product_cat','hide_empty'=>false] )` with indented child names.
- "Don't ask about these products": WooCommerce's product search (`<select multiple class="wc-product-search" data-action="woocommerce_json_search_products">`), the same pattern as the Tools QR picker (ToolsPage.php:256). Variations aren't offered because reviews attach to the parent product.
- "Don't send requests to customers with these roles": a checkbox list of `wp_roles()->get_names()`.
- Help text: "Excluded items are left out of the email and the review page, including links already sent. If an order has nothing left to review, no email is sent. Customers can still review any product on its product page."

The rules apply to automatic, manual (RR-04), follow-up (RR-06), Pro campaign, Pro automation and Pro ESP sends, because all of them read `Reviewable::for_order()` or the RR-09 gate.

Request log: an order queued before the exclusion and skipped at send time shows "Cancelled" with the reason "No reviewable products in this order." or "Customer role is excluded from review requests." An order that is already excluded when it qualifies gets no row.

## 4. Reuse map
- **`Collection\Reviewable::for_order()`** (Reviewable.php:24-43) is the product choke point: Mailer (Mailer.php:99), Pro Engine (Engine.php:150-151, :177), Pro SmartRules (SmartRules.php:33-34), Pro ESP Dispatcher (Dispatcher.php:116), `for_customer()` (:52-72) and RR-15 all call it. Exclusions go here.
- **`Mailer::check_eligibility()`** (RR-09 API), step 6 "customer role not excluded", returns `ndvr_customer_excluded`; step 7 "reviewable products not empty" uses `for_order()`, so it is exclusion-aware.
- **`Collection\Landing`**: `pending_products()` (Landing.php:193-205) and `token_products()` feed render (:134) and submit (:232-237).
- RR-00 F6 settings registry; `Support\Settings` defaults (Settings.php:31-63).
- WooCommerce admin scripts `wc-enhanced-select` and `woocommerce_admin_styles`, enqueued the way ToolsPage does (ToolsPage.php:84-91).

## 5. Blast radius
- **Free:** Mailer (fewer products, or `ndvr_nothing_to_review`), Scheduler (queue-time `ndvr_customer_excluded` means no row), Landing (render and submit), RR-04 notices, RR-06 follow-ups, RR-15 lists, `Reviewable::for_customer()`.
- **Pro Engine:**
  - **Today's Pro** (`run_step()`, Engine.php:130) stops when `for_order()` is empty (:151) and mints its token from `for_order()` (:177), so product exclusions apply at once. Role exclusion reaches its email because `Channel\Email::send()` calls `Mailer::send_for_order()` (Channel/Email.php:44-46), and RR-09 rev 3 makes that legacy direct path run `check_eligibility( stage=send, origin=pro )` (RR-09 §5, §6.2).
  - **After RR-06P:** `Engine::maybe_start()` (:81) queues through `queue_for_order( … ['origin'=>'pro'] )`, whose queue-time `check_eligibility()` runs steps 6 and 7, so an excluded customer or an order with nothing left to review gets no row (`ndvr_customer_excluded`, `ndvr_nothing_to_review`). `run_step()` is replaced by RR-09's `process()`.
- **Pro SmartRules / BulkCampaign:** inherit product exclusions through `for_order()`; role exclusion through `queue_for_order()` once BulkCampaign switches to it (RR-06P).
- **Pro ESP Dispatcher:** gets excluded products dropped at Dispatcher.php:116 immediately. **Role exclusion** reaches it through RR-06P, which makes the Dispatcher call `check_eligibility()` before pushing (replacing the suppression-only check at Dispatcher.php:112). Until RR-06P ships, a role-excluded customer can still be pushed to an ESP. That check is an RR-06P acceptance criterion, not one of this PRD's.
- **`Reviewable` construction:** `new Reviewable()` with no arguments (Plugin.php:259-264). The constructor stays argument-free; settings are read lazily.
- **`RequestsPage` save:** gains registry-driven keys (below).

## 6. Contract delta
- **Settings keys** (F6 registry, page `reminders`, defaults through `Settings::defaults()`):
  - `reminder_exclude_cats` (int[] term ids, default `[]`)
  - `reminder_exclude_products` (int[] product ids, default `[]`)
  - `reminder_exclude_roles` (string[] role slugs, default `[]`)
- **Saving on the Reminders screen** (the branch is built by RR-09 §6.2; this PRD only registers keys):
  - `RequestsPage::handle_actions()`'s `save` branch (RequestsPage.php:148-162) keeps its fixed keys and also sanitizes every key from `SettingsPage::fields()` whose `page` is `reminders`, and only those (RR-00 F6).
  - Each sanitize callback receives `wp_unslash( $_POST[ $key ] )`, or `null` when the key is absent (unchecked box, empty multi-select). `null` gives the empty value (`false`, `[]`).
  - The result is merged with `Settings::update()` (Settings.php:99-103), so unknown keys are kept.
- **`Reviewable` additions** (all public):
  - `is_excluded_product( int $product_id ): bool`: the product is in `reminder_exclude_products`, or any of its `product_cat` terms is an excluded term or a descendant of one.
  - `filter_excluded( int[] $product_ids ): int[]`
  - `is_excluded_customer( \WC_Order $order ): bool`: the order's customer account (`get_customer_id()` → `get_userdata()`) has any excluded role.
  - `for_order()` drops excluded products, then applies the filter below.
- **Filters:**
  - `ndv-reviews/reviewable_order_products` (int[] `$product_ids`, WC_Order `$order`): the final list after exclusions.
  - `ndv-reviews/request_excluded_product` (bool `$excluded`, int `$product_id`).
  - `ndv-reviews/request_excluded_customer` (bool `$excluded`, WC_Order `$order`).
- **Eligibility:** `check_eligibility()` step 6 returns `WP_Error( 'ndvr_customer_excluded', __( 'Customer role is excluded from review requests.', 'rosette-reviews' ) )` when `is_excluded_customer()` is true. The code is already in RR-09's `SKIP_CODES`, so the row is `cancelled`, not `failed`.
- **Landing:** `pending_products()` and `token_products()` return `Reviewable::filter_excluded()` of the stored list. An excluded product therefore disappears from the page, and a submit for it gets the existing "This product is not part of your review link." error (Landing.php:232-234). If nothing is left, the page shows the existing "All done" state (magic-landing.php:35-40).
- **Cache:** a per-request static holds the three settings and the expanded excluded term set (`get_term_children()` per excluded term). It's reset on `update_option_ndv_reviews_settings`.
- **CONTRACTS.md:** the keys, the methods, the three filters, and "Landing filters stored token products by exclusions".

## 7. Storage and upgrade
Three keys inside `ndv_reviews_settings`. No table, column, meta or DB version. An old site has no exclusions until the merchant sets them; output is unchanged.

Category matching uses the parent product's `product_cat` terms. `for_order()` already uses `$item->get_product_id()`, which is the parent for variations (Reviewable.php:36).

## 8. Security
- **Settings save:** the Reminders nonce `ndvr_requests` then `Caps::manage( 'reminders' )` (RequestsPage.php:143-146), then:
  - categories: `array_map( 'absint' )`, intersected with existing `product_cat` term ids;
  - products: `array_map( 'absint' )`, kept only when `get_post_type()` is `product`;
  - roles: `sanitize_key`, intersected with `array_keys( wp_roles()->get_names() )`.
- **Product search:** WooCommerce's own AJAX `woocommerce_json_search_products`, which checks the `search-products` nonce (WC 11.2.0 includes/class-wc-ajax.php:2082). No new endpoint.
- **Output:** term names, product titles and role names through `esc_html`; ids through `esc_attr`.

## 9. Privacy
No personal data. Role names and product ids are store configuration.

## 10. Performance and assets
- **Per order:** `update_object_term_cache( $product_ids, 'product' )` once, then `get_the_terms()` per item (cached; not `wp_get_post_terms()`, which skips the cache).
- **Excluded term expansion:** once per request.
- **Role check:** one `get_userdata()` (cached).
- **Assets:** `wc-enhanced-select` and `woocommerce_admin_styles` enqueued only when the admin hook suffix contains `ndv-reviews-reminders` (the same check ToolsPage uses, ToolsPage.php:85); both are registered by WooCommerce admin.

## 11. Compatibility
- **HPOS:** only `WC_Order` getters are used. Legacy storage the same.
- **Variations and grouped products:** variations follow their parent; a grouped product's children are separate line items and are checked one by one.
- **Multiple roles:** any match excludes.
- **WC 8.0, WP 6.0, PHP 7.4:** all functions used exist there.
- **Pro absent:** free sends honour all rules. **Pro present:** product exclusions apply at once; role exclusion for ESP pushes follows RR-06P.

## 12. Acceptance criteria
All in Playground through PHP, mail captured with `pre_wp_mail`.
1. With the "Gift cards" category excluded, an order with a gift card and a mug: `Reviewable::for_order()` returns only the mug's id, and `Scheduler::process()` sends an email whose body contains the mug's name and not the gift card's.
2. A token minted **before** the exclusion (covering both products): the landing pending list (`Landing::pending_products()` called through `ReflectionMethod`) returns only the mug, and a submit for the gift card returns the "not part of your review link" error.
3. (a) An order with only excluded products, completed after the exclusion is saved: `queue_for_order()` returns `ndvr_nothing_to_review` and no row exists. (b) An order queued **before** its only products were excluded: `Scheduler::process()` ends its row `cancelled` with "No reviewable products in this order." and no email goes out.
4. A child category of an excluded parent is excluded.
5. With the `wholesale` role excluded, completing a wholesale customer's order creates no row (`queue_for_order()` returns `ndvr_customer_excluded`), and an RR-04 manual send reports "customer role excluded".
6. A guest order with the same products is not role-excluded.
7. `check_eligibility( $wholesale_order, ['stage'=>'send','origin'=>'pro'] )` on the `mailer` service returns `ndvr_customer_excluded`, and the legacy direct `send_for_order( $wholesale_order_id )` returns the same code without sending. (The ESP Dispatcher half of this check is RR-06P's acceptance criterion.)
8. For an order containing only excluded products, `queue_for_order( $id, ['source'=>'auto','origin'=>'pro'] )` returns `ndvr_nothing_to_review` and creates no row. With Pro Automation (RR-06P) active, completing that order through `Engine::maybe_start()` creates no `ndvr_requests` row for it (row count unchanged).
9. Saving bogus values (`[0, -3, 'abc']`, a deleted term id, the role `not_a_role`) stores only valid ids and roles; submitting the form with nothing selected stores `[]` for all three keys.
10. Changing the exclusions and calling `for_order()` again in the same request gives the new result (cache reset).
11. Core flows pass.

## 13. Test plan
1. Create a parent and a child product category, a gift card, a mug and a product in the child category.
2. Create a user with a custom role `wholesale`, and a guest order.
3. Create orders, set exclusions through the Reminders save handler (built `$_POST` with a valid nonce) and through `Settings::update()`.
4. Run requests with `Scheduler::process()`; inspect captured mail and `Reviewable` output.
5. If Pro with RR-06P is available in the Playground, repeat the second half of AC8 with Pro active.
6. Run core flows; record results in `LOG.md`.

## 14. Open questions
None.
