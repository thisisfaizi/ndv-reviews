# Code review: RR-12 (minimum review length)

Reviewer: independent code review, 2026-10-10. Read-only: no plugin code was edited, no QA harness was run, WordPress wasn't booted and nothing was installed. The build reviewed is commit `c6d7339` ("RR-12: minimum review length").

**Working tree note.** The working tree has uncommitted RR-13 (captcha) edits in `ReviewForm.php`, `TestimonialForm.php`, `SettingsPage.php`, `AntiSpam.php`, `Renderer.php` and others, and they changed while this review ran. Every line number below is from the `c6d7339` copies (`git show c6d7339:<file>`), not the working tree. PHPCS flags in the working tree (TestimonialForm.php:183 brace, ReviewForm.php:409 alignment) are RR-13's.

**Files read.** Free plugin:
- `includes/Reviews/ReviewLength.php` (new)
- `Reviews/ReviewRepository.php` (`create()`, `content_length()`, `check_length()`)
- `Reviews/Sources.php`
- `Forms/ReviewForm.php`
- `Forms/TestimonialForm.php`
- `Forms/FieldRenderer.php`
- `Collection/Landing.php`
- `templates/magic-landing.php`
- `Display/Renderer.php` (`render_form()`, `claim_ids()`)
- `Admin/SettingsFields.php`
- `Admin/SettingsPage.php` (trust card)
- `Display/Transparency.php` (the other trust-card fields)
- `Importers/Csv.php`
- `Importers/WooNative.php`
- `Support/Assets.php`
- `Plugin.php`
- `assets/js/reviews.js` and `assets/js/collect.js`, with their `.min` builds
- `assets/css/reviews.css` and `assets/css/collect.css`, with their `.min` builds
- the harness `.agents/qa/rr-12.php`
- the PRD (rev 3 plus build notes), CONTRACTS, LOG, TASKS, readme, and `round2-all.md` (RR-12 items)

Pro: `Importers/ProImporter.php` (source constant and the `create()` call). `Admin/ManualReviews.php` was grepped (it doesn't call `create()`).

**Reference sources.** WordPress 7.1.3 at `D:/.devcache/qa-site/wordpress/`:
- `wp-includes/kses.php`: `wp_kses_split` :1278, `wp_kses_split2` :1396, `wp_kses_normalize_entities` :2169
- `wp-includes/formatting.php`: `wp_strip_all_tags` :5600
- `wp-includes/load.php`: `absint` :1468

**Static and offline checks:**
- `php -l` (PHP 8.3 CLI) is clean on all six changed PHP files.
- `vendor/bin/phpcs --standard=phpcs.xml.dist`, excluding ValidHookName and PrefixAllGlobals, gives 0 errors and 0 warnings on the committed copies. That run includes PHPCompatibilityWP (`testVersion 7.4-`) and the text-domain check.
- **Counting experiment (no DB, no bootstrap).**
  - `wp-includes/plugin.php`, `formatting.php` and `kses.php` were loaded standalone, with three stub functions and an autoloader for the HTML API classes.
  - Each string was put through `wp_kses_post( trim() )` and then a verbatim copy of `ReviewLength::count()`.
  - Node 26 was given the same strings and the shipped JS expression `Array.from( v.replace( /\s+/g, ' ' ).trim() ).length`.
  - The whitespace sets were enumerated over U+0000–U+FFFF in PHP (PCRE 10.42, `/u`) and in JS.
  - The results are quoted in M1 and m1.
- The `.min.js` and `.min.css` builds contain the same counter and rules as the sources. `Support\Assets` serves `.min` in production.

---

## Verdict

**CHANGES REQUESTED.** There are no blockers.

The server gate is correct and complete:
- It sits in `create()`, applies to interactive sources only, and imports are exempt.
- The early checks in the three handlers run before any upload, with 400 and the right message.
- The setting is registered correctly and clamped.

Three majors remain:
- **M1, server and JS counts disagree on ordinary text.** The JS counter counts the raw textarea, but the server counts after `wp_kses_post`. That deletes everything from a `<` up to the next `>` or the end of the text. Any review containing "<3", "<$20" or "<-" can show "45 / 30" and "Minimum length reached." and still be refused with "Please write at least 30 characters.". The contract says the two "can differ only on rare whitespace characters". That is not true.
- **M2, the filter's `$data` differs between render, the early check and `create()`.**
  - The product form renders with no `product_id`, so a per-product filter (the PRD's stated use) shows one minimum and enforces another.
  - The landing page renders as `magic_link` even for `list_link` tokens.
  - The early check passes 2 keys, while `create()` passes the full submission.
- **M3, on the standalone form and the landing page, the hint and the live status sit inside the textarea's `<label>`.** The hint is therefore read twice (in the name and in the description). Once an announcement fires, it becomes part of the field's name. This is reasoned from the accessible-name spec and not tested in a browser.

---

## What was checked and is correct

**The gate in `create()`** (ReviewRepository.php:100-103):
- It runs right after the empty-content check and before the author, email, rating and RR-11 question checks, and before any insert.
- It returns `ndvr_too_short`, using `_n()` with `number_format_i18n()`.
- `check()` (ReviewLength.php:122-140) defaults a missing `source` to `onsite`. That matches RR-11's default (ReviewRepository.php:138).
- It is a no-op for non-interactive sources, through `Sources::is_interactive()`, which is filterable.

**Exempt sources:**

| Path | Source / route | Gate |
|---|---|---|
| `Importers\Csv` | `'source' => 'import'` (Csv.php:174) | exempt |
| `Importers\WooNative` | re-tags existing comments, never calls `create()` (WooNative.php:74) | n/a |
| Pro `ProImporter` | `SOURCE = 'import'` (ProImporter.php:47, :582 → `create()` :590) | exempt |
| Pro `ManualReviews`, `ExternalReviews` | direct inserts, no `create()` | n/a |

**Handler order.** All three handlers run their checks in this order:
1. size, nonce, spam, consent and product gates;
2. the rating check;
3. **the length check**;
4. the RR-11 required-questions check;
5. the upload;
6. `create()`.

The handlers:
- ReviewForm.php:569 (rating) → :575 (length) → :590 (questions) → :598 (upload) → :607 (`create()`)
- TestimonialForm.php:279 → :284 → :298 → :305 → :312
- Landing.php:331 → :337 → :353 → :370 → :394

Other details:
- Every handler returns `wp_send_json_error( …, 400 )` with the `WP_Error` message.
- **Landing source.** The landing early check uses `'list' === $row->type ? 'list_link' : 'magic_link'`, which is the same expression as `create()` (:405) and the questions check (:353).
- **Landing language.** The message is built after `switch_to_locale()` (:296), so it is in the customer's language.
- **Same count in both places.** The handlers count `wp_kses_post( trim( x ) )`. `create()` counts `trim( wp_kses_post( x ) )`. `count()` trims and collapses whitespace anyway, so the two counts are identical.
- **Orphans.** If `create()` fails after an upload, the orphan clean-up still runs.

**Counting**, comparing the server with the shipped JS:

| Input | Result |
|---|---|
| 3 emoji outside the BMP | 3 in both |
| ZWJ family emoji | 5 in both (code points, as the PRD says) |
| CJK | equal |
| `"a"` + 40 spaces + `"b"` | 3 in both |
| NBSP runs, U+3000, CRLF | equal |
| `Fish & chips` | equal (kses gives `&amp;`, which is decoded back) |
| `5 > 4` | equal (`&gt;`, decoded) |
| `<strong>Too short</strong> &amp;` | 11 on the server (AC4) |

**Settings (F6):**
- **Registration.** `register_fields()` sets `page => settings` and `card => trust`, with a render callback. `render_card_fields()` prints the marker for each field it renders, so `sanitize_page()` only saves the key when the trust card was on the submitted form.
- **Default.** The default is 0.
- **Clamping.** `sanitize()` clamps to 500. `min()` re-casts and clamps the filtered value to 0–500 (ReviewLength.php:99), so a bad filter can't push it negative or above 500.
- **Boot.** `ReviewLength` is booted unconditionally (Plugin.php:537), so the defaults and the filter are present on the front end.
- **Number input.** It has `min="0"` and `max="500"`, and an escaped value.

**Markup and escaping:**
- `textarea_attrs()` and `after_textarea()` escape everything. The integer goes through `%d`, and the `phpcs:ignore` notes are justified.
- With minimum 0, both helpers return `''`, so there is no hint, counter or data attribute (AC6).
- There is no `minlength` attribute (PRD §3).
- The product form's label is a separate `<label for="comment">`, so M3 doesn't apply there.
- The testimonial form's ids come from `wp_unique_id( 'ndvr-t' )` (TestimonialForm.php:164).
- The landing ids are `p{pid}-length`, which can't clash with `p{pid}-f{id}` (FieldRenderer.php:41) or `p{pid}-c…`.

**JS:**
- **No per-keystroke announcements.** `say()` is called at most once from `input` (the `reached` latch) and once per blur. A repeated identical text is suppressed by `last`.
- **The status region is ready before it is used.** It is in the server-rendered HTML and empty, so screen readers register it before the first write.
- **The visible counter is hidden from screen readers.** The counter is `aria-hidden="true"` and carries no live region.
- **`fill()` is safe.** It does a string-pattern `replace` with a digit-only replacement, so `$` patterns can't be injected.
- **Browser support.** `Array.from` is ES2015, which is within WP 6.0's browser support.
- **The counter needs data attributes.** It initialises only when `data-ndvr-min-length` and the sibling elements are present. Without them, the server alone enforces the minimum.

**CSS:**
- reviews.css adds a scoped `.ndvr-length-status.screen-reader-text` clip rule (reviews.css:359), so the status stays hidden on themes without `.screen-reader-text`.
- collect.css already has `.ndvr-collect .screen-reader-text` (:19-29).
- The hint and counter use `--ndvr-slate` #626b7a, which is 5.38:1 on white and passes AA at 0.8em.

**i18n:**
- Every string uses the `rosette-reviews` domain.
- `_n()` is used for both plural strings.
- Translator comments sit directly above each placeholder string, which `make-pot` needs.
- `readme.txt` gains a changelog line.
- The landing hint renders inside `with_locale()` (Landing.php:205).

**PHP 7.4.** No 8.x syntax or functions are used, and PHPCompatibilityWP is clean.

---

## Findings

### MAJOR

**M1. The JS counter and the server disagree on any review that contains `<`. The customer passes the counter and fails the server.** Locations: ReviewLength.php:108-113, reviews.js:122-124, collect.js:139-141; WP kses.php:1278 (`wp_kses_split`) and :1396 (`wp_kses_split2`).

The cause:
- `wp_kses_split()` treats `<` followed by anything up to the next `>`, or to the end of the string, as a tag.
- `wp_kses_split2()` returns `''` for anything that isn't an allowed element. That includes `<3`, `<$`, `<-` and `< half`.
- The server therefore counts a truncated text, while the JS counts what the customer typed.

Measured with the real WP 7.1.3 kses and the shipped expressions:

| Customer types | JS count | Server count | kses keeps |
|---|---|---|---|
| `Love it <3 would buy again, great value` | 39 | **7** | `Love it ` |
| `Cost <$20, a bargain, very happy with it` | 40 | **4** | `Cost ` |
| `Best purchase <- no doubt about it at all` | 41 | **13** | `Best purchase ` |
| `Price was < half of the shop next door, great` | 45 | **9** | `Price was ` |
| `Fits S<M but M>L, so order one size up` | 38 | **29** | `Fits SL, so order one size up` |
| `Quality 10/10 <<< worth every penny honestly` | 44 | **13** | `Quality 10/10 ` |
| `&nbsp;` typed 5 times between `a` and `b` | 32 | 3 | (entity decoded) |
| `&copy;` typed 5 times | 30 | 5 | |
| `<strong>Too short</strong> &amp;` typed | 32 | 11 | |

Failure scenario, with minimum 30:
1. A customer types `Cost <$20, a bargain, very happy with it`.
2. The counter shows "40 / 30" and announces "Minimum length reached.".
3. They submit, and the server replies "Please write at least 30 characters.".
4. Nothing on the page explains why. Adding more text after the `<` never helps.

`<3` is common in consumer reviews. The reverse case (JS refuses, server accepts) doesn't occur with `<`.

**Out of scope, but surfaced by this review:** with the minimum at 0, the same reviews are stored silently truncated ("Cost "). This is pre-existing, and the same as core's `wp_filter_kses` on comments. Recommend a separate task, for example encoding a `<` that doesn't start an allowed tag before kses, for plain-text review bodies.

Fix for RR-12 (tested in Node against the server results above):
- (a) Mirror kses in both counters, before the whitespace collapse:
  ```js
  text = text.replace( /<!--[\s\S]*?(-->|$)/g, '' ).replace( /<[^>]*(>|$)/g, '' );
  ```
  - With this, these cases match the server exactly: all six `<` strings in the table, `I'd say <b>great</b> product overall really` (36) and `nice <!-- hidden --> product` (12).
  - Typed entities still differ (`&nbsp;`, `&copy;`, `&lt;3`, and the `&amp;` in the `<strong>` case, which gives 15 against 11).
  - To close that too, decode with a detached `textarea` element (`t.innerHTML = s; s = t.value`). A textarea parses as RCDATA, so nothing runs. This part is untested here because Node has no DOM.
- (b) Put the server's count in the error message, so a mismatch can be understood: "Please write at least %1$s characters (we counted %2$s)." This needs a new `_n` string with a translator comment.
- (c) Correct the contract and the PRD §6 sentence "The two can differ only on rare whitespace characters".
- Add a harness case: `create()` with `Cost <$20, a bargain, very happy with it` at minimum 30 → `ndvr_too_short`. Then assert `ReviewLength::count()` equals the documented JS result for the same string.

**M2. The filter's `$data` differs between the hint and the server, so a per-product minimum shows one number and enforces another.** Locations: ReviewForm.php:450, magic-landing.php:108-113, ReviewForm.php:575-581, TestimonialForm.php:284-290, Landing.php:337-343, ReviewRepository.php:100.

PRD §6 says the filter receives `$data` "so a store can vary it per product". The three call points pass different arrays:

| Call | `$data` passed |
|---|---|
| Product form render (ReviewForm.php:450) | `array( 'source' => 'onsite' )`, **no `product_id`** |
| Landing render (magic-landing.php:108-113) | `source => 'magic_link'` always, even for a `list` token |
| Testimonial render (TestimonialForm.php:200-205) | `source`, `product_id` (correct) |
| Early checks (three handlers) | `source`, `product_id` only |
| `create()` | the full submission: `author`, `email`, `content`, `criteria`, `user_id`, `order_id`, `answers` … |

Failure scenarios:
1. **Per-product filter.** A store adds `fn( $min, $data ) => 123 === ( $data['product_id'] ?? 0 ) ? 50 : $min` with the setting at 30. On product 123, the hint says "At least 30 characters." and the counter announces "reached" at 30, but the server refuses 30–49 with "Please write at least 50 characters.". With the setting at 0, the product page shows no hint or counter at all, yet refuses everything under 50. A filter written as `$data['product_id']`, without `??`, also raises "Undefined array key" (PHP 8 warning, PHP 7.4 notice) on every product page render.
2. **Source filter.** A filter that returns 0 for `list_link` (no minimum for list campaigns) still shows the hint and counter on list landing pages. The server, correctly, doesn't enforce the minimum there.
3. **Filter on other keys.** A filter that lowers the minimum when `order_id` is set (verified buyers) is refused by the early check, which has no `order_id`, but would be accepted by `create()`. The customer gets the error before `create()` can apply the lower value.

Fix:
- Product form: pass the product id. `customize_review_form()` runs from `Renderer::render_form( $product_id )` (Renderer.php:405) and from WooCommerce's own template, both on a product page. Use `get_the_ID()` or the `global $product`. Better, have Renderer add `$comment_form['ndvr_product_id'] = $product_id` before the filter, and read it in `customize_review_form()` with a `get_the_ID()` fallback.
- Landing: pass a `source` variable into the template (`'list' === $row->type ? 'list_link' : 'magic_link'`, Landing.php:187-201). Have the template use `isset( $source ) ? $source : 'magic_link'`, so old overrides keep working.
- Document in CONTRACTS and the filter docblock that `$data` **guarantees only `source` and `product_id`**, and make every call point pass exactly those. Alternatively, pass the same submission-shaped array (with `user_id` and `order_id`) to the early checks and to `create()`.
- Add a harness case: the filter keyed on `product_id`. Render the product form for that product, assert the hint says 50, and assert the AJAX handler refuses 40 characters.

**M3. On the standalone form and the landing page, the hint and the live status are inside the `<label>` that names the textarea.** Locations: TestimonialForm.php:198-207, magic-landing.php:106-118 (output of ReviewLength.php:178-188). This is reasoned from the HTML-AAM and accname specs and not tested with a screen reader.

The cause:
- Both forms use an implicit label: `<label>Your review * <textarea …></textarea><span hint/><span count aria-hidden/><span status/></label>`.
- The textarea's accessible name is computed from the label's text content.
  - The counter is excluded, because it is `aria-hidden`.
  - The hint and the status are not excluded. `.screen-reader-text` hides visually only.

Failure scenario (NVDA or VoiceOver on the landing page, minimum 30):
1. On focus, the field reads "Your review star At least 30 characters., edit text, multi line, At least 30 characters.". The hint is spoken twice, once in the name and once in the description.
2. After a blur at 12 characters, the status holds "12 of 30 characters.".
3. Returning to the field, the name is now "Your review star At least 30 characters. 12 of 30 characters." That is stale, and wrong once more is typed.
4. After "Minimum length reached.", that sentence becomes part of the name for the rest of the visit.
5. The product form doesn't have this problem, so the three forms behave differently.

The build note says the `<span>` was chosen because a `<p>` isn't allowed in a `<label>`. The better fix is to take the extras out of the label.

Fix:
- On both forms, use an explicit label: `<label for="{prefix}comment">Your review *</label><textarea id="{prefix}comment" …>`, with the hint, counter and status as siblings in the `<p class="ndvr-field">`. Use the `$prefix` id on the testimonial form and `p{pid}-comment` on the landing page.
- Then change the JS lookup from `area.parentNode` to `area.closest( '.ndvr-field' ) || area.parentNode` (see m7).
- Update the template docblock so overrides follow the new pattern.
- Check the result once with NVDA or VoiceOver. That is the PRD §13.4 manual check, which LOG/TASKS still list as open.

### MINOR

**m1. The whitespace sets differ in three code points.** Locations: ReviewLength.php:110, reviews.js:123, collect.js:140.

Enumerated over the BMP: PHP `/\s/u` (PCRE 10.42 with UCP) matches U+0085 (NEL) and U+180E, and JS `\s` doesn't. JS `\s` matches U+FEFF, and PHP doesn't. Everything else is identical.

| Text | JS count | Server count |
|---|---|---|
| `a` + 2 NEL + `b` | 4 | 3 |
| `a` + 2 U+180E + `b` | 4 | 3 |
| BOM + `good` + BOM | 4 | 6 |

The NEL and U+180E cases can pass JS and fail the server. These are rare (pasted legacy text), and the PRD accepts "the server decides". If you want exact parity, use one explicit class on both sides, for example `[\t\n\v\f\r \u0085\u00A0\u1680\u180E\u2000-\u200A\u2028\u2029\u202F\u205F\u3000\uFEFF]` (with `\x{…}` in PHP). Whether other PCRE builds treat U+180E differently is speculation.

**m2. Zero-width characters satisfy the minimum on both sides.** `ok` followed by 28 × U+200B counts 30 in both (measured: `a` + 5 ZWSP + `b` = 7 in both). A customer, or a bot, can satisfy any minimum with invisible padding.

Optional fix: strip `\p{Cf}` before counting (PHP `/\p{Cf}/u`, JS `/\p{Cf}/gu`). That also drops ZWJ, so the family emoji would count 3 instead of 5. That is arguably closer to what a reader sees, but it changes the PRD's "code points" rule, so it's an owner decision. Whichever way it goes, document it.

**m3. The count is wrong during IME composition.** Location: reviews.js:137-144, collect.js:154-161.
- `input` fires during composition. A Japanese or Chinese user typing romaji or pinyin can reach the minimum in the pre-conversion string (e.g. "nihao" = 5) and hear "Minimum length reached.".
- After conversion the count drops ("你好" = 2). Because `reached` latches, the correct moment is never announced.
- Fix: `if ( e.isComposing ) { return; }` in the `input` handler, and run the same update on `compositionend`.

**m4. reviews.js leaves a stale counter after a successful submit.** Location: reviews.js:216.
- `form.reset()` doesn't fire `input`, so "35 / 30" stays under the empty box.
- `reached` and `last` stay set, so a second review from the same page load never gets the "reached" announcement.
- A `say()` timer still pending can announce "Minimum length reached." after the "Thank you" message.
- collect.js hides the fields on success, so it isn't affected.
- Fix: listen for the form's `reset` event in `lengthCounter()`. Clear the counter and the status, reset `reached` and `last`, and clear the timer.

**m5. The latch and the 1-second timer can drop the only "reached" announcement. A pre-filled value isn't counted.** Location: reviews.js:128-149, collect.js:145-166.
- **The brief's debounce question: only when the blur happens below the minimum.**
  - A blur at or above the minimum never calls `say()`, so the pending "reached" text still fires.
  - The message is lost in one case: the customer reaches 30 and then, within 1 s, deletes back below 30 and tabs away. The blur's `say()` then replaces the pending "reached" text, which is correct for that moment.
  - But `reached` is already true, so reaching 30 again later is never announced.
- **Restored values.** A value restored by the browser (back/forward cache or form restoration) or pre-filled by an extension shows no counter until the next keystroke.
- Fix:
  - Set `reached = true` only when the "reached" text is actually written.
  - Or reset `reached` when the count drops below the minimum, while keeping the `last` de-duplication so crossing back and forth isn't repeated. Note that the PRD says "first reaches", so either change is a PRD wording change.
  - Run the update once at init when `area.value` isn't empty, without announcing.

**m6. The product form's hint id is fixed.** Locations: ReviewForm.php:451-452.
- The hint id is `ndvr-length-hint`. `Renderer` already expects the reviews tab to render more than once per page (`claim_ids()`, Renderer.php:133-147, for builders and block themes). Two product forms would then give two `id="ndvr-length-hint"`, and both textareas would describe themselves with the first hint.
- This follows existing duplicates (`id="comment"`, `#ndvr-review-form`, `#ndvr-title`), and reviews.js only wires the first form (`getElementById`, reviews.js:10). The second form gets no counter either.
- Fix: `wp_unique_id( 'ndvr-length-' )`. That's cheap, and it matches TestimonialForm's approach.

**m7. The `area.parentNode` lookup is fragile.** Location: reviews.js:110, collect.js:127.
- A theme override of `magic-landing.php` that keeps the helper calls but wraps the textarea (for example `<div class="control"><textarea…></div>` followed by the helper output) silently gets no counter.
- With M3's fix, the extras would no longer be the textarea's siblings either.
- Fix: find the elements by id. Give the counter and status ids derived from the hint id, or look up `document.getElementById( area.getAttribute( 'aria-describedby' ) )` and search its parent. Alternatively use `area.closest( '.ndvr-field' )`.

**m8. Settings: `absint()` turns `-5` into 5.** Location: ReviewLength.php:65; WP load.php:1468 (`abs( (int) … )`).
- A typed negative becomes a positive minimum. The browser's `min="0"` normally blocks it, but not if the form has `novalidate` or the input is scripted.
- Fix: `min( self::MAX, max( 0, (int) $raw ) )`. The PRD says "absint then clamp", so update the PRD with it.
- Nit: the help text `<span class="description">` isn't tied to the input with `aria-describedby`. That matches the other fields on the page.

**m9. Nits:**
- **Invalid UTF-8.** If `preg_replace( '/\s+/u' )` gets invalid UTF-8, it returns `null`, which `(string)` turns into `''`. The count is then 0, and a long review is refused as too short. Only a crafted request can send that (FormData is always UTF-8), and it fails closed. Optional: `wp_check_invalid_utf8()` first.
- **`&apos;`.** `html_entity_decode( …, ENT_QUOTES, 'UTF-8' )` uses HTML 4.01 rules, so `&apos;` isn't decoded. kses keeps it, so a typed `&apos;` counts as 6. Use `ENT_QUOTES | ENT_HTML5`.
- **Format strings.** JS `fill()` replaces only `%1$d` and `%2$d`. A translation that uses `%d` or `%s` shows the raw placeholder. Say "keep %1$d and %2$d" in the two translator comments.
- **Digits.** The counter uses ASCII digits, while the hint uses `number_format_i18n()`. That's cosmetic.
- **Empty review.** With a minimum set, an empty review gets "Please write at least 30 characters." from the early check instead of "Please write your review." That's acceptable, but different from `create()` (which checks empty first).

---

## Harness gaps (`.agents/qa/rr-12.php`, 16/16)

1. **The early check before uploads is never exercised.** `setup()` sets `photo_uploads => false`, so AC1's "stores no attachment" is true without the early checks. Removing all three would still pass 16/16, because `create()` sends the same message.
   - **A synthetic file won't upload.** `Upload::handle()` goes through `media_handle_upload()` (Upload.php:137), which applies the `is_uploaded_file()` test, so a made-up `$_FILES` entry can't be stored.
   - **It still makes a working test.** Enable `photo_uploads` and add a fake `$_FILES['ndvr_photos']` entry. Count calls to `wp_handle_upload_prefilter`, which fires before that test. Then assert two things for each handler: the response is the length message, not an upload error, and the prefilter count is 0.
   - Without the early check, the handler would reach the upload and answer with an upload error instead.
2. **No HTTP status check.** AC1 says "returns 400"; the harness checks only the message. Capture the status with a `status_header` filter, or with `wp_send_json_error`'s `$status_code` through `wp_die_ajax_handler`.
3. **`list_link` is tested only through `create()`** (AC8). Add a `list` token through `Landing::handle_submit()`.
4. **The F6 save path (PRD §13.1) isn't used.** The harness writes `settings->update()` directly. It should assert the field is in `SettingsFields::card_fields( 'settings', 'trust' )`, then save through `sanitize_page( 'settings', … )` with and without the marker, and cover the negative input from m8.
5. **No parity check between the hint and the server for the same product under the filter** (M2). AC9 checks only `create()`.
6. **No `<` input case** (M1).
7. **AC7 never checks `data-ndvr-count-format`.** It also renders the product form with no product context (Reflection on `render_fields()`), so M2's missing `product_id` can't show up.
8. **The order of RR-11 and RR-12 isn't tested.** The harness zeroes `max_review_fields`, so "short review plus a missing required answer → the length message first" isn't covered.
9. **The JS isn't tested**, as planned in PRD §13.4. The manual screen-reader check is still open in LOG and TASKS, and should include M3, m3 and m5.
10. **AC10 (core flows) wasn't re-run** for this review, by instruction.

---

## Required before approval

| # | Item | Blocks |
|---|---|---|
| M1 | Strip `<…` and comments in both JS counters (tested regexes above), ideally decode entities. Put the server's count in the error message. Correct the contract and PRD sentence. Add the `<` harness case. Open a separate task for the pre-existing kses truncation. | approval |
| M2 | Pass `product_id` at the product-form render and the real `source` at the landing render. Make the filter's `$data` keys the same at all call points, or document the guaranteed keys. Add the hint/server parity harness case. | approval |
| M3 | Move the hint, counter and status out of the `<label>` on the standalone and landing forms (explicit `for`/`id`), update the JS lookup, and do the manual screen-reader check. | approval |
| m4 | Reset the counter, latch and timer on `form.reset()` in reviews.js. | approval (cheap) |
| m6 | Unique hint id on the product form. | approval (cheap) |
| m8 | Clamp negatives to 0 instead of `absint`. | approval (cheap) |
| Harness | Cases 1, 2, 3, 4, 5 and 6 above. | approval |

Recommended but not required: m1 (one shared whitespace class), m2 (owner decision on `\p{Cf}`), m3 (IME), m5 (latch and init), m7 (id-based lookup), m9.

**Verdict: CHANGES REQUESTED**
