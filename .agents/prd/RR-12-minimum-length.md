# RR-12: Minimum review length

Status: prd-ok (rev 3) · Plan: F · Inherits PRD-00 + RR-00 · No schema change · No Pro-facing API, so no `NDVR_API` change

Review findings applied: round 2 RR-12 items 1 (live region) and 2 (`list_link`).

## 1. Problem and who it's for
One-word reviews ("good", "ok") help nobody, and a page full of them can look fake. Merchants with many short reviews want a floor. CusRev sells a minimum length in its Professional tier; we include it in free.

## 2. Scope / non-goals
In scope:
- A setting for the minimum number of characters in the review text: 0 (off, the default) to 500.
- Enforced on interactive sources only (RR-00 F1: `Sources::interactive()` = `onsite`, `form`, `magic_link`, `list_link`), on create and on customer edits (RR-15, RR-22).
- A visible hint and a visible counter on all three forms, plus one quiet screen-reader announcement; the server is authoritative.

Non-goals: a maximum length (existing limits stay), a word count, the title field, imports, Pro manual and external reviews (they bypass `create()`: ManualReviews.php:166, ExternalReviews.php:858).

## 3. User experience
- **Settings → "Reviews and trust" card (F6):** "Minimum review length (characters)", number input, min 0, max 500. Help: "0 means no minimum. Applies to reviews customers write, not to imports."
- **Forms** (product form, testimonial form, landing page), when the minimum is above 0:
  - under the textarea, a hint `<p class="ndvr-length-hint" id="…">At least 30 characters.</p>`, referenced by the textarea's `aria-describedby`;
  - a visible counter `<span class="ndvr-length-count" aria-hidden="true">12 / 30</span>`, empty until typing starts. It has **no** live region, so screen readers aren't interrupted on every keystroke;
  - a separate polite region `<span class="ndvr-length-status screen-reader-text" role="status" aria-live="polite"></span>`, empty at first. The script writes to it only:
    - once, when the count first reaches the minimum: "Minimum length reached.";
    - on blur of the textarea while below the minimum: "12 of 30 characters." (`%1$d of %2$d characters.`);
    - debounced by 1 second, and only when the text would change, so repeated blurs or crossings back and forth don't repeat it;
  - on submit below the minimum, the server error "Please write at least 30 characters." in the form's existing message region (`.ndvr-form-message`, role status).
- No `minlength` attribute: browsers count UTF-16 units, which disagrees with the server count for emoji (see §9).
- Minimum 0: no hint, no counter, no data attribute.

## 4. Reuse map
- `ReviewRepository::create()` (single gate, ReviewRepository.php:91-93 is where content is trimmed).
- `Reviews\Sources::is_interactive()` (RR-00 F1).
- F6 `SettingsPage::fields()` registry.
- Form markup: `ReviewForm::render_fields()` (ReviewForm.php:286-289), `TestimonialForm::render()` (TestimonialForm.php:194), `templates/magic-landing.php` (:101-105).
- Scripts:
  - product form: handle `ndvr-reviews` → `assets/js/reviews.js`, localized object `ndvrReviews` (ReviewForm.php:189-205);
  - testimonial and landing: handle `ndvr-collect` → `assets/js/collect.js`, which has **no** localized object and reads data attributes from `.ndvr-collect` (collect.js:234-237).

## 5. Blast radius
Free:
- `create()` gains the check, so every interactive entry path is covered: ReviewForm (source `onsite`, ReviewForm.php:427), TestimonialForm (`form`, :281), Landing (`magic_link`, Landing.php:295, and `list_link` for list tokens, RR-09 §6.2).
- Imports (`import`: Csv.php:150, WooNative.php:74) are not interactive, so they're exempt.
- The three forms' markup and the two scripts (counter). Run `npm run build:assets`.
- RR-15 `ReviewRepository::update()` and RR-22 call the same length check.
- Theme override of `magic-landing.php` without the hint: no hint or counter shows; the server still enforces the minimum.
- RR-03 transparency: no new sentence. The rule is shown to the writer before submitting, applies to every rating alike, and doesn't change how a submitted review is handled.

Pro (paired tasks, Pro never edited by free):
- `Admin\ManualReviews` and `External\ExternalReviews` insert directly and never call `create()`, so they're unaffected. No Pro task.
- Pro `Importers\ProImporter` uses source `import` (ProImporter.php:31), so it's exempt. No Pro task.
- Pro `Moderation\Plus::validate()` (Plus.php:84-99) runs on `validate_review` after our check; no interaction.

## 6. Contract delta
- **Settings key** `min_review_length` (int, default 0, sanitize `absint` then clamp 0 to 500; card "Reviews and trust").
- **Filter** `ndv-reviews/min_review_length` (int `$min`, array `$data`), so a store can vary it per product.
- **Helper** `ReviewRepository::content_length( string $html ): int` (public static, see Measurement below) and `ReviewRepository::check_length( string $content, array $data ): true|WP_Error`.
- **Error code** `ndvr_too_short`.
- **Markup:** textarea attributes `data-ndvr-min-length="30"`, `data-ndvr-count-format="%1$d / %2$d"`, `data-ndvr-status-reached="Minimum length reached."`, `data-ndvr-status-format="%1$d of %2$d characters."` (translated, `esc_attr`); classes `.ndvr-length-hint`, `.ndvr-length-count`, `.ndvr-length-status` (reviews.css and collect.css, on tokens). collect.css already defines `.screen-reader-text`; reviews.css gains the same rule if it lacks it, so the status stays hidden on themes that don't define it.
- No new handle; both existing scripts gain the counter.

### Measurement (server authoritative)
`content_length()` runs on the kses'd content:
1. `wp_strip_all_tags()`;
2. `html_entity_decode( …, ENT_QUOTES, 'UTF-8' )`, so `&amp;` counts as one character;
3. collapse runs of whitespace (`preg_replace( '/\s+/u', ' ', … )`) and `trim()`, so padding with spaces doesn't count;
4. `mb_strlen( …, 'UTF-8' )`, counting Unicode code points.

The JS mirrors it: `Array.from( text.replace( /\s+/g, ' ' ).trim() ).length`. That also counts code points, so an emoji counts once in both. A joined emoji sequence (family, flag) counts as several code points in both. The two can differ only on rare whitespace characters; the server decides.

The check applies when `Sources::is_interactive( $data['source'] )`. It runs in `create()` right after the empty-content check (ReviewRepository.php:91-94), before ratings and before any insert.

## 7. Storage and upgrade
One settings key, registered through F6 (default 0), so old sites behave as before. No migration.

## 8. Security
- No new entry point. The check runs inside the existing nonce'd handlers via `create()`.
- The setting saves through the F6 handler (nonce, `Caps::manage()`, `absint`, clamp).
- The hint and counter strings are `esc_html`'d; the data attributes are `esc_attr`'d integers and a translated format string.

## 9. Privacy
No new personal data. The setting is store configuration, and nothing about the reviewer is stored. No readme change.

## 10. Performance and assets
- One `mb_strlen` per submission.
- A few lines of JS in each existing script. They run only when `data-ndvr-min-length` is present.
- No new requests.

## 11. Compatibility
- No-JS: none of the three forms submits without JS today (ReviewForm.php:108-132 blocks a native post; collect.js handles submit). The server check needs no JS.
- PHP 7.4 with mbstring: WooCommerce already requires it.
- WP 6.0, WC 8.0, HPOS and block checkout: not affected.
- Elementor: same WooCommerce form.
- Pro absent or present: same behaviour.

## 12. Acceptance criteria
1. With minimum 30, `create()` with source `onsite` and 10 characters returns `ndvr_too_short` with "Please write at least 30 characters.". The same holds through `ndvr_submit_review`, `ndvr_testimonial_submit` (TestimonialForm.php:26) and `ndvr_collect_submit` (each returns 400 with that message and stores no comment or attachment).
2. 30 emoji (code points outside the BMP) are accepted, and 29 are refused. 30 CJK characters are accepted.
3. "a" followed by 40 spaces and "b" counts as 3 characters and is refused.
4. `<strong>` tags and `&amp;` don't inflate the count: `<strong>Too short</strong> &amp;` counts as 11.
5. Importing a 5-character review through `Importers\Csv` succeeds.
6. With minimum 0, the rendered forms contain no `data-ndvr-min-length` and no `.ndvr-length-hint`, and a 1-character review saves.
7. With minimum 30, the rendered product form has `data-ndvr-min-length="30"`, a hint with "At least 30 characters." whose id matches the textarea's `aria-describedby`, a `.ndvr-length-count` with no `aria-live` attribute, and exactly one empty `.ndvr-length-status` with `role="status"` and `aria-live="polite"`. The textarea carries both `data-ndvr-status-*` strings. The same holds for the testimonial form and the landing page.
8. A `create()` with source `list_link` and 10 characters returns `ndvr_too_short`.
9. The `ndv-reviews/min_review_length` filter returning 50 raises the floor for that request.
10. Core flows pass.

## 13. Test plan
Use the RR-11 §13 harness.
1. Set the setting via the F6 save handler.
2. Call `create()` directly with fixtures (AC1 to AC5, AC8, AC9), then the three AJAX handlers (AC1).
3. Render the three forms and assert the markup (AC6, AC7).
4. JS counter and announcement timing (one announcement at the minimum, one on blur, none per keystroke): code review plus a manual screen-reader check in a browser, recorded in LOG. It's not an acceptance gate.
5. Run core flows.

## 14. Open questions
None.
