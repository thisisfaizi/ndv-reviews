# Data Model

**Decision:** the review itself is stored as a WordPress **comment** with `comment_type = 'review'` (same as native WooCommerce) for maximum compatibility and trivial migration. Everything extra lives in custom tables prefixed `{$wpdb->prefix}ndvr_`.

## Custom tables (12)

| Table | Purpose | Phase |
|---|---|---|
| `ndvr_criteria` | Criteria definitions (global / category / product) | 1 |
| `ndvr_review_criteria` | Per-review criteria scores (→ comment_id) | 1 |
| `ndvr_review_media` | Review photos/videos | 1 (photo), 5 (video) |
| `ndvr_review_votes` | Helpful votes (1 per user/IP) | 2 |
| `ndvr_requests` | Review-request queue + log | 3 |
| `ndvr_questions` | Product Q&A questions | 7 (Pro) |
| `ndvr_answers` | Product Q&A answers | 7 (Pro) |
| `ndvr_ai_meta` | AI enrichment cache | 7 (Pro) |
| `ndvr_forms` | Standalone collection forms | 4 |
| `ndvr_connections` | External OAuth/marketing connections (encrypted) | 6 (Pro) |
| `ndvr_campaigns` | Bulk request / social-post jobs | 6/8 (Pro) |
| `ndvr_review_tokens` | Tokenized review-collection links | 3 |

All 12 are created in Phase 0 so later phases need migrations only for schema changes.

## Comment meta keys

On the review comment: `_ndvr_recommend` (yes\|neutral\|no), `_ndvr_verified`, `_ndvr_anonymous`, `_ndvr_highlight`, `_ndvr_country`, `_ndvr_helpful_up`, `_ndvr_helpful_down`, `_ndvr_overall_rating` (cached mean of criteria), `_ndvr_order_id`, `_ndvr_source` (`onsite`/`qr`/`form`/`google`/`facebook`/`import`/`magic_link`). Native Woo `rating` meta is kept in sync.

## Options

A single autoloaded option `ndv_reviews_settings` (versioned array) holds all free settings. Pro uses `ndv_reviews_pro_settings`. Schema version tracked in `ndv_reviews_db_version`.

## Security notes

- OAuth tokens in `ndvr_connections` are encrypted at rest (libsodium), never plaintext.
- Review-collection tokens store only **hashes** (`token_hash`, `email_hash`); the signing secret lives in `wp-config.php`, not the DB.
- User IP is treated as PII and stored hashed.
