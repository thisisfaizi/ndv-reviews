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

## Backlog — from PRODUCTION-PLAN.md (Phase 1 security/data first)
| id | title | owner | status | AC |
|---|---|---|---|---|
| T-C1 | Cache + rate-limit + type-restrict the public `ndvr_translate` AI endpoint | pro/@sec | todo | no repeat paid call for same comment+locale; nopriv rate-limited; reviews only |
| T-B1 | Require a star rating; delete stale `rating` meta on recompute-to-zero | free/@be | todo | rating-less submit rejected (client+server); Woo average never desyncs |
| T-C2 | Pro settings screens **merge** into `ndv_reviews_pro_settings` (no full replace) | pro/@data | todo | saving Pro Settings preserves `external_sync_interval` + other-screen keys |
| T-B2 | Rate-limit before upload; upload after body validation; delete orphaned attachments | free/@sec | todo | failed submit leaves no attachment; every attempt counted |
| T-C3 | Suppress free reminder when Pro automation/ESP active | both/@be | todo | one review request per order, not 2–3 |
| T-C5 | Fix Pro version header (1.7.4 → match `NDVR_PRO_VERSION`) | pro/@integrate | todo | header == constant |
| T-M1 | Marquee: seamless infinite loop | free/@fe | todo | no visible jump; degrades to scroll under reduced-motion |
| T-M2 | Marquee: left/right (+up/down) direction on Elementor/shortcode/block | free/@fe | todo | direction control on all 3 surfaces |
| T-M3 | Marquee: polish (real avatar, responsive width, speed=px/s, double-row) + data gaps (category, min_rating order, Elementor parity) | free/@fe | todo | avatar shows; mobile-sized cards; category filter works or removed |
| T-ST | Elementor Style tabs for all review widgets (shared StyleControls helper) | both/@fe | todo | every widget has a Style tab; no render changes |
| T-R1 | Robustness: N+1 batch in `to_view()`; fix Deactivator hook; complete `uninstall.php`; i18n loaders; dead-feature cleanup | both/@be | todo | see PRODUCTION-PLAN §B3/B4/C4/C7 |
| T-A1 | Accessibility: photo lightbox; `aria-live` on AJAX list; pill `aria-pressed`/`aria-current` | free/@fe | todo | SR announces filter results; lightbox keyboard-dismiss |
| T-L1 | Licensing system (key storage + activation UI + remote check + updater + tier→feature map) | pro/@be | todo | deferred; gate stays open until this ships |
