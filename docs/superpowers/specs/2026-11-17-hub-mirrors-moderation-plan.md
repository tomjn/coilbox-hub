# Hub mirrors moderation implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build step 4 of hub mirrors: removal controls for uploaded archives, anonymous mismatch reports with a moderation queue, the fix for uploads lost to a lapsed reservation, and fresh download links on cached map and game pages.

**Architecture:** Every rule lives in security definer SQL functions, as in the earlier mirror migrations. The PHP side is thin: one anonymous JSON route, two pages and their form posts. Removing an archive sets `state = 'removed'`, which the existing trigger turns into a new catalogue, and mirrors delete the file on their next sync.

**Tech Stack:** Laravel 13 in `hub-php/`, Supabase Postgres, pgTAP, PHPUnit with `Http::fake`.

**Spec:** `docs/superpowers/specs/2026-11-16-hub-mirrors-design.md` (Rules: Removal, Mismatch reports, Build order step 4). The earlier hub plan is `docs/superpowers/specs/2026-11-17-hub-mirrors-hub-plan.md`.

## Owner rulings (2026-11-17)

- An uploader may withdraw their own listed archive. The spec's rule stands: the hash and name stay blocked for good.
- Removal reasons are a fixed list with a catch all: `withdrawn` (set when an uploader or game owner removes), and for moderators `broken`, `wrong_facts`, `rights`, `harmful`, `other`. `other` requires a private note.
- Fix the lapsed reservation gap.

## Global Constraints

- Production rules from the earlier plan: every UPDATE and DELETE has a WHERE clause (PostgREST loads safeupdate). New tables have RLS on and every grant revoked. Functions are security definer with `set search_path = ''`.
- A function a browser session calls uses `auth.uid()` and `public.is_moderator()`, never a user id passed in.
- The mismatch report stores the reporter's IP only while the report is open, and clears it when the report is handled, matching `asset_upload_ip`.
- Rate limit: 20 mismatch reports per IP per hour. The number copies the existing publish limit (`20260809181909_publish_rate_limit.sql`), which is documented there as a ceiling on automation. It is a policy choice, not a measurement.
- No new dependency. No cron.

## Review Focus

1. A removed archive must disappear from map and game download lists at once, not after the week-long page cache. Test: removal invalidates `HubCache::MAPS` and `HubCache::GAMES`.
2. Reviving a failed upload must never break a unique index when a newer upload took the hash or name. Test: pgTAP with a competing listed row.
3. A game owner must only remove archives of games they own, and a plain user must not remove another user's map. Test: pgTAP per role.
4. A mismatch report whose `found` values all equal the stored facts is refused, so a buggy client cannot fill the queue. Test: PHPUnit and pgTAP.
5. The same IP reporting the same archive twice while open keeps one row. Test: pgTAP.

---

### Task 1: Removal, mismatch and revive in the database

**Files:**
- Create: `supabase/migrations/20261117130000_hub_mirror_moderation.sql`
- Create: `supabase/tests/hub_mirror_moderation.test.sql`
- Modify: `supabase/tests/hub_mirror_reports.test.sql` (revive and `changed`)

**Interfaces (produces):**
- `archive.removal_note text` (1 to 2000 chars). `archive.removal_reason` checked to `withdrawn, broken, wrong_facts, rights, harmful, other`.
- Table `archive_mismatch(id bigint identity, archive_id uuid fk, found jsonb, ip inet null, created_at, handled_at, handled_by uuid, handled_how text check in ('removed','dismissed'))`, unique `(archive_id, ip) where handled_at is null`.
- `remove_archive(p_id uuid, p_reason text, p_note text) returns text`, authenticated. Outcomes: `removed`, `not_found`, `not_allowed`, `not_listed`, `bad_reason`, `note_required`. `withdrawn` is allowed for the uploader, the owner of `archive.game_id`, or a moderator. The other codes are moderator only. Closes open mismatches as `removed` and clears their IPs.
- `dismiss_archive_mismatches(p_archive uuid) returns integer`, authenticated, moderator only (others get 0). Closes as `dismissed`, clears IPs.
- `my_archives() returns jsonb`, authenticated: a list of `{id, filename, kind, state, internal_name, version, created_at, listed_at, removed_at, removal_reason, mine, via_game}` for archives the caller uploaded or whose game they own, newest first, `state <> 'failed'`.
- `moderation_archives(p_query text) returns jsonb`, authenticated, null for non-moderators: `{reported: [...], recent: [...]}`. `reported` holds listed archives with open mismatches: the archive's facts, `reports: [{found, created_at}]` and `copies: [download url]` from healthy holding mirrors (`base_url || '/' || kind || 's/' || url_path_segment(filename)`). `recent` holds the 100 newest listed archives, filtered by `filename_lower like '%'||lower(p_query)||'%'` when the query is not empty. Both include `uploader_name` from the existing author name source if one exists, otherwise omit it.
- `report_archive_mismatch(p_sha256 text, p_found jsonb, p_ip inet) returns jsonb`, service_role. Outcomes: `recorded`, `already`, `unknown_archive` (not listed), `agrees` (no field in `found` differs), `rate_limited`. Keeps only the keys of `found` among `kind, shortname, internal_name, version` whose value differs from the archive's.
- `record_mirror_report` replaced: before the listing update, revive rows `state = 'failed' and upload_mirror_id = p_mirror and sha256 = any(held)`, one at a time, newest first per hash, inside a sub-block that skips on `unique_violation`. A revived row becomes `listed`. Returns a new boolean `changed`: anything listed, a copy row inserted or deleted.
- `set_mirror_health` now returns boolean: whether `healthy` changed.

- [ ] Write the pgTAP tests first: each outcome of each function, each role (anon, a plain user, the uploader, a game owner, a moderator), revive with and without a competing row, the dedupe, the rate limit at 20 and 21, IP cleared on handle, `changed` true and false.
- [ ] Run `supabase test db` and see them fail.
- [ ] Write the migration. Run `supabase test db` until it passes.
- [ ] Commit.

### Task 2: Mismatch report route

**Files:**
- Create: `hub-php/app/Domain/Mirrors/Mismatch.php`, `hub-php/app/Http/Controllers/Mirrors/MismatchController.php`
- Modify: `hub-php/routes/api/mirrors.php`
- Test: `hub-php/tests/Unit/Mirrors/MismatchTest.php`, `hub-php/tests/Feature/Mirrors/MismatchRouteTest.php`

**Contract:** `POST /api/v1/archives/mismatch`, no auth, JSON body of at most 4096 bytes: `{"sha256": "<64 lowercase hex>", "found": {"kind"?: "map"|"game", "shortname"?: string|null, "internal_name"?: string, "version"?: string}}`. Strings are at most 256 characters. Answers: `202 {"outcome": "recorded"}`, `200 {"outcome": "already"}`, `400 bad_request`, `404 unknown_archive`, `422 agrees`, `429 rate_limited`, `503 unavailable`. The IP is `UploadIp::clientIp`. A request with no usable IP is still recorded, with a null IP, and is not rate limited, because the socket address is always present on the droplet.

- [ ] Tests for each answer, with `Http::fake` for the RPC.
- [ ] Implement `Mismatch::parse(mixed $json): array|string` and `Mismatch::handle(string $body, ?string $ip): array{status, body}`.
- [ ] Run `php artisan test --filter Mismatch`. Commit.

### Task 3: Fresh download links

**Files:**
- Modify: `hub-php/app/Domain/Mirrors/Reports.php`
- Test: `hub-php/tests/Feature/Mirrors/ReportRouteTest.php`

- [ ] Test: a recorded report with `changed: true`, or a health check that flips health, calls `HubCache::invalidate([HubCache::MAPS, HubCache::GAMES])`. A report with nothing changed does not.
- [ ] Implement. Run the mirror tests. Commit.

### Task 4: Withdraw and remove pages

**Files:**
- Create: `hub-php/app/Domain/Mirrors/Removal.php` (reason labels, outcome messages, the `remove`, `dismiss` calls and the cache invalidation)
- Create: `hub-php/app/Http/Controllers/Auth/UploadsController.php`, `hub-php/resources/views/account/uploads.blade.php`
- Create: `hub-php/app/Http/Controllers/Moderation/ArchivesController.php`, `hub-php/resources/views/moderation/archives.blade.php`
- Modify: `hub-php/routes/web/auth.php` (or wherever `/account` lives), `hub-php/routes/web/moderation.php`, `ModerationController::SECTIONS` (add `archives` after `games`, label `Uploads`), the account page (a link to `/account/uploads`)
- Test: `hub-php/tests/Feature/Mirrors/UploadsPageTest.php`, `hub-php/tests/Feature/Mirrors/ArchivesModerationTest.php`

Pages:
- `/account/uploads`, signed in only: the caller's archives from `my_archives()`. A listed one has a Withdraw form. The copy says the file leaves every mirror on their next sync, and that the file name and contents cannot be uploaded again.
- `/moderation/archives`: "Reported" first, each with the stored facts beside what reporters found, links to the mirror copies, a Remove form (reason select, note) and a Dismiss form. Then "Recent uploads" with a filename search and a Remove form each.
- POST `/account/uploads/withdraw` and `/moderation/archives/remove`, `/moderation/archives/dismiss`, answered with `ModerationController::answer` style flash messages.

- [ ] Feature tests: signed out gets a redirect to sign in, a stranger gets 404 on the moderation page, each outcome message shows, a removal invalidates the map and game cache tags.
- [ ] Implement. Run `php artisan test`. Commit.

### Task 5: End to end removal and mismatch

**Files:** Modify `scripts/mirrors-e2e.sh`.

- [ ] After the existing checks: post a mismatch report with a different `internal_name` and expect `202`. Call `remove_archive` through local PostgREST as the test user with reason `withdrawn`, expect `removed`. Sync both mirrors and check the file is gone from both and the catalogue's `removed` list has it.
- [ ] Run it. Expected last line: `e2e ok`. Commit.

### Task 6: Coilbox follow up

- [ ] File a Coilbox issue for the mismatch report client against the contract in Task 2, add it to milestone 101, and tell the milestone thread.
