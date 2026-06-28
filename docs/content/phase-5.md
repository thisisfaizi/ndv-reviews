# Phase 5 — Pro Foundation {badge:wip:CODE-COMPLETE}

> **Goal (build-plan §15):** Pro plugin bootstrap, license provider + feature flags, unlimited criteria, video reviews, rating styles, anonymous/highlight/country, admin reply.
> Pro lives in a **separate private repository** (`ndv-reviews-pro`) and is installed as its own plugin.

## Architecture — the free ↔ Pro boundary

`ndv-reviews-pro` boots on the free plugin's **`ndv-reviews/loaded`** action, receiving the free `Plugin` instance (and its container). It registers gated modules through the free plugin's **documented action/filter API** and **never edits free files**. Removing Pro leaves the free plugin fully working — and the free zip contains no Pro code.

```
ndv-reviews-pro.php   Boots on ndv-reviews/loaded; own PSR-4 autoloader (NdvReviews\Pro\)
includes/ProPlugin    Registers feature-gated modules
includes/License      License (OPEN in dev) + FeatureFlags
```

## Licensing — intentionally open in development

`License::is_pro_active()` returns `true` and `License::can($feature)` is open, so **every Pro feature is testable without a license**. When licensing is wired later (WPLM / EDD / Lemon Squeezy), only `License` + `FeatureFlags` change; feature modules keep calling `License::can()` — **feature flags, not scattered conditionals** (build-plan §5.4).

## Pro modules (Phase 5)

| Module | What it does | Free hook used |
|---|---|---|
| **Unlimited criteria** | Raises the free 3-criteria cap. | `ndv-reviews/max_criteria` |
| **Video reviews** | Adds a video URL field; stores + embeds (oEmbed/`<video>`). | `ndv-reviews/review_form_fields`, `ndv-reviews/review_created`, `ndv-reviews/review_item_after` |
| **Rating styles** | Stars / hearts / thumbs / emoji. | `ndv-reviews/stars_html` |
| **Admin reply** | Merchant reply via the comment-edit meta box, shown on the storefront. | `ndv-reviews/review_item_after` |
| **Highlight** | Pin a review to the top + "Featured" badge. | `ndv-reviews/review_items`, `ndv-reviews/review_item_after` |

> Free extension points added this phase: `ndv-reviews/stars_html`, `ndv-reviews/review_items`, `ndv-reviews/review_item_after`, `ndv-reviews/review_form_fields` (see Hooks & Filters).

## Acceptance criteria (§7.1, §7.2, §7.9, §7.16)

Status: **code-complete, lint-clean; pending a runtime pass with the Pro plugin active.**

- ☐ Pro activates only when the free plugin is present; gated features appear; free still works without Pro.
- ☐ Criteria cap lifts to unlimited.
- ☐ Video URL field saves and embeds.
- ☐ Rating style switches glyphs (presentational only).
- ☐ Admin reply renders nested under the review.
- ☐ Highlighted review pins to the top with a badge.

### Deferred to a 5.x follow-up
Anonymous reviews, reviewer country flag, saved-reply templates + bulk reply, and per-category criteria templates.

## Next

**Phase 6 — automation + incentives:** coupon-for-review and the ESP connectors (Klaviyo / Mailchimp / Brevo / webhook).
