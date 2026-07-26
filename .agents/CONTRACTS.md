# CONTRACTS.md — CANONICAL public surface (whole product line)

This is the **single source of truth** for everything an outside plugin (or the Pro add-on) can hook, read,
or key off. The Pro repo mirrors this file **read-only**. Changing/removing any name here is a **breaking
change** — it needs `@manager` approval + a paired task opened in the Pro repo *before* the change merges.
Verified 2026-07 by grepping both plugins.

Legend: (a) = action, (f) = filter. All hooks are prefixed `ndv-reviews/`.

## Hooks — free plugin
| Name | Type | Where |
|---|---|---|
| `loaded` | a | `Plugin::boot()` — Pro's boot entrypoint (passes `$plugin`) |
| `services` | f | `Plugin` — filter the service registry |
| `review_created` | a | `ReviewRepository::create()` after insert |
| `review_form_fields` | a | `Forms\ReviewForm` — Pro adds video/anonymous fields |
| `should_approve` | f | `ReviewRepository` — auto-approve decision |
| `validate_review` | f→`true`\|`WP_Error` | `ReviewRepository` before insert (profanity/purchase gate) |
| `review_media_status` | f | `ReviewRepository::save_media()` |
| `should_send_reminder` | f | `Requests\Scheduler::on_order_status()` — Pro suppresses free reminder when its automation/ESP is active |
| `review_items` | f | `ReviewQuery::paginate()` — Pro pins highlighted reviews |
| `review_author` | f | `ReviewQuery::to_view()` — Pro anonymizes |
| `review_query_args` | f | `ReviewQuery::paginate()` |
| `review_item_after` | a | `templates/review-item.php` — Pro renders video/reply/share |
| `after_summary` | a | `Display\Renderer` — Pro AI "customers say" summary |
| `show_verified_badge` / `show_overall_stars` / `show_review_date` / `show_criteria` / `show_recommend` / `show_helpful_button` | f | `templates/review-item.php` — Pro CardDisplay toggles |
| `stars_html` | f | `Display\Html::stars()` — Pro rating-style swap |
| `criteria_name` | f | `Reviews\Criteria` — Pro multilingual |
| `marquee_repeat` | f | `Display\Widgets::marquee()` — args `($repeat, $items)`; default scales with item count |
| `per_page` | f | `Display\Renderer` |
| `qa_shortcode_output` | f | `Integrations\Shortcodes` `[ndvr-qa]` — Pro renders Q&A |
| `reviewable_post_types` | f | `Reviews\PostTypes` |
| `review_pool_id` | f | `Reviews\Pool` (variation/group pooling) |
| `aggregate` | f | `Reviews\AggregateStore` |
| `is_verified_buyer` | f | `Reviews\VerifiedBuyer` |
| `max_criteria` | f | `Reviews\CriteriaRepository` |
| `max_photo_bytes` | f | `Forms\Upload` |
| `rate_limit_per_hour` | f | `Forms\AntiSpam` |
| `recaptcha_threshold` | f | `Forms\AntiSpam` |
| `token_expiry_days` | f | `Collection\TokenRepository` |
| `template_path` | f | `Support\View::locate()` |
| `json_ld` / `woo_structured_data_active` / `seo_plugin_active` | f | `Schema\JsonLd` |

## AJAX actions
- Free: `ndvr_list_reviews` (Renderer, priv+nopriv), `ndvr_vote` (Votes, priv+nopriv), review submit
  (`ReviewForm`, nopriv), testimonial submit (`TestimonialForm`, nopriv), collection landing
  (`Collection\Landing`, nopriv).
- Pro: `ndvr_qa_ask`, `ndvr_qa_vote`, `ndvr_ai_reply`, `ndvr_translate`, `ndvr_refresh_external`,
  `ndvr_search_products` (now nonce-gated via `ManualReviews::NONCE`), `ndvr_external_status` (planned).

## Shortcodes
- Free: `[ndvr-reviews]` `[ndvr-summary]` `[ndvr-criteria-graph]` `[ndvr-stars]` `[ndvr-marquee]`
  `[ndvr-qa]` `[ndvr-testimonial]` `[ndvr-form]`
  - `[ndvr-marquee]` `direction` accepts `left|right|up|down` (preferred) or legacy `horizontal|vertical`
    (+ `reverse`). Same on the Gutenberg block + Elementor Marquee widget.
- Pro: `[ndvr-carousel]` `[ndvr-gallery]` `[ndvr-wall]` `[ndvr-badge]` `[ndvr-video-carousel]`
  `[ndvr-avatar-carousel]` `[ndvr-auto-slider]` `[ndvr-inline]` `[ndvr-sidebar]` `[ndvr-popup]`
  `[ndvr-trust-badge]` `[ndvr-all-reviews]` `[ndvr-google-badge]` (real synced Google rating; `link`
  attribute defaults to the `google_profile_url` setting) `[ndvr-store-rating-badge]` (on-site aggregate
  only — renamed from `[ndvr-trustpilot-badge]`, which never pulled live Trustpilot data)

## Options
- Free: `ndv_reviews_settings`, `ndv_reviews_db_version`, `ndv_reviews_unsubscribed`. Transient marker
  `ndv_reviews_activated`.
- Pro: `ndv_reviews_pro_settings`, `ndvr_google_aggregate`.

## DB tables (`{$wpdb->prefix}ndvr_`, named via `Db::table('<suffix>')`)
`criteria`, `review_criteria`, `review_media`, `review_votes`, `requests`, `review_tokens`, `questions`,
`answers`, `question_votes`, `ai_meta`, `connections`, `campaigns`, `forms`.
(Note: `questions`/`answers`/`question_votes`/`ai_meta`/`connections`/`campaigns`/`forms` are created by
the free installer but only written by Pro. `question_votes` added in `NDVR_DB_VERSION` 2 — dedup table
for Q&A voting, same `UNIQUE KEY (entity_id, user_id, ip_hash)` shape as `review_votes`.)

## Comment meta
- Free: `rating` (Woo), `verified` (Woo), `_ndvr_overall_rating`, `_ndvr_title`, `_ndvr_recommend`,
  `_ndvr_verified`, `_ndvr_helpful_up`, `_ndvr_tag` (repeated), `_ndvr_source`, `_ndvr_external_id`.
- Pro: `_ndvr_video`, `_ndvr_admin_reply`, `_ndvr_posted`, `_ndvr_esp_pushed`, `_ndvr_rewarded`.

## Elementor
- Category `ndv-reviews`. Free widgets: `ndvr-stars`, `ndvr-summary`, `ndvr-reviews`, `ndvr-marquee`.
  Dynamic tags: `ndvr-rating-value`, `ndvr-review-count`.
- Pro parts: `ndvr-part-{author,avatar,rating,title,text,date,verified,recommend,photos,helpful,criteria}`.
- Pro injects layout/card-design controls into the free `ndvr-reviews` widget via
  `elementor/element/ndvr-reviews/content/after_section_end` and overrides output via
  `elementor/widget/render_content`.

## Script / style handles
- Free: `ndvr-display` (css + js), `ndvr-marquee`, `ndvr-collect`, `ndvr-design-admin`, `ndvr-admin`,
  `ndvr-criteria`. JS localized object: `ndvrDisplay` (`ajaxUrl`, `action`, `nonce`, `voteAction`).
- Pro: `ndvr-qa`, `ndvr-pro-widgets`, `ndvr-pro-elementor`, `ndvr-translate`.

## Constants
- Free: `NDVR_VERSION`, `NDVR_DB_VERSION`, `NDVR_SLUG`, `NDVR_NAME`, `NDVR_TEXTDOMAIN`,
  `NDVR_TABLE_PREFIX='ndvr_'`, `NDVR_OPTION_SETTINGS='ndv_reviews_settings'`,
  `NDVR_OPTION_DB_VERSION='ndv_reviews_db_version'`, `NDVR_FILE/DIR/URL/BASENAME`.
- Pro: `NDVR_PRO_VERSION`, `NDVR_PRO_FILE/DIR/URL/BASENAME`, `NDVR_PRO_OPTION_SETTINGS='ndv_reviews_pro_settings'`.

## Admin
- Menu parent slug `ndv-reviews`; submenus include Reviews list, Rating Criteria, Design, Settings,
  Requests. Pro adds: Pro Settings, Manual Reviews, Q&A.

## Nonce actions (reference)
`ndvr_list_reviews` (Renderer), `ndvr_vote` (Votes::NONCE_ACTION), plus per-form review/testimonial nonces.
Pro: `ndvr_qa`, `ndvr_admin_reply`, `ndvr_external`, `ndvr_qa_admin`.
