# PRD review round 3: verification pass (2026-10-10)

Read-only check of the rev-4 edits against round3-A, round3-B and round3-C. No PRD edited. Accepted deviations (not flagged): RR-22 lock name `ndv_reviews_invite_lock_{id}` with the RR-00 F2 option-prefix group; RR-19 gating on `free_api( 5 )`; RR-00 activation calling only `maybe_upgrade()` (no polling); RR-22 moving `nocache_headers()` before `lookup()`.

## Per-finding status

| # | Status | Where the fix lives |
|---|---|---|
| A1 | RESOLVED | RR-00 F3 lines 45-54 (`INSERT IGNORE` acquire, CAS takeover, token-checked release in `finally`); AC 1.2 lines 245-249 |
| A2 | RESOLVED | RR-00 F3 lines 71-80 (Activator calls only `maybe_upgrade()`, downgrade does nothing, registry entries line 80, also Storage lines 192-195); AC 1.4 line 256 |
| A3 | RESOLVED (one gap, see N3) | RR-00 now has Scope, UX, Reuse map, Blast radius, Storage, Security, Privacy, Performance, Compatibility, Test plan (lines 153-240); RR-09 §1-§14 all present (§7 line 190, §8 line 197, §11 line 219, §13 line 254). Gap: the RR-00 Security text doesn't say the nonce comes before the capability check |
| A4 | RESOLVED | RR-09 §6.2 lines 130-134 (`delay` from `reminder_delay_days`); AC3 line 231 |
| A5 | RESOLVED | RR-09 §6.2 step 7 line 101 (`$reviewable->has_reviewed( $email, $product_id )`, `filter_excluded()` only once RR-05 is built) |
| A6 | RESOLVED | (a) RR-09 lines 136-139, AC15 line 251; (b) line 103; (c) lines 170-173, AC14 line 250 |
| A7 | RESOLVED | RR-09 lines 119-128 (15-min lag, `ndvr_expired` past 14 days, 60 s spread, registration on activation and `admin_init` only); SKIP_CODES line 107; AC13 lines 245-249 |
| A8 | RESOLVED | RR-00b §6.3 lines 90-94 (re-sweep from `ndvr_requests_recover`, AC included); RR-09 line 124 |
| A9 | RESOLVED | RR-05 AC3 line 112; §3 line 37 |
| A10 | RESOLVED | RR-04 test plan step 1, line 147; citation `RequestRepository.php:67-73` fixed at line 70 |
| B1 | RESOLVED | PRD-00 §6 line 120; RR-15 §6 lines 141-144, AC21 line 239; Pro RR-01 §6 lines 97-101 |
| B2 | RESOLVED | Pro RR-17 §5 line 69, §7 lines 87-90, AC1b line 149 |
| B3 | RESOLVED | RR-22 §8 page check 4 line 150, save step 5 line 157; AC7 line 192 |
| B4 | RESOLVED as written; NEW defect R1 | RR-22 §8 line 140 (lock kept, `invited_at` re-read after the claim); AC6 line 191. The claim still uses `add_option()`, which isn't atomic (R1) |
| B5 | RESOLVED | RR-22 §5 lines 88-93; §8 step 6 line 158; §6 transient line 126 and registry line 128; AC8 line 193 |
| B6 | RESOLVED | RR-15 §3 line 59, §5 line 96, §6 line 154, §8 line 183; AC7 line 224 |
| B7 | RESOLVED | RR-15 §6 lines 132-140, nonce/hidden field line 158, §8 line 183, §3 line 65; AC7b line 225; RR-22 §4 line 78 |
| B8 | RESOLVED | RR-21 §8 line 124 (marked canonical); RR-03 row line 82; stale listener wording removed from RR-03 line 60 |
| B9 | RESOLVED | RR-14 §6 line 98; AC13b line 165; test plan line 177 |
| C1 | RESOLVED | Pro RR-01 §6 `reward()` early return line 96; §5 line 77; §7 dated tool line 121; §2 non-goal line 34; §3 help line 43; AC10 line 160, AC10b line 161 |
| C2 | RESOLVED | Pro RR-01 §6 "Deletion" line 95; AC16 bullet line 174 |
| C3 | RESOLVED | Pro RR-01 §6 lines 97-101 (`reasons()` reads `non_rating_reasons()` at `free_api( 8 )` on the `reviews` service; `spam_filter` stays local); AC16 line 175. The slug is `offensive`, as chosen by B1 / PRD-00 §6 |
| C4 | RESOLVED | Pro RR-02 §5 line 59 (one bullet), §6.1 line 74 (fires `third_party_import_done`), §6.4 lines 96-100 (`import_verified` sentence and cache); §4 cites RR-03 §6 (line 52); AC16 line 197 |
| C5 | RESOLVED | Pro RR-17 (as B2); Pro RR-10 §5 line 64 |
| C6 | RESOLVED | Pro RR-17 §3 line 46, §7 line 109; AC4 line 152 |
| C7 | RESOLVED | Pro RR-19 §6.2 line 127, §3 line 65, §4 line 72; AC22 line 264 |
| C8 | RESOLVED | Pro RR-19 line 134 and every call site (lines 147, 184, 196, 199); no `use RatingCache`; AC21 line 263 |

## Targeted contradiction scan (results)

- **Reason slugs:** `spam`, `offensive`, `personal_info`, `off_topic`, `policy` are identical in PRD-00 §6 (line 120), RR-15 §6 (line 141) and Pro RR-01 §6 (line 98), with the labels in the same order (RR-15 line 61, RR-01 lines 44 and 98). No `abusive` slug is left anywhere (the only match is prose in RR-14 line 8).
- **Trusted email:** RR-22 §8 (lines 141-145) and Pro RR-17 §7 (lines 87-90) match: account email when `user_id > 0`; otherwise `comment_author_email` for `magic_link` and for guest `list_link`; nothing for guest `onsite`/`form`. Pro RR-10 line 64 agrees.
- **`NDVR_API` levels:** RR-00 = 2 (F3b line 89), RR-09 = 3 (lines 3, 136, AC15), RR-00b = 4, RR-03 = 5, RR-06 = 6, RR-11 = 7, RR-15 = 8, RR-16 = 9, RR-20 = 10, RR-22 = 11, RR-23 = 12, RR-24 = 13, RR-25 = 14. No duplicates. Pro gates: RR-01 at 4 (8 for reasons), RR-02 at 4 (5 for the sentence), RR-10 and RR-17 at 4, RR-19 at 5. One stale sentence: N4.
- **`has_reviewed( $email, $product_id, $user_id = 0 )`:** every call site has the right order: RR-00b lines 100 and 220-221; RR-09 line 101; RR-15 line 150; Pro RR-10 lines 55 and 94.
- **Static `RatingCache::` calls:** none. The only matches are the PRD-00 rule itself and RR-19 AC21's negative grep.
- **`summary_footer`:** `( int $product_id, string $surface )` in both RR-03 §6 (line 114) and RR-19 §6.2 (line 127). Priority 5 in RR-19 runs before transparency at 10, which matches.
- **`ndvr_expired`** is in RR-09 `SKIP_CODES` (line 107).
- **Template sections:** RR-09 has all 14. RR-00 has every section A3 asked for, but it still has no "Problem and who it's for" or "Contract delta" heading. Its intro (line 5) and F1-F9 do that job, and A3 didn't require those headings, so this isn't flagged. If you want a strict match with PRD-00 §1, rename the intro "## Problem and who it's for" and add "## Contract delta" above F1.

## New contradictions or regressions introduced by rev 4

### R1. MAJOR: RR-22 §8 invite claim uses the non-atomic `add_option()` that RR-00 F3 now rejects
- **The contradiction.** RR-22 line 140 says the claim is "`add_option( 'ndv_reviews_invite_lock_' . $id, … )`, the same `add_option` claim as the upgrade lock (RR-00 F3)". RR-00 F3 rev 4 (line 45) now says `add_option()` isn't atomic and replaces it with raw `INSERT IGNORE`.
- **Why it matters.** Two concurrent dispatches can both get past `add_option()`, which reads, then upserts (option.php:1119-1124, :1142). Both then re-read `_ndvr_recovery_invited_at` before either writes it, and both send. That breaks "sent at most once per review" (§2, a legal guardrail) and AC6.
- **Fix text, RR-22 §8 line 140.** Replace "Sending is claimed with `add_option( 'ndv_reviews_invite_lock_' . $id, time(), '', false )`, the same `add_option` claim as the upgrade lock (RR-00 F3). Only the request whose `add_option` succeeds continues," with:
  > "Sending is claimed with `$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", 'ndv_reviews_invite_lock_' . $id, (string) time() ) )`, the same raw-SQL claim as the upgrade lock (RR-00 F3; `add_option()` isn't atomic). Then `wp_cache_delete( 'notoptions', 'options' )`. Only the request for which the insert returns `1` continues,"
- **Fix text, end of the same paragraph.** Change "still fails its `add_option`" to "still inserts 0 rows".
- **Fix text, RR-22 §6 line 127.** Change "(autoload no, value `time()`)" to "(written by raw `INSERT IGNORE`, autoload `no`, value `time()`)".
- **AC6 add:** "With the lock row already present (inserted by direct SQL) and `notoptions` cached as saying it's absent, the claim returns 0 and nothing is sent."

### N2. MINOR: RR-03 §7 describes the old Activator
- **The contradiction.** RR-03 line 144 says reactivation "runs `Activator` and sets the DB version without running steps, Activator.php:26-27". RR-00 F3 rev 4 (line 72) removes exactly that: Activator now runs `maybe_upgrade()`, steps included. The conclusion (no transparency step needed) still holds, but the reason given is now false.
- **Fix text, RR-03 line 144.** Replace the parenthetical with:
  > "(including deactivate, replace, reactivate, which runs `Installer::maybe_upgrade()` and its pending steps through RR-00 F3; there is no transparency step to run)"

### N3. MINOR: nonce-before-capability order not stated (left over from A3)
- **The gap.** The code checks the capability first and the nonce second (SettingsPage.php:87, then :90). RR-03 §8 line 150 cites it as "nonce, then `Caps::manage()`". A3's fix text asked RR-00 to put the nonce first, but RR-00 Security line 205 only says "both before any work".
- **Fix text, RR-00 Security, F6 bullet (line 205).** Replace with:
  > "**F6 settings saves:** each handler runs its existing nonce check (`ndvr_settings`, the RequestsPage `NONCE`, Design) **first**, then `Caps::manage()`, then work (PRD-00 §2.2). Today `SettingsPage::handle_save()` checks the capability first (SettingsPage.php:87, nonce at :90); F6 swaps them."
- **Fix text, RR-03 §8 line 150.** Change "SettingsPage.php:86-90" to "(RR-00 F6 order)".

### N4. MINOR: RR-09 §11 names the wrong Pro fallback level
- **The contradiction.** RR-09 line 222 says "below `NDVR_API` 3, Pro keeps its own paths (RR-06P)". RR-06P gates on `free_api( 4 )`, and RR-09's own line 45 says 4.
- **Fix text, RR-09 line 222.** Replace with:
  > "**New Pro on an old free:** below `NDVR_API` 4 (the level Pro gates on, RR-00b E12), Pro keeps its own paths (RR-06P)."

### N5. MINOR: the Reminders save branch rule lives in two places, and RR-09 drops the unticked-box rule
- **The problem.** RR-09 (lines 172-173) now builds the registry-driven Reminders save, but only RR-05 line 63 says an absent key gives `null` and so the empty value. RR-05 line 61 also still says "whichever is built first adds it". Built from RR-09 alone, unticking "Add UTM tags" wouldn't store `false`.
- **Fix text, RR-09 §6.2 Settings keys, appended after line 172:**
  > "Each sanitize callback receives `wp_unslash( $_POST[ $key ] )`, or `null` when the key is absent (an unticked box or an empty multi-select), and `null` gives the empty value (`false`, `[]`)."
- **AC14 add:** "Saving again with the box unticked stores `reminder_utm = false`."
- **Fix text, RR-05 §6 line 61.** Change "(shared with RR-06, RR-07 and RR-09's tracking keys; whichever is built first adds it)" to "(the branch is built by RR-09 §6.2; this PRD only registers keys)".

### N6. MINOR: the recover job can query columns that don't exist yet
- **The problem.** RR-09 registers `ndvr_requests_recover` from `Activator::activate()` and `admin_init` (line 126). The job reads `claimed_at` and `scheduled_at` state from the v4 shape. If the upgrade is in backoff, or skipped because the lock is held, the job runs before `V_PIPELINE` and its query fails.
- **Fix text, RR-09 §6.2 crash recovery, first line (117).** Append:
  > "The job returns at once unless `Installer::is_current( Installer::V_PIPELINE )`."

### Note (pre-existing, not introduced by rev 4)
- **The gap.** RR-03 §2 and §5 put the notice and the `summary_footer` action on `[ndvr-criteria-graph]`, but the surface list (§6, line 114) has no name for it. So whether the graph shows the notice, and the RR-19 group note, is unspecified.
- **Fix text, RR-03 §6 line 114.** Add `criteria` to the surfaces ("`tab`, `summary`, `criteria`, `reviews`, `widget`") and to the `transparency_surfaces` default.
- **Optional, RR-19 AC22.** Add `[ndvr-criteria-graph product_id=B]`.
