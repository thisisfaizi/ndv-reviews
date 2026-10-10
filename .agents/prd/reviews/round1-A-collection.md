# PRD review round 1, batch A: collection (RR-03..RR-09, RR-06P, RR-10)
Reviewer: independent adversarial subagent, 2026-10-10. Verdict: every PRD in this batch needs CHANGES REQUIRED.
Response: RR-09 was rewritten as `RR-09-request-pipeline-v2.md`, and RR-00 foundations was added. The other PRDs are revised in place.

## Cross-PRD findings
- **X1 BLOCKER: the DB version plan can strand columns.** `maybe_upgrade` runs only when the version mismatches (Installer.php:26-30). A site already on v3 never receives columns that a later "fold into v3" adds, and inserts then fail silently (RequestRepository.php:29-44 returns 0). Fix: a single ledger, one bump per schema PRD, and a shipped version is never amended.
- **X2 BLOCKER: double sends between free and Pro.**
  - Pro BulkCampaign inserts rows without a source (BulkCampaign.php:125-131), so they would default to `auto`, and RR-06 would then add a free follow-up to every past-order campaign.
  - RR-04 manual sends skip `should_send_reminder`. With Pro automation on, that produces a free step 1, a free follow-up and Pro's email.
  - Under RR-06P, Pro's step 1 is `source=auto,step=1`, which triggers the free follow-up.
  - RR-06 rule 1 ("followup_enabled off → cancel") also cancels Pro's step 2 and 3 rows, and the setting is off by default.
  - Fix: Pro BulkCampaign uses `source=campaign`. Follow-ups are scheduled only from `auto`/`manual` rows that aren't Pro-origin (`queue_for_order(... followup=false)`). RR-06 rule 1 is scoped to rows free created. Add the filter `ndv-reviews/order_already_requested` so Pro can report `SENT_META`/`PUSHED_META`.
- **X3 MAJOR: race conditions.** `process()` is check-then-send with no claim (Scheduler.php:134-151), so a concurrent Action Scheduler run plus Retry, or a duplicate status event, can send twice. `on_order_status` is check-then-insert (:95-114). Fix: an atomic `UPDATE … SET status='sending' WHERE id=%d AND status IN('scheduled','failed')` and proceed only if one row changed. Check the cooldown at send time for every source. A manual send cancels pending scheduled auto and follow-up rows for the order.
- **X4 MAJOR: the cooldown misses converted rows.** `mark_reviewed()` changes `sent` to `converted`, so a cooldown that checks `status='sent'` loses it. Key the cooldown on `sent_at`, whatever the status.
- **X5 MAJOR: eligibility checks are scattered.** Consent, role exclusion, suppression and status are checked in different places. Pro `Esp\Dispatcher` checks only suppression (~:113) before sending personal data and a link to Klaviyo, Mailchimp, Brevo and webhooks. Fix: one public `Mailer::check_eligibility( WC_Order ): true|WP_Error` plus a filter, used by Mailer, Scheduler, the RR-04 pre-checks and Pro ESP. Add `Esp\Dispatcher` to the RR-05/07 blast radius.
- **X6 MAJOR: free API is specified only in Pro PRDs.** `queue_for_order`, `request_email_texts`, the `list` token type, `create_list_token`, `send_to_list_recipient`, order_id=0 handling and two columns appear only in RR-06P and RR-10. That breaks AGENTS §3.9. Move them into RR-09 and CONTRACTS.
- **X7 MINOR: the skip codes are incomplete.** Add `ndvr_no_consent`, `ndvr_customer_excluded`, follow-up-disabled and should_send_followup to Scheduler.php:145, and name each one.

## Per-PRD findings
- **RR-03**
  - BLOCKER: free never auto-approves. Landing.php:298, ReviewForm.php:429 and TestimonialForm.php:283 all create with `approved=>0`, and `should_approve` defaults false (ReviewRepository.php:131). `comment_moderation` is irrelevant because `wp_insert_comment` is used, so picking the sentence from it would publish a false statement. Free always prints the manual-approval sentence. Make `transparency_sentences` keyed so Pro replaces the sentence rather than appending.
  - BLOCKER: "imported reviews are labelled" is false. No template renders a source label, and `_ndvr_source=import` also covers the WooCommerce native backfill. Build a label or drop the sentence.
  - MAJOR: the verified-buyer sentence ("we check order records") is false for CSV imports, whose verified status comes from the spreadsheet (Csv.php:162-165).
  - MAJOR: defaulting this on for existing installs makes legal claims without the merchant reviewing them. Default off on upgrade and show an admin notice.
  - MAJOR: `after_summary` fires only in `Renderer::render_reviews_tab` (Renderer.php:256), not for `[ndvr-summary]`, the summary block or Elementor. Add it to `Widgets::summary()`.
  - MAJOR: the reminder sentence hardcodes "completed", but the trigger is `reminder_status` (Settings.php:53). With Pro automation or ESP on, the sentence is omitted even though emails are sent. Pro must supply it, and free must use the real status label.
  - MINOR: choose `<details>` and drop the JS-button variant; `[ndvr-transparency]` must enqueue `ndvr-display` in its render path; every importer, Pro's included, must clear `ndvr_has_imported`; "average of all published reviews" is wrong under pooling.
- **RR-04**
  - MAJOR: X2, X3 and X4.
  - MAJOR: `queue_for_order` is never defined here (X6).
  - MINOR: state that `exists_for_order($id)` with no argument keeps "any email row" (BulkCampaign.php:116,178 relies on it); hide the dropdown item without `Caps::manage()`; AC5 needs back-dated `sent_at`.
- **RR-05**
  - MAJOR: Landing doesn't use `for_order`. It renders the stored token products (Landing.php:81, 193-205), so exclusions apply only to tokens minted afterwards. `Esp\Dispatcher` (:116) is missing from the blast radius, and role exclusion never reaches it.
  - MINOR: `new Reviewable()` takes no arguments (Plugin.php:262), so keep the constructor argument-free; use `get_the_terms` (cached), not `wp_get_post_terms`.
- **RR-06**
  - BLOCKER: X2.
  - MAJOR: X3, because the follow-up idempotency is check-then-act.
  - MINOR: the delay range is 1–60 but the tests use 0; theme overrides without `$is_followup`; consent is missing from the send rules; conversion counts follow-ups, which deflates it, so count per order.
- **RR-07**
  - MAJOR: the Pro Engine already calls `Mailer::send_for_order` (Channel/Email.php:45) and BulkCampaign uses `Scheduler::process`. The real gap is `Esp\Dispatcher`.
  - MAJOR: orders created in admin, through express-pay buttons or through REST lack the meta and would count as "legacy". Store `consent_enabled_at` and compare the order date.
  - MAJOR: the eraser deleting the meta turns a "no" into "legacy", which sends when `consent_legacy_orders=send`. Write 'erased' instead. Cover uninstall for HPOS and post meta. Add a readme note. Store the label text shown.
  - MAJOR: `CheckoutFields::get_field_from_object` is an instance method. Use `Package::container()->get(CheckoutFields::class)`, falling back to the `_wc_other/<id>` meta.
  - Verify: the location name (`order` vs `additional`) and the group value for 8.9 through 9.x; whether the classic checkbox survives the `update_order_review` refresh (checkout.js keeps only #terms and payment inputs, so maybe use `woocommerce_after_order_notes`); the label registration timing for RR-08.
- **RR-08**
  - MAJOR: `switch_to_locale()` rejects locales missing from `get_available_languages()`, so a fixture with only the plugin `.mo` can't pass AC2; install the core fr_FR pack. AC1's byte-identical compare fails because of the random token and unsubscribe key (Mailer.php:104, :243), so normalise them.
  - MAJOR: WPML registration "on settings save" never registers existing strings. Register on `update_option_ndv_reviews_settings` plus once at upgrade.
  - Verify: WPML's `locale` filter overriding `switch_to_locale` in Action Scheduler context (WCML also calls `wpml_switch_language`); Polylang for WooCommerce (paid) on HPOS is untestable, so limit AC5 to free Polylang on legacy storage.
- **RR-09**
  - BLOCKER: X1.
  - MAJOR: "Pro calls `send_for_order($id)` nowhere" is false (Channel/Email.php:45). Keep the `true|WP_Error` return, pass `request_id` in `$args`, and let Mailer store `token_id` via a new `set_token()`.
  - MAJOR: running the upgrade on `init` with no lock lets concurrent requests run the ALTERs at once. Add an `add_option` lock with expiry.
  - MINOR: the pixel is a public DB write, so it needs an explicit waiver of PRD-00 §2.2; an invalid HMAC must do no DB work; hook it at `parse_request`. Link scanners inflate the count, so label it "Link opened". The eraser keeps `customer_id`/`order_id` next to `opened_at`, which still links the data to the person.
- **RR-06P**
  - BLOCKER: X2.
  - MAJOR: idempotency must key on (`order_id`, `step`, origin). Plan for in-flight `ndvr_pro_automation_step` jobs at update, and for pending step 2/3 rows after Pro is removed, because free would send them with default text. Remove `Engine::link()`, which mints an unused token per step (Engine.php:157, :173-181).
  - MINOR: say where `variant` comes from at send time.
- **RR-10**
  - BLOCKER: list-token reviews can't save. `Landing::token_email()` resolves only an order or customer email (Landing.php:379-394) and returns '' for a list token, so `create()` fails with `ndvr_missing_email` (:98-110). Fix: resolve the email from the request row via `find_by_token()`, and skip the forced verified override (Landing.php:311-313) for list tokens.
  - BLOCKER: X1 and X6; `TokenRepository::create()` allows only customer, test and order (:222).
  - MINOR: the email footer reads "because you placed an order" (email-request.php:113), so list recipients need different copy; don't store the name twice, read it from the request; the upload transient holds up to 5k emails, so give it a short expiry and delete it after queueing.
