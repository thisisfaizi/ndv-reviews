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

## Phase 2 — DONE (2026-07, free 0.10.0)
| id | title | evidence |
|---|---|---|
| T-M1 | Marquee seamless loop — animation moved to each `.ndvr-marquee-group` (`translate -100% - gap`); repeat scales with item count to fill viewport | browser harness: motion + no blank gap; 0 console errors |
| T-M2 | Direction left/right/up/down normalized in `Widgets::marquee()`; exposed on shortcode, Gutenberg block, Elementor (legacy horizontal/vertical/reverse still work) | php -l clean |
| T-M3 | Initials avatar (fixes empty `get_avatar`), responsive `clamp()` card width, hover lift; Elementor gains gap/pause/with_media | screenshot: colored initials, styled cards |

## Page-speed pass — DONE (2026-07, free 0.10.1 / Pro 0.10.0)
| id | title | evidence |
|---|---|---|
| PS-1 | Pro `RatingStyles`: glyph CSS moved off the global `wp_enqueue_scripts` onto the lazy `stars_html` path — loads only where stars render | browser: home page loads 0 ndvr assets |
| PS-2 | Minification: `bin/minify-assets.mjs` (esbuild) + `npm run build:assets`; ships `assets/**/*.min.*` (CSS −15-27%, JS −37-52%) | build output; product page serves `*.min.*` |
| PS-3 | `Support\Assets` loader-src filter swaps any `ndvr-*` handle to `.min` in production (off under SCRIPT_DEBUG); one filter covers free + Pro | browser: `display.min.css`, Pro `qanda.min.css` served, 0 console errors |
| PS-4 | Codified "assets load conditionally" invariant in AGENTS.md/CLAUDE.md (both) | — |

## Backlog — from PRODUCTION-PLAN.md (Phase 2.5 next)
| id | title | owner | status | AC |
|---|---|---|---|---|
| T-M3b | Marquee remaining data gaps: category-source filter (currently no-op), min_rating-after-limit starvation, px/s speed normalization, double-row variant | free/@fe | todo | category filter works or removed; row never starved |
| T-ST | Elementor Style tabs for all review widgets (shared StyleControls helper) | both/@fe | todo | every widget has a Style tab; no render changes |
| T-R1 | Robustness: N+1 batch in `to_view()`; fix Deactivator hook; complete `uninstall.php`; i18n loaders; dead-feature cleanup | both/@be | todo | see PRODUCTION-PLAN §B3/B4/C4/C7 |
| T-A1 | Accessibility: photo lightbox; `aria-live` on AJAX list; pill `aria-pressed`/`aria-current` | free/@fe | todo | SR announces filter results; lightbox keyboard-dismiss |
| T-L1 | Licensing system (key storage + activation UI + remote check + updater + tier→feature map) | pro/@be | todo | deferred; gate stays open until this ships |
