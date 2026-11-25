# Hub engines (hub part) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The hub accepts engine uploads from grant holders, lists engines in the signed catalogue, and shows them through a public API and two unlisted pages.

**Architecture:** An engine is an `archive` row with kind `engine`, so uploads, reports, removal and the catalogue reuse `ArchiveFunctions` and `MirrorFunctions` in `app/Supabase/Local/`. Engine-only rules live in `app/Domain/Engines/`. Everything reads and writes MariaDB. The Supabase path is not supported for engines and is not touched.

**Tech Stack:** Laravel, PHPUnit on SQLite in memory (`phpunit.xml`), Blade, Pint.

**Spec:** `docs/superpowers/specs/2026-11-25-hub-engines-design.md`

Run everything from `hub-php/`. Read `hub-php/AGENTS.md` first.

## Global Constraints

- Platforms: exactly `amd64-linux`, `amd64-windows`, `arm64-macos`.
- Engine filename: `recoil_<version>_<platform>.7z`. Version is 1 to 64 characters from `[0-9A-Za-z.+-]`, starting with a letter or digit. The 64 is a policy choice.
- Engine internal name is always `recoil`. Note is plain text, 1 to 1,000 characters after trimming, stored in `archive.description`.
- Warning text, used verbatim on both pages: `Unofficial test build. Not made or supported by the Recoil team. It may crash, desync in multiplayer or damage your settings. Use it only if you know why you want it.`
- Engine pages use `'robots' => 'noindex'`, are not in the nav, and are not in `App\Domain\Seo\Sitemap`.
- Listing an engine awards no community points.
- Engines never go in the catalogue's `archives` list.
- Copy follows the repo's writing rules: plain English, sentence case, no emoji.

## Review Focus

- A version with dots, hyphens and plus signs, such as `2026.09.01-137-g15f18d5`, must work in page URLs and the API. Test in Task 7.
- A mirror that reports without `kinds`, for example after going back to an older release, must stop getting engine uploads. Test in Task 4.
- A map upload must still be refused when an archive has the same name and version, after the unique index changes. Test in Task 1.
- A note containing HTML and line breaks must show as text with its line breaks, not as markup. Test in Task 7.
- A removed engine must be in the catalogue's `removed` list and gone from the API and pages. Test in Tasks 5 and 7.

---

### Task 1: Migration

**Files:**
- Create: `database/migrations/2026_12_01_120000_add_engines.php`
- Test: `tests/Feature/Engines/EngineSchemaTest.php`

**Interfaces:**
- Produces: table `engine_uploader` (`user_id` char 36 primary, `granted_by` char 36, `granted_at` datetime(6)). Column `archive.platform` string(16), not null, default `''`. Unique index `archive_name_version_idx` on (`internal_name`, `version`, `platform`, `live`). Column `mirror.takes_engines` boolean, default false.

- [ ] **Step 1: Write the failing tests** (`RefreshDatabase`, insert rows with `DB::table`, copying the archive row shape from `LocalMirrorsTest::archive()`)

```php
test_two_engines_with_one_version_on_different_platforms_both_insert()
    // kind engine, internal_name recoil, version 2026.09.03, platforms arm64-macos and amd64-linux, different sha256 and filename: both insert
test_two_engines_with_one_version_and_platform_clash()
    // same as above with one platform: second insert throws UniqueConstraintViolationException
test_two_maps_with_one_name_and_version_still_clash()
    // two kind map rows, platform left at default, same internal_name and version: second throws
test_a_failed_archive_frees_its_name_version_and_platform()
    // first row state failed, second same facts listed: inserts
test_mirror_takes_engines_defaults_to_false()
```

- [ ] **Step 2: Run** `php artisan test tests/Feature/Engines/EngineSchemaTest.php`. Expected: fail, no `platform` column.

- [ ] **Step 3: Write the migration.** Follow `2026_11_30_120000_create_mirror_request_table.php` for `binary()`. In `up()`, drop `archive_name_version_idx` and add it again with `platform`. `down()` reverses it. It must run on SQLite and MariaDB.

- [ ] **Step 4: Run** the test file again. Expected: pass. Then `php artisan test tests/Feature/Mirrors`. Expected: pass.

- [ ] **Step 5: Commit** `Add the tables and columns engines need`

---

### Task 2: Engine filename and upload facts

**Files:**
- Create: `app/Domain/Engines/EngineFilename.php`
- Modify: `app/Domain/Mirrors/UploadFacts.php`
- Test: `tests/Unit/Engines/EngineFilenameTest.php`, `tests/Unit/Mirrors/UploadFactsTest.php`

**Interfaces:**
- Produces: `EngineFilename::PLATFORMS` (list from Global Constraints). `EngineFilename::parse(string $filename): ?array{version: string, platform: string}`.
- Produces: `UploadFacts::parse()` return shape gains `platform: string`. It is `''` for map and game, as is every map or game result.
- Produces: `App\Domain\Engines\Engines::NOTE_MAX = 1000` and `Engines::WARNING` (verbatim text). Create `Engines` here with only these two constants. Task 6 adds its methods.

- [ ] **Step 1: Write failing tests**

```php
// EngineFilenameTest
parse('recoil_2026.09.03_arm64-macos.7z') === ['version' => '2026.09.03', 'platform' => 'arm64-macos']
parse('recoil_2026.09.01-137-g15f18d5_amd64-linux.7z')['version'] === '2026.09.01-137-g15f18d5'
parse('recoil_2026.09.03_arm64-macos.sd7') === null
parse('recoil_2026.09.03_riscv-linux.7z') === null
parse('recoil__arm64-macos.7z') === null
parse('recoil_'.str_repeat('1', 65).'_arm64-macos.7z') === null
parse('recoil_a/b_arm64-macos.7z') === null
parse('RECOIL_2026.09.03_arm64-macos.7z') === null   // exact case, as official releases are named

// UploadFactsTest, engine body: sha256, size, filename recoil_2026.09.03_arm64-macos.7z, kind engine, version 2026.09.03, platform arm64-macos, note "Tests the Metal path."
test_an_engine_is_accepted_with_internal_name_recoil_and_the_note_as_description()
    // internal_name 'recoil', shortname null, author null, description 'Tests the Metal path.', platform 'arm64-macos'
test_an_engine_filename_must_agree_with_its_version_and_platform()
    // version 2026.09.02 with that filename -> string message
test_an_engine_note_is_required_and_at_most_1000_characters()
    // '   ' -> message; str_repeat('a', 1001) -> message; str_repeat('a', 1000) -> array
test_an_engine_needs_a_known_platform()
test_a_map_has_an_empty_platform()
test_kind_message_names_all_three_kinds()   // 'kind must be "map", "game" or "engine".'
```

- [ ] **Step 2: Run** `php artisan test tests/Unit/Engines tests/Unit/Mirrors/UploadFactsTest.php`. Expected: fail.

- [ ] **Step 3: Implement.** Pattern for `parse`: `/\Arecoil_([0-9A-Za-z][0-9A-Za-z.+-]{0,63})_(amd64-linux|amd64-windows|arm64-macos)\.7z\z/`. In `UploadFacts::parse`, branch on kind `engine` after the sha256 and size checks. An engine skips `ArchiveFilename::isValid`. A map or game keeps its current rules.

- [ ] **Step 4: Run** the same tests. Expected: pass.

- [ ] **Step 5: Commit** `Read engine facts from an upload request`

---

### Task 3: Grants and engine uploads

**Files:**
- Create: `app/Domain/Engines/EngineUploaders.php`
- Modify: `app/Supabase/Local/ArchiveFunctions.php` (`begin`, `healthyMirror`, `duplicate`, insert), `app/Domain/Mirrors/Uploads.php` (`refused`, `pageUrl`)
- Test: `tests/Feature/Engines/EngineUploadTest.php`

**Interfaces:**
- Consumes: Task 1 schema, Task 2 facts.
- Produces: `EngineUploaders::has(?string $userId): bool`, `grant(string $userId, string $grantedBy): bool` (false when already granted), `revoke(string $userId): bool` (false when there was none), `all(): list<array{user_id: string, granted_by: string, granted_at: string}>` newest first.
- Produces: `begin_archive_upload` refusal reasons `not_engine_uploader` and `no_engine_mirror`. Duplicate `existing` gains `version` and `platform`.
- Produces: `Engines::pagePath(string $version, string $platform): string`, which returns `'/engines/'.rawurlencode($version).'/'.rawurlencode($platform)`. Add it to `Engines` here.

- [ ] **Step 1: Write failing tests.** Copy the setup, `upload()` and `fakeUploader()` from `LocalMirrorsTest`. Set `mirror.takes_engines` true in setup.

```php
test_a_grant_holder_uploads_an_engine()
    // 201, row kind engine, platform arm64-macos, internal_name recoil, description is the note
test_a_moderator_uploads_an_engine_without_a_grant()
test_anyone_else_is_refused_with_403()
    // reason not_engine_uploader, error "You can't upload engines. Ask a moderator for the engine uploader grant.", no row
test_only_a_mirror_that_takes_engines_is_chosen()
    // add a second healthy mirror without takes_engines, repeat 10 uploads with distinct hashes and filenames: every upload_url is the first mirror's
test_no_mirror_taking_engines_is_503()
    // reason no_engine_mirror, error "No mirror takes engines yet."
test_a_map_upload_still_uses_any_healthy_mirror()
test_the_same_version_on_another_platform_is_not_a_duplicate()
test_the_same_version_and_platform_is_a_duplicate_linking_the_engine_page()
    // 409, duplicate.check name_version, duplicate.url https://hub.test/engines/2026.09.03/arm64-macos when listed
test_a_revoked_grant_stops_uploads()
```

- [ ] **Step 2: Run** `php artisan test tests/Feature/Engines/EngineUploadTest.php`. Expected: fail.

- [ ] **Step 3: Implement.**
  - `EngineUploaders` uses `DB::table('engine_uploader')` with `Values::now()` for `granted_at`.
  - In `begin`, refuse a non-moderator without a grant before the duplicate check.
  - `healthyMirror(?string $id, bool $engine)` adds `where('takes_engines', true)` when `$engine`. It applies to both the reissue path and the new-upload path.
  - The `name_version` duplicate check adds `where('platform', $facts['platform'])`.
  - Insert `platform`.
  - `Uploads::refused` maps the two new reasons to the messages above.
  - `pageUrl` returns `Site::absolute(Engines::pagePath(...))` for a listed engine.

- [ ] **Step 4: Run** the new tests and `php artisan test tests/Feature/Mirrors`. Expected: pass.

- [ ] **Step 5: Commit** `Let grant holders and moderators upload engines`

---

### Task 4: Reports, health checks and points

**Files:**
- Modify: `app/Domain/Mirrors/Report.php` (`validate`), `app/Supabase/Local/MirrorFunctions.php` (`report`), `app/Domain/Mirrors/HealthCheck.php`
- Test: `tests/Unit/Mirrors/ReportTest.php`, `tests/Unit/Mirrors/HealthCheckTest.php`, `tests/Feature/Engines/EngineReportTest.php`

**Interfaces:**
- Produces: a report payload may carry `kinds`, a list of strings. A missing `kinds` is valid. Anything else is `bad_request`.
- Produces: each recorded report sets `mirror.takes_engines` to whether `kinds` holds `engine`.

- [ ] **Step 1: Write failing tests**

```php
// ReportTest
validate(payload + ['kinds' => ['map', 'game', 'engine']]) === true
validate(payload + ['kinds' => 'engine']) === false
validate(payload + ['kinds' => [1]]) === false
validate(payload without kinds) === true

// HealthCheckTest
check('https://m.test', ['filename' => 'recoil_2026.09.03_arm64-macos.7z', 'kind' => 'engine', 'size' => 43]) sends HEAD https://m.test/engines/recoil_2026.09.03_arm64-macos.7z

// EngineReportTest. Signed reports: follow how LocalMirrorsTest signs fixture reports. Sign a payload with the fixture mirror key and kinds set.
test_a_report_with_engine_in_kinds_sets_takes_engines()
test_a_report_without_kinds_clears_takes_engines()
test_listing_an_engine_awards_no_points()
    // uploading engine row, report holding its hash: state listed, no community points row for it (see tests/Feature/Points/ArchiveAndOwnerPointsTest.php for how points are read)
test_listing_a_map_still_awards_points()
```

- [ ] **Step 2: Run** `php artisan test tests/Unit/Mirrors tests/Feature/Engines/EngineReportTest.php`. Expected: fail.

- [ ] **Step 3: Implement.**
  - `HealthCheck` maps kind to a folder: `map` to `maps`, `game` to `games`, `engine` to `engines`.
  - In `MirrorFunctions::report`, read `kind` with `uploader` for both award paths and skip `CommunityPoints::award` for `engine`.
  - Write `takes_engines` in the same `mirror` update as `last_report_at`.

- [ ] **Step 4: Run** the same tests plus `php artisan test tests/Feature/Mirrors tests/Feature/Points`. Expected: pass.

- [ ] **Step 5: Commit** `Record which mirrors take engines`

---

### Task 5: Catalogue

**Files:**
- Modify: `app/Supabase/Local/MirrorFunctions.php` (`catalogueSource`), `app/Domain/Mirrors/Catalogue.php`
- Test: `tests/Unit/Mirrors/CatalogueTest.php`, `tests/Feature/Engines/EngineCatalogueTest.php`

**Interfaces:**
- Produces: `catalogue_source` returns `engines`: listed engines ordered by `listed_at`, then `id`, each `{filename, sha256, size, version, platform, note}`. `archives` excludes kind `engine`.
- Produces: `Catalogue::encode` writes `engines` after `removed`, with keys in the order above, only when the list is not empty. With no engines, the bytes are exactly what they are today, so the shared fixture `tests/Fixtures/mirror/catalogue.json` stays valid.

- [ ] **Step 1: Write failing tests**

```php
// CatalogueTest
test_engines_follow_removed_in_order()            // decode the JSON and check the key order
test_no_engines_writes_no_engines_key()           // existing fixture comparison still passes unchanged
// EngineCatalogueTest
test_a_listed_engine_is_in_engines_and_not_archives()
test_a_removed_engine_is_in_removed()
test_editing_nothing_else_keeps_map_entries_unchanged()
```

- [ ] **Step 2: Run** `php artisan test tests/Unit/Mirrors/CatalogueTest.php tests/Feature/Engines/EngineCatalogueTest.php`. Expected: fail.

- [ ] **Step 3: Implement.** Use `$source['engines'] ?? []` in `encode`.

- [ ] **Step 4: Run** the same tests plus `php artisan test tests/Feature/Mirrors`. Expected: pass.

- [ ] **Step 5: Commit** `List engines in the signed catalogue`

---

### Task 6: Engines API and profile flag

**Files:**
- Modify: `app/Domain/Engines/Engines.php`, `app/Http/Controllers/Gallery/ProfileApiController.php`
- Create: `app/Http/Controllers/Engines/EngineApiController.php`, `routes/api/engines.php`
- Test: `tests/Feature/Engines/EngineApiTest.php`

**Interfaces:**
- Produces: `Engines::listed(?string $platform): list<array{id: string, version: string, platform: string, filename: string, size: int, sha256: string, note: string, listed_at: string, page_url: string, sources: list<string>}>`. Listed engines only, newest `listed_at` first. `listed_at` is ISO 8601 UTC. `sources` are `{base_url}/engines/{rawurlencode(filename)}` for enabled, healthy mirrors holding the engine, ordered by mirror id.
- Produces: `Engines::find(string $version, string $platform): ?array`. Same shape plus `uploader: ?string`. Listed engines only, even with no sources.
- Produces: `GET /api/v1/engines` and `OPTIONS /api/v1/engines`. `bootstrap/app.php:22` loads every `routes/api/*.php` file under `api/v1`, so the new file needs no registration.
- Produces: `can_upload_engines` in `GET /api/v1/profile`.

- [ ] **Step 1: Write failing tests**

```php
test_the_api_lists_engines_with_their_sources_newest_first()
    // body {"engines":[{version, platform, filename, size, sha256, note, listed_at, page_url "https://hub.test/engines/2026.09.03/arm64-macos", sources ["https://mirror.test/engines/recoil_2026.09.03_arm64-macos.7z"]}]}; no id or uploader keys in the API output
test_the_api_filters_by_platform()
test_an_unknown_platform_is_400()
test_an_engine_no_healthy_mirror_holds_is_left_out()
test_removed_engines_and_maps_are_not_listed()
test_the_api_needs_no_sign_in_and_allows_any_origin()
test_profile_says_whether_the_caller_can_upload_engines()
    // false for a plain user, true with a grant, true for a moderator
```

- [ ] **Step 2: Run** `php artisan test tests/Feature/Engines/EngineApiTest.php`. Expected: fail.

- [ ] **Step 3: Implement.**
  - Respond with `Api::json`, using the same `Cache-Control` as `CatalogueController::show`.
  - The controller removes `id` and `uploader` before responding.
  - For the profile, use `Access::isModerator($auth->client) || EngineUploaders::has($auth->userId)`.

- [ ] **Step 4: Run** the same tests plus `php artisan test tests/Feature/Gallery`. Expected: pass.

- [ ] **Step 5: Commit** `Publish the engine list for Coilbox`

---

### Task 7: Engine pages

**Files:**
- Modify: `app/Domain/Engines/Engines.php`, `app/Supabase/Local/ArchiveFunctions.php` (`moderationRows` page link)
- Create: `app/Http/Controllers/Engines/EnginesController.php`, `routes/web/engines.php`, `resources/views/engines/index.blade.php`, `resources/views/engines/show.blade.php`
- Test: `tests/Feature/Engines/EnginePagesTest.php`

**Interfaces:**
- Produces: `Engines::setNote(string $archiveId, string $userId, bool $moderator, string $note): string`, which returns `saved`, `not_allowed`, `not_found` or `bad_note`. It saves through `MirrorFunctions::touch` and calls `MirrorFunctions::bump()`, so the catalogue rebuilds.
- Produces routes:
  - `GET /engines`
  - `GET /engines/{version}/{platform}`
  - `POST /engines/{version}/{platform}/note`
  - `POST /engines/{version}/{platform}/withdraw`, which calls `Removal::remove(HubSession::client(), $id, 'withdrawn', null)`

- [ ] **Step 1: Write failing tests.** Extend `ModerationTestCase` with `RefreshDatabase`, and use `signedIn()` for the session.

```php
test_the_list_shows_the_warning_and_engines_by_platform()
test_the_list_and_page_are_noindex_and_not_in_the_sitemap()
    // <meta name="robots" content="noindex"> on both; GET /sitemap.xml has no /engines
test_the_nav_has_no_engines_link()
test_a_version_with_dots_hyphens_and_plus_works()
    // version 2026.09.01-137-g15f18d5+mac, page 200, page_url path rawurlencoded
test_the_page_shows_sha256_and_a_link_per_healthy_mirror()
test_an_engine_with_no_healthy_mirror_says_none_hold_it()   // text: "No mirror holds this build right now."
test_a_removed_or_unknown_engine_is_404()
test_a_note_with_html_and_line_breaks_shows_as_text()
    // note "<b>x</b>\nsecond line": response contains &lt;b&gt; and not <b>x</b>; element has class whitespace-pre-line
test_the_uploader_edits_the_note_and_the_catalogue_changes()
    // catalogue_state.changes goes up by 1
test_a_stranger_cannot_edit_or_withdraw()
test_the_uploader_withdraws_and_the_engine_is_gone()
test_moderators_see_the_remove_form()
test_moderation_archives_links_an_engine_to_its_page()
```

- [ ] **Step 2: Run** `php artisan test tests/Feature/Engines/EnginePagesTest.php`. Expected: fail.

- [ ] **Step 3: Implement.**
  - Base the layout on `resources/views/maps/show.blade.php`.
  - Pass `'robots' => 'noindex'` to `layouts.app`.
  - Show the note with `{{ }}` in an element with `whitespace-pre-line`.
  - For the moderator remove form, include `resources/views/moderation/partials/archive-remove.blade.php`.
  - A page lookup on a removed or unknown engine calls `abort(404)`.

- [ ] **Step 4: Run** the same tests plus `php artisan test tests/Feature/SeoTest.php tests/Feature/Mirrors`. Expected: pass.

- [ ] **Step 5: Commit** `Add unlisted engine pages`

---

### Task 8: Moderation list of engine uploaders

**Files:**
- Create: `app/Http/Controllers/Moderation/EngineUploadersController.php`, `resources/views/moderation/engine-uploaders.blade.php`
- Modify: `routes/web/moderation.php`, `resources/views/components/moderation/nav.blade.php`
- Test: `tests/Feature/Moderation/EngineUploadersTest.php`

**Interfaces:**
- Consumes: `EngineUploaders` (Task 3), `Accounts::fetch($client, $search, 0)` for finding accounts by name.
- Produces routes:
  - `GET /moderation/engines`, with an optional `q` to search accounts
  - `POST /moderation/engines/grant` with `user_id`
  - `POST /moderation/engines/revoke` with `user_id`

- [ ] **Step 1: Write failing tests**

```php
test_a_signed_out_visitor_and_a_stranger_get_404_and_write_nothing()
test_the_page_lists_grants_with_names_and_who_granted()
test_searching_lists_matching_accounts_with_a_grant_button()
test_granting_adds_a_row_and_says_so()
test_granting_twice_says_already_granted()
test_revoking_removes_the_row_and_keeps_their_engines()
test_the_nav_links_the_page()
```

- [ ] **Step 2: Run** `php artisan test tests/Feature/Moderation/EngineUploadersTest.php`. Expected: fail.

- [ ] **Step 3: Implement.** Follow `ArchivesController`:
  - Every action starts with `Access::moderatorClient()`.
  - A refusal calls `Access::refuse('/moderation/engines')`.
  - Results go in a session flash, `engineUploadersState`, with `ok` and `message`.
  - The page heading is "Engine uploaders".

- [ ] **Step 4: Run** `php artisan test tests/Feature/Moderation`. Expected: pass.

- [ ] **Step 5: Commit** `Let moderators grant engine uploads`

---

### Task 9: Whole suite

- [ ] **Step 1: Run** `vendor/bin/pint --test`. Expected: no files listed. If any are, run `vendor/bin/pint` on the changed files only and commit `Format`.
- [ ] **Step 2: Run** `php artisan test`. Expected: every test passes, with the count reported.
- [ ] **Step 3: Run** `php artisan migrate:fresh` against local MariaDB, started with `scripts/local-services.sh up mariadb`. Expected: no errors. Then `scripts/local-services.sh down`.
