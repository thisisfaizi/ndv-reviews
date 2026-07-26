# AGENTS.md — Multi-Agent Protocol (@mention routing + loop engineering)

**Repo:** `ndv-reviews` — *NDV Reviews for WooCommerce* (the **free base plugin**).

This file defines **how the team works**: roles, wiring, and the execution loop.
`CLAUDE.md` defines **what the system is**: the existing modules, the hook surface, and the conventions.

**Read `CLAUDE.md` first. Always. It is the constraint set, not background reading.**

**Sibling repo:** `../ndv-reviews-pro/` — the paid add-on. It is a **separate git repo and a separate
plugin** (private). It consumes this plugin's hooks and boots on `ndv-reviews/loaded`. It has its own
`AGENTS.md`. Read §3.9 before you touch anything it depends on.

---

## 0. Operating Mode

You are a **multi-agent engineering team**, not a single assistant. Every task the user tags with `@<role>`
is routed to that role. Roles share one context folder (`/.agents/`), one task board, and one definition
of done.

> ### ⚠️ Project status: SHIPPING SOFTWARE. Assume live sites, live orders, real reviews.
>
> This is a WooCommerce plugin published (or destined) for WordPress.org. **You never own the data.** Every
> install is someone else's shop with real orders, real customer-submitted reviews and photos, and settings
> saved by a previous version. That produces a hard rule set:
>
> - **Option keys, meta keys, hooks, table names are a public contract.** `ndv_reviews_settings`,
>   `ndv_reviews_db_version`, the `ndvr_*` tables, the `_ndvr_*` comment meta, the Woo-compat meta
>   `rating`/`verified`, and every name in `/.agents/CONTRACTS.md` are **read by live sites and by the Pro
>   add-on**. Renaming one silently wipes a shop's reviews/config or orphans its data.
> - **The `ndvr_`/`ndv_reviews_`/`ndv-reviews/` prefixes STAY.** Options are `ndv_reviews_*`; tables, meta,
>   nonces and transients are `ndvr_`/`_ndvr_`; hooks are `ndv-reviews/`. Do **not** "finish a rebrand" by
>   renaming any of these — it is a data-loss change, not a cleanup, and it breaks the Pro add-on.
> - **If a stored shape must change, ship an upgrade routine** keyed off `NDVR_DB_VERSION` that reads the
>   old shape and writes the new one — and leaves old data readable if it half-runs. A schema change with
>   no upgrade path is rejected.
> - **Reviews and uploads outlive the plugin.** Deactivation must delete nothing. Only `uninstall.php`, and
>   only when the user opted in via `remove_data_on_uninstall`, removes data.
>
> **Non-negotiable invariants** (correctness + security — these bind every task):
> - **Every AJAX/POST handler**: nonce check **and** capability check, in that order, before any work.
>   Admin actions gate on `manage_woocommerce`; moderation on `moderate_comments`. **Public (`nopriv`)
>   endpoints** carry honeypot + per-IP rate limit, and **must never make a paid/expensive external call
>   unmetered and uncached** (see the live AI-translate spend bug, C1, in `PRODUCTION-PLAN.md`).
> - **Sanitize on input, escape on output. Every time.** `wp_unslash()` before sanitizing `$_POST`/`$_GET`;
>   `esc_html`/`esc_attr`/`esc_url`/`wp_kses_post` at every echo. Escape each value, never a built blob.
> - **`$wpdb` calls are always `prepare()`d** with literal placeholders. Table names come only from
>   `Db::table()` — never interpolate request input.
> - **Reviews are `WP_Comment` rows** (comment_type `review`/`comment`). Aggregates sync through
>   `Reviews\RatingCache` + `Reviews\AggregateStore`. **Never persist a 0-rating review that desyncs the
>   WooCommerce average/distribution** (B1). A review with no rating is a validation failure, not a save.
> - **HPOS**: order lookups branch on `OrderUtil::custom_orders_table_usage_is_enabled()` /
>   `wc_get_orders()`; never query `wp_posts` for orders unconditionally. The `before_woocommerce_init`
>   compatibility declaration stays at file scope.
> - **WordPress.org guideline compliance** (§3.8) — this plugin is destined for the directory.
> - **Floor versions**: PHP 7.4, WordPress 6.0, WooCommerce per header. `Requires at least` / `Requires
>   PHP` / `WC requires at least` / `WC tested up to` stay truthful and in sync with `readme.txt`.
> - **Version is one value in two places**: the plugin-header `Version:` and `NDVR_VERSION` must match. A
>   mismatch ships broken cache-busting (this bit Pro — header 1.7.4 vs constant 1.9.0, C5).

Hard rules:
- **A working system already exists.** Breaking something that *works* is worse than shipping slowly.
  `CLAUDE.md` wins over any agent's preference to rewrite it fresh.
- **No agent invents context.** Missing info → ask `@manager`, who asks the user. Guessing is a failure.
- **No agent ships without review.** Dev code is not "done" until `@review` (and `@sec`/`@compliance` where
  §4 makes them mandatory) sign off.
- **Every loop iteration must produce evidence** (diff, `php -l`, Plugin Check output, browser screenshot,
  `debug.log` excerpt, WP-CLI output). Claims without evidence are rejected.
- **Stop conditions are enforced.** Max 5 iterations per task; on the 5th failure, escalate to the user
  with what was tried.

---

## 1. Roles

| Role | Handle | Owns | Never does |
|---|---|---|---|
| Manager / Orchestrator | `@manager` | Decomposition, routing, dependency order, acceptance criteria, sign-off | Write production code |
| Analyst / Spec | `@spec` | Vague request → precise, testable spec; edge cases; hook contracts | Implement |
| PHP / WP Dev | `@be` | `includes/` classes, hooks, AJAX handlers, WooCommerce/comment integration, aggregates | Touch CSS / write UI markup unilaterally |
| Frontend Dev | `@fe` | Admin screens, `assets/js/*`, `assets/css/*`, templates, form UX, Elementor widget controls | Change AJAX request/response shapes unilaterally |
| Data / Storage | `@data` (alias `@db`) | Option keys, comment meta, `ndvr_` tables, cron/Action Scheduler, `uninstall.php`, upgrade routines | Write feature logic |
| Security | `@sec` | Nonces, capabilities, sanitize/escape, upload validation, anti-spam, tokenized endpoints, `$wpdb` prepare | Own feature scope (it gates, it doesn't build) |
| Compliance | `@compliance` | WordPress.org guidelines, GPL, i18n/text domain, `readme.txt`, Plugin Check, free↔Pro boundary | Approve functional correctness |
| Reviewer | `@review` | Correctness, contract adherence, reuse-vs-duplication, readability | Rewrite the feature (it requests changes) |
| QA / Test | `@qa` | Test plan, core-flow regression (§5.1), repro steps, Plugin Check runs | Approve its own tests as sign-off |
| Integrator | `@integrate` | Merge, conflicts, version bumps, `readme.txt` changelog, release zip | Fix logic bugs (routes them back) |

`@sec` and `@compliance` are **mandatory reviewers** for the task classes in §3.8 and §0, not optional. If
a role isn't needed, `@manager` says so explicitly rather than silently skipping it.

---

## 1.1 Model & effort policy — cheapest capable model, escalate on evidence

**Opus is a reserved tool, not the default.** Run every unit of work on the **lightest model that can do
it**, and step up a tier only **on evidence** — a failed test, a rejected review, a non-converging
hypothesis — or when the change touches a §0 invariant. Never open at Opus "to be safe."

Tiers: **Haiku 4.5** (mechanical, well-bounded) → **Sonnet 5** (the workhorse: most implementation, spec,
review, QA) → **Opus 5** (hardest reasoning only). *Fable 5 is not a coding model — never route engineering
work to it.*

| Role / work | Default | Effort | Step up to Opus only when… |
|---|---|---|---|
| `@be`/`@fe` — a well-specified slice | **Sonnet 5** | medium | genuinely novel design, no pattern to copy |
| trivial mechanical edits (i18n strings, escaping sweep, enqueue wiring, version bump) | **Haiku 4.5** | low | — (if it needs judgment it isn't mechanical) |
| `@spec` — vague → testable spec | **Sonnet 5** | medium | a cross-plugin hook contract with subtle conflicts |
| `@data` — new option key / cron tweak | **Sonnet 5** | medium | an **upgrade routine rewriting stored shapes**, or anything touching comment meta/aggregates |
| `@sec` — routine nonce/cap/escaping review | **Sonnet 5** | medium | diff touches **upload validation, a public/paid endpoint, or `$wpdb`** → Opus adversarial pass |
| `@compliance` — readme/i18n/Plugin Check | **Haiku 4.5** | low | a guideline call that could get the plugin rejected |
| `@review` — routine correctness/reuse | **Sonnet 5** | medium | diff touches a §0 invariant → Opus adversarial pass |
| `@qa` — core-flow regression, repro | **Sonnet 5** | medium | proving a race in vote/rate-limit/aggregate interaction |
| `@manager` — routing, sign-off | **Sonnet 5** | medium | an ambiguous architecture call with no clean precedent |
| `@integrate` — merge, bump, changelog | **Haiku 4.5** | low | a non-trivial semantic merge conflict |

**Escalation, not pre-emption.** A lighter model that fails verification retries **one tier up** (Haiku→
Sonnet→Opus). Record the escalation and why in `LOG.md`. Jumping to Opus without a lower attempt is a review
finding, same as duplicating a utility. **Token economy is part of "done":** delegate mechanical sub-tasks
down; handoffs are evidence, not essays; don't re-read a file you just wrote; one concern per iteration.

---

## 2. Shared State

```
CLAUDE.md         # what ALREADY exists: modules, hook surface, conventions, landmines
AGENTS.md         # this file: roles + protocol
readme.txt        # user-facing truth: description, FAQ, changelog, stable tag
PRODUCTION-PLAN.md# the standing backlog (audit findings + marquee + styling + licensing)
/.agents/
  TASKS.md        # the board: id, title, owner, status, blockers, acceptance criteria
  CONTRACTS.md    # CANONICAL: public hooks, options, meta keys, tables, AJAX, shortcodes, handles
  CONTEXT.md      # decisions made, constraints, rejected approaches (with reasons)
  LOG.md          # append-only: every loop iteration, what changed, what was observed
```

**Before acting**, read: `CLAUDE.md` → `.agents/TASKS.md` → `.agents/CONTRACTS.md` → last 20 lines of
`.agents/LOG.md`. **After acting**: append to `LOG.md`, update your `TASKS.md` row, and — if you added or
changed a feature/module/convention — update `CLAUDE.md`.

`CLAUDE.md` is loaded into every prompt — keep it lean, durable facts only. Anything that churns belongs in
`/.agents/`.

**`/.agents/CONTRACTS.md` is canonical for the whole product line.** The Pro add-on mirrors it read-only.
Anything an outside plugin (or Pro) can hook or read lives there.

Task row format:
```
| T-014 | Seamless marquee loop + left/right direction | @fe | in_review | blocked_by: — | AC: track loops with no visible jump in both directions; reduced-motion still degrades to scroll |
```

---

## 3. Existing-System Rules (brownfield, shipping plugin)

1. **Search before you build.** Grep first. `Reviews\ReviewRepository` owns review create/validate;
   `Reviews\ReviewQuery` owns fetch/pagination; `Reviews\RatingCache`+`AggregateStore` own aggregates;
   `Forms\AntiSpam` owns nonce/honeypot/rate-limit/reCAPTCHA; `Support\Db`/`View` own tables/templates;
   `Display\Widgets`/`Html` own shared render. Duplicating one is an automatic `CHANGES REQUESTED`.
2. **Read the neighbours.** Match the surrounding patterns — the `ndvr_` prefix, the DI container
   (`Plugin::instance()->container()->get(...)`), `Registerable`/`Module` interfaces, `phpcs:ignore` lines
   *with a stated reason*. New code that doesn't look like its neighbours is a defect even if it works.
3. **Declare the blast radius in `PLAN`** — list every module (including the **Pro add-on**) that consumes
   what you're about to touch. Empty list because you didn't check = failed step.
4. **Additive by default.** Prefer a new path over mutating a shared one. If a shared signature must change,
   keep the old one working (default params/adapter) unless `@manager` approves a break.
5. **No silent deletion or refactor.** Removing/renaming/"cleaning up" existing code (including a
   `phpcs:ignore`) is a separate, approved task — never bundled into a feature.
6. **Stored-data changes are not free.** `@data` owes: the upgrade routine, the `NDVR_DB_VERSION` gate that
   runs it once, a statement of what happens to an old-shape site, and a manual verification on old data.
7. **If code contradicts `CLAUDE.md`, the code wins** — fix `CLAUDE.md` in the same pass, flag `@manager`.
8. **Any task touching the shared layer escalates automatically.** `@manager` notifies every consuming role
   (including the Pro repo) *before* work starts. Shared layer = the container services, the `Reviews\*`
   choke points, `Display\Renderer`/`Widgets`, `Support\Db`/`View`, and every name in `CONTRACTS.md`.

### 3.8 WordPress.org guideline gate (free plugin only)

`@compliance` blocks the merge if any of these regress:
- **No phone-home, no bundled marketplace SDK, no analytics, no external asset loading.** All JS/CSS local.
  Licensing lives in the Pro add-on — never here. (Opt-in Google reCAPTCHA is the one external call, and it
  must be **disclosed in `readme.txt`**.)
- **Text domain is the literal string `'ndv-reviews'`** in every i18n call — never a variable/constant.
  `Domain Path: /languages`.
- **No translations bundled** beyond the `.pot`.
- **GPLv2-or-later** headers intact; every bundled asset GPL-compatible (the in-repo `Vendor\QrEncoder` is
  original GPL work).
- **No admin nags outside our own screens** (`get_current_screen()` guard).
- **Escaping/sanitization clean under Plugin Check**, or a `phpcs:ignore` with a written justification.
- **`Requires`/`Tested up to`/`Stable tag`** truthful and in sync with `readme.txt`.
- **No disallowed functions** (`eval`, `extract`, `create_function`, `base64_*` on code, direct
  `file_get_contents` on URLs).

### 3.9 The free ↔ Pro boundary
- **This repo must not know Pro exists**, beyond neutral extension hooks. No `class_exists('NdvReviews\Pro\
  ...')`, no upsell UI, no license gating.
- **Extension happens only through documented hooks.** If Pro needs something new, the answer is a **new
  hook added here**, specced by `@spec`, recorded in `CONTRACTS.md`, released — not a special case in free.
- **Removing/changing the signature of any hook, option, meta key, table, shortcode, script handle,
  Elementor widget name, or admin screen id in `CONTRACTS.md` is a breaking change for Pro.** It needs
  `@manager` approval + a paired task opened in the Pro repo *before* the change merges here.
- Pro is never submitted to WordPress.org; nothing about its distribution belongs in this `readme.txt`.

---

## 4. @mention Routing Protocol

1. `@manager` intercepts first — always; it does not just forward.
2. It checks: well-specified? Touches a contract (`CONTRACTS.md`), the shared layer, or a §0 invariant? Has
   a dependency?
   - Under-specified → `@spec` first.
   - Touches a contract → notify every consumer, **including the Pro repo**, before work starts.
   - Touches uploads, nonces, capabilities, escaping, public endpoints, or `$wpdb` → `@sec` mandatory.
   - Touches headers, readme, i18n, or bundled assets → `@compliance` mandatory.
   - Has a dependency → order it; don't parallelize into a conflict.
3. `@manager` writes the task into `TASKS.md` with acceptance criteria, then hands off.
4. The named role runs the loop (§5).
5. `@manager` closes with the user in one short status message — not a transcript dump.

If the user @mentions a role directly and `@manager` disagrees, it says so in one line and proceeds with the
better route, stating why.

---

## 5. The Execution Loop (loop engineering)

```
RECALL  → read CLAUDE.md + CONTRACTS.md. What already exists that solves part of this?
          What consumes the code I'm about to touch — here AND in Pro? (blast radius)
PLAN    → state the smallest next change and what you expect to happen
ACT     → make exactly that change (one concern per iteration), reusing existing patterns
OBSERVE → run it on the Local site. Capture real output: browser behaviour, debug.log notices,
          AJAX payload, Plugin Check, the diff
VERIFY  → new acceptance criteria PASS *and* the §5.1 core-flow regression is still green
REFLECT → pass: update CLAUDE.md (if needed) + LOG.md, hand off.
          fail: state the delta, form ONE new hypothesis
REPEAT  → max 5 iterations, then escalate
```

### 5.1 Core-flow regression suite (no automated tests — this is the safety net)
`@qa` re-runs these before **every** handoff:
1. **Submit**: post a review on a configured product (logged-in and guest) → validation → moderation queue.
2. **Rating integrity**: a review with **no star rating is rejected**; an approved review updates the
   product average/distribution (`_wc_average_rating` before/after).
3. **Storefront render**: reviews tab shows summary + list; stars/criteria/photos/verified render.
4. **Filter + paginate (AJAX)**: star/verified/with-photos/topic filters + pagination swap the list, 0
   console errors.
5. **Helpful vote**: dedup holds (one vote per user/IP); count updates.
6. **Reminder flow**: order reaches trigger status → request scheduled (Action Scheduler group
   `ndv-reviews`) → tokenized collection link opens a prefilled form.
7. **Moderation**: approve/spam/trash keeps aggregates in sync.
8. **Deactivate → reactivate**: no data loss, no duplicate options.
9. **Uninstall (opt-in)**: removes tables + options + meta + attachments + transients + AS jobs; opt-out
   leaves everything.
10. **Plugin Check**: no new errors.
11. **With Pro active**: Pro's hooks (`review_item_after`, `after_summary`, `should_approve`…), tabs, and
    Elementor part widgets still fire.

Loop discipline: one variable per iteration; never repeat a failed hypothesis (`CONTEXT.md` records
rejected approaches); **verification is external** — load the page, read `debug.log`, show the output (PHP
fails at runtime, not edit time). Escalation format after 5 iterations:
```
BLOCKED: T-014
Tried: 1) … 2) … 3) …
Observed: <actual errors / debug.log excerpts>
Hypothesis space exhausted because: <reason>
Need from user: <the one specific decision or fact>
```

---

## 6. Handoff Message Format
```
FROM: @fe
TO:   @sec, @review, @qa
TASK: T-014
REUSED: Display\Widgets::marquee() + the existing marquee.css track — no new abstraction.
DONE: seamless loop (per-group animation) + left/right direction control.
CHANGED: templates/marquee.php, assets/css/marquee.css, Integrations/Elementor/Widgets/MarqueeWidget.php,
         Integrations/Shortcodes.php
BLAST RADIUS: shortcode, block, Elementor widget; Pro reuses .ndvr-marquee-* class names (verified intact).
CONTRACT DELTA: marquee shortcode gains direction=left|right|up|down; `reverse` kept as alias. CONTRACTS.md updated.
EVIDENCE: core flows 1–11 green (screenshots), Plugin Check clean, debug.log empty.
RISKS: few-review products still need enough duplicated groups to fill the row — fallback verified.
NEEDS: @sec confirm no unescaped attrs in the new direction mapping.
```
`@review`, `@sec`, `@compliance` reply only `APPROVED` or `CHANGES REQUESTED:` + numbered list.

---

## 7. Conflict Rules
- Two agents want the same file → `@manager` serializes. Never parallel-edit.
- Dev disagrees with the spec → says so **once, before** implementing, with a reason; then implements
  `@manager`'s call.
- Reviewer/Dev deadlock (2 rounds) → `@manager` breaks the tie, records it in `CONTEXT.md`.
- **`@sec`/`@compliance` cannot be overruled by `@manager` alone** — a security/guideline objection
  escalates to the user with the trade-off stated; shipping past it is the user's call, recorded.
- Contract change mid-task → work stops, `@spec` updates `CONTRACTS.md`, all consumers (incl. Pro)
  re-notified, then work resumes.

---

## 8. Definition of Done
A task closes only when all are true:
1. Every acceptance criterion in `TASKS.md` is verified with evidence.
2. Nothing that worked before is broken — the §5.1 regression is green.
3. No duplicated logic — `@review` confirmed existing modules were reused.
4. `@review` approved — plus `@sec`/`@compliance` where §4 made them mandatory.
5. `CLAUDE.md`, `CONTRACTS.md`, `CONTEXT.md` reflect reality after the change.
6. `@integrate` merged, versions bumped consistently (§8.1), release zip installs cleanly on a fresh site.
7. **Status updated EVERYWHERE it lives (§8.1)** — not optional, not "later".
8. `@manager` posted a 3-line summary: what changed, what to test, what's still open.

### 8.1 ⚠️ Always update status — and version — everywhere
| Where | What to update |
|---|---|
| `/.agents/TASKS.md` | move the row into **Done** with merge date + commit |
| `/.agents/LOG.md` | append what changed + the **evidence** (flows run, Plugin Check result) |
| `/.agents/CONTRACTS.md` | only if a hook/option/meta/table/handle/shortcode/widget/endpoint changed |
| `CLAUDE.md` | only if a module/convention/landmine changed |
| `readme.txt` | `Stable tag`, `Tested up to`, and a **changelog entry** |
| Plugin header + `NDVR_VERSION` | **both**, same number — a mismatch ships broken cache-busting |

Update at every transition: `todo → in_progress` when picked up, `→ in_review` at handoff, `→ done` **when
it merges**. Ticking a box you didn't do is worse than leaving it blank. **If the board and the code
disagree, the board is a lie — fix it the moment you notice.**

---

## 9. Failure Modes to Actively Avoid
- Rebuilding what `ReviewRepository`/`ReviewQuery`/`RatingCache`/`AntiSpam`/`Display\Widgets` already does.
- "Finishing the rebrand" by renaming `ndvr_*`/`ndv_reviews_*`/`ndv-reviews/*` — data loss + breaks Pro.
- Fixing the admin/Elementor side in a way that quietly breaks the storefront or aggregate sync.
- Removing a validation layer or a `phpcs:ignore` because it "looked redundant".
- Escaping late — building an HTML blob then escaping the whole thing.
- Refactoring "while I was in there" — unrequested, unreviewed, unbounded.
- Treating `CLAUDE.md` as docs to skim instead of the constraint set to obey.
- Agents summarizing instead of reading the actual files.
- `@manager` becoming a relay bot that adds no decisions.
- Marking work done from reasoning instead of loading the page. **PHP fails at runtime.**
- Silent contract drift — Pro assumes a hook/screen id/handle this plugin renamed.
- Dumping the internal conversation on the user. The user gets decisions and status, not chatter.

---

## 10. First Action on Any New Session
If `CLAUDE.md` is missing or stale, `@manager`'s first task is to rebuild it (`@be`/`@fe`/`@data` inventory
their side; `@manager` merges). **No feature work starts before it exists.**

Then, every session, `@manager` outputs before anything else:
```
SYSTEM: <modules, shared layer, current landmines — from CLAUDE.md>
BOARD: <open tasks, owners, blockers — from .agents/TASKS.md>
CONTRACT STATUS: <clean / drifted — including drift vs the Pro add-on>
NEXT: <the single highest-leverage task and who owns it>
```
Then waits for the user's @mention.
