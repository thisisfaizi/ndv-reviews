# Code review: RR-11 (custom review questions)

Reviewer: independent code review, 2026-10-10. Read-only: no plugin code was edited, no QA harness was run and WordPress wasn't booted (the QA database is shared). The build reviewed is commit `fa803f8` ("RR-11: custom review questions (NDVR_API 8, DB 6)").

**Dirty working tree.** Uncommitted RR-12 work (minimum review length) touches these files:
- `Landing.php`, `ReviewForm.php`, `TestimonialForm.php`, `ReviewRepository.php`, `Plugin.php`
- `magic-landing.php`
- `reviews.js` and `collect.js`, plus the CSS

For those files, every file:line below comes from `git show fa803f8:<file>`. Every other file is unchanged since the commit, so the working-tree line numbers are the same.

**Files read.** Free plugin:
- New: `Reviews/ReviewFieldRepository.php`, `Reviews/ReviewFields.php`, `Forms/FieldRenderer.php`, `Admin/QuestionsPage.php`
- Changed: `Reviews/ReviewRepository.php`, `Reviews/ReviewQuery.php`, `Forms/ReviewForm.php`, `Forms/TestimonialForm.php`, `Collection/Landing.php`, `templates/magic-landing.php`, `Moderation/Page.php`, `Importers/Exporter.php`, `Importers/Csv.php`, `Privacy/Privacy.php`, `Uninstall.php`, `Installer.php`, `rosette-reviews.php`, `Plugin.php`, `Admin/DashboardPage.php`, `Support/Caps.php`, the three CSS files
- Context: `Reviews/Sources.php`, `Display/Renderer.php` (`ajax_list`, `render_form`), `assets/js/display.js`, `reviews.js`, `collect.js`, `Admin/ToolsPage.php`, `Admin/CriteriaPage.php`, `templates/review-item.php`, `.agents/qa/rr-11.php`, `.agents/qa/rr-00.php`
- Docs: the PRD (rev 3 plus build notes) and `CONTRACTS.md` "Review questions"

Pro plugin: `.agents/TASKS.md` (RR-11P-1/2), `Importers/ProImporter.php` (its `create()` call), `Admin/ManualReviews.php`, `Developer/RestApi.php`, plus a grep of every `review_query_args` and `paginate()` consumer.

**Reference sources.** WP 7.1.3 under `D:/.devcache/qa-site/wordpress/`:
- `wp-includes/formatting.php`: `_sanitize_text_fields` :5708, `esc_attr` :4776, `esc_textarea` :4801, `sanitize_title_with_dashes` → `utf8_uri_encode( $title, 200 )` :2295
- `class-wp-comment-query.php` :323, :491
- `comment-template.php` :2650, :2734

**Static checks:**
- `php -l` (PHP 8.3 CLI) is clean on all 18 changed PHP files, using the commit versions.
- `phpcs --standard=phpcs.xml.dist`, ignoring ValidHookName and PrefixAllGlobals, finds 0 RR-11 errors.
  - `Exporter.php:130` (`count()` in a `do…while` condition) is pre-existing. The same line is at :104 in `fa803f8^`.
  - The working-tree errors in `TestimonialForm.php` and `magic-landing.php` belong to RR-12. The committed versions are clean.
  - `Csv.php` keeps its pre-existing assignment-in-condition and slow-meta warnings.
- `phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 7.4-` is clean on the four new files and on Privacy, Exporter, Csv and Uninstall. The `...$this->answer_rows()` spread inside an array literal (Privacy.php:187) is PHP 7.4 syntax and gets integer-keyed rows, so 7.4 accepts it.

---

## Verdict

**CHANGES REQUESTED.** There are no blockers.

The core works:
- the sanitize/validate rules
- the pre-upload checks in all three handlers
- the per-product landing forms
- the admin nonce/capability order
- the upgrade guard
- escaping
- the privacy eraser
- the uninstall group

Four majors remain:
- **M1, cached pages.** A cached form that carries the `ndvr_answers_present` marker blocks every submission once a new required question is added.
- **M2, retyping.** Changing a question to Yes/No shows every earlier answer as "No".
- **M3, the Pro chips can't reach the storefront list.** Neither `display.js` nor `ajax_list()` can carry an `answers` filter, and nothing lets Pro add it. This has to be settled before `NDVR_API` 8 is published.
- **M4, filter keys.** The `_ndvr_ans_<id>` keys are never backfilled or cleared when `filterable` changes, so the Pro chips would miss every review written before the flag was set.

---

## What was checked and is correct

**Sanitize** (ReviewFieldRepository.php:391-419):
- Unknown ids and non-scalar values are dropped (:395-398).
- Values are trimmed and passed through `sanitize_text_field`, and empty values are dropped.
- **Choice** uses a strict `in_array` against the stored options.
- **Yes/No** is lower-cased and whitelisted to `yes|no`.
- **Text** uses `mb_substr( 0, 120 )` on code points. `mb_internal_encoding` defaults to UTF-8, and core polyfills `mb_substr` / `mb_strlen`.

**Option round trip with `&`, quotes and `<`.** Options are stored through `sanitize_textarea_field` and then per-line `sanitize_text_field` (QuestionsPage.php:95, Repository :185). Each form posts an option back through `esc_attr` → the browser → `wp_unslash` → `sanitize_text_field`. `esc_attr` doesn't double-encode existing entities (`_wp_specialchars`, default `double_encode` false). Traced cases:

| Option typed | Stored | Posted | After `sanitize_text_field` | Match |
|---|---|---|---|---|
| `Fish & Chips` | `Fish & Chips` | `Fish & Chips` | same | yes |
| `"Petite"` / `O'Neil` | unchanged | unchanged | same | yes |
| `< 1 month` | `&lt; 1 month` (`wp_pre_kses_less_than`) | `< 1 month` | `&lt; 1 month` | yes |
| `100%ab` | `100` (octet stripped) | `100` | `100` | yes |
| literal `&amp;` typed by the merchant | `&amp;` | `&` | `&` | **no** (edge case; see m9) |

**Validate** (:430-445):
- It returns early unless `$present` is set and the source is interactive (`Sources::is_interactive`: onsite, form, magic_link, list_link).
- It walks `get_active()`, which is the same capped slice the forms render, so a required question past the cap is neither shown nor required.
- The error code is `ndvr_missing_answer`, with the text "{label} needs an answer."

**Required answers only for interactive sources:**
- The CSV import passes `source` `import` and `answers_present` false (Csv.php:174, :176).
- Pro `ProImporter` uses `import` (ProImporter.php:47, :582).
- Pro `ManualReviews` never calls `create()`.

No caller in the tree uses an interactive source without passing answers (but see m10).

**Answers marker.** A form with no active questions prints no marker (FieldRenderer.php:202-204), so a question added after the page was rendered is never required on a page that showed none. An old `magic-landing.php` override sends no marker and skips the check (AC9).

**Pre-checks before uploads.** All three handlers run `sanitize()` and `validate()` after `wp_unslash( $_POST )` and the nonce check, before `Upload::handle()`:

| Handler | Unslash | Check | Upload |
|---|---|---|---|
| ReviewForm | :532 | :573-579 | :584 |
| TestimonialForm | :241 | :275-281 | :285 |
| Landing | :284 | :339-345 | :359 |

`create()` repeats the check with the same source and the same `$present` (ReviewRepository.php:131-136), so the two can't disagree. No new orphan-attachment path is introduced.

**Landing forms** (magic-landing.php:67, :112-115):
- Each product has its own `<form>`, and the renderer prefix is `p{pid}-`, so ids `p{pid}-f{fid}-o{n}` are unique.
- Radio names `ndvr_answers[<id>]` group per form.
- `collect.js` builds `new FormData( form )` per form (collect.js:79), so the checked radio, the text input and the hidden marker are all sent.
- The product form: `reviews.js:190` does the same.
- The standalone form uses `wp_unique_id( 'ndvr-t' )` (TestimonialForm.php:164).

**Do client-side `required` attributes hide server errors?** No:
- Both scripts listen for `submit`, so native validation runs first and blocks an empty required group on a form without `novalidate`.
- On WP 6.0–6.8.1, html5 themes get `novalidate` on `comment_form`. On WP ≥ 6.8.2 `novalidate` defaults to false (comment-template.php:2650, :2734). Where native validation doesn't run, the server's 400 message is shown through `textContent` (reviews.js `setMessage`, collect.js `fail`). Both paths are safe.

**Admin screen** (QuestionsPage.php):
- **Nonce before capability.** Routing reads only `isset`. Then `check_admin_referer( 'ndvr_review_fields' )` runs (:83), then `current_user_can( Caps::manage( 'questions' ) )` (:84), before any work. `render()` re-checks the capability.
- **Escaping.** Labels and options use `esc_html`, attributes use `esc_attr`, the textarea uses `esc_textarea`, the confirm text uses `esc_js`, and notices use `esc_html`.
- **Sanitising.** Input is unslashed, then the label uses `sanitize_text_field`, the type `sanitize_key`, the options `sanitize_textarea_field`, and ids `absint`.
- **POST handling** is the same as CriteriaPage: no PRG redirect, and notices are kept on the instance.

**Cap logic:**
- `insert()` refuses an active question when `count_active() >= max_active()` (:238).
- `update()` checks the cap only on an inactive → active change (:294). Editing an already active question while over the cap is allowed, and deactivating is always allowed.
- Reactivating through Toggle is refused with "You can have 2 active questions." (`_n`, `number_format_i18n`).
- Imported questions are inactive and don't count.
- **Pro raise.** `ndv-reviews/max_review_fields` is read on every call and clamped at ≥ 0.

**Reorder.** `move()` (:319-339) swaps within the `get_all()` order (position, then id) and rewrites positions 0..n-1. Gaps left by deletes are healed, and ends return false. Buttons are real `<button>`s with an `aria-label`, disabled at the ends.

**Slugs:**
- A slug is set once on insert (`sanitize_title` of the label, or of the CSV slug) and made unique with `-2`, `-3` (:535-545).
- `update()` never changes it, so renaming a question keeps its CSV column. The UNIQUE key backs this up.
- Deleting "Fit" frees `fit`, so a later "Fit" reuses the column. That is the right behaviour for CSV round trips.

**Upgrade and read guard:**
- `V_FIELDS` is 6 and `NDVR_DB_VERSION` is '6'.
- The table is in `schema()` and in `table_names()`, so `missing_tables()` (Installer.php:359-374) probes it, and the version isn't stored if dbDelta failed.
- No data step is needed.
- `ready()` → `Installer::is_current( V_FIELDS )` gates `get_all()`, `insert()` and `ensure_imported()`, so `get_active`, `find`, `sanitize`, `validate`, `answers_for_view`, the Exporter columns and the paginate filter are all empty before the upgrade.
- The Csv importer skips `q_` columns when not ready (Csv.php:98).
- The admin screen shows a "finishing a database update" notice.
- **Schema.** `slug varchar(191)` with a UNIQUE key fits the utf8mb4 767-byte index limit. `options`, `type`, `status`, `position` and `required` are non-reserved in MySQL.

**Display:**
- `render_card` sits on `review_body_after` (review-item.php:132): after `.ndvr-review-body` (:124) and before `.ndvr-review-criteria` (:144).
- The `review_field_answers` filter runs first. `show_answers` runs only when there are answers. Label and value use `esc_html`.
- Answers to deleted questions are skipped (`answers_for_view` walks `get_all()`). Deactivated questions still show. Yes/No is translated at render time.

**No N+1 on lists:**
- `paginate()` uses `WP_Comment_Query` with the default `update_comment_meta_cache` true, which calls `wp_lazyload_comment_meta( $ids )` (class-wp-comment-query.php:323, :491). The first `get_comment_meta` primes the whole page in one query.
- The Exporter's `get_comments()` behaves the same way.
- Definitions are read once per request (the instance property on the container singleton, then `wp_cache`).

**Admin edit:**
- The row is inside the Edit table, before Photos (Page.php:623). The save fires after the title update (:355), inside the existing `check_admin_referer( 'ndvr_edit_review' )` and `moderate_comments` check.
- Without the marker, nothing is touched (ReviewFields.php:158).
- **Criteria/rating redirect.** The rating-error redirect at :331-339 calls `exit` (`redirect_clean`, :483-490) before content, title or answers are written. A failed save is all-or-nothing, which matches how content and title already behave.
- **The "untouched old choice survives" rule** (:165-171) keeps a posted value that equals the stored one when `sanitize()` dropped it. This covers AC14 (see m3 for one entity case).

**CSV:**
- **Export alignment.** The base yield keys (Exporter.php:109-123) follow `columns()` order, and `+ $extra` adds the `q_` keys in `question_columns()` order, which is the same order used in the header.
- Slugs are unique and no base column starts with `q_`, so no key can collide.
- The JSON export carries the same `q_` keys.
- Exported answers go through the spreadsheet formula guard, and `unguard_cell()` reverses it on import.
- **Import:**
  - The BOM is stripped before the header map (Csv.php:90), and names are lower-cased and trimmed.
  - `ensure_imported()` reuses an existing slug of any type and status, otherwise it inserts an inactive Short text question.
  - Values are then cleaned against the question's real type.
  - Percent-encoded (non-Latin) slugs survive `sanitize_title`.
  - A second run is deduplicated by the existing `_ndvr_import_hash` and `exists()` check, and its `ensure_imported()` calls find the questions the first run created.

**Privacy:**
- The exporter adds "Question: {label}" rows (`answer_rows`, Privacy.php:113-124; the spread at :187).
- The eraser deletes `_ndvr_answers` and runs a prepared `LIKE` with `$wpdb->esc_like( '_ndvr_ans_' ) . '%'` (:469-471), then clears the comment-meta cache.
- **LIKE escaping matters here.** Unescaped, `_` is a wildcard, and `_ndvr_ans_%` would also match `_ndvr_answers` (the `_` would match the `w`). The same holds in `save_answers()` (:466), where an unescaped pattern would delete the array it had just written. Both are escaped.

**Uninstall:**
- `comment_meta_prefixes` (Uninstall.php:79-81) is swept at :188-193 with the same escaped LIKE. The dry run logs it and the real run deletes it.
- The table is dropped through `table_names()`, and `_ndvr_answers` is in `comment_meta`.
- `Deactivator` reads only `scheduler_hooks`. rr-00's AC2 iterates every registry group generically, so the new group is covered by its dry-run check.

**Contract and API:**
- `NDVR_API` 8 and `V_FIELDS` 6 match `CONTRACTS.md`.
- All four actions and three filters exist with the documented arguments.
- The `paginate()` `answers` arg applies only to filterable fields and is used by both the page query and the count query.
- The view-model key is `answers`.
- Pro REST and the Elementor grid get answers with no Pro change.

**i18n and accessibility:**
- Every string uses `rosette-reviews`, and every `sprintf` has a translator comment.
- `_n` is used for the cap notice.
- Choice and Yes/No render as `fieldset`/`legend`. Text has `<label for>`.
- The visible `*` is `aria-hidden`, with `required` on the inputs.
- The admin edit controls are wrapped in `<label>`.

**Native post bypass.** The product form's stars are `ndvr_criteria[]`, not WooCommerce's `rating`, so a native no-JS post from our form is stopped by `block_unrated_native_post()`. A hand-crafted native POST with `rating` skips our pipeline entirely. That is pre-existing, and PRD §8 says the marker is not a security control.

---

## Findings

### MAJOR

**M1. A cached form with the marker blocks every submission once a required question is added.** Locations: FieldRenderer.php:250, ReviewFieldRepository.php:430-445, ReviewForm.php:573-579, TestimonialForm.php:275-281.

- The marker is a bare `ndvr_answers_present=1`. It says the form rendered *some* questions, not *which* ones.
- `validate()` then requires every active required question.

Failure scenario:
1. The store has "Fit" active.
2. A full-page cache (WP Rocket, LiteSpeed, Cloudflare APO and similar) holds the product page or an `[ndvr-form]` page, with Fit and the marker.
3. The merchant adds a required "Skin type", or makes an existing question required, or activates one.
4. Every shopper served the cached page gets "Skin type needs an answer." and has no field to fill in. This lasts until the cache is purged.

The same happens in a tab left open across the change.

Scope:
- A cached page without the marker (no questions when it was cached) is safe.
- Landing pages are per-token and so are rarely cached.
- The nonce on cached pages limits the window to the nonce lifetime, but that is still hours.

Fix: make the marker carry the rendered ids. For example, `ndvr_answers_present` = `"12,15"` (written by `FieldRenderer::render`), or one hidden `ndvr_answers_shown[]` per question. `validate()` should then require only rendered ∩ active ∩ required.

Keep a bare `1` meaning "all active", for old overrides. Pass the id list through `create()` as `answers_present` (array|bool). This needs a CONTRACTS note.

Add a harness case: render, add a second required question, then submit the first render's fields. Expect success.

**M2. Changing a question's type rewrites how its old answers read. Changing it to Yes/No shows them all as "No".** Locations: ReviewFieldRepository.php:280-290 (`update()` accepts any type change and leaves answers alone) and :495-501 (`display_value`).

- `display_value()` returns "Yes" for `'yes'` and "No" for **any other value**.
- PRD §6 tells merchants that imported Short text questions "can be renamed, **retyped** or activated later". So retyping is a documented path, not a corner case.

Failure scenario:
1. A Short text "Used for" holds "Daily walks". Or a Choice "Fit" holds "True to size".
2. The merchant changes it to Yes or no.
3. Every existing card, the privacy export and the edit screen's text now say "No", attributed to the customer.
4. The edit screen's select shows "Daily walks" as an extra selected option (ReviewFields.php:131-133) while the card says "No".

Matrix of transitions. Stored values are never rewritten:

| From → To | Card | Edit screen | CSV export, then re-import into the same site |
|---|---|---|---|
| choice → text | option text (fine) | text input (fine) | kept |
| text → choice | free text shown, even though it isn't an option | appended as an extra option, survives an untouched save | **dropped** on re-import (not an option) |
| choice → choice (options edited, AC14) | old text (fine) | appended, survives | old values **dropped** on re-import |
| text or choice → yesno | **"No" for every value** | appended raw value | exported raw; re-import drops non-yes/no |
| yesno → text or choice | raw `yes` / `no`, lower-case and untranslated | raw | kept (text) or dropped (choice) |

Fix:
- **Minimum:** in `display_value()`, map only `yes` / `no` and return any other value unchanged.
- **Better:**
  - show the "Yes"/"No" display for yes/no values on any type (or keep them raw);
  - on a type change of a question that has answers, show an admin warning: "N reviews have answers to this question; they keep their text";
  - or refuse →yesno when stored answers are neither yes nor no.

Add harness cases for text → yesno and yesno → text.

**M3. The Pro filter chips can't reach the storefront list.** Locations: Renderer.php:417-452 (`ajax_list`), display.js:24-36 and :43-62 (`getState`, `fetchList`).

- Pro RR-11P-1 (Pro TASKS row 26) is "Filter chips by review-question answer … `paginate( answers )` + `_ndvr_ans_<id>`, registered only at free `NDVR_API >= 8`".
- `ajax_list()` passes a fixed set of keys to `paginate()` and never reads `answers`.
- `ndv-reviews/review_query_args` gets `$args` that are already normalised, after the `answers` meta query was built.
- `display.js` builds its `FormData` from a fixed `state` and has no `CustomEvent` or `dispatchEvent` hook (0 matches at fa803f8).
- The `filtered` flag (:451), which picks the empty-state text, ignores answers too.

So Pro's only options are:
- its own list endpoint and script, duplicating the free renderer;
- patching `fetch`;
- reading `$_POST` inside `review_query_args` and adding its own `meta_query`, which bypasses the `answers` contract and still needs a way to get chip state into the request.

Shipping `NDVR_API` 8 freezes this API without it. Fix, in free:
- `ajax_list` reads `answers` (`array_map( 'sanitize_text_field', … )`, `absint` keys) into `paginate()` and into `filtered`.
- `display.js` keeps `state.answers` and appends `answers[<id>]` entries.
- A delegated click handler on `[data-ndvr-answer-field][data-value]` chips toggles the state, the same way topic pills do (display.js:150-158).
- Or, at minimum, a `ndvr:list-request` event that hands listeners the `FormData` before `fetch`.
- Document the chip markup in CONTRACTS.

**M4. Filter keys are never synced when `filterable` changes (or on delete).** Locations: ReviewFieldRepository.php:275-310 (`update()`), :347-353 (`delete()`), :454-474 (`save_answers()`).

- `_ndvr_ans_<id>` is written only by `save_answers()`, which runs at `create()` time and on an admin edit save.
- When Pro turns on "Show as filter" (`update( $id, [ 'filterable' => true ] )` from `review_fields_saved`), no existing review gets the key.

Failure scenario:
1. A store with 400 reviews answering "Fit" turns on the filter.
2. The "True to size" chip returns only reviews written after that moment, and the count reads 0 at first.
3. Turning the flag off leaves the keys behind. Turning it on again later gives a mix of old and missing keys.
4. `delete()` leaves `_ndvr_ans_<id>` rows that nothing will ever read.

That is meta bloat, and with M5 it is wrong data.

Fix, and decide which side owns it (CONTRACTS and Pro TASKS must say):
- **Free (preferred).** When `filterable` flips in `update()`, run a batched backfill or clear, using Action Scheduler for large stores. Backfill reads `_ndvr_answers` for comments that have it and writes `_ndvr_ans_<id>`; clear is a `DELETE … WHERE meta_key = '_ndvr_ans_<id>'`. `delete()` should also delete `_ndvr_ans_<id>`.
- **Or** expose `reindex_filter_keys( int $field_id )` for Pro to call, and say so in RR-11P-1.

Related Pro-facing defect. The Add card's default `$field` (QuestionsPage.php:270-276), passed to `ndv-reviews/review_field_form_after`, has no `filterable`, `slug` or `status` key. A Pro listener that reads `$field['filterable']` raises an undefined-index warning on PHP 8 for every new question. Add `'filterable' => false, 'slug' => '', 'status' => 'active'` to the default.

### MINOR

**m1. A deleted question's id can come back and adopt its answers.** *Conditional; the engine behaviour is from documentation, not tested here.* Location: ReviewFieldRepository.php:347-353.

- `delete()` removes the row but keeps the answers under the numeric id in `_ndvr_answers`, as PRD §3 intends.
- InnoDB on **MySQL < 8.0** resets `AUTO_INCREMENT` to `MAX(id)+1` at server restart. MySQL 8.0+ and MariaDB ≥ 10.2.4 persist the counter. The SQLite driver is unverified.

Scenario on MySQL 5.7:
1. Delete the newest question, "Skin type" (id 7).
2. MySQL restarts.
3. Add "Allergies". It gets id 7.
4. Old "Oily" answers now show publicly as "Allergies: Oily", and also in exports and on the edit screen.

Fix: soft delete. Set `status = 'deleted'`, exclude it from `get_all()` (or give it its own `get_defined()`), and so never reuse ids. Alternatively, purge the id from every `_ndvr_answers` on delete, but that contradicts "answers stay stored".

**m2. Over-cap state is shown as normal.** Locations: QuestionsPage.php:183-191, :214, :255-266; Repository :113.

After Pro is removed (or the filter lowered) with 4 active questions:
- The table shows four "Active: Yes".
- The description reads "4 of 2 questions active".
- Only the first two by position render and are required. Up/Down silently changes which two are live.

Fix: mark the active rows past `max_active()` as "Active (not shown: over the limit)", and word the notice for that case.

**m3. The admin edit save loses data in two edge cases.** Locations: ReviewFields.php:156-173, ReviewFieldRepository.php:454-464.
- **(a) Hidden answers are wiped.** `save_answers()` replaces the whole array with what was posted. Answers to *deleted* questions aren't rendered (`render_edit_row` walks `get_all()`), so the first admin save of a review erases them. That makes "answers stay stored but stop showing" true only until someone edits the review. Fix: merge the posted ids over `stored()`, and drop only ids that were rendered and posted empty.
- **(b) The untouched rule compares raw input with stored text.** `(string) $value === $stored[ $id ]` compares the browser-decoded value with the stored `sanitize_text_field` form. A removed option stored as `&lt; 1 month` posts back as `< 1 month`, fails both checks and is dropped. Fix: compare `trim( sanitize_text_field( $value ) )`.

**m4. The privacy export omits answers that are still stored.** Location: Privacy.php:113-124. `answer_rows()` uses `answers_for_view()`, which skips deleted questions, but the eraser deletes those values, so they are personal data. That is a GDPR Art. 15 completeness gap. Fix: export every `stored()` entry, using the label when the question exists and "Question (deleted) #{id}" otherwise.

**m5. Slugs: possible overflow, and never shown to the merchant.** Location: ReviewFieldRepository.php:241, :535-545.
- **Overflow.** `sanitize_title()` percent-encodes non-Latin labels up to 200 bytes (`utf8_uri_encode( $title, 200 )`, formatting.php:2295), and `unique_slug()` can append `-2`. Both exceed `slug varchar(191)`. In strict SQL mode the insert fails with only "Could not save the question." In non-strict mode the value is truncated, which can collide on the UNIQUE key.
  - Fix: cut the base slug to about 180 bytes at a `%xx` boundary before making it unique.
- **Not shown.** The screen's help text (QuestionsPage.php:308) says CSV columns are "q_ followed by the question's slug", but the slug appears nowhere in the UI. Show it, or the column name, in the table.

**m6. CSV import feedback.** Locations: Csv.php:98-111, ToolsPage.php:176-183.
- "Column {name} was skipped." goes into `errors`, but ToolsPage shows `errors` only when nothing was imported **and** nothing was skipped. The build-note promise "reported in the import result" never reaches the merchant.
- Values dropped against a Choice or Yes/No question (AC10's second half) are silent: there's no count.
- `ensure_imported()` runs before the rows, so a file whose rows are all skipped still creates inactive questions.

Fix:
- Append the `errors` to the success notice.
- Add a reason such as "{n} answers not among the question's options".
- Optionally create the questions lazily, on the first row that imports.

**m7. Caching edges.** Location: ReviewFieldRepository.php:80-95, :552-555; Uninstall.php.
- **No expiry and a read/write race.** `wp_cache_set` has no expiry. With a persistent object cache on several web nodes, request A reads the old rows, B writes and deletes the key, and A then sets the old rows. The stale definitions stay until the next write.
- **An empty array is cached.** When the table is missing while the version says it is current (a failed repair), `get_results()` errors, returns `[]`, and that is cached indefinitely.
- **Uninstall.** Uninstall drops the table without `wp_cache_delete( 'review_fields', 'ndvr' )`. A reinstall on a persistent cache then serves the deleted questions.
- Fix:
  - use a `last_changed` key (core's pattern) or a modest expiry;
  - don't cache when `$wpdb->last_error` is set;
  - delete the key in `Uninstall::run()`.
- **Long-running processes (speculation).** A long Action Scheduler or WP-CLI process keeps `$this->all` for its lifetime. No free background job reads definitions today: ProImporter passes no answers, and BulkCampaign renders no forms. Impact is nil for now. Note it for future background users, or have `flush()` listen to a `ndv-reviews/review_fields_changed` action.

**m8. The product form uses a fixed `ndvr-` id prefix.** Location: ReviewForm.php:453. With two product forms on one page (two `[ndvr-reviews]` instances, or an Elementor template plus the tab; `Renderer::claim_ids()` expects several instances), `ndvr-f{id}-o{n}` repeats. Clicking an option's label in the second form then checks the radio in the **first** form, and the second form fails "Fit needs an answer.". The form's own ids (`ndvr-review-form`, `ndvr-title`) already repeat, so this is pre-existing in kind. Fix: `wp_unique_id( 'ndvr-' ) . '-'`, the same as TestimonialForm.

**m9. Entities leak into text, and other cosmetics:**
- `sanitize_text_field` turns a lone `<` into `&lt;`. That shows literally:
  - in the options textarea (`esc_textarea` double-encodes, :290);
  - in the "{label} needs an answer." message (set as `textContent`);
  - in the privacy export label;
  - in CSV values.
- `mb_substr( 0, 120 )` after that conversion can cut an `&lt;` to `&l`.
- A merchant who types a literal entity such as `&amp;` gets an option that can never match (table above), which makes a required question impossible to answer.
- Fix: store options and answers decoded (`wp_specialchars_decode` after sanitising) and escape on output, or reject `&…;` sequences in options.

**m10. `answers_present` defaults to true.** Location: ReviewRepository.php:133.
- Every internal caller passes it explicitly.
- Any third-party `create()` caller using an interactive source, or a source added via `ndv-reviews/interactive_sources`, without answers starts failing with `ndvr_missing_answer` as soon as a merchant adds a required question. That is a silent behaviour change under API 8.
- The contract says "default true", so either flip the default to false (no internal caller changes), or add an explicit "breaking for add-on callers" line under API 8 in CONTRACTS and the readme changelog.

**m11. `show_answers` and `review_field_answers` apply to the card only.** The view-model `answers`, Pro REST `GET /reviews` and the CSV/JSON export always include every answer, deactivated ones too. That is defensible, but document it in CONTRACTS next to the filters, so "hide answers" isn't mistaken for "don't publish them".

**m12. Multilingual (RR-08).**
- Question labels and options aren't registered with `Multilingual::STRINGS`, so the landing page, rendered in the order's language, shows them in the site language.
- If translation is added later, Choice answers must still store the site-language option text, or sanitize must map the translations back. Otherwise answers won't match.
- Log this for RR-08. It is not a code defect against this PRD.

**m13. Nits:**
- The options textarea always shows; PRD §3 says "shown for Choice". Hide it with a few lines of admin JS, or a `<details>`.
- `Caps::manage()`'s filter docblock (Caps.php:29-30) still lists the old contexts.
- CSS `margin-right` on `.ndvr-question label` and the radio should be `margin-inline-end` for RTL.
- `esc_html_e( '—' )` makes a dash translatable.
- The Edit link's `href` is spread over several lines by phpcbf. Browsers trim it, but it's noisy.
- The TestimonialForm recaptcha attribute was reflowed into multi-line PHP. The output is fine.

---

## Harness gaps (`.agents/qa/rr-11.php`, 34/34)

1. **The upload pre-check is untested.** AC5 compares the attachment count, but the harness never puts a file in `$_FILES` (no `_FILES` in rr-11.php), so "no orphan attachment" holds trivially. Add a real temp image in `$_FILES['ndvr_photos']` for each of the three handlers.
2. **The TestimonialForm path is never driven.** There is neither markup (AC4) nor a submission, though test plan §13 step 4 says "all three AJAX handlers". The landing page's required-failure path (marker present, Fit missing → 400 and nothing stored) isn't tested either.
3. **AC10 imports a hand-written CSV.** The Exporter's `stream_csv()` output isn't used, so the header/row alignment, the formula guard and unguard round trip and a BOM-prefixed header are untested. Capture `stream_csv()` with an output buffer (throw on `exit` with a `wp_die` handler, or factor the writer), then import that file.
4. **Cap edges:**
   - Toggle reactivation at the cap is untested (no `'toggle'` in the harness).
   - The over-cap slice after lowering `max_review_fields` is untested.
   - `move()` up/down is untested.
5. **Yes/No.** No form submission or display of a yes/no answer, and no translated "Yes"/"No" check.
6. **Option round trips** with `&`, quotes and `<` (the table above), and a label containing `<script>` and quotes rendered on the card, the form and the admin.
7. **Type-change matrix** (M2) and **filterable toggle backfill** (M4). The harness sets `filterable` and then submits a new review, so it never checks reviews written before the toggle.
8. **The M1 case:** render, add a required question, then submit the old render's fields.
9. **AC11:** the "untouched old choice survives" rule (an option removed, then the review saved without touching it) isn't asserted, and neither is the m3(a) wipe.
10. **Uninstall:** rr-11 has no real run for the `_ndvr_ans_` prefix group. rr-00's dry run covers only the log line.
11. **The display filters** `show_answers` and `review_field_answers` are untested.
12. **Persistent object cache:** not testable on the QA site, which has no object cache. At least assert that `flush()` deletes the `wp_cache` key, and that a second repository instance sees a write.

---

## Required before approval

| # | Item | Blocks |
|---|---|---|
| M1 | The marker carries the rendered question ids; `validate()` requires only rendered ∩ active ∩ required. CONTRACTS note for `answers_present`. Harness case 8. | approval |
| M2 | `display_value()` maps only `yes`/`no`; warn on (or refuse) type changes of answered questions. Harness case 7 (types). | approval |
| M3 | Storefront path for `answers`: `ajax_list` reads it (and counts it in `filtered`); `display.js` state plus chip handler, or a request event. Chip markup in CONTRACTS. | approval (before `NDVR_API` 8 ships) |
| M4 | Decide the owner and implement the `_ndvr_ans_<id>` backfill and clear on a `filterable` flip, plus cleanup on delete. Add the missing default keys to `review_field_form_after`'s `$field`. Update Pro TASKS RR-11P-1. Harness case 7 (filterable). | approval |
| m3 | Merge admin-edit answers over the stored ones; compare the sanitised value in the untouched rule. | approval (cheap) |
| m4 | The privacy export includes answers to deleted questions. | approval (cheap) |
| m6 | Show the import `errors` (skipped columns) in the Tools notice. | approval (cheap) |
| m10 | Either default `answers_present` to false, or document the API 8 behaviour change. | approval (cheap) |
| Harness | Cases 1, 2, 3, 4 and 9 above. | approval |

Recommended but not required: m1 (soft delete; cheap, and removes a latent misattribution on MySQL 5.7), m2, m5, m7, m8, m9, m11, m12 (RR-08 log) and m13.

**Verdict: CHANGES REQUESTED**
