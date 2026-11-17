# Hub mirrors: hub implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The hub issues signed upload tokens, records signed mirror reports, serves a signed catalogue, and links healthy mirror copies of maps and games. This is build order step 2 of the spec.

**Architecture:** Four Supabase migrations add the tables, the upload and report functions, the catalogue state and the mirror download sources. Laravel code in `hub-php/app/Domain/Mirrors/` signs and verifies with sodium. Thin controllers in `hub-php/app/Http/Controllers/Mirrors/` serve five `/api/v1` routes. The hub never stores files and never opens archives.

**Tech stack:** Laravel 13 on PHP 8.3 locally and 8.4 on the droplet, sodium (built into PHP), PHPUnit 12 with `Http::fake()`, Postgres functions called over PostgREST, pgTAP.

**Spec:** `docs/superpowers/specs/2026-11-16-hub-mirrors-design.md`. The contract is `~/dev/coilbox-mirror/PROTOCOL.md` at commit 49ad5fa. Read both before starting. Read `hub-php/AGENTS.md` too.

## Global constraints

- Code goes in `hub-php/` and `supabase/`. Never touch the Next.js app in the repository root.
- Branch `feat/hub-mirrors` from `main`. The lead session commits. Subagents never commit.
- No new Composer or npm packages.
- Every format matches PROTOCOL.md exactly: base64url with no padding (`SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING`), signatures over a context string followed by the message, JSON written with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` and no trailing newline.
- Signature contexts: token `"coilbox-mirror/token/v1\n"`, catalogue `"coilbox-mirror/catalogue/v1\n"`, hub key endorsement `"coilbox-mirror/hub-key/v1\n"`, report `"coilbox-mirror/report/v1\n"`.
- Every anchored regex uses `\z` or the `D` modifier, never a bare `$`.
- Time limits come from config, with the spec's values as defaults: token start-by window 300 seconds (spec: 5 minutes), reservation 86400 seconds (spec: 24 hours). No other number is enforced. HTTP timeouts are Laravel's documented defaults (`timeout` 30, `connect_timeout` 10 in `PendingRequest`).
- The hub's secret key is `HUB_MIRROR_SECRET_KEY` in the droplet's `shared/.env`. It is never in the repository, a test, a log line or a response.
- Tests never use the network. Copies of the mirror fixtures live in `hub-php/tests/Fixtures/mirror/`. The fixture keys are for tests only.
- Migrations follow the production database rules in the root `CLAUDE.md`. Tables start with row level security on and every grant revoked, then grant only what is listed.
- Comments and Markdown: plain English, no semicolons, no em dashes.

## Decisions this plan adds to the spec

- Unknown files (owner ruling, 2026-11-17). The hub validates a report's `unknown` list and then ignores it. Nothing is stored. `maps/persepolis_v1.3.sd7` on jj is treated as if it were never there.
- Map sources (owner ruling). `map_facts` carries one `url` source per healthy mirror holding the map, after the owner's sources, with `filename` and `sha256`. The lookup route and the map page both read `map_facts`, so Coilbox and the page get the same list. Hub mirrors get no `map_mirror_host` row. That table's section says "may have this one", which is wrong for a checked copy, and a row would show the copy twice.
- No size cap on archives (owner ruling).
- `archive_mismatch` and removal controls are step 4. This plan adds the columns removal needs (`removed_at`, `removal_reason`, `removed_by`) so the catalogue's `removed` list is real and step 4 needs no table change.
- An archive's upload ID is its `archive.id` UUID, which meets the token's upload ID rule.
- `archive.upload_mirror_id` records the mirror a token was issued for. PROTOCOL.md section 7 links a report to an upload by hash and mirror, so the hub needs it.
- A map archive belongs to the map whose `map_name` equals its `internal_name`. `map_name` is the engine's full canonical name with the version, per `20260818100000_map_catalog.sql`. A game archive belongs to the game whose `shortname` equals the archive's shortname exactly, as `submit_game_facts` matches.
- A game's mirror sources come from its most recently listed archive.
- Reservations lapse lazily. `begin_archive_upload` and `archive_upload_status` mark lapsed `uploading` rows `failed` before they read. No scheduler is needed.
- Asking again for a token. A `POST /api/v1/uploads` from the same uploader with the same SHA-256, filename, kind and size as their own unlapsed `uploading` archive returns a new token for the same upload ID. It keeps the same mirror while that mirror is enabled and healthy, and picks another otherwise.
- Stale reports. The hub refuses a report whose `sent_at` is lower than the last accepted `sent_at` for that mirror. An equal value is accepted, because an upload report and a sync report can share a second, and applying a report twice changes nothing.
- Health. A new mirror starts unhealthy. On each report the hub runs a HEAD request for one listed archive the mirror holds, chosen at random, and compares `Content-Length` with the size. A mirror holding no listed archive is checked with a GET of `{base_url}/listing.json`, which must answer 200. The check does not follow redirects, as PROTOCOL.md requires of peers.
- Catalogue rebuilds. A trigger counts every change to listed or removed archives and to the mirror list. A catalogue is rebuilt when the count has moved since the stored one was built, when the next key changed, or when none is stored. `GET /api/v1/catalogue` rebuilds first if needed, and so does each report. `php artisan mirrors:catalogue` forces one. The sequence number is taken inside the same statement that reads the data, so the highest sequence always holds every change.
- The catalogue lists enabled mirrors whatever their health, ordered by ID, and listed archives ordered by `listed_at` then ID.
- Key rotation. `HUB_MIRROR_NEXT_SECRET_KEY` is optional. While it is set, every catalogue carries `next_key`, endorsed by the current key. The current key still signs every catalogue and token. `php artisan mirrors:status` lists each mirror that has not yet reported a sequence at or above the first catalogue that carried the new key. Once none is left, the operator moves the next key into `HUB_MIRROR_SECRET_KEY` and clears the next one. Every mirror already trusts it by then, so no token or catalogue is refused.
- jj-darkstars is registered by migration, as `20260819100000_map_mirror_seed.sql` registered the map hosts. Its public key is public.
- The local end-to-end script covers upload and sync. Removal is step 4, so the script's removal half comes with step 4.

## Review focus

1. A filename such as `New Map (v1.0) é.sd7` must keep its exact bytes in the token, the catalogue and the download URL, which encodes it as PHP's `rawurlencode` does: `Test Map (v1.2) é.sd7` becomes `Test%20Map%20%28v1.2%29%20%C3%A9.sd7`. Tests in Task 3 (token bytes match the fixture) and Task 8 (`url_path_segment`).
2. Two uploads race for one name. The unique indexes must refuse the second with a `duplicate` answer, not a 500. Test in Task 2 (pgTAP, unique violation path) and Task 6 (route maps it to 409).
3. A mirror's upload report fails and only the next sync report carries the file, with `upload` null. The archive must still become `listed`. Test in Task 2 (`record_mirror_report` with a sync event).
4. The catalogue served must be the exact bytes that were signed, with the signature from the same row. A rebuild between the two GETs must not pair one catalogue with another's signature in a single response. Test in Task 4 (body and sig come from one stored row, served byte for byte).
5. A report with a valid shape but signed by another mirror's key, or naming a disabled mirror, must change nothing. Test in Task 5.

---

## File structure

```
supabase/migrations/
  20261117120000_hub_mirrors.sql                 tables, triggers, jj-darkstars row, access
  20261117120100_hub_mirror_uploads.sql          begin_archive_upload, archive_upload_status
  20261117120200_hub_mirror_reports.sql          record_mirror_report, set_mirror_health, catalogue_source, store_catalogue
  20261117120300_hub_mirror_downloads.sql        url_path_segment, map_mirror_sources, game_mirror_sources, map_facts, game_browse
supabase/tests/
  hub_mirrors_access.test.sql  hub_mirror_uploads.test.sql  hub_mirror_reports.test.sql  hub_mirror_downloads.test.sql
hub-php/
  config/hub.php                                 'mirrors' block
  deploy/env.example  deploy/README.md           HUB_MIRROR_SECRET_KEY
  app/Domain/Mirrors/
    B64.php  Signing.php  HubKeys.php  ArchiveFilename.php  UploadToken.php
    Catalogue.php  CatalogueStore.php
    Report.php  HealthCheck.php  Reports.php
    UploadFacts.php  Uploads.php
  app/Http/Controllers/Mirrors/
    CatalogueController.php  ReportController.php  UploadController.php
  routes/api/mirrors.php
  routes/console.php                             mirrors:catalogue, mirrors:status
  app/Domain/Games/Download.php                  passes sha256 through
  resources/views/components/maps/downloads.blade.php
  tests/Fixtures/mirror/                         copies of ~/dev/coilbox-mirror/fixtures
  tests/Unit/Mirrors/  tests/Feature/Mirrors/
scripts/mirrors-e2e.sh                           local hub, two mirrors, one upload
```

---

### Task 1: Tables, triggers, jj-darkstars and access rules

**Files:**
- Create: `supabase/migrations/20261117120000_hub_mirrors.sql`, `supabase/tests/hub_mirrors_access.test.sql`

**Interfaces:**
- Produces:
  - `public.mirror`: `id text primary key` checked against `^[a-z0-9][a-z0-9-]{0,63}\z` (Postgres: `~ '^[a-z0-9][a-z0-9-]{0,63}$'` is safe here because Postgres `$` does not match before a trailing newline), `name text not null` (1 to 128), `base_url text not null` (`^https?://[^/?#\s]+(/[^?#\s]*)?$` and not ending in `/`), `public_key text not null` (`^[A-Za-z0-9_-]{43}$`), `enabled boolean not null default true`, `healthy boolean not null default false`, `last_report_at timestamptz`, `last_report_sent_at bigint`, `last_catalogue_seq bigint`, `last_catalogue_generated_at bigint`, `last_check_at timestamptz`, `created_at timestamptz not null default now()`.
  - `public.archive`: `id uuid primary key default gen_random_uuid()`, `sha256 text not null` (`^[0-9a-f]{64}$`), `filename text not null` (1 to 255 bytes, `octet_length`), `filename_lower text generated always as (lower(filename)) stored`, `kind text not null check (kind in ('map','game'))`, `internal_name text not null` (1 to 256), `version text not null` (0 to 256, may be empty), `shortname text` (1 to 64), `author text` (up to 256), `description text` (up to 4000), `size bigint not null check (size > 0)`, `uploader uuid references auth.users (id) on delete set null`, `state text not null default 'uploading' check (state in ('uploading','listed','failed','removed'))`, `game_id uuid references public.game (id) on delete set null`, `upload_mirror_id text references public.mirror (id)`, `reserved_until timestamptz`, `listed_at timestamptz`, `removed_at timestamptz`, `removal_reason text` (1 to 200), `removed_by uuid references auth.users (id) on delete set null`, `created_at`, `updated_at` (both `not null default now()`). A check that `kind = 'game'` implies `shortname is not null`.
  - Unique partial indexes `where state <> 'failed'`: `archive_sha256_idx (sha256)`, `archive_filename_idx (filename_lower)`, `archive_name_version_idx (internal_name, version)`. Plain indexes on `(game_id, listed_at)` where state is listed, and `(kind, internal_name)` where state is listed.
  - `public.archive_mirror`: `archive_id uuid references public.archive (id) on delete cascade`, `mirror_id text references public.mirror (id) on delete cascade`, `first_seen_at`, `last_seen_at` (`not null default now()`), primary key `(archive_id, mirror_id)`.
  - `public.catalogue_state`: one row. `id boolean primary key default true check (id)`, `next_seq bigint not null default 1`, `changes bigint not null default 0`, `built_changes bigint not null default -1`, `seq bigint`, `generated_at bigint`, `body text`, `sig text`, `next_key_public text`, `next_key_since_seq bigint`. The migration inserts the row.
  - Triggers that run `update public.catalogue_state set changes = changes + 1`: after insert or delete on `mirror`, after update of `enabled`, `base_url` or `public_key` on `mirror`, and after update of `state` on `archive` when old or new state is `listed` or `removed`. One `security definer` trigger function, `public.bump_catalogue_changes()`.
  - A trigger keeping `archive.updated_at` current.
  - The jj-darkstars row: `('jj-darkstars', 'jj.darkstars.co.uk', 'https://jj.darkstars.co.uk', 'B17CkPdtRVPSfwdwz9CenRCdM6QSUBFMarO-10NppNY')`, enabled, not healthy.
  - Access: all four tables have row level security on and every privilege revoked from `anon`, `authenticated` and `service_role`. Then `grant select on public.mirror, public.catalogue_state to service_role`. Nothing else. Writes happen only through the functions in Tasks 2 and 4.

- [ ] **Step 1: Write the failing pgTAP test** `supabase/tests/hub_mirrors_access.test.sql`, run as the PostgREST roles like `map_download_source.test.sql`:

```sql
-- table_privs_are for each of the four tables and anon, authenticated, service_role
-- (service_role: SELECT on mirror and catalogue_state, nothing on archive and archive_mirror)
-- anon and authenticated: select from each table throws 42501
-- the jj-darkstars row exists with healthy = false and the public key above
-- inserting a second archive with the same sha256 (state uploading) throws 23505
-- the same insert succeeds once the first is state 'failed'
-- 'Map.sd7' after 'map.sd7' throws 23505 (filename_lower)
-- same internal_name and version throws 23505
-- a game archive with null shortname throws 23514
-- updating an archive from uploading to listed raises catalogue_state.changes by 1
-- updating an archive from uploading to failed leaves changes alone
-- updating mirror.healthy leaves changes alone, updating mirror.base_url raises it
```

- [ ] **Step 2: Run it to verify it fails**

Run: `supabase test db supabase/tests/hub_mirrors_access.test.sql`
Expected: FAIL, relation `public.mirror` does not exist. Local Supabase must be up. The lead starts it with `scripts/local-services.sh up supabase`.

- [ ] **Step 3: Write the migration**

Apply it locally with `supabase migration up`.

- [ ] **Step 4: Run the test and the existing privilege tests**

Run: `supabase test db supabase/tests/hub_mirrors_access.test.sql supabase/tests/table_privileges.test.sql`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add supabase/migrations/20261117120000_hub_mirrors.sql supabase/tests/hub_mirrors_access.test.sql
git commit -m "Add mirror, archive and catalogue state tables"
```

---

### Task 2: Upload reservations and mirror reports in the database

**Files:**
- Create: `supabase/migrations/20261117120100_hub_mirror_uploads.sql`, `supabase/migrations/20261117120200_hub_mirror_reports.sql`, `supabase/tests/hub_mirror_uploads.test.sql`, `supabase/tests/hub_mirror_reports.test.sql`

**Interfaces:**
- Consumes: Task 1 tables.
- Produces (all `security definer`, `set search_path = ''`, execute revoked from public and granted to `service_role` only):
  - `public.begin_archive_upload(p_user uuid, p_is_moderator boolean, p_facts jsonb, p_reservation_seconds integer) returns jsonb`. `p_facts` keys: `sha256, size, filename, kind, internal_name, version, shortname, author, description`. The route validates shapes first, so this function trusts them. In order:
    1. Mark lapsed rows failed: `state = 'uploading' and reserved_until < now()`.
    2. Reissue. If the caller has an `uploading` row with the same sha256, filename, kind and size: keep its mirror if enabled and healthy, otherwise pick one. Renew `reserved_until`. Return `{"outcome":"reissued","id","mirror_id","base_url","reserved_until"}` with `reserved_until` as epoch seconds.
    3. A game needs `public.game` where `shortname = p_facts->>'shortname'`, else `{"outcome":"refused","reason":"unknown_game"}`. It needs `owner_user_id = p_user` or `p_is_moderator`, else `reason` `not_owner`.
    4. Duplicates, checked in this order against rows with state other than `failed`: sha256, then lowercased filename, then internal name with version. Return `{"outcome":"refused","reason":"duplicate","check":"sha256"|"filename"|"name_version","existing":{"id","filename","kind","state","internal_name","shortname"}}`.
    5. Pick an enabled, healthy mirror at random. None gives `reason` `no_mirror`.
    6. Insert the row as `uploading` with `uploader`, `game_id`, `upload_mirror_id` and `reserved_until = now() + p_reservation_seconds * interval '1 second'`. If the insert raises `unique_violation`, run step 4 again and return its answer, so a race reads as a duplicate.
    7. Return `{"outcome":"created","id","mirror_id","base_url","reserved_until"}`.
  - `public.archive_upload_status(p_user uuid, p_id uuid, p_reservation_seconds integer) returns jsonb`. Null when the row does not exist or `uploader` is not `p_user`. It marks the row failed if it lapsed, and renews `reserved_until` if it is still `uploading`. It returns `{"id","state","filename","kind","reserved_until"}`.
  - `public.record_mirror_report(p_mirror text, p_payload jsonb) returns jsonb`. The payload is already verified and validated by PHP. In order:
    1. If the mirror is missing or disabled, return `{"outcome":"unknown_mirror"}`.
    2. If `(p_payload->>'sent_at')::bigint < last_report_sent_at`, return `{"outcome":"stale"}`.
    3. Update the mirror's `last_report_at = now()`, `last_report_sent_at`, `last_catalogue_seq` and `last_catalogue_generated_at`.
    4. Each `uploading` archive whose `upload_mirror_id = p_mirror` and whose sha256 is in `held` becomes `listed`, with `listed_at = now()` and `reserved_until = null`. The event type does not matter.
    5. Upsert `archive_mirror` for every `listed` archive whose sha256 is in `held`, setting `last_seen_at`. Delete this mirror's `archive_mirror` rows whose archive is not in `held`.
    6. Return `{"outcome":"recorded","listed":<count from step 4>,"check":{"filename","kind","size"}|null}`. `check` is one random listed archive the mirror holds.
  - `public.set_mirror_health(p_mirror text, p_healthy boolean) returns void`. Sets `healthy` and `last_check_at = now()`.

- [ ] **Step 1: Write the failing pgTAP tests**

```sql
-- hub_mirror_uploads.test.sql (seed: one healthy mirror, one disabled, users owner/stranger, game 'TG' owned by owner)
-- a map upload by stranger is created, state uploading, upload_mirror_id is the healthy mirror
-- the disabled mirror is never picked; with no healthy mirror the reason is no_mirror
-- a game upload by stranger is refused not_owner; by stranger with p_is_moderator true is created
-- a game upload naming shortname 'NOPE' is refused unknown_game
-- the same sha256 again by another user is refused duplicate with check sha256
-- 'MAP.SD7' after 'map.sd7' is refused with check filename
-- same internal_name and version under a new hash and name is refused with check name_version
-- the same facts again by the same uploader return outcome reissued with the same id
-- a row whose reserved_until has passed becomes failed, and its sha256 is free again
-- archive_upload_status for another user returns null; for the uploader it returns state and renews reserved_until
-- execute is refused to anon and authenticated (42501)
-- hub_mirror_reports.test.sql
-- a sync report (upload null) whose held has an uploading archive's sha256 for that mirror lists it, listed = 1
-- the same sha256 held by a different mirror does not list it
-- archive_mirror gains the row; a later report without the hash deletes it
-- a report with sent_at lower than the last accepted is stale and changes nothing
-- an equal sent_at is recorded
-- a disabled mirror's report returns unknown_mirror
-- check is null when the mirror holds no listed archive, and names the archive otherwise
-- set_mirror_health sets healthy and last_check_at
```

- [ ] **Step 2: Run them to verify they fail**

Run: `supabase test db supabase/tests/hub_mirror_uploads.test.sql supabase/tests/hub_mirror_reports.test.sql`
Expected: FAIL, the functions do not exist.

- [ ] **Step 3: Write both migrations and apply them with `supabase migration up`**

- [ ] **Step 4: Run the tests again**

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add supabase/migrations/20261117120100_hub_mirror_uploads.sql supabase/migrations/20261117120200_hub_mirror_reports.sql supabase/tests/hub_mirror_uploads.test.sql supabase/tests/hub_mirror_reports.test.sql
git commit -m "Reserve archive uploads and record mirror reports in the database"
```

---

### Task 3: Keys, filenames and upload tokens in PHP

**Files:**
- Create: `hub-php/app/Domain/Mirrors/B64.php`, `Signing.php`, `HubKeys.php`, `ArchiveFilename.php`, `UploadToken.php`, `hub-php/tests/Fixtures/mirror/*` (copy every file in `~/dev/coilbox-mirror/fixtures/`), `hub-php/tests/Unit/Mirrors/MirrorFixtures.php` (a helper trait), `hub-php/tests/Unit/Mirrors/SigningTest.php`, `ArchiveFilenameTest.php`, `UploadTokenTest.php`
- Modify: `hub-php/config/hub.php`, `hub-php/deploy/env.example`

**Interfaces:**
- Produces:
  - `B64::encode(string $bin): string`. `B64::decode(string $s): ?string` returns null for padding, `+`, `/`, whitespace or any invalid input.
  - `Signing::CTX_TOKEN`, `CTX_CATALOGUE`, `CTX_HUB_KEY`, `CTX_REPORT`. `Signing::sign(string $ctx, string $msg, string $secretKey): string` returns raw 64 bytes. `Signing::verify(string $ctx, string $msg, string $sig, string $publicKey): bool` returns false, never throws, on a wrong length.
  - `config('hub.mirrors')`: `secret_key` from `HUB_MIRROR_SECRET_KEY`, `next_secret_key` from `HUB_MIRROR_NEXT_SECRET_KEY`, `token_window` from `HUB_MIRROR_TOKEN_WINDOW` default 300, `reservation` from `HUB_MIRROR_RESERVATION` default 86400. Each number gets a comment naming the spec's time limits table.
  - `HubKeys::fromConfig(): ?HubKeys` returns null when `secret_key` is empty or does not decode to 64 bytes. It has `secretKey(): string`, `publicKey(): string` (raw, from `sodium_crypto_sign_publickey_from_secretkey`) and `nextKey(): ?array{public_key: string, sig: string}`. `nextKey` gives base64url values and is null without a valid next key. Its `sig` signs the next key's 32 raw public key bytes with `CTX_HUB_KEY` and the current secret key.
  - `ArchiveFilename::isValid(string $name): bool` applies the PROTOCOL.md section 2 rule: valid UTF-8, 1 to 255 bytes, ends in `.sd7` or `.sdz` in any case, no leading dot, no `/`, `\` or NUL, and none of `\p{Cc}`, `\p{Cf}`, `\p{Zl}`, `\p{Zp}`.
  - `UploadToken::issue(string $mirrorId, string $uploadId, string $sha256, int $size, string $filename, string $kind, int $startBy, string $secretKey): string`. The payload keys go in this order: `v` (1), `mirror`, `upload`, `sha256`, `size`, `filename`, `kind`, `start_by`. It encodes with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR` and returns `B64(payload) . '.' . B64(sig)`.

- [ ] **Step 1: Write the failing tests**

```php
// SigningTest
public function test_fixture_token_verifies_under_the_fixture_hub_key(): void
public function test_fixture_catalogue_sig_verifies_and_fails_under_the_report_context(): void
public function test_b64_rejects_padding_plus_slash_and_trailing_newline(): void
public function test_hub_keys_null_without_a_valid_secret(): void
public function test_next_key_endorsement_matches_the_rotated_fixture(): void
// config secret = keys.json hub_secret_key, next = rotated_hub_secret_key.
// nextKey() equals catalogue-rotated.json's next_key exactly.
// ArchiveFilenameTest (data provider)
// valid: 'Test Map (v1.2) é.sd7', 'x.SDZ', 'a.b.c.sd7'
// invalid: '.hidden.sd7', 'a/b.sd7', 'a\\b.sd7', "a\0.sd7", "a\x7f.sd7", "a\u{202E}7ds.sd7", "a\u{2028}.sd7", "a\u{FEFF}.sd7", 'map.zip', str_repeat('a', 252).'.sd7', "\xff.sd7"
// UploadTokenTest
public function test_token_matches_the_fixture_byte_for_byte(): void
// issue() with token.payload.json's values and keys.json hub_secret_key === trim-free contents of token.txt
```

- [ ] **Step 2: Run them to verify they fail**

Run: `cd hub-php && php artisan test --filter=Mirrors`
Expected: FAIL, classes not found.

- [ ] **Step 3: Implement the classes, the config block and the `env.example` lines**

`env.example` gets `HUB_MIRROR_SECRET_KEY=` and a commented `# HUB_MIRROR_NEXT_SECRET_KEY=`, with one line saying the key comes from `bin/keygen.php` in the mirror repository.

- [ ] **Step 4: Run the tests again**

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add hub-php/app/Domain/Mirrors hub-php/config/hub.php hub-php/deploy/env.example hub-php/tests/Fixtures/mirror hub-php/tests/Unit/Mirrors
git commit -m "Sign upload tokens with the hub key, matching the mirror fixtures"
```

---

### Task 4: The signed catalogue

**Files:**
- Modify: `supabase/migrations/20261117120200_hub_mirror_reports.sql` (append), `supabase/tests/hub_mirror_reports.test.sql` (append)
- Create: `hub-php/app/Domain/Mirrors/Catalogue.php`, `CatalogueStore.php`, `hub-php/app/Http/Controllers/Mirrors/CatalogueController.php`, `hub-php/routes/api/mirrors.php`, `hub-php/tests/Unit/Mirrors/CatalogueTest.php`, `hub-php/tests/Feature/Mirrors/CatalogueApiTest.php`

**Interfaces:**
- Consumes: `Signing`, `HubKeys`, `B64` (Task 3). The Task 1 tables.
- Produces:
  - SQL `public.catalogue_source() returns jsonb`, granted to service_role only. One statement increments `catalogue_state.next_seq` and returns `{"seq","changes","mirrors":[{id,base_url,public_key}],"archives":[{filename,kind,sha256,size,internal_name,version,shortname,author,description}],"removed":[{sha256,filename,reason,removed_at}]}`. `seq` is the value before the increment. Mirrors are enabled ones, ordered by id. Archives are `listed`, ordered by `listed_at`, then `id`. Removed rows give `reason` as `coalesce(removal_reason, 'removed')` and `removed_at` as epoch seconds.
  - SQL `public.store_catalogue(p_seq bigint, p_changes bigint, p_generated_at bigint, p_body text, p_sig text, p_next_key text) returns boolean`, granted to service_role only. It writes `seq`, `generated_at`, `body` and `sig` only when `seq is null or p_seq > seq`. It sets `built_changes = greatest(built_changes, p_changes)` either way. When `p_next_key` differs from `next_key_public`, it sets `next_key_public = p_next_key` and `next_key_since_seq = p_seq`, or null for both when `p_next_key` is null. It returns whether the body was written.
  - `Catalogue::encode(array $source, int $generatedAt, ?array $nextKey): string`. Top-level keys go in the order `v` (1), `seq`, `generated_at`, `mirrors`, `archives`, `removed`, then `next_key` only when `$nextKey` is not null. Entry keys go in the orders listed above. An empty list encodes as `[]`. `size`, `seq` and `removed_at` are ints. It uses the same JSON flags as the token.
  - `CatalogueStore::__construct(Client $admin, ?HubKeys $keys)`. `current(): ?array{body: string, sig: string}` reads `body` and `sig` from one `catalogue_state` row. `isStale(): bool` is true when `body` is null, `changes > built_changes`, or `next_key_public` differs from the configured next key. `rebuild(): bool` calls `catalogue_source`, encodes, signs with `CTX_CATALOGUE` and the current secret key, then calls `store_catalogue`. It returns false without keys or on a `SupabaseException`, which it logs without the key. `refresh(): ?array` runs `rebuild()` when `isStale()`, then returns `current()`.
  - `GET /api/v1/catalogue` answers 200 with `refresh()`'s body byte for byte, `Content-Type: application/json` and `Cache-Control: no-store`. `GET /api/v1/catalogue.sig` answers 200 with `current()`'s sig and no newline, `Content-Type: text/plain; charset=utf-8`, and no rebuild. Either answers 503 `{"error": "..."}` when nothing is stored.

- [ ] **Step 1: Write the failing tests**

```php
// CatalogueTest
public function test_encode_matches_the_fixture_catalogue_byte_for_byte(): void
// source built from catalogue.json's lists and seq 1; generatedAt 1794787200; no next key.
// encode() === catalogue.json, and sign with hub_secret_key === catalogue.sig
public function test_encode_with_next_key_matches_the_rotated_fixture(): void
// seq 2, generatedAt 1794790800, nextKey from HubKeys with the rotated key;
// encode() === catalogue-rotated.json, signed with rotated_hub_secret_key === catalogue-rotated.sig
public function test_empty_lists_encode_as_arrays(): void
// CatalogueApiTest (Http::fake on rest/v1/catalogue_state*, rpc/catalogue_source, rpc/store_catalogue)
public function test_serves_the_stored_body_and_sig_unchanged(): void
public function test_rebuilds_when_changes_moved_and_serves_the_new_body(): void
public function test_sig_route_never_rebuilds(): void          // Http::assertNotSent rpc/catalogue_source
public function test_answers_503_when_nothing_is_stored_and_no_key_is_set(): void
```

```sql
-- appended to hub_mirror_reports.test.sql
-- catalogue_source returns seq 1 then seq 2 on a second call
-- it lists only listed archives and enabled mirrors
-- store_catalogue with a lower seq than stored returns false and keeps the body
-- store_catalogue records next_key_since_seq the first time a next key appears and clears it with null
```

- [ ] **Step 2: Run them to verify they fail**

Run: `cd hub-php && php artisan test --filter=Catalogue` and `supabase test db supabase/tests/hub_mirror_reports.test.sql`
Expected: FAIL.

- [ ] **Step 3: Implement**

Recreate the local database with `supabase db reset` after editing a migration that is already applied, then run every pgTAP file.

- [ ] **Step 4: Run the tests again**

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add supabase/migrations/20261117120200_hub_mirror_reports.sql supabase/tests/hub_mirror_reports.test.sql hub-php/app/Domain/Mirrors hub-php/app/Http/Controllers/Mirrors hub-php/routes/api/mirrors.php hub-php/tests/Unit/Mirrors hub-php/tests/Feature/Mirrors
git commit -m "Build, sign and serve the mirror catalogue"
```

---

### Task 5: Mirror reports and health checks

**Files:**
- Create: `hub-php/app/Domain/Mirrors/Report.php`, `HealthCheck.php`, `Reports.php`, `hub-php/app/Http/Controllers/Mirrors/ReportController.php`, `hub-php/tests/Unit/Mirrors/ReportTest.php`, `HealthCheckTest.php`, `hub-php/tests/Feature/Mirrors/ReportApiTest.php`
- Modify: `hub-php/routes/api/mirrors.php`

**Interfaces:**
- Consumes: `B64`, `Signing` (Task 3). `CatalogueStore` (Task 4). `record_mirror_report` and `set_mirror_health` (Task 2).
- Produces:
  - `Report::open(string $body): array{mirror: string, payloadBytes: string, sig: string}|string`. A string return is an error code. The body must be a JSON object with string `payload` and `sig`. Both must decode as base64url, the signature must be 64 bytes, the payload must be a JSON object, and its `mirror` must match the mirror ID rule. Otherwise the code is `bad_request`.
  - `Report::verify(string $payloadBytes, string $sig, string $publicKeyB64): bool`.
  - `Report::validate(array $payload): bool` follows PROTOCOL.md section 7. `v` is 1. `event` is `upload` or `sync`. `upload` is a string matching `^[A-Za-z0-9-]{1,64}\z` for `upload` and null for `sync`. `sent_at` is an int. `catalogue_seq` and `catalogue_generated_at` are int or null. `held` is a list of unique 64 lowercase hex strings. `unknown` is a list of objects. Unknown entries are not stored (owner ruling).
  - `HealthCheck::check(string $baseUrl, ?array $candidate): bool`. A candidate means a HEAD to `{base}/{maps|games}/{rawurlencode(filename)}`, healthy only on status 200 with `Content-Length` equal to `size`. No candidate means a GET of `{base}/listing.json`, healthy only on 200. Use `Http::withoutRedirecting()`. A connection exception is unhealthy.
  - `Reports::handle(string $body): array{status: int, body: array}`. It opens the body, then reads `mirror` with `select=id,base_url,public_key,enabled`. A missing or disabled mirror is 403 `unknown_mirror`. A failed signature is 403 `bad_signature`. A failed `validate` is 400 `bad_request`. Then it calls `record_mirror_report`: `stale` is 409 `stale`, `unknown_mirror` is 403. On `recorded` it runs `HealthCheck::check`, then `set_mirror_health`, then `CatalogueStore::refresh()`. It answers 200 `{"ok": true}`. A `SupabaseException` is 503. The signature is checked before the payload is trusted.
  - `POST /api/v1/mirrors/report` returns `Reports::handle($request->getContent())` as JSON with `Cache-Control: no-store`.

- [ ] **Step 1: Write the failing tests**

```php
// ReportTest
public function test_fixture_sync_and_upload_reports_open_verify_and_validate(): void   // mirror_public_key from keys.json
public function test_report_fails_under_another_mirrors_key(): void                  // other_mirror_public_key
public function test_sync_event_with_an_upload_id_is_invalid(): void
public function test_duplicate_or_uppercase_hash_in_held_is_invalid(): void
public function test_padded_base64_payload_is_bad_request(): void
// HealthCheckTest (Http::fake)
public function test_head_with_matching_length_is_healthy(): void
public function test_head_with_other_length_or_404_or_redirect_is_unhealthy(): void
public function test_filename_is_rawurlencoded_in_the_url(): void   // 'Test Map (v1.2) é.sd7' -> Test%20Map%20%28v1.2%29%20%C3%A9.sd7
public function test_no_candidate_checks_listing_json(): void
// ReportApiTest
public function test_fixture_report_is_recorded_checked_and_answered_200(): void
// Http::fake: mirror row with keys.json mirror_public_key, rpc/record_mirror_report -> recorded with a check,
// HEAD on the mirror -> 200 with Content-Length; assert set_mirror_health sent with p_healthy true
public function test_disabled_or_unknown_mirror_is_403_and_nothing_recorded(): void
public function test_wrong_key_is_403_and_record_mirror_report_never_called(): void
public function test_stale_report_is_409(): void
```

- [ ] **Step 2: Run them to verify they fail**

Run: `cd hub-php && php artisan test --filter='Report|HealthCheck'`
Expected: FAIL.

- [ ] **Step 3: Implement**

- [ ] **Step 4: Run the tests again**

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add hub-php/app/Domain/Mirrors hub-php/app/Http/Controllers/Mirrors hub-php/routes/api/mirrors.php hub-php/tests/Unit/Mirrors hub-php/tests/Feature/Mirrors
git commit -m "Accept signed mirror reports and check mirror health"
```

---

### Task 6: Upload API

**Files:**
- Create: `hub-php/app/Domain/Mirrors/UploadFacts.php`, `Uploads.php`, `hub-php/app/Http/Controllers/Mirrors/UploadController.php`, `hub-php/tests/Unit/Mirrors/UploadFactsTest.php`, `hub-php/tests/Feature/Mirrors/UploadApiTest.php`
- Modify: `hub-php/routes/api/mirrors.php`

**Interfaces:**
- Consumes: `Bearer` (`app/Support/Bearer.php`), `HubKeys`, `UploadToken`, `ArchiveFilename` (Task 3). `begin_archive_upload` and `archive_upload_status` (Task 2).
- Produces:
  - `UploadFacts::parse(mixed $json): array|string` returns the normalised facts or an error message. `sha256` is 64 lowercase hex. `size` is an int of at least 1. `filename` passes `ArchiveFilename::isValid`. `kind` is `map` or `game`. `internal_name` is a string of 1 to 256 characters after trimming. `version` is a string of up to 256. `shortname` is required for a game, 1 to 64 characters. `author` is null or up to 256. `description` is null or up to 4000. Strings are stored as sent, apart from the trimmed `internal_name`.
  - `POST /api/v1/uploads`. Bearer required (401 as `Bearer` answers). The body may be at most 16384 bytes, a request cap on JSON facts and not a file size. Larger is 413. Invalid facts are 400 `{"error": msg, "reason": "bad_request"}`. The moderator flag comes from `$auth->client->rpc('is_moderator')`, which counts the second factor rule. No hub key configured is 503 `{"reason": "not_configured"}`. Answers by outcome:
    - `created` is 201 and `reissued` is 200, both `{"upload": {"id", "state": "uploading", "mirror", "upload_url": base."/upload.php", "limits_url": base."/limits.php", "token", "start_by", "reserved_until"}}`. `start_by = time() + token_window`. The token is `UploadToken::issue` with the archive id as upload id.
    - `unknown_game` is 422 and `not_owner` is 403. Each has an `error` message naming the reason and the shortname.
    - `duplicate` is 409 `{"error", "reason": "duplicate", "duplicate": {"check", "filename", "kind", "state", "url"}}`. `url` is the hub page when the existing archive is `listed`: `/maps/{slug}` for a map whose `map_name` equals its internal name (read with the admin client), `/games/{shortname}` for a game, absolute through `App\Support\Site`. Otherwise it is null.
    - `no_mirror` is 503.
    - Every answer has `Cache-Control: no-store` and the API's CORS headers.
  - `GET /api/v1/uploads/{id}`. Bearer required. A malformed UUID or a null result is 404. Otherwise 200 `{"upload": {"id", "state", "filename", "kind", "reserved_until"}}`, no-store.
  - `OPTIONS` preflight on both routes through `Api::preflight()`.

- [ ] **Step 1: Write the failing tests**

```php
// UploadFactsTest: one valid map, one valid game; each rule above broken once (data provider) returns a string
// UploadApiTest (keys.json hub_secret_key in config, Http::fake on auth/v1/user, rpc/is_moderator, rpc/begin_archive_upload)
public function test_created_upload_returns_a_token_the_fixture_hub_key_verifies(): void
// decode token payload: mirror, upload == id, sha256, size, filename, kind match; start_by == now + 300 (Carbon::setTestNow)
public function test_moderator_flag_is_passed_from_the_callers_own_client(): void
public function test_duplicate_is_409_with_the_check_and_a_map_page_url_when_listed(): void
public function test_not_owner_403_unknown_game_422_no_mirror_503(): void
public function test_no_bearer_is_401_and_nothing_called(): void
public function test_missing_hub_key_is_503_before_any_rpc(): void
public function test_status_of_someone_elses_upload_is_404(): void
public function test_status_returns_state_and_is_not_cached(): void
```

- [ ] **Step 2: Run them to verify they fail**

Run: `cd hub-php && php artisan test --filter='Upload'`
Expected: FAIL.

- [ ] **Step 3: Implement**

- [ ] **Step 4: Run the whole PHP suite**

Run: `cd hub-php && php artisan test`
Expected: PASS, no failures in existing tests.

- [ ] **Step 5: Commit**

```bash
git add hub-php/app/Domain/Mirrors hub-php/app/Http/Controllers/Mirrors hub-php/routes/api/mirrors.php hub-php/tests/Unit/Mirrors hub-php/tests/Feature/Mirrors
git commit -m "Issue upload tokens to signed-in Coilbox users"
```

---

### Task 7: Operator commands and deploy notes

**Files:**
- Modify: `hub-php/routes/console.php`, `hub-php/deploy/README.md`
- Create: `hub-php/tests/Feature/Mirrors/MirrorCommandsTest.php`

**Interfaces:**
- Consumes: `CatalogueStore`, `HubKeys`.
- Produces:
  - `php artisan mirrors:catalogue` calls `rebuild()`. It prints `catalogue seq N` and exits 0, or prints why it failed and exits 1. It never prints a key.
  - `php artisan mirrors:status` prints one line per mirror: id, enabled, healthy, last report time, `last_catalogue_seq`, and the catalogue time it holds. When a next key is set it prints `next key since seq N` and the mirrors still below N. The last line is `safe to switch keys` or `waiting for: <ids>`. Only enabled mirrors count. It also prints the stored catalogue's seq and whether it is stale.
  - `deploy/README.md` gets a section, "Mirror signing key". It covers making the key with `bin/keygen.php` from the mirror repository, setting `HUB_MIRROR_SECRET_KEY`, then `php artisan config:cache` in `current` and `sudo systemctl reload php8.4-fpm`, because `release.sh` caches config. It covers the sealed copy, and rotation with `HUB_MIRROR_NEXT_SECRET_KEY` and `mirrors:status`.

- [ ] **Step 1: Write the failing test**

```php
public function test_catalogue_command_prints_the_seq(): void
public function test_status_lists_mirrors_waiting_for_the_next_key(): void
public function test_catalogue_command_fails_without_a_key(): void
```

- [ ] **Step 2: Run it to verify it fails**, **Step 3: implement**, **Step 4: run `php artisan test`**, expected PASS.

- [ ] **Step 5: Commit**

```bash
git add hub-php/routes/console.php hub-php/deploy/README.md hub-php/tests/Feature/Mirrors/MirrorCommandsTest.php
git commit -m "Add mirror catalogue and status commands"
```

---

### Task 8: Mirror download sources for maps and games

**Files:**
- Create: `supabase/migrations/20261117120300_hub_mirror_downloads.sql`, `supabase/tests/hub_mirror_downloads.test.sql`
- Modify: `hub-php/app/Domain/Games/Download.php`, `hub-php/resources/views/components/maps/downloads.blade.php`, `hub-php/tests/Unit/Games/DownloadTest.php`, `hub-php/tests/Feature/Maps/MapPagesTest.php`

**Interfaces:**
- Consumes: Task 1 tables.
- Produces:
  - `public.url_path_segment(p text) returns text`, immutable. It percent-encodes every UTF-8 byte outside `A-Z a-z 0-9 - _ . ~` as uppercase `%XX`, as PHP's `rawurlencode` does.
  - `public.map_mirror_sources(p_map_name text) returns jsonb` and `public.game_mirror_sources(p_game_id uuid) returns jsonb`. Both are `security definer`, stable, and executable by anon, authenticated and service_role. Each returns a list of `{"kind": "url", "value": base_url || '/maps/' || url_path_segment(filename), "filename", "sha256"}`, using `/games/` for games. There is one entry per enabled, healthy mirror holding the archive, ordered by mirror id. The map archive is the newest-listed `listed` map archive whose `internal_name = p_map_name`. The game archive is the newest-listed `listed` archive with `game_id = p_game_id`. Otherwise the list is `[]`.
  - `map_facts` is restated from `20261115140000_map_download_sources.sql` with `'downloads'` as `coalesce(downloads.entries, '[]'::jsonb) || public.map_mirror_sources(wanted.map_name)`.
  - `game_browse` is restated from `20261021120000_game_base_game.sql` with `downloads` as the owner's list `|| public.game_mirror_sources(g.id)`. Columns and order are unchanged, so `create or replace view` works. The grants are restated.
  - `Download::readDownloads` keeps `sha256` on a `url` entry when it is 64 lowercase hex, and drops it otherwise.
  - The map page's Download section labels an entry with a `sha256` as `Download {filename} from {host}`, where host is `parse_url(value, PHP_URL_HOST)`, so two mirrors' buttons differ.

- [ ] **Step 1: Write the failing tests**

```sql
-- url_path_segment('Test Map (v1.2) é.sd7') = 'Test%20Map%20%28v1.2%29%20%C3%A9.sd7'
-- a listed map archive held by a healthy mirror appears in map_facts downloads after an owner source, with sha256
-- the same held by an unhealthy or disabled mirror does not appear
-- an uploading archive does not appear
-- game_browse downloads for a game end with the mirror source; anon can read game_browse and sees it
-- anon still cannot select from public.archive
```

```php
// DownloadTest
public function test_sha256_is_kept_on_url_sources_and_dropped_when_malformed(): void
// MapPagesTest
public function test_a_mirror_source_is_labelled_with_its_host(): void
```

- [ ] **Step 2: Run them to verify they fail.** Run: `supabase test db supabase/tests/hub_mirror_downloads.test.sql` and `cd hub-php && php artisan test --filter='DownloadTest|MapPagesTest'`.

- [ ] **Step 3: Implement**

- [ ] **Step 4: Run every pgTAP file and the PHP suite**

Run: `supabase db reset`, then `supabase test db`, then `cd hub-php && php artisan test`.
Expected: PASS. `map_download_source.test.sql`, `game_browse.test.sql` and `game_download_source.test.sql` must still pass unchanged.

- [ ] **Step 5: Commit**

```bash
git add supabase/migrations/20261117120300_hub_mirror_downloads.sql supabase/tests/hub_mirror_downloads.test.sql hub-php/app/Domain/Games/Download.php hub-php/resources/views/components/maps/downloads.blade.php hub-php/tests/Unit/Games/DownloadTest.php hub-php/tests/Feature/Maps/MapPagesTest.php
git commit -m "Link healthy mirror copies of maps and games"
```

---

### Task 9: Local end-to-end run

**Files:**
- Create: `scripts/mirrors-e2e.sh`, `scripts/mirrors-e2e-upload.php`

Do this after Tasks 1 to 8. It needs local Supabase running, which only the lead starts.

**Interfaces:**
- `scripts/mirrors-e2e.sh [--keep]` does the following, then stops every server it started and deletes its temp folder unless `--keep` is given.
  1. Make a temp folder. Write two mirrors from `~/dev/coilbox-mirror` into it with rsync, excluding the same files as the mirror plan's Task 16. Give each a key pair from `bin/keygen.php`. Their `hub_url` is `http://127.0.0.1:8201`. They serve on ports 8202 and 8203 with `php -S`, using the mirror repository's `tests/Support/mirror-router.php` as the router.
  2. Make a test hub key pair. Insert the two mirrors as `e2e-a` and `e2e-b`, healthy, into the local database with `psql` against `supabase status`'s DB URL. Disable `jj-darkstars` locally.
  3. Start the hub with `php artisan serve --port=8201`. The env points `SUPABASE_URL` and both keys at local Supabase, from `supabase status -o env`, with the test hub key.
  4. Create a local test user through the local Auth admin API and sign in for an access token.
  5. Run `scripts/mirrors-e2e-upload.php`. It acts as Coilbox: it makes a 2048-byte test map file named `E2E Map (v1.0) é.sd7`, calls `POST /api/v1/uploads`, reads `limits.php`, sends the file in two chunks, then polls `GET /api/v1/uploads/{id}` until `listed`. It gives up after 30 polls a second apart. These counts are test pacing, not a product limit.
  6. Run `sync.php --verbose` on both mirrors. Assert that both hold the file with the same SHA-256, and that `GET /api/v1/catalogue` lists it and verifies against the test hub public key.
  7. Remove the two e2e mirror rows and the test archive, and enable `jj-darkstars` again.

- [ ] **Step 1: Write both scripts**
- [ ] **Step 2: Run `scripts/mirrors-e2e.sh`.** Expected: the last line is `e2e ok` and the exit status is 0.
- [ ] **Step 3: Commit**

```bash
git add scripts/mirrors-e2e.sh scripts/mirrors-e2e-upload.php
git commit -m "Add a local end to end run of upload and sync across two mirrors"
```

---

### Task 10: Go live and run one real sync

Do not start this task without the owner. It makes the production key, writes production env, applies production migrations and changes jj.darkstars.co.uk.

**Files:** none, unless a step finds a bug. Fix that in the earlier task's files with a test.

- [ ] **Step 1: Make the hub key pair.** On the owner's machine, run `php ~/dev/coilbox-mirror/bin/keygen.php`. The `secret_key` value is the hub secret. The printed public key is the hub public key. Nothing is written to disk.
- [ ] **Step 2: Sealed copy.** The owner gives the secret key to a second trusted person, sealed, as the spec's risks section asks. Record the date and the person in the owner's own notes, not in a repository.
- [ ] **Step 3: Droplet env.** Add `HUB_MIRROR_SECRET_KEY=<secret>` to `/srv/www/hub.coilbox.org/shared/.env`. The deploy that follows in Step 5 runs `config:cache`.
- [ ] **Step 4: Production migrations.** Run `supabase migration list --linked`, read every pending migration, then run `supabase db push --linked` once the owner has approved the PR. Run `supabase migration list --linked` again to confirm. This must come before Step 5, because the new `map_facts` and `game_browse` call functions that only these migrations add.
- [ ] **Step 5: Deploy.** Merge the PR. The deploy workflow ships `hub-php/`. Then run `php artisan mirrors:catalogue` in `/srv/www/hub.coilbox.org/current`. Expected: `catalogue seq 1`.
- [ ] **Step 6: Check the catalogue from outside.** `curl -s https://hub.coilbox.org/api/v1/catalogue` and `curl -s https://hub.coilbox.org/api/v1/catalogue.sig`. Verify the signature against the hub public key with a one-line PHP sodium call, using the catalogue context. The catalogue lists jj-darkstars and no archives.
- [ ] **Step 7: Trust the hub key on jj.** On jj.darkstars.co.uk, replace the placeholder in `hub_keys` in `~/jj.darkstars.co.uk/private/config.php` with the hub public key. Run `/usr/local/php85/bin/php bin/check.php` there. Expected: `ok`. `curl -s -o /dev/null -w '%{http_code}' https://jj.darkstars.co.uk/upload.php` now answers 400, not 503.
- [ ] **Step 8: One real sync.** Run `ssh jj.darkstars.co.uk '/usr/local/php85/bin/php ~/jj.darkstars.co.uk/sync.php --verbose'`. Expected: exit 0, the catalogue accepted at seq 1, and a report sent.
- [ ] **Step 9: Confirm the hub recorded it.** Run `php artisan mirrors:status` on the droplet. Expected: jj-darkstars has a last report time from Step 8, `last_catalogue_seq` 1, and healthy `yes`, because jj holds no listed archive and its `listing.json` answers 200.
- [ ] **Step 10: Hand back.** The owner chooses `sync_time_limit` in jj's config and adds the cron job. Nobody else adds cron.
