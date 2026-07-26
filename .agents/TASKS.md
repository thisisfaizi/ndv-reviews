# TASKS.md — the board

Row format:
`| id | title | owner | status | blockers | AC |`
Status: `todo` → `in_progress` → `in_review` → `done`. Update at every transition (§8.1 of AGENTS.md).
Cross-plugin tasks are duplicated on the Pro board with the same id.

## Done (shipped)
| id | title | plugin | shipped |
|---|---|---|---|
| P1–P7 | Original phase roadmap (core reviews → automation → widgets → AI/replies → external → social → pooling/reputation/moderation/CTA/roles) | both | free 0.9.x / Pro 1.7.x |
| S-01 | Review-card element toggles (CardDisplay) | pro | 1.7.6 |
| S-02 | WordPress.org submission prep (readme, extract() removal, license headers) | free | 0.9.7 |
| S-03 | Q&A reCAPTCHA-block fix + manual admin add + `[ndvr-qa]` shortcode | both | free 0.9.8 / pro 1.7.7 |
| S-04 | Google reviews by link → aggregate badge (`[ndvr-google-badge]`) | pro | 1.8.1 |
| S-05 | Elementor Review Grid folded into Review Section widget + 11 part widgets + CSS/style-depends fixes | pro | 1.9.0 |

## In flight
| id | title | owner | status | blockers | AC |
|---|---|---|---|---|---|
| T-00 | USER runtime-verify Elementor editor (parts styled; grid + loop-item modes; each card = different review) | user | in_review | — | parts render styled in editor; grid columns lay out; loop template renders per-review data |

## Phase 1 — DONE (2026-07, free 0.9.9 / Pro 1.9.1)
| id | title | evidence |
|---|---|---|
| T-C1 | Cache (comment meta per review+locale) + per-IP rate-limit + review-type restriction on `ndvr_translate` | php -l clean; `Translate.php` |
| T-B1 | Require ≥1 star rating (client `reviews.js` + server `ReviewForm`); `RatingCache` deletes stale `rating` on recompute-to-zero | php -l + node --check clean |
| T-C2 | Pro settings screens now **merge** into `ndv_reviews_pro_settings` | `SettingsPage.php` array_merge |
| T-B2 | Rating checked before upload; orphaned attachments deleted on create() failure (Review + Testimonial forms); rate limiter counts every attempt | `AntiSpam.php`, both forms |
| T-C3 | New `ndv-reviews/should_send_reminder` filter; Pro `Automation\Engine::suppress_free_reminder()` returns false when automation/ESP/SMS/WA active | both plugins |
| T-C5 | Pro version header 1.7.4 → 1.9.1 (matches constant) | `ndv-reviews-pro.php` |

## Backlog — from PRODUCTION-PLAN.md (Phase 2 next)
| id | title | owner | status | AC |
|---|---|---|---|---|
| T-M1 | Marquee: seamless infinite loop | free/@fe | todo | no visible jump; degrades to scroll under reduced-motion |
| T-M2 | Marquee: left/right (+up/down) direction on Elementor/shortcode/block | free/@fe | todo | direction control on all 3 surfaces |
| T-M3 | Marquee: polish (real avatar, responsive width, speed=px/s, double-row) + data gaps (category, min_rating order, Elementor parity) | free/@fe | todo | avatar shows; mobile-sized cards; category filter works or removed |
| T-ST | Elementor Style tabs for all review widgets (shared StyleControls helper) | both/@fe | todo | every widget has a Style tab; no render changes |
| T-R1 | Robustness: N+1 batch in `to_view()`; fix Deactivator hook; complete `uninstall.php`; i18n loaders; dead-feature cleanup | both/@be | todo | see PRODUCTION-PLAN §B3/B4/C4/C7 |
| T-A1 | Accessibility: photo lightbox; `aria-live` on AJAX list; pill `aria-pressed`/`aria-current` | free/@fe | todo | SR announces filter results; lightbox keyboard-dismiss |
| T-L1 | Licensing system (key storage + activation UI + remote check + updater + tier→feature map) | pro/@be | todo | deferred; gate stays open until this ships |
