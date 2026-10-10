# RR-11: Custom questions on the review form

Status: built, in review (rev 3) · Plan: F (2 active questions) + P (higher cap, filter chips) · Inherits PRD-00 + RR-00 · **Schema `Installer::V_FIELDS`** (planned v6; provisional per PRD-00 §4, the number is taken at merge) · Raises `NDVR_API` to **7** (RR-00 F3b; planned build order, final at merge)

Review findings applied: round 2 RR-11 item 1 (unknown `q_<slug>` columns).

Build notes (2026-10-10):
- **Numbers at merge:** `Installer::V_FIELDS` = **6** (`NDVR_DB_VERSION` 6). **`NDVR_API` 8**: 7 went to the RR-10 review (list campaign cancel and counts), so RR-15's planned 8 becomes 9.
- **Export columns:** one `q_<slug>` column per defined question, active or not, in position order, rather than only the questions that have a stored answer. The CSV is streamed with a fixed header, so the set is known before the first row. Questions with no answers export an empty column, and a deleted question exports nothing (its answers no longer show either).
- **Skipped columns:** an import column `q_` whose slug sanitises to empty is reported in the import's `errors` list ("Column {name} was skipped.").
- **Uninstall:** the registry gains a `comment_meta_prefixes` group (`_ndvr_ans_`), as §6 allowed.
- **Hooks:** the card renderer, moderation row and moderation save live in `Reviews\ReviewFields` (service `review_field_hooks`). The repository is `Reviews\ReviewFieldRepository` (service `review_fields`). `answers_for_view()` gives Yes/No in the reader's language, and the stored value stays `yes`/`no`.
- **Admin edit:** a choice answer that is no longer among the options stays selectable and survives a save untouched (§3, AC14).

## 1. Problem and who it's for
Shoppers want context beyond stars: "How does it fit?", "What's your skin type?", "How long have you used it?". Merchants in apparel, beauty and home goods ask for it. CusRev (free, 2 questions), Yotpo, Judge.me and WiserReview offer custom questions. Our "form field builder" is on the Not-built list. This PRD covers the core need without a full form builder.

## 2. Scope / non-goals
In scope:
- Merchant-defined questions on every customer review form: product page form (`Forms\ReviewForm`), `[ndvr-testimonial]`/`[ndvr-form]` (`Forms\TestimonialForm`), and the email landing page (`Collection\Landing` + `templates/magic-landing.php`).
- Three types: **Choice** (one of 2 to 6 options), **Short text** (up to 120 characters), **Yes/No**.
- Optional or required per question. Required is enforced only for interactive sources (RR-00 F1).
- Answers show on the review card ("Fit: True to size").
- Answers editable on the admin review edit screen, exported and imported as CSV columns `q_<slug>`.
- Free: 2 active questions. Pro raises the cap through a filter and builds "filter by answer" chips (paired Pro task).

Non-goals: per-category questions (could reuse RR-20 scopes later), rating-type questions (criteria do that), conditional logic, file fields, questions on Pro Manual Reviews (it bypasses `create()`; see §5).

## 3. User experience
**Admin, new submenu "Review Questions"** (slug `ndv-reviews-questions`), placed after Rating Criteria in `DashboardPage::order_submenu()` (DashboardPage.php:111-133, add the slug after `ndv-reviews-criteria`).
- Table: Question, Type, Options, Required, Active, with Up/Down buttons (real `<button>`s) to reorder. Drag is not needed.
- "Add a question" card, modelled on Rating Criteria: Question (text), Type (select: "Choice", "Short text", "Yes or no"), Options (one per line, shown for Choice), "Required" checkbox.
- Edit opens the same card prefilled. Delete asks "Delete this question? Its answers stop showing on reviews."
- When the cap is reached: "2 of 2 questions active. Deactivate one to add another." Adding or activating a third returns the notice "You can have 2 active questions."

**Forms.** Questions render after "Your review" and before the photo field, in position order:
- Choice: `<fieldset><legend>Fit</legend>` with one radio per option.
- Yes/No: a fieldset with radios "Yes" and "No".
- Short text: `<label>` + `<input type="text" maxlength="120">`.
- Required: `required` on the inputs and a visible `*`; the server repeats the check. Error: "{Question} needs an answer." (for example "Fit needs an answer.").

**Review card.** Answers render through the F5 hook `ndv-reviews/review_body_after`, so they sit after the review text and before the criteria pills:
`<dl class="ndvr-answers"><div><dt>Fit</dt><dd>True to size</dd></div>…</dl>`
- Answers to deleted questions don't show.
- Answers to deactivated questions still show (they're the customer's words); `ndv-reviews/show_answers` can hide them.

**Admin review edit** (`Moderation\Page::render_edit`, Page.php:462-557): a row "Answers" with one control per question that is active or already answered.

## 4. Reuse map
- Storage pattern: `Reviews\CriteriaRepository` (CRUD, cap, position, status). Questions get their own table so criteria code doesn't fork.
- Admin UI pattern: `Admin\CriteriaPage` (nonce'd POST, `push_result` notices).
- `Reviews\ReviewRepository::create()` (ReviewRepository.php:81-229): single validation point.
- `Reviews\ReviewQuery::to_view()` (ReviewQuery.php:240-265): new `answers` key.
- `Reviews\Sources::is_interactive()` (RR-00 F1).
- F5 `review_body_after`; F2 uninstall registry; F3 `Installer::is_current()`.
- `Importers\Exporter::columns()` (Exporter.php:45) and `Importers\Csv` (Csv.php:85-160).
- `Privacy\Privacy::export()/erase()` (Privacy.php:135-333).

## 5. Blast radius
Free:
- `ReviewRepository::create()`: sanitizes `answers` for every source, checks required for interactive sources before insert, stores meta after insert.
- `ReviewForm::render_fields()` (ReviewForm.php:244-343) and `handle_submit()` (:350-449): render fields, pre-check answers before the upload at :404-412.
- `TestimonialForm::render()` (TestimonialForm.php:161-218) and `handle_submit()` (:225-298), same.
- `Landing::maybe_render()` passes `review_fields`; `Landing::handle_submit()` (Landing.php:212-324) reads `ndvr_answers`.
- `templates/magic-landing.php`: one `<form>` per product (magic-landing.php:63) with flat field names, so answers are `ndvr_answers[<field_id>]` inside each form, with element ids `p{product_id}-f{field_id}-o{n}`. No nested names.
- `Moderation\Page::render_edit()` / `save_edit()` (Page.php:241-301): answers row and save. This PRD adds two reusable actions there (RR-15 and RR-22 reuse them; whichever ships first adds them): `ndv-reviews/moderation_edit_fields` (WP_Comment) before the Photos row (~Page.php:535) and `ndv-reviews/moderation_edit_save` (int `$comment_id`) after the title update (Page.php:274).
- `Exporter` / `Csv`: `q_<slug>` columns. On import, a `q_<slug>` column with no matching question creates an **inactive** Short text question first (§6), so answers are never lost and nothing new appears on forms.
- `Privacy`: export and erase.
- `DashboardPage::order_submenu()`: new slug.
- Theme overrides: an old `review-item.php` without `review_body_after` shows no answers (still renders). An old `magic-landing.php` without the field renderer submits no `ndvr_answers_present` marker, so required checks are skipped for those submissions and nothing breaks.

Pro (never edited by free; paired tasks in Pro TASKS):
- **Pro task RR-11P-1:** filter chips by answer (choice and yes/no questions marked "Show as filter"), using the `answers` arg of `ReviewQuery::paginate()` and the `_ndvr_ans_<id>` meta. Adds the "Show as filter" checkbox via `ndv-reviews/review_field_form_after` and saves it via `ndv-reviews/review_fields_saved`.
- **Pro task RR-11P-2:** raise the cap through `ndv-reviews/max_review_fields`.
- Pro REST `GET /reviews` returns `paginate()` items as-is (RestApi.php:145-157), so `answers` appears there with no Pro change. Note it in Pro docs.
- Pro Elementor `GridRenderer` renders `review-item.php` (GridRenderer.php:84-87), so answers show in its default layout. A part widget `ndvr-part-answers` is a Pro follow-up, not in scope.
- Pro `Admin\ManualReviews` inserts with `wp_insert_comment` (ManualReviews.php:166) and never calls `create()`: no answers on manual reviews. Recorded as a known gap.

## 6. Contract delta
- **Table** `ndvr_review_fields` (`Installer::V_FIELDS`, provisional v6): `id`, `label varchar(191)`, `slug varchar(191)`, `type varchar(20)` (choice|text|yesno), `options longtext` (JSON array of strings), `required tinyint(1)`, `filterable tinyint(1)` (Pro), `position int(11)`, `status varchar(20)` (active|inactive), `created_at datetime`; `UNIQUE KEY slug (slug)`, `KEY status_pos (status, position)`.
- **Comment meta:**
  - `_ndvr_answers`: array `field_id => string`. Choice stores the option text, so later option edits don't rewrite old answers. Yes/No stores `yes`|`no`.
  - `_ndvr_ans_<field_id>`: one per answer, only for filterable fields (Pro `meta_query`).
- **Service** `review_fields` → `Reviews\ReviewFieldRepository`: `get_active()`, `get_all()`, `find()`, `insert()`, `update()`, `delete()`, `sanitize( array $raw ): array`, `validate( array $clean, string $source, bool $present ): true|WP_Error`, `save_answers( int $comment_id, array $clean )`, `answers_for_view( int $comment_id ): array`, `ensure_imported( string $slug ): int` (CSV only, below).
- **Renderer** `Forms\FieldRenderer::render( array $fields, string $id_prefix, array $values = [] ): string`, outputs the fields plus `<input type="hidden" name="ndvr_answers_present" value="1">`.
- **`create()` data keys:** `answers` (array), `answers_present` (bool, default true).
- **Error code** `ndvr_missing_answer`.
- **Filters:** `ndv-reviews/max_review_fields` (int, default 2), `ndv-reviews/review_field_answers` (array `$answers`, array `$review`) for display, `ndv-reviews/show_answers` (bool, array `$review`).
- **Actions:** `ndv-reviews/review_fields_saved` (int `$id`, array `$post`), `ndv-reviews/review_field_form_after` (array `$field`), `ndv-reviews/moderation_edit_fields` (WP_Comment), `ndv-reviews/moderation_edit_save` (int).
- **`ReviewQuery::paginate()` arg** `answers` (array `field_id => value`), applied as `meta_query` on `_ndvr_ans_<id>` for filterable fields only; ignored otherwise.
- **View-model key** `answers`: list of `{field_id, label, value}`.
- **Admin page** slug `ndv-reviews-questions`, class `Admin\QuestionsPage`, capability `Support\Caps::manage( 'questions' )`, nonce `ndvr_review_fields`.
- **Template variable** `$review_fields` in `magic-landing.php`.
- **CSS** `.ndvr-answers` (display.css), `.ndvr-question` (reviews.css, collect.css), on tokens.
- **CSV columns** `q_<slug>`.
  - **Export:** one column per question that has any stored answer, active or not; the value is the stored text (`yes`/`no` for Yes/No).
  - **Import, known slug:** values are sanitized against that question's type (§8).
  - **Import, unknown slug:** before the first row, `Csv` calls `ensure_imported( string $slug ): int` on the `review_fields` service (the import already runs after the Tools nonce and `Caps::manage( 'tools' )`, ToolsPage.php:118-119), which inserts a question with `label` = the slug turned into words (`ucfirst( str_replace( ['-','_'], ' ', $slug ) )`), `type` `text`, `required` 0, `status` **`inactive`**, and returns its id. Values are then stored as text answers (`sanitize_text_field`, 120 characters). Inactive questions don't count toward `max_review_fields`, don't render on forms, and their answers show on cards (§3). The merchant can rename, retype or activate them later.
  - A slug that sanitizes (`sanitize_title`) to empty is skipped and reported in the import result ("Column {name} was skipped.").
- **`NDVR_API` 7** covers the Pro-facing API here: `max_review_fields`, `review_field_form_after`, `review_fields_saved`, the `answers` arg of `paginate()` and the `_ndvr_ans_<id>` meta. Pro tasks RR-11P-1 and RR-11P-2 register only when `NDVR_API >= 7`.
- **Uninstall registry (F2):** table via `Installer::table_names()`; comment meta `_ndvr_answers` and prefix `_ndvr_ans_` in the registry's comment-meta group (imported reviews survive uninstall and may carry answers). If F2 shipped without a comment-meta group, this PRD adds it.

## 7. Storage and upgrade
- `Installer::schema()` gains the table; `NDVR_DB_VERSION` becomes `Installer::V_FIELDS` (provisional v6 per PRD-00 §4; the real number is `max(shipped) + 1` at merge); `table_names()` gains `review_fields`.
- No migration step: old sites have no questions.
- **Read guard:** every front-end and handler read goes through `ReviewFieldRepository`, which returns `[]` until `Installer::is_current( Installer::V_FIELDS )`. Before the upgrade runs, forms show no questions, `create()` skips answers and the CSV importer ignores `q_` columns.
- Definitions cached in a static plus `wp_cache` group `ndvr` key `review_fields`, flushed on every write.

## 8. Security
- **Admin page:** every POST runs `check_admin_referer( 'ndvr_review_fields' )`, then `current_user_can( Caps::manage( 'questions' ) )`, before any work.
  - Label: `sanitize_text_field`, 1 to 191 characters.
  - Type: whitelist.
  - Options: `array_map( 'sanitize_text_field' )`, trimmed, empty and duplicate lines removed, 2 to 6 options of at most 60 characters each.
  - Ids: `absint`.
- **Front end:** answers are read inside the existing nonce'd handlers (ReviewForm.php:351, TestimonialForm.php:226, Landing.php:213), after `wp_unslash`.
- **Sanitize (every source, including CSV):**
  - choice values must equal one of the field's options, otherwise dropped;
  - text: `sanitize_text_field` + `mb_substr( 0, 120 )`;
  - yes/no: `yes` or `no`, otherwise dropped;
  - unknown field ids dropped.
- **Required check:** only when `Sources::is_interactive( $source )` and `answers_present` is true. Imports never fail on answers. The marker is a UX aid, not a security control: a client that strips it only skips its own required questions.
- **Output:** `esc_html` on labels and values; `esc_attr` on ids and values in attributes.
- Admin edit save: inside the existing `check_admin_referer( 'ndvr_edit_review' )` + `moderate_comments` (Page.php:116-123).

## 9. Privacy
Answers can describe the person ("skin type").
- **Exporter:** one row per answer ("Question: Fit", value) in each review's item.
- **Eraser:** add `_ndvr_answers` to the fixed delete list (Privacy.php:304-308), and delete `_ndvr_ans_*` with a prepared `DELETE FROM {$wpdb->commentmeta} WHERE comment_id = %d AND meta_key LIKE %s` (`$wpdb->esc_like( '_ndvr_ans_' ) . '%'`).
- **Uninstall:** registry entries as in §6.
- **Readme privacy notes:** "Answers to review questions are stored with the review, included in personal data exports and removed by erasure."

## 10. Performance and assets
- One cached definitions read per request.
- Answers are comment meta, primed by `WP_Comment_Query`'s meta cache, so no extra queries per card.
- No new JS. The existing scripts already submit the whole `FormData` (reviews.js:138, collect.js:260).
- CSS additions in existing files; run `npm run build:assets`.

## 11. Compatibility
- **No-JS:** the product form is AJAX-only today, because `block_unrated_native_post()` stops a native post without a rating (ReviewForm.php:108-132). Questions add no native path. The testimonial and landing forms are JS-submitted too (collect.js:296-301).
- HPOS: no order access.
- Block checkout: not involved.
- PHP 7.4, WP 6.0, WC 8.0: plain PHP and core APIs.
- Elementor: the review form is WooCommerce's, so Elementor product templates show the questions; Pro grid as in §5.
- Theme overrides: §5.
- Pro absent: free works with 2 questions. Pro present: cap and chips.

## 12. Acceptance criteria
1. With the stored DB version at `Installer::V_FIELDS - 1`, upgrading creates `ndvr_review_fields` and sets the DB version to `Installer::V_FIELDS`. Before the upgrade, `get_active()` on the `review_fields` service returns `[]` and a submission still saves.
2. Admin POST creates "Fit" (choice: Runs small / True to size / Runs large, required) and "Used for" (text, optional). A POST without the nonce, or by a user without `Caps::manage()`, changes nothing.
3. A third active question is refused with "You can have 2 active questions." With `max_review_fields` filtered to 10, it's allowed.
4. `View::render`ed product form markup contains both questions, in order, after the textarea and before `ndvr-photos`.
5. `ndvr_submit_review` without a Fit answer returns 400 "Fit needs an answer.", and no attachment or comment is created (upload count unchanged).
6. A valid submission stores `_ndvr_answers` = `{fit_id: "True to size", used_id: "Daily walks"}`. Once approved, the rendered card contains `<dt>Fit</dt><dd>True to size</dd>` inside `.ndvr-answers`, after `.ndvr-review-body` and before `.ndvr-review-criteria`.
7. A posted choice value not in the options is dropped. A 200-character text answer is stored as 120 characters.
8. The landing page renders the questions inside each product's form with ids `p{pid}-f{fid}-o{n}` (unique across forms). `ndvr_collect_submit` with `ndvr_answers[fit_id]` saves the answer on that product's review.
9. A landing submission without `ndvr_answers_present` (old override) and without Fit still saves.
10. CSV export has a `q_fit` column. Importing that file into a clean site (no questions) creates an inactive Short text question with slug `fit`, stores each row's Fit value as its answer, and the approved cards show them. The active count stays 0, and the product form renders no question. Importing again into a site where "Fit" exists as a Choice question restores the answers against it, dropping values that aren't among its options. An import row missing a required answer imports.
11. The admin edit screen shows the answers; saving changes the stored answer.
12. Privacy export lists the answers; erase removes `_ndvr_answers` and every `_ndvr_ans_*` key.
13. Deleting "Fit" hides its answers on cards. Deactivating "Used for" keeps its answers visible.
14. Editing an option label later doesn't change stored answers.
15. Core flows pass.

## 13. Test plan
Harness (shared by RR-11 to RR-25): a PHP script run with `wp eval-file` in Playground.
- Set `$_POST`, `$_SERVER['REMOTE_ADDR']` and the current user.
- Call the AJAX action with `do_action( 'wp_ajax_nopriv_…' )`. Add a `wp_die_ajax_handler` filter that throws, so the JSON can be captured.
- Capture mail with `pre_wp_mail` and stub HTTP with `pre_http_request`.
- Assert on markup from `Support\View::render()` and on database state.

Steps for this PRD:
1. Simulate the previous version (delete the table, set the version option to `Installer::V_FIELDS - 1`), run `Installer::maybe_upgrade()`, assert AC1.
2. Drive `QuestionsPage::handle_actions()` with and without a nonce or capability (AC2, AC3).
3. Render the forms and the landing template; assert the markup (AC4, AC8).
4. Submit through all three AJAX handlers (AC5 to AC9).
5. Run the Exporter, then delete every question row, then run the Csv importer on the exported temp file (AC10, first half); recreate "Fit" as a Choice question and import again (second half). Then the moderation save (AC11) and the privacy callbacks (AC12).
6. Mutate definitions and re-render (AC13, AC14).
7. Run `.agents/qa/core-flows.php`.

## 14. Open questions
None.
