# Design System — "Trust Panel"

NDV Reviews ships a deliberate, modern visual identity — not the default WordPress look. The whole front end is self-contained and **theme-safe**: every component scopes its own design tokens under an `.ndvr-` root, sets `box-sizing`, and never relies on the host theme's colors or fonts.

## Thesis

For a reviews UI, the most characteristic content is the **distribution of opinion**, not a single score. So the summary's hero is an oversized **tabular numeral** treated as a typographic element, paired with a **distribution that animates in** on load — the one memorable, on-subject moment. Everything else stays quiet and disciplined.

## Tokens

| Token | Value | Role |
|---|---|---|
| Ink | `#181a1f` | Text, structure, primary buttons |
| Slate | `#626b7a` | Secondary text |
| Paper / Haze | `#ffffff` / `#f5f6f8` | Surfaces & tracks |
| Line | `#e8eaef` | Hairlines |
| **Gold** | `#f6a93b` | **Stars only** (the subject's natural material) |
| **Emerald** | `#0f7d5b` | **Trust signals** — verified, recommends, helpful-voted, primary actions |
| Rose | `#c0392b` | Negative / errors (used minimally) |

Two chromatic colors, each with one job. Personality comes from **uppercase micro-labels** (real structural labels — "VERIFIED REVIEWS", "RATING BREAKDOWN", "VERIFIED BUYER") and heavy **tabular figures**, since a WordPress.org plugin can't load remote fonts — the type treatment is a refined system stack, not a neutral default.

## Signature

The **Trust Panel**: the big average numeral + the staggered **bar-fill animation** on the rating distribution and per-criterion breakdown. One bold place; everything around it is calm.

## Quality floor

Responsive to mobile, visible keyboard focus, `prefers-reduced-motion` respected (animations resolve to their final state), and a modern admin skin scoped to NDV Reviews screens only (`body.ndvr-admin`) so the rest of wp-admin is untouched.

## Design settings screen (free)

**NDV Reviews → Design** lets the merchant choose how reviews look — with modern card selectors (an emerald check on the selected card, not the default WordPress look). Competitors gate design behind Pro; here it is **free**.

| Control | Options | Effect |
|---|---|---|
| **Accent color** | swatch presets + custom picker | buttons, active filter pills, links, pagination (`--ndvr-accent`) |
| **Layout** | List / Grid | review list as rows or a 2-up card grid |
| **Summary style** | Trust Panel / Compact | full distribution panel, or an inline score |
| **Card style** | Soft / Bordered / Flat | surface treatment of the summary + grid cards |
| **Rating icon** | Stars / Hearts / Thumbs / Emoji | the glyph used everywhere stars render |

Choices are stored in `ndv_reviews_settings` and applied with **zero markup churn**: `Display\Design` emits a root class list (`ndvr-template-grid`, `ndvr-summary-compact`, …), a body-level rating class so every star instance (reviews, widgets, marquee, form) swaps together, and one inline custom property for the accent. The CSS variants live in `display.css`.

## Files

- `assets/css/display.css` — Trust Panel summary, filter bar, review list, voting, AI summary.
- `assets/css/reviews.css` — review form + interactive star control + Woo form wrapper.
- `assets/css/marquee.css` — reviews marquee.
- `assets/css/collect.css` — tokenized collection landing page.
- `assets/css/admin.css` — admin skin.
- Pro: `assets/css/widgets.css` (carousel/gallery/wall/badge), `assets/css/qanda.css`.
