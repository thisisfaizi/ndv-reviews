# Phase 7 — Q&A + AI (Pro) {badge:wip:CODE-COMPLETE}

> **Goal (build-plan §15):** Product Q&A (ask/answer/vote/moderate/schema); an AI adapter (≥2 providers) with summary, sentiment, and spam scoring — cached + async.

## Product Q&A (`QandA/`)

- **QuestionRepository** — CRUD over the `ndvr_questions` / `ndvr_answers` tables (created by the free plugin in Phase 0).
- **QandA module** — adds a **Q&A tab** to the product page: list questions + answers, **ask** (AJAX, anti-spam via the free stack, enters moderation), **vote**, with merchant answers **badged**. Emits **`QAPage` JSON-LD** for answered questions.
- **Moderation** — admin screen to approve/spam/trash questions and **post a store answer** (auto-approves the question), with a pending-count badge in the menu.

## AI module (BYO key) (`AI/`)

Provider-agnostic — the merchant supplies their own key; we never proxy or mark up usage.

- **ProviderInterface** + **OpenAI** (Chat Completions) + **Anthropic** (Messages) adapters.
- **AiService** — **product summary** ("Customers say…"), per-review **sentiment** (−1..1), and **fake/spam score** (0..1). Everything is **cached in `ndvr_ai_meta`** so there is never a per-pageview API call; the summary regen is throttled (daily).
- **Ai module** — on review approval, enrichment runs **async via Action Scheduler**; the "Customers say…" summary renders above the review list (free `ndv-reviews/after_summary` hook) and via `[ndvr-ai-summary]`. **Degrades gracefully**: no key or an API error simply hides the feature — never fatal.

Configured under **NDV Reviews → Pro Settings → AI** (provider, key, optional model).

> Free extension point added this phase: **`ndv-reviews/after_summary`** (fires below the summary box, before the list).

## Acceptance criteria (§7.10, §7.11)

Status: **code-complete, lint-clean; storefront re-verified. Q&A/AI flows pending a user pass with a key.**

- ☐ Ask / answer / vote with moderation; merchant answers badged; QAPage schema validates.
- ☐ AI works with ≥2 providers behind one interface.
- ☐ Graceful degradation if no key / API error (feature hidden, never fatal).
- ☐ Results cached; no per-pageview API calls; summary renders above reviews and refreshes (throttled).

### Deferred to a 7.x follow-up
AI auto-tagging → shopper-facing topic pills, AI highlight (key-phrase highlighting), product-insight clustering, suggested admin replies, and translation (the `AiService` interface already accommodates them).

## Next

**Phase 8 — Widgets+, social, feeds, variations, reputation.**
