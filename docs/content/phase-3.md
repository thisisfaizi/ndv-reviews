# Phase 3 — Requests + Reliability + Collection Link {badge:wip:CODE-COMPLETE}

> **Goal (build-plan §15, §21):** Action-Scheduler request queue, `ndvr_requests`, customizable template, test-send, log + retry, unsubscribe, health check — plus the tokenized multi-product review-collection link with its no-login landing page.
> **Done when:** the reminder reliably sends on order status, is visible in the log, is retryable, and unsubscribe works; the token link opens a prefilled multi-product review page.

## What was built

### Request engine (`includes/Requests/`)
- **RequestRepository.php** — durable `ndvr_requests` records (intent persists so a missed cron is recoverable), with an **idempotency guard** (one email request per order).
- **Scheduler.php** — on the configured order status, inserts a request row and schedules an **Action Scheduler** job (`ndvr_send_request`) after the configured delay. The callback sends and records `sent`/`failed`; `retry()` re-runs a failed one. Falls back to immediate send if Action Scheduler is unavailable.
- **Mailer.php** — composes the HTML reminder (subject/body placeholders, product list, tokenized CTA, unsubscribe link), honors the unsubscribe suppression list, and `send_test()` for the admin.
- **Unsubscribe.php** — one-click, HMAC-verified unsubscribe endpoint with a standalone confirmation page.
- **HealthCheck.php** — admin warning when reminders are enabled but delivery looks unreliable (Action Scheduler missing, `DISABLE_WP_CRON`, or overdue jobs) — **surfacing the usual root cause instead of failing silently.**

### Tokenized collection link (`includes/Collection/`, build-plan §21)
- **TokenRepository.php** — issues opaque per-order / per-customer tokens. The URL carries a random token; the database stores only its **HMAC-SHA-256 hash** (and a hash of the email). Tokens expire and are revocable; products are tracked per-token.
- **Reviewable.php** — resolves which products a customer can still review (order line items minus already-reviewed), preventing duplicate reviews.
- **Landing.php** — renders the **no-login multi-product review page** at `?ndvr_k=<token>` (`noindex`), and processes each submission over AJAX: anti-spam + consent + photo upload, creates the review **verified, `source=magic_link`**, into the normal moderation queue, and marks the product reviewed on the token.

### Admin (`includes/Admin/RequestsPage.php`)
- **Review Reminders** screen: enable, trigger status, delay, subject, from name/email, link expiry; **test-send**; and the **request log** with status and per-row **Retry**.

### Templates & assets
- `templates/email-request.php` (overridable HTML email), `templates/magic-landing.php` (overridable landing), `assets/css/collect.css`, `assets/js/collect.js`.

## WooCommerce-native distribution

On the configured order status the token link is generated and emailed through the reliability engine above. The `{review_link}` placeholder is available in the subject/body. (Klaviyo/Mailchimp/Brevo/webhook distribution is **Pro**, Phase 6.)

## Acceptance criteria (§7.7, §21.1–21.3)

Status: **code-complete, lint-clean; pending a runtime pass.**

- ☐ Reminder fires via Action Scheduler on the chosen order status; visible in the log.
- ☐ Retry on failure; failures surfaced in the UI; test-send works.
- ☐ Unsubscribe honored (suppressed from future sends).
- ☐ No duplicate requests per order (idempotent).
- ☐ Token link opens the multi-product form; tampered/expired tokens show a safe "request a fresh link" page.
- ☐ Submissions enter moderation flagged `source=magic_link`, `verified=1`; completed items hidden on return.

### How to verify

1. **NDV Reviews → Review Reminders**: enable, set trigger = Completed, delay = 0 days, **Save**; click **Send test email** (check Local's Mailpit at the mail catcher).
2. Set an order to Completed → a row appears in the log; once the queue runs (WooCommerce → Status → Scheduled Actions), status flips to `sent`.
3. Open the emailed link → the multi-product page lists the order's products; submit one with a photo → "awaiting moderation"; reload the link → that product is gone.
4. Approve it under **All Reviews**; click the email's **Unsubscribe** → confirm a later send is suppressed.

## Next

**Phase 4 (free v1.0):** shortcodes + Gutenberg blocks + Elementor + classic widgets, CPT support, importers/exporter, GDPR, performance pass, i18n `.pot` → submit free to WordPress.org.
