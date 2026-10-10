# RR-08: Review emails in the customer's language (WPML and Polylang)

Status: done (rev 4) · Plan: F · Inherits PRD-00 + RR-00 + RR-09 (rev 3) · No schema change, no DB version · No Pro-facing API, so no `NDVR_API` change

Review findings applied: round 1 batch A RR-08 (all, including the "verify" items, resolved below or as Build spikes); round 2 RR-08 item 1.

Rev 4 (code review `reviews/code-RR-08.md`, 0 blockers, 4 majors, 9 minors):
- **M1:** `handle_submit()` switches back to the site language as `ndv-reviews/review_created` fires (a listener at `PHP_INT_MIN`). The store's "new review" email, Pro alerts and AI hooks run in the site language. Validation messages and the thank-you message stay in the customer's.
- **M2:** on WordPress below 6.2, `change_locale` reloads the plugin's text domain for the new locale (`Multilingual::reload_textdomain()`, from `WP_LANG_DIR/plugins/` or the plugin's `languages/`). Before 6.1 a switch unloads plugin domains for the rest of the request, and before 6.2 a reload in admin or AJAX picks the admin's language. The WP 6.0 repeat itself is still open (no Playground).
- **M3:** a send with no detected order language uses the site's **default** language (`wpml_default_language` / `pll_default_language()`, both code and locale), never the current request's: `Multilingual::send_language()` / `default_language()`.
- **M4:** language-aware review links are **off by default** (§3 "links to the store's home in the order language" and §6 step 4 amended). Links use `home_url( '/' )`, the PRD's own fallback for Build spike 5. The review page still follows the order's language. Filter `ndv-reviews/language_review_links` ( false, $code, $plugin ) turns them on once a site has checked its redirects.
- **m1:** texts are looked up trimmed, as registered; an untranslated text comes back exactly as stored.
- **m2:** WPML registration needs WPML String Translation (`WPML_ST_VERSION`). Its version is part of the hash, so installing String Translation later registers the texts.
- **m3:** the whole review page, `<html lang>` and `<title>` included, renders inside the switch, with WPML's language switched too. A used link's thank-you page also gets the order's language.
- **m4:** every switch in `with_locale()` happens inside the `try`.
- **m5:** a translated subject goes through `sanitize_text_field()`.
- **m6:** list-recipient emails are built in the site's default language.
- **m7:** a Pro TASKS note. Pro's `Engine::step_texts` (priority 20) replaces the translated follow-up with Pro's own step texts, which aren't registered for translation.
- **m8/m9:** accepted as documented.
- Build spikes 1–5 with real WPML, WCML and Polylang, and the WP 6.0 repeat, remain open. The harness now stubs both plugins.

`Mailer`, `Scheduler` and `Multilingual` are instance services reached through the container (`mailer`, `scheduler`, `multilingual`; RR-09 §6.2). `Class::method()` below names the method, not a static call.

## 1. Problem and who it's for
Multilingual stores, common in our EU audience, send every review request in the site's default language, because the email is built in an Action Scheduler context with no visitor language. A French customer who gets an English request is less likely to review. CusRev (free), Yotpo and Judge.me send per-language emails. Who it's for: stores running WPML (with WooCommerce Multilingual) or Polylang (with Polylang for WooCommerce).

## 2. Scope / non-goals
In scope:
- Detect the order's language (WPML/WCML; Polylang for WooCommerce), with a filter for anything else.
- Build the email inside `switch_to_locale()`, so built-in strings use that language. Translations come from WordPress.org language packs.
- Make the merchant's custom texts translatable through each plugin's string translation: reminder subject and text, follow-up subject and text (RR-06), consent checkbox texts (RR-07), transparency extra text (RR-03).
- Open the review page in the order's language.

Non-goals:
- Bundling translations (WordPress.org forbids it).
- TranslatePress (no stable string API for this; deferred).
- Translating review content (Pro AI does that).
- Product names: the email shows the name of the product on the order line. On WPML and Polylang stores that is normally the translated product the customer bought.
- List recipients (Pro RR-10, no order): sent in the site language.

## 3. User experience
- No new settings. Nothing visible when neither plugin is active.
- With WPML active, a note on Review Reminders under the settings card: "WPML detected. Review emails go out in the language of the order. Translate your own subject and text in WPML → String Translation, domain 'ndv-reviews'."
- With Polylang active: "Polylang detected. Review emails go out in the language of the order. Translate your own subject and text in Languages → Translations, group 'Rosette Reviews'."
- The email's review button links to the store's home page (in the order language only when `ndv-reviews/language_review_links` is on, rev 4), and the review page shows its labels in the order language.

## 4. Reuse map
- `Requests\Mailer`:
  - `send_for_order()` (Mailer.php:70-113), `subject()` (:261-271), `body()` (:284-328), `build_link()` (:232-234);
  - RR-09's `send_for_order( $order_id, ['request_id','variant'] )` and `check_eligibility()`, which stay outside the language switch.
- `Collection\Landing::maybe_render()` (Landing.php:124-165) and `handle_submit()` (:212).
- WordPress `switch_to_locale()` / `restore_previous_locale()` (WP_Locale_Switcher). `switch_to_locale()` returns false when the locale isn't installed or is already current (verified on WP 7.1.3, class-wp-locale-switcher.php:75-83).
- Pro's existing multilingual code uses the same context and filters: `Multilingual\Wpml::CONTEXT = 'ndv-reviews'` with names `criterion: {name}` (Pro Wpml.php:20, :62), and `wpml_active_languages` / `pll_languages_list` (Pro Translate.php:124-131).

## 5. Blast radius
- **Mailer:** the subject and body are built inside the switch; the custom texts go through `ndv-reviews/translate_setting`; the link becomes language-aware. The return value and arguments of `send_for_order()` are unchanged, so Pro `Channel\Email` (Channel/Email.php:45) works as before and gets translated emails for free.
- **Retry from the admin:** `Scheduler::retry()` runs `process()` synchronously in an admin request (RequestsPage.php:121-127). There `determine_locale()` returns the admin user's locale. So when no order language is found, the Mailer switches to the **site** locale explicitly instead of doing nothing. The same applies to Action Scheduler runs on `admin-ajax.php`.
- **Landing:** switches to the order's locale before rendering and in `handle_submit()`, so labels and messages match the email even if the link carried no language.
- **RR-03, RR-06, RR-07:** each reads its merchant text through `apply_filters( 'ndv-reviews/translate_setting', $value, $key, $lang )`, which this PRD hooks. If RR-08 isn't active the filter returns the value unchanged.
- **Pro:**
  - Wpml module: same context, no name collision.
  - ESP Dispatcher builds its own links (Dispatcher.php:131-138), so they aren't language-aware. The landing switch still shows the right language. Language-aware ESP links are a Pro TASKS note, not required.
  - RR-06P per-step texts arrive through `ndv-reviews/request_email_texts`, and Pro translates its own.

## 6. Contract delta
- **New service** `Integrations\Multilingual` (Registerable), container id `multilingual`, in `Plugin::boot()`'s service list. Every call is guarded with `defined()`/`function_exists()`; with neither plugin active, `register()` adds nothing.
  - `active(): string` returns `'wpml'`, `'polylang'` or `''`.
  - `order_language( \WC_Order $order ): array` returns `{code, locale}`, both '' when unknown.
  - `with_locale( string $locale, callable $build )`: switches, runs `$build`, and restores in `finally`. It restores only if `switch_to_locale()` returned true.
  - `translate( string $value, string $key, string $lang = '' ): string`. `''` = current language.
  - `home_url( string $code ): string`
- **Filters:**
  - `ndv-reviews/order_language` (array `{code, locale}`, WC_Order). Applied last; lets any plugin or merchant force it.
  - `ndv-reviews/translate_setting` (string `$value`, string `$key`, string `$lang`). Multilingual hooks it at 10.
- **String registration** (context or group, then names):
  - WPML context `ndv-reviews`; Polylang group `Rosette Reviews`.
  - Names: `reminder_subject`, `reminder_body`, `followup_subject`, `followup_body`, `consent_label_optin`, `consent_label_optout`, `transparency_extra`. Only non-empty values are registered. Bodies register as multiline.
- **Option** `ndv_reviews_ml_strings_hash` (string, autoload no): md5 of the registered values, so WPML registration runs once per change.
- **Mailer change:** `build_link( $token, string $lang = '' )`. The new argument is optional, so existing callers are unchanged.
- **Uninstall registry** (RR-00 F2): option `ndv_reviews_ml_strings_hash`.
- **CONTRACTS.md:** the service, both filters, the string names, and the Mailer argument.

**Detection order** (`order_language()`):
1. **WPML** (`defined( 'ICL_SITEPRESS_VERSION' )`):
   - `code = $order->get_meta( 'wpml_language' )` (WooCommerce Multilingual stores it; Build spike 1);
   - `locale = apply_filters( 'wpml_active_languages', null, ['skip_missing'=>0] )[ $code ]['default_locale']`.
2. **Polylang** (`function_exists( 'pll_get_post_language' )`): Polylang for WooCommerce's order language (Build spike 2); on legacy storage `pll_get_post_language( $order_id, 'slug' )` and `'locale'`.
3. Otherwise `{'', ''}`. Then the `ndv-reviews/order_language` filter.

**Sending** (inside `send_for_order()`, after eligibility and token creation):
1. `$lang = order_language( $order )`, and `$target = $lang['locale']` or, when empty, the site locale captured with `get_locale()` before any switch.
2. `with_locale( $target, … )` builds the subject and body.
3. The custom texts pass through `translate_setting( $value, $key, $lang['code'] )` **before** `replace_tokens()` and `wp_kses_post()`, so a translation gets the same sanitizing as the original.
4. The link is `build_link( $token, $lang['code'] )`. By default (rev 4, M4) that is `home_url( '/' )`. Only when `ndv-reviews/language_review_links` returns true: with WPML, `apply_filters( 'wpml_permalink', home_url( '/' ), $code )`; with Polylang, `pll_home_url( $code )`. Then `ndvr_k` is added.
5. `wp_mail()` runs after the switch is restored. The locale is never left switched, even when building throws.

**Landing:** after `resolve()`, when the token row has an `order_id`, `with_locale( order locale )` wraps the `View::render( 'magic-landing.php' )` call (Landing.php:148) and the JSON messages in `handle_submit()`.

**Registration:**
- **WPML:** `do_action( 'wpml_register_single_string', 'ndv-reviews', $name, $value )` on `update_option_ndv_reviews_settings` for changed keys, and on `admin_init` whenever WPML is active and the stored hash differs. That covers strings saved before WPML was installed and strings that existed when this feature shipped, with no per-request cost.
- **Polylang:** `pll_register_string( $name, $value, 'Rosette Reviews', $multiline )` on `admin_init`, because Polylang lists only strings registered during the current admin request.

**Translation:**
- WPML: `apply_filters( 'wpml_translate_single_string', $value, 'ndv-reviews', $name, $lang )`.
- Polylang: `pll_translate_string( $value, $lang )`.
- For `$lang = ''`: `apply_filters( 'wpml_current_language', null )` or `pll_current_language()`.

## 7. Storage and upgrade
One small option. No table, column, order meta or DB version: the order language is read from the multilingual plugin, never copied. An old site with neither plugin sees no change. A site that installs WPML later gets its existing texts registered on the next admin page load.

## 8. Security
- No new entry point, nonce or capability.
- Stored texts are sanitized at save as today (RequestsPage.php:155-156 and the RR-06/07 sanitizers).
- Translated values are sanitized at use: subjects keep the plain-text handling (Mailer.php:266), and bodies pass through `wp_kses_post` after translation.
- Output escaping is unchanged.

## 9. Privacy
No new personal data. The order language is already stored by the multilingual plugin; we only read it. Readme: no change.

## 10. Performance and assets
- Per send: one order meta read, one language-list lookup (cached by the plugin), one locale switch (reloads text domains for this request).
- Admin: one option read and an md5 of up to seven strings on `admin_init` when WPML is active; Polylang registration of up to seven strings.
- No assets.

## 11. Compatibility
- **Neither plugin active:** identical email output (acceptance criterion 1), except that a retry run from the admin now uses the site locale, which is the documented fix.
- **WP 6.0:** `switch_to_locale()` (4.7) and just-in-time plugin translations (4.6) exist. Test plan step 6 repeats AC2 on WP 6.0.
- **HPOS:** `$order->get_meta()` only; the Polylang for WooCommerce HPOS path is Build spike 2.
- **PHP 7.4:** `try`/`finally` is fine.
- **Pro absent or present:** as in section 5.

### Build spikes (no WPML, WCML or Polylang source is on disk; verify at build start)
1. **WPML / WCML.**
   - Verify the order meta key `wpml_language` on HPOS and legacy storage.
   - Verify that `wpml_translate_single_string` honours its fourth `$language_code` argument in an Action Scheduler (cron) request.
   - Verify that WPML's own `locale` handling doesn't override `switch_to_locale()` there. WCML itself switches with `wpml_switch_language` around WooCommerce emails.
   - **Fallback:** inside `with_locale()`, also `do_action( 'wpml_switch_language', $code )`, and switch back to the previous `wpml_current_language` in `finally`.
2. **Polylang for WooCommerce on HPOS.**
   - Find the public way to read an order's language (it doesn't use the post taxonomy on HPOS).
   - **Fallback:** free Polylang assigns no language to orders. Without a verified API, detection returns `''` and the site language is used; developers can supply it through `ndv-reviews/order_language`. That is documented in the readme FAQ.
3. **Polylang registration timing.** Confirm that registering on `admin_init` makes the strings appear under Languages → Translations. **Fallback:** register on `pll_init` in admin requests.
4. **Block checkout label language (RR-07).** The field label is fixed when it's registered on `woocommerce_init`. Check whether WPML and Polylang already know the visitor's language at that point (it is `init` priority 0). **Fallback:** on `wp`, if the translated label differs, `deregister_checkout_field( $id )` (WC 11.2.0 CheckoutFields.php:380) and register it again with the translated label.
5. **Language URLs.** Confirm that `/fr/?ndvr_k=…` (Polylang directory mode, WPML directory and parameter modes) reaches `Landing::maybe_render()` on `template_redirect` without a redirect dropping the query. **Fallback:** keep `home_url( '/' )`. The landing switch from the token's order still renders the right language.

## 12. Acceptance criteria
All in Playground through PHP. **Fixture:** `wp-content/languages/fr_FR.mo` (an empty MO file written with WordPress's `MO::export_to_file()`, so `get_available_languages()` lists fr_FR), and `wp-content/languages/plugins/rosette-reviews-fr_FR.mo` translating "How was your order from %s?" to "Comment s'est passée votre commande chez %s ?". `switch_to_locale()` rejects locales that aren't installed, so the plugin `.mo` alone isn't enough.

**Fixture timing:** `WP_Locale_Switcher` reads `get_available_languages()` once, in its constructor during bootstrap, and `switch_to_locale()` checks only that cached list (WP 7.1.3 class-wp-locale-switcher.php:48-50, :81). Fixture files created or removed inside the scenario's own request are therefore invisible to it. Each scenario runs as two `wp eval-file` calls: a **setup run** that writes or deletes the `.mo` files (and the de_DE core file for AC5), then a separate **scenario run** that does the send and the assertions.
1. **No multilingual plugin:** for the same order, the email HTML with the `multilingual` service removed through `ndv-reviews/services` equals the HTML with it registered, after replacing the random token (`ndvr_k=[^"&]+`, minted per send at Mailer.php:104) with a placeholder. The unsubscribe key is an HMAC of the email (Mailer.php:243-250, :506-508), so it is stable without normalising.
2. **WPML stub:**
   - define `ICL_SITEPRESS_VERSION`;
   - stub `wpml_active_languages` returning `fr => ['code'=>'fr','default_locale'=>'fr_FR']`;
   - give the order meta `wpml_language = fr`.
   The default subject is "Comment s'est passée votre commande chez {store} ?".
3. A stub `wpml_translate_single_string` filter receives `('ndv-reviews', 'reminder_subject', 'fr')` and its return value is the sent subject. A translated body containing `<script>` is sent without it.
4. `get_locale()` after the send equals the value before it. With a `pre_wp_mail` callback that throws, the test catches the exception and the locale is still restored.
5. **Admin retry, no order language:** as a user with locale `de_DE` (core de_DE fixture file written in the setup run) in an admin context (`set_current_screen( 'dashboard' )`), with the request row set to `failed` (`retry()` accepts only failed rows, Scheduler.php:160-163), the `scheduler` service's `retry()` sends the subject in the site language, not German.
6. With fr_FR not installed (fixture files removed in a setup run before this scenario's run), a French order still sends, in the site language, with no error.
7. The `ndv-reviews/order_language` filter returning `{code:'fr', locale:'fr_FR'}` produces the French subject with no multilingual plugin.
8. **WPML registration** (stub counting `wpml_register_single_string`):
   - the first `admin_init` with WPML active registers each non-empty text once;
   - a second `admin_init` registers nothing;
   - saving a changed `reminder_subject` registers that one name.
9. **Real Polylang** (installed from WordPress.org in Playground, languages en and fr):
   - after `admin_init`, `reminder_subject` is in Polylang's registered strings;
   - with a French string translation saved and the order language supplied through `ndv-reviews/order_language`, the email subject is the translation;
   - the review link starts with `pll_home_url( 'fr' )`.
10. **Landing:** a test filter on `ndv-reviews/template_path` records `get_locale()` when `magic-landing.php` is located, then throws to stop before `exit`. For a French order's token, the recorded locale is `fr_FR`.
11. `apply_filters( 'ndv-reviews/translate_setting', 'X', 'transparency_extra', '' )` with a WPML stub returns the stub's translation for the current language.
12. Core flows pass with no multilingual plugin.

## 13. Test plan
1. Playground with WooCommerce, the plugin, reminders on, an order with one product.
2. Write the fixture `.mo` files with `MO` (wp-includes/pomo/mo.php) in a setup script, run with its own `wp eval-file` **before** each scenario's run (see "Fixture timing"). AC6 has its own setup run that deletes them, and the next scenario's setup run writes them again.
3. WPML: stub constants and filters in a must-use plugin loaded only for the test.
4. Polylang: Blueprint `installPlugin` step for `polylang` from WordPress.org; create languages with `PLL()->model->add_language()`.
5. Run sends with `process()` and `retry()` on `Plugin::instance()->container()->get( 'scheduler' )`; capture mail with `pre_wp_mail`.
6. Repeat AC2 and AC4 on WordPress 6.0 (Playground `wp` version option).
7. Run core flows; record results and the Build spike outcomes in `LOG.md`.

## 14. Open questions
None.
