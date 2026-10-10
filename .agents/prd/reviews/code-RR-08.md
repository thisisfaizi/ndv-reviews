# Code review: RR-08 multilingual emails (free plugin)

Reviewer: i18n (WPML / WCML / Polylang). Date: 2026-10-11. Scope: `includes/Integrations/Multilingual.php` (new), `includes/Requests/Mailer.php`, `includes/Collection/Landing.php`, `includes/Admin/RequestsPage.php`, `includes/Plugin.php`, `includes/Uninstall.php`, plus the `translate_setting` callers `includes/Display/Transparency.php` and `includes/Requests/Consent.php`, the Pro modules `rosette-reviews-pro/includes/Multilingual/*` and `Automation/Engine.php`, and the harness `.agents/qa/rr-08.php`.

Method: I read the code against PRD RR-08 rev 3, CONTRACTS "Multilingual emails (RR-08)" and the LOG entry. There is no WPML, WCML or Polylang source on this machine, so third-party behaviour is judged from their documented public APIs. Core behaviour was checked against WP 7.1.3 at `D:/.devcache/qa-site/wordpress`. `php -l` is clean on all seven files (PHP 8.3 CLI). I made no code edits.

**Verdict: CHANGES REQUESTED.** 0 blockers, 4 majors, 9 minors, plus harness gaps.

---

## What is right

- **`with_locale()`** (Multilingual.php:154-171):
  - It restores only when `switch_to_locale()` returned true.
  - It restores in `finally`, in LIFO order (WPML language first, then the WP locale).
  - `return` inside `try` with `finally` is fine on PHP 7.4.
  - The `$this`-bound closure in Mailer.php:286 is fine on 7.4.
- **Mailer** (Mailer.php:274-302):
  - Eligibility and token creation stay outside the switch.
  - `wp_mail()` runs after the restore.
  - `lang_code` is reset in `finally`.
  - `$target` falls back to `get_locale()`, so an admin retry with no order language no longer uses the admin's user locale (AC5).
- **Translation happens before sanitising:**
  - Body: `translated()` → `replace_tokens()` → `wp_kses_post()` → `wpautop()` (Mailer.php:695-699).
  - Transparency: through `wp_kses( EXTRA_KSES )` (Transparency.php:293-299).
  - Consent: the classic label goes through `esc_html` (Consent.php:254).
- **API usage matches the documented signatures:**
  - `wpml_translate_single_string( $v, $context, $name, $lang )`;
  - `wpml_register_single_string( $context, $name, $value )`;
  - `wpml_active_languages( null, ['skip_missing'=>0] )[code]['default_locale']`;
  - `wpml_permalink( $url, $code )`;
  - `wpml_switch_language` / `wpml_current_language`;
  - `pll_register_string( $name, $string, $group, $multiline )`;
  - `pll_translate_string( $string, $lang_slug )`;
  - `pll_get_post_language( $id, 'slug'|'locale' )`;
  - `pll_home_url( $slug )`.
- **WPML language restore with a null previous language:** `SitePress::switch_lang( null )` returns to the request's original language, not the default language. That is the right semantics for a restore. A previous language of `'all'` (admin "All languages") is accepted by `switch_lang`.
- **Link building:** `add_query_arg()` on the WPML/Polylang home URL handles the directory form (`/fr/` → `/fr/?ndvr_k=`) and the parameter form (`/?lang=fr` → `/?lang=fr&ndvr_k=`).
- **Pro collisions: none.**
  - Pro registers `criterion: {name}` in context `ndv-reviews` (Pro Wpml.php:62). Free registers the seven setting keys. The shared context is intentional and the names can't overlap.
  - Pro has no Polylang `pll_register_string` group and no `ndv_reviews_ml_*` option.
- **AC1 deviation:** hooks are always added and `active()` is checked at run time. The callbacks return at once with neither plugin. This is acceptable, and the removal from `ndv-reviews/services` still leaves the container entry, so `get( 'multilingual' )` never returns null.
- **Uninstall:** `ndv_reviews_ml_strings_hash` is in the registry (Uninstall.php:44).
- **Note:** it is escaped (Multilingual.php:327) and hidden with neither plugin. The action sits under the Reminder card (RequestsPage.php:406-410).
- **Readme:** the FAQ documents `ndv-reviews/order_language` (readme.txt:120).

---

## MAJOR

### M1. `handle_submit()` switches locale and never restores it, so merchant notifications go out in the customer's language
- **Where:**
  - Landing.php:285-288 is a bare `switch_to_locale( $locale )` with no restore.
  - Everything after it runs in the order locale, including `$this->reviews->create()` (Landing.php:355).
  - `create()` fires `ndv-reviews/review_created` (ReviewRepository.php:234).
- **Listeners on that hook:**
  - free `AdminNotify::notify` (AdminNotify.php:52, priority 40) builds the merchant email with `__()` and sends it;
  - Pro `Moderation\Plus::low_rating_alert` (Plus.php:42) and `AI\Ai::on_created` (Ai.php:54);
  - Webhooks and `CouponReward`.
- **Failure:** a French customer submits through a French-order link on a German store. The merchant gets "[Shop] Nouvel avis en attente…" in French, and Pro's low-rating alert is French too.
  - Before RR-08, that request ran in the site locale. So this is a regression, not an existing quirk.
  - It also deviates from PRD §6 "Landing": `with_locale()` should wrap the JSON messages only.
- **Is "the response ends the request" acceptable?** Only for the early validation messages. It is not acceptable once `create()` and its listeners run.
- **Fix:** keep the switch only around building messages.
  - Switch at :287 for the validation errors.
  - Call `restore_previous_locale()` (when the switch returned true) immediately before `$this->reviews->create()`.
  - Build the final success message in `with_locale( $locale, fn () => … )`.
  - Messages from `create()`'s own `WP_Error` may stay in the site locale.
  - Pass the order's language code as well, for consistency with the render.
  - Add a harness case: the submit, with an `admin_notify` capture asserting the merchant subject is in the site language.

### M2. WordPress 6.0: the locale switch kills the plugin text domain for the rest of the request
- **Where:** Multilingual.php:155/168, which is core `WP_Locale_Switcher::load_translations()`.
- **Requirement:** the plugin declares "Requires at least: 6.0" (rosette-reviews.php:7, readme.txt:4), and PRD-00 §11 lists WP 6.0. The 6.0 repeat (test plan step 6) was skipped (LOG RR-08).
- **Evidence (inferred from the 6.1 change; I had no 6.0 source):**
  - `unload_textdomain()`'s `$reloadable` parameter is `@since 6.1.0` (7.1.3 l10n.php:869-879).
  - The switcher now calls `unload_textdomain( $domain, true )` with the comment "allow them to be reloaded after switching back" (class-wp-locale-switcher.php:258-262).
  - On 6.0 the switcher called `unload_textdomain( $domain )`. That sets `$l10n_unloaded['rosette-reviews'] = true`, and `_load_textdomain_just_in_time()` then refuses the domain (l10n.php:1430 in 7.1.3 has the same guard).
- **Failure on 6.0:**
  1. Inside the switch, our strings come out as NOOP (English), so AC2 (French default subject) fails.
  2. After `restore_previous_locale()` the domain is still dead. Every later `__( …, 'rosette-reviews' )` in the same request is English, even on a non-English site. One Action Scheduler batch with one French order followed by German orders on a de_DE store sends the German orders the English default subject. An admin retry likewise leaves the rest of the admin page in English.
  3. Also on WP < 6.2 (the switcher's `determine_locale` filter came with 6.2's user-locale switching): in admin and admin-ajax requests, which cover a retry and the Action Scheduler async runner carrying the admin's cookies, just-in-time loading resolves `determine_locale()` to the admin's user locale. Inside a switch to fr_FR, our strings then load in the admin's language.
  - None of this is reachable on 6.2+, which is all the harness ran on.
- **Fix:** do what WooCommerce does (`wc_switch_to_site_locale()`): explicitly reload our domain on `change_locale` when WP < 6.2. Feature-test with `function_exists( 'switch_to_user_locale' )`. For example, in `Multilingual::register()`:
  ```php
  add_action( 'change_locale', function ( $locale ) {
      if ( function_exists( 'switch_to_user_locale' ) ) { return; } // WP 6.2+ handles it.
      unset( $GLOBALS['l10n_unloaded']['rosette-reviews'] );
      $f = static function () use ( $locale ) { return $locale; };
      add_filter( 'plugin_locale', $f );
      load_plugin_textdomain( 'rosette-reviews' );
      remove_filter( 'plugin_locale', $f );
  } );
  ```
  - Then run the WP 6.0 repeat of AC2, AC4 and AC5. If it can't be run, record it as an open risk and do not claim 6.0 in PRD §11.
  - The alternative is to skip switching below 6.1 and document that.

### M3. Sends with no order language use the request's current language, not the site language
- **Where:**
  - Mailer.php:279 `get_locale()`;
  - Mailer.php:770, which passes `$this->lang_code = ''`;
  - Multilingual.php:188 and :194, which turn `''` into `wpml_current_language` / `pll_current_language()`.
- **Failure:** the order language is unknown in several common cases:
  - Polylang on HPOS, which is always `''` (spike 2), and is WooCommerce's default storage since 8.2;
  - orders created before WPML was installed;
  - orders that WCML didn't tag.

  Such a send in an admin context uses the admin's current language. That covers RequestsPage.php:127 retry, Action Scheduler's async runner on admin-ajax (dispatched from admin pages with the admin's cookies), and a manual send.
  - With WPML, the admin-bar language (say `de`) is "current". The merchant's custom subject and body are then translated to German.
  - WPML also filters `locale`, so `get_locale()` itself returns the de locale.
  - With Polylang, the admin's content-language filter (`pll_filter_content`) is the current language.
  - The result is a mixed or wrong-language email. That contradicts PRD §5 and CONTRACTS: "an admin retry never uses the admin's locale."
  - The AC5 stub can't show this. The stub has no `wpml_current_language` or `locale` filter.
- **Fix:** when `order_language()` returns `code === ''` and a plugin is active, resolve the **default** language explicitly:
  - WPML: `apply_filters( 'wpml_default_language', null )`, plus its `default_locale` from `wpml_active_languages`;
  - Polylang: `pll_default_language( 'slug' )` / `pll_default_language( 'locale' )`;
  - then pass that code to `translated()` and `with_locale()`.
  - Without a plugin, keep `get_locale()`. That keeps AC1 intact.
  - `''` stays "current language" for the front-end callers (Transparency, Consent), where that is correct.

### M4. Language-aware links shipped with Build spike 5 open
- **Where:** Mailer.php:629 and Multilingual.php:221-232.
- **Risk:** every review link on a WPML or Polylang store now points at `/fr/?ndvr_k=…` or `?lang=fr&ndvr_k=…`. PRD spike 5 ("confirm the query survives to `template_redirect` with no redirect dropping it") is recorded as **open** in LOG. If either plugin's canonical or language redirect drops the query, every review link on those stores breaks. Three cases need checking:
  - Polylang "hide default language" or "language from the content";
  - WPML domain-per-language;
  - a trailing-slash mismatch from `pll_home_url()` on a site with no trailing slash.
- **Domain-per-language mode:** the landing is served on `fr.example.com`, but `ajax_url` is `admin_url( 'admin-ajax.php' )` (Landing.php:193). If WPML doesn't map `admin_url` to the language domain, the submit becomes cross-origin.
- The PRD already chose the fallback: keep `home_url( '/' )`. The landing switch to the order locale (Landing.php:182) already gives the right page language without a language in the URL.
- **Fix (either):**
  - verify spike 5 for Polylang directory mode and WPML directory and parameter modes, with evidence in LOG; or
  - ship the fallback until verified, for example `build_link()` using `home_url()` unless `apply_filters( 'ndv-reviews/language_links', false )`.

---

## MINOR

### m1. Registered value and looked-up value differ (trim)
- **Where:**
  - registration trims (Multilingual.php:242, :294);
  - `Mailer::translated()` passes the raw stored value (Mailer.php:761-770) and trims only after the filter;
  - Transparency passes the raw value (Transparency.php:293);
  - Consent trims first (Consent.php:179), which is correct.
- Bodies are saved with `wp_kses_post()`, which doesn't trim (RequestsPage.php:159).
- **Failure:** Polylang's `pll_translate_string()` is a gettext lookup by the exact original text. A body or extra text with a leading or trailing space or newline is registered trimmed, then looked up untrimmed, and silently never translates.
  - WPML looks up by context and name. But WPML String Translation can treat a differing original as a changed string, re-registering it or marking translations "needs update" (unverified).
- **Fix:** use the same normalised value on both sides: `trim()` before applying the filter in `translated()` and in Transparency.

### m2. WPML hash is saved when String Translation isn't active
- **Where:** Multilingual.php:266-277, and :287-310.
- `wpml_register_single_string` is provided by WPML String Translation, not by WPML core (`ICL_SITEPRESS_VERSION`). With core only, the `do_action` is a no-op, but the hash is written.
- **Failure:** the merchant installs WPML core, then adds String Translation later, which is the normal setup-wizard order. The strings never appear in String Translation until a text is edited. The same happens after String Translation's "delete unused strings" or a reinstall. PRD §7 promises "existing texts registered on the next admin page load."
- **Fix:** register and store the hash only when `defined( 'WPML_ST_VERSION' )` (or `has_action( 'wpml_register_single_string' )`), and fold `WPML_ST_VERSION` into the hash.
- Also consider the 5th argument `$source_lang_code = apply_filters( 'wpml_default_language', null )`. Merchants write these texts in the site's default language, which isn't necessarily String Translation's "strings language".

### m3. Landing: half the page is outside the switch, and the WPML language isn't switched
- **Where:** Landing.php:200-202.
- **Part of the page renders after the restore.** `output_page()` runs after the restore, so `<html <?php language_attributes() ?>>` (:523) and `<title>Write a review` (:528) are in the URL or site language around French content. That fails WCAG 3.1.1 (Level A) and the PRD §3 promise that "the review page shows its labels in that language."
- **No language code is passed.** `with_locale( $locale, $build )` gets no `$code`, so on WPML the plugin's current language stays the URL's. Anything in the template that reads `wpml_current_language` keeps the URL language, including Pro's criteria names and `translate_setting( …, '' )`. That matters for links without a language: Pro ESP links (PRD §5), test links, and the M4 fallback.
- **The used-token thank-you state gets no locale.** `$row` is null there (:174-179); `$any` holds the order id.
- **Fix:**
  - pass `order_language()['code']` too;
  - render and output inside the wrapper (`ob_start(); $this->output_page( … ); return ob_get_clean();`), and `echo` plus `exit` after it, since `exit` inside the closure would skip `finally`;
  - use `$any` for the locale when `$row` is null.

### m4. `with_locale()` reads and switches the WPML language before `try`
- **Where:** Multilingual.php:157-159.
- **Failure:** if a `wpml_current_language` or `wpml_switch_language` listener throws, the WP locale switched at :155 is never restored.
- **Fix:** move both calls inside `try` and track `$wpml_switched`.
- **Nested switches.** `restore_previous_locale()` pops the stack top. If `$build` leaves its own switch unbalanced, we pop theirs. That is acceptable, but worth a nested-switch test.

### m5. Translated subjects don't get the original's sanitising
- **Where:** Mailer.php:663-667.
- The stored subject went through `sanitize_text_field()` at save (RequestsPage.php:158). A translation entered in WPML or Polylang doesn't, and `subject()` collapses only newlines. Translators may be a separate WPML Translator role.
- PRD §6 step 3 says "a translation gets the same sanitizing as the original."
- **Fix:** run `sanitize_text_field()` on the translated subject before `replace_tokens()`, or the `wp_strip_all_tags` + `html_entity_decode` treatment from Mailer.php:476.

### m6. List-recipient sends aren't pinned to the site language
- **Where:** `send_to_list_recipient()` (Mailer.php:318-360) has no `with_locale( get_locale() )`.
- **Failure:** in admin and admin-ajax runs it builds in the admin's user locale, which contradicts PRD §2 ("List recipients: sent in the site language").
- **Fix:** wrap the `request_texts()` call the way `send_for_order()` does. Pass the default language code too, per M3.

### m7. Pro: step texts override the translation, and Polylang has no language switch
- **Step texts:** Pro `Engine::step_texts` hooks `translate_setting` at 20 (Engine.php:92, :267-279). It replaces the already-translated `followup_subject` / `followup_body` with untranslated Pro step texts. So Pro reminder steps always go out in the default language, and PRD §5's "Pro translates its own" isn't true today.
- **No Polylang switch:** `with_locale()` switches WPML's language but nothing for Polylang. Pro code reading `pll_current_language()` inside the build sees the cron or admin language.
- Neither blocks this review. Add a Pro TASKS item: register and translate the step texts per step, and receive the language code (for example via the `request_email_texts` row or a new argument).

### m8. Admin-hook cost
- **Where:** Multilingual.php:73.
- `admin_init` fires on every `admin-ajax.php` call, including heartbeat. With WPML that is one option read plus an md5. With Polylang it is up to seven `pll_register_string` calls. This is cheap, and matches PRD §10. It is noted only because spike 3 (Polylang registration timing) is still open.
- **Fix (optional):** skip when `wp_doing_ajax()`.

### m9. Text-domain reload cost (note)
- Each cross-language send does two full reloads of every loaded domain (switch and restore, including WooCommerce's), plus WPML String Translation's reload on `wpml_switch_language`.
- Same-locale sends don't switch at all, because `switch_to_locale()` returns false. On WP 6.5+ (`.l10n.php`) the cost is modest.
- An Action Scheduler batch of 25 cross-language orders on an older WP with `.mo` files is noticeably heavier. That is acceptable as documented. No change required.

---

## Open Build spikes (from LOG, still open)
- **Spike 3:** Polylang registration on `admin_init`.
- **Spike 4:** block-checkout consent label language at `woocommerce_init`.
- **Spike 5:** language URLs (see M4).
- **Spike 1:** the WPML locale override in a cron request (relevant to M3).

Spikes 3 and 4 may stay open if they are recorded as known limits. Spike 5 can't, because the link is live code (M4).

---

## Harness gaps (`.agents/qa/rr-08.php`)
1. **AC4 "exception while building" never reaches `with_locale()`.**
   - :465-473 calls `send_for_order( $o, ['request_id'=>0] )` on order `$o`, which was sent minutes earlier in AC2.
   - The legacy path runs the full gate, so the send stops on `ndvr_cooldown` (Mailer.php:183-197) before the switch, and the throwing `request_email_texts` filter never runs. Nothing asserts the exception was caught.
   - AC10 does cover `finally` via the landing.
   - **Fix:** use a fresh order, or `ndv-reviews/request_cooldown` → 0, and assert `$caught`.
2. **AC1 doesn't remove the service through `ndv-reviews/services`, as the PRD specifies.**
   - It only removes the `translate_filter` hook (:307).
   - It runs in CLI, where `get_locale()` equals the current locale, so no switch happens in either run. The equality is trivial.
   - Add an admin-context run where the user locale is not the site locale, to pin the documented AC1 exception.
3. **No spy on `wpml_switch_language`** during sends, so the switch, the restore to the previous language, and the null and `'all'` previous values are untested.
4. **No Polylang coverage at all.** Polylang functions can be stubbed in a child process the way WPML is (`pll_get_post_language`, `pll_translate_string`, `pll_register_string`, `pll_home_url`, `pll_current_language`). That covers `order_language()`, `translate()`, `register_strings()` (including multiline) and `home_url()`, even without real Polylang (AC9).
5. **No `handle_submit()` test** (M1): the message locale, plus the merchant-notification locale.
6. **No nested-switch test**, no WPML parameter-mode link test (`?lang=fr&ndvr_k=`), and no trim-mismatch test (m1).
7. **The WP 6.0 repeat (test-plan step 6) was skipped**, and that is where M2 bites.

---

## Compatibility summary
- **PHP 7.4:** fine. There is no 8.x-only syntax. `?RequestRepository` and `try`/`finally` with `return` are fine, and `$lang_code` is declared (Mailer.php:750), so there is no dynamic-property deprecation on 8.2.
- **WP 6.0–6.1:** see M2. Locale switching for our own text domain is broken there without the reload.
- **WP 6.0 and 6.1 in admin contexts:** user-locale bleed into the switch, also under M2.
- **HPOS:** WPML reads meta through `$order->get_meta()`, which is fine. Polylang on HPOS always returns `''` (documented, and made worse by M3).

---

## Required before done
1. **M1:** restore the submit switch before `create()`. Merchant notifications must go out in the site language. Add a harness case.
2. **M2:** reload our text domain on `change_locale` for WP < 6.2, or drop the 6.0 claim. Run or record the WP 6.0 repeat.
3. **M3:** when the order language is unknown and a plugin is active, use the default language (code and locale), not the current one.
4. **M4:** verify spike 5 with evidence, or ship the `home_url( '/' )` fallback until verified.
5. **Harness gap 1:** make the AC4 exception actually run inside `with_locale()` and assert that it did.

**Recommended in the same pass:** m1 (one-line trim), m2 (String Translation gate), m3 (whole page in locale plus WPML code) and m5 (subject sanitising). Add harness gaps 3-5. Add the m7 Pro TASKS note.

**Verdict: CHANGES REQUESTED.**
