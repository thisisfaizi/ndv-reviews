# Phase 2 — Moderation + Display {badge:wip:CODE-COMPLETE}

> **Goal (build-plan §15):** Admin list table (filter/bulk/edit), 2 display templates, summary + criteria graphs, filtering/sorting/AJAX pagination, helpful voting, schema.
> **Done when:** full moderation incl. edit works; product page shows summary, graphs, filterable list; Rich Results validates.

## What was built

### Display (`includes/Display/`, `templates/`)
- **Summary.php** — aggregate stats: average, count, star distribution, per-criterion averages, recommend %, verified count.
- **ReviewQuery.php** — filtered/sorted/paginated review querying (by star, verified, with-photos; sort recent/helpful/highest/lowest) with criteria + media enrichment.
- **Html.php** — accessible star markup (full/half/empty).
- **Renderer.php** — **takes over the WooCommerce reviews tab**: summary box + filter/sort bar + paginated list + the review form (Phase 1 field injection still applies). AJAX endpoint returns rendered list fragments — filtering/sorting/pagination happen without a reload.
- **Templates** (theme-overridable via `yourtheme/ndv-reviews/…`): `summary.php`, `review-list.php`, `review-item.php` (criteria bars, photo thumbnails, verified badge, recommend, helpful button).
- **View.php** — template locator with theme-override resolution.

### Voting (`includes/Reviews/Votes.php`)
- One helpful vote per user/IP (hashed), deduped via `INSERT IGNORE` on the unique key; cached count in `_ndvr_helpful_up`; AJAX endpoint.

### Schema (`includes/Schema/JsonLd.php`)
- Single-product-only `Product` + `AggregateRating` + `Review` JSON-LD with a **duplicate-avoidance** strategy: in `auto` mode it **defers** when WooCommerce core or a known SEO plugin (Yoast/Rank Math/SEOPress/AIOSEO) already emits product schema — preventing the duplicate `AggregateRating` Google flags. Modes: `auto` (default) / `plugin` / `off`. Because reviews are stored as comments with synced `_wc_*` meta, **WooCommerce's own schema already reflects our reviews** — so the default `auto` mode correctly emits nothing extra on a standard store.

### Moderation (`includes/Moderation/`)
- **ListTable.php** — `WP_List_Table` with status views (All/Pending/Approved/Spam/Trash), product + star filters, columns (author, rating, review, product, photos, date), row actions, and bulk actions.
- **Page.php** — All Reviews screen + **full edit** (title, body, per-criterion ratings, remove photos), all nonce/cap-guarded. Recalculates aggregates after edits.
- **Actions.php** — recalculates product aggregates on **any** review status transition (so approving from the native Comments screen updates stars too), and adds a **Rating column to the native Comments screen**.

## Acceptance criteria (§7.4, §7.5, §7.6)

Status: **storefront runtime-verified** (product page renders summary + distribution + criteria bars + filterable list + form with **0 console errors, no fatal**, photo on a review). Admin + AJAX-interaction items pending a user pass.

- ☑ Summary numbers match the database; criteria bars render (Quality 4.0 / Value 3.0 / Service 5.0 confirmed live).
- ☑ Front-end review list renders with verified badge, per-criteria stars, photo, recommend.
- ☐ All single + bulk moderation actions work and update caches/schema. *(admin)*
- ☐ Edit saves criteria + media changes; aggregates recalculate. *(admin)*
- ☐ Pending reviews never leak to the front end.
- ☐ Filters/sort/paginate via AJAX without layout shift. *(click-test)*
- ☐ Helpful voting increments once per user/IP. *(click-test)*
- ☐ Single product emits exactly one valid AggregateRating; loop grids emit none; validates in Google Rich Results.

> Fixed during verification: the "Most helpful" sort excluded unvoted reviews (meta JOIN) — now seeds `_ndvr_helpful_up = 0` on create; and filter/helpful button text contrast on themes that force white button text.

### How to verify

1. Submit 2–3 reviews on a product (varying stars, one with a photo).
2. **NDV Reviews → All Reviews**: approve/unapprove/spam/trash (row + bulk); open **Edit**, change a criterion and remove a photo, save.
3. On the product, confirm the summary box, star-distribution + criteria bars, filter pills (star/verified/with-photos), sort dropdown, AJAX pagination, and the **Helpful** button all work.
4. Run the product URL through Google's Rich Results Test — exactly one `AggregateRating` (WooCommerce's, reflecting our reviews); no duplicates.

## Next

**Phase 3 — Requests + reliability:** Action Scheduler review-reminder engine (queue/log/retry/test-send/unsubscribe/health) and the tokenized multi-product review-collection link with the multi-product landing page.
