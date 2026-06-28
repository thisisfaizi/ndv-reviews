# Phase 6 — Automation + Incentives (Pro) {badge:wip:CODE-COMPLETE}

> **Goal (build-plan §15, §21.4–21.7):** Incentives (coupon/store-credit for review) and the ESP connectors that push the tokenized review link into the merchant's own email platform.

## Coupon for review (`Incentives/CouponReward`)

When a review is **approved**, Pro issues a WooCommerce coupon:
- single-use (`usage_limit = 1`), auto-expiring, **restricted to the reviewer's email**;
- percentage or fixed amount, configurable expiry;
- **fraud guard**: only on approval, **once per review** (`_ndvr_rewarded` meta), and the code is emailed to the reviewer.

## ESP connectors (`Esp/`)

On **order completed**, Pro builds the tokenized links with the **free plugin's own token engine** (`TokenRepository` + `Reviewable`) and pushes them to every enabled provider — **idempotently** (one push per order). Each uses the platform's own merge mechanism, with **the merchant's own credentials** (no data flows through us):

| Provider | Mechanism | Merge tag shown in admin |
|---|---|---|
| **Klaviyo** | "Review Request" custom event (Events API) | `{{ event.review_url }}` |
| **Mailchimp** | Member upsert with `RVWURL` merge field = customer magic link | `*|RVWURL|*` |
| **Brevo** | Contact attribute `REVIEW_URL` | `{{ contact.REVIEW_URL }}` |
| **Webhook** | HMAC-signed POST (Zapier / Make / custom) with `review_url` + `customer_magic_link` + `items[]` | mapped field |

> Mailchimp uses the **customer magic link** (not a per-order link) because merge fields are per-contact and overwritten on each order — so it stays correct across repeat purchases (build-plan §21.5 caveat).

All connectors are configured under **NDV Reviews → Pro Settings**, which **surfaces the exact merge tag to paste** for each platform.

## Acceptance criteria (§7.8, §21.4–21.7)

Status: **code-complete, lint-clean; pending a runtime pass.**

- ☐ Coupon issued only after approval; single-use + expiry + email restriction enforced; no double-rewarding.
- ☐ Klaviyo event posts with `review_url` + `items`; idempotent per order.
- ☐ Mailchimp merge field populated with the customer magic link.
- ☐ Brevo contact attribute set.
- ☐ Webhook fires with the full payload + HMAC signature.

### How to verify
Under **Pro Settings**, enable a connector with a test key (or a Zapier/Make catch-hook for the webhook) and the coupon-for-review. Complete an order → check the connector received the event/contact with the link. Approve a review → confirm the reviewer gets a single-use coupon.

### Deferred to a 6.x follow-up
Multi-step drip sequences, **SMS (Twilio)** + **WhatsApp (Cloud API)** channels, smart send-time, segments, bulk past-customer/CSV campaigns, and media-tiered reward amounts.

## Status

This completes the requested **Phases 4–6**. The free plugin is at the **v1.0 feature surface**; Pro has its **foundation + automation/incentives**. Remaining build-plan phases (7–9: Q&A, AI, widgets+, social, feeds, analytics, importers+, multilingual, REST/CLI) follow the same architecture.
