# Community points implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Accounts earn community points for adding to the hub, see them on their own profile, and Coilbox reads them from `GET /api/v1/profile/points`.

**Architecture:** One MariaDB table, `community_point`, holds one row per award. `App\Domain\Points\CommunityPoints` adds and removes rows. Each write path calls it at the point where the hub knows something new was stored or taken away. Where that point is inside `app/Supabase/Local/`, the call goes there, because the controllers do not know the ids.

**Tech stack:** Laravel, PHP 8.3 and 8.4, MariaDB in production, SQLite in memory in local tests, PHPUnit, Tailwind 4.

**Spec:** `docs/superpowers/specs/2026-11-23-community-points-design.md`

## Global constraints

- All work is in `hub-php/`. Read `hub-php/AGENTS.md` first.
- Queries must run on SQLite and MariaDB.
- Points per kind: `game_owner` 100, `archive` 20, `item` 20, `picture` 20, `unit_picture` 1, `game_facts` 5, `map` 5, `report` 5, `first_text` 5.
- A points failure never fails the contribution. `award` and `revoke` catch `QueryException`, call `report($e)` and return.
- `/api/v1/profile` keeps its shape. The new endpoint is separate.
- The colour token is `--points: #fbbf24`, exposed to Tailwind as `--color-points`.
- Comments explain why, briefly. No semicolons or em dashes in comments.
- No new Composer packages.
- Hooks in `app/Supabase/Local/` only run while that area's store is MariaDB. Production has every area in MariaDB, so this is accepted.

## Review focus

1. Coilbox resends the same facts, maps or archives. Expect no new rows. Tasks 5 and 6 test it.
2. A moderator approves a picture that another account replaced. Expect the old uploader's row to go and the new uploader's row to arrive. Task 4 tests it.
3. An author restores a withdrawn gallery item. Expect the 20 points back. Task 3 tests it.
4. A visitor views someone else's profile, signed in or signed out. Expect no points shown. Task 2 tests it.
5. The `community_point` table is missing or the insert fails. Expect the contribution to succeed. Task 1 tests it by dropping the table.

---

### Task 1: Table and `CommunityPoints`

**Files:**
- Create: `database/migrations/2026_11_29_120000_create_community_point_table.php`
- Create: `app/Domain/Points/CommunityPoints.php`
- Test: `tests/Unit/Points/CommunityPointsTest.php` (use `RefreshDatabase`, matching the nearest test that touches `DB::table`, such as the `Downloads` tests)

**Interfaces:**
- Produces:
  - `CommunityPoints::POINTS`, an `array<string, int>` keyed by kind, with the values in Global constraints
  - `CommunityPoints::award(?string $userId, string $kind, string $subject): void`, which does nothing when `$userId` is null
  - `CommunityPoints::revoke(string $kind, string $subject): void`
  - `CommunityPoints::total(string $userId): int`
  - kind constants `GAME_OWNER`, `ARCHIVE`, `ITEM`, `PICTURE`, `UNIT_PICTURE`, `GAME_FACTS`, `MAP`, `REPORT`, `FIRST_TEXT`, with the string values in Global constraints

Table columns: `id`, `user_id char(36)`, `kind string(32)`, `subject string(255)`, `points unsignedSmallInteger`, `created_at dateTime(6)`. Add a unique key `community_point_award_idx` on (`user_id`, `kind`, `subject`) and an index on `user_id`. Copy the `self::binary($table)` call that other hub migrations use, if they define one.

- [ ] **Step 1: Write the failing tests**
  - `test_an_award_adds_the_kinds_points`: award `ITEM` once, then `total` is 20.
  - `test_awarding_twice_adds_one_row`: award the same row twice, then the row count is 1 and the total is 20.
  - `test_revoke_removes_every_holders_row`: two users hold `GAME_OWNER` for `g1`. Revoke, then both totals are 0.
  - `test_first_text_is_earned_once_across_accounts`: user A then user B award `FIRST_TEXT` for `game:g1:description`. A has 5 and B has 0.
  - `test_a_null_user_earns_nothing`: the row count stays at 0.
  - `test_a_failed_write_does_not_throw`: drop `community_point`, then `award` and `revoke` return without throwing.
- [ ] **Step 2: Run `php artisan test --filter=CommunityPointsTest`.** Expect a failure because the class is missing.
- [ ] **Step 3: Write the migration and the class.** Use `insertOrIgnore` for `award`. For `FIRST_TEXT`, skip the insert when any row with that kind and subject exists.
- [ ] **Step 4: Run the tests again.** Expect a pass.
- [ ] **Step 5: Commit** with the message "Add the community points table and the class that awards them".

### Task 2: API endpoint and profile display

**Files:**
- Create: `app/Http/Controllers/Gallery/PointsApiController.php`, following `ProfileApiController`
- Modify: `routes/api/profile.php`, adding `Route::get('/profile/points', [PointsApiController::class, 'show']);`
- Modify: `app/Http/Controllers/Gallery/ProfileController.php`, passing `'points' => HubSession::userId() === $accountId ? CommunityPoints::total($accountId) : null` to the view, outside `Contributions::cached`
- Modify: `resources/views/gallery/profile.blade.php`, below the `<h1>` at line 24
- Modify: `resources/css/app.css`, adding `--points: #fbbf24;` beside `--brand` and `--color-points: var(--points);` in `@theme inline`
- Test: `tests/Feature/Gallery/PointsTest.php`, using the bearer and session helpers from `tests/Feature/Gallery/LocalItemsTest.php`

**Interfaces:**
- Consumes: `CommunityPoints::total`

The markup when `$points !== null` is `<p class="mt-1 text-sm"><span class="font-semibold text-points">{{ number_format($points) }}</span> community points</p>`.

- [ ] **Step 1: Write the failing tests**
  - `test_the_api_answers_the_callers_points`: give the user 25 points. `GET /api/v1/profile/points` with a bearer token returns 200, the JSON `{"points":25}` and a `Cache-Control` containing `private` and `no-store`.
  - `test_the_api_answers_zero_for_an_account_with_no_points`.
  - `test_the_api_refuses_without_a_token`: expect 401.
  - `test_the_owner_sees_their_points_on_their_profile`: the page contains `25` and `community points`.
  - `test_another_account_does_not_see_the_points`.
  - `test_a_signed_out_visitor_does_not_see_the_points`.
- [ ] **Step 2: Run `php artisan test --filter=PointsTest`.** Expect a failure.
- [ ] **Step 3: Implement the four changes listed under Files, then run `bun run build`.**
- [ ] **Step 4: Run the tests again.** Expect a pass.
- [ ] **Step 5: Commit** with the message "Show community points to their owner and serve them to Coilbox".

### Task 3: Gallery items

**Files:**
- Modify: `app/Http/Controllers/Publish/PublishController.php`, after `$outcome['ok']` (about line 70)
- Modify: `app/Http/Controllers/Gallery/ItemsApiController.php`, in `store` after the success check (about line 102)
- Modify: `app/Http/Controllers/Publish/EditController.php`, in `withdraw` (lines 88-106)
- Modify: `app/Http/Controllers/Moderation/ReportsController.php`, in `act` where `withdraw === 'true'`
- Test: `tests/Feature/Points/ItemPointsTest.php`

On publish, award `ITEM` to the user for `$outcome['item']['id']`. On withdraw, revoke `ITEM` for the item, but only after the update succeeded. On restore (`withdrawn` is not `'true'`), read `item.author_id` and award `ITEM` to the author. A moderator's withdraw revokes `ITEM`.

- [ ] **Step 1: Write the failing tests.** Publishing through the web and through the API each give 20. Withdrawing gives 0. Restoring gives 20 again. A moderator withdraw through `/moderation/reports` gives 0.
- [ ] **Step 2: Run the tests.** Expect a failure.
- [ ] **Step 3: Add the calls.**
- [ ] **Step 4: Run the tests.** Expect a pass.
- [ ] **Step 5: Commit** with the message "Award community points for gallery items".

### Task 4: Pictures

**Files:**
- Create: `app/Domain/Points/PicturePoints.php` with `public static function changed(?array $old, array $new): void`
- Modify: `app/Supabase/Local/AssetTriggers.php`, calling `PicturePoints::changed($old, $new)` at the end of `after()`
- Test: `tests/Feature/Points/PicturePointsTest.php`, driving uploads and moderation the way the existing picture tests in `tests/Feature/Assets` do

Rules in `changed`:
- The kind is `UNIT_PICTURE` when `$new['unit_name']` is not null, otherwise `PICTURE`. The subject is `$new['id']`.
- If `uploaded_by` changed, or `moderation` became anything other than `approved`, revoke both kinds for the subject.
- If `$new['moderation'] === 'approved'`, award the kind to `$new['uploaded_by']`. This covers an insert that skips the queue (`$old` is null), a moderator approval, and an approved replacement.

- [ ] **Step 1: Write the failing tests.**
  - An upload that skips the queue gives 20.
  - A pending upload gives 0. After a moderator approves it, the total is 20.
  - An approved unit picture gives 1.
  - Rejecting an approved picture gives 0.
  - Another account replacing the picture gives the old uploader 0 and the new uploader 20 once approved.
- [ ] **Step 2: Run the tests.** Expect a failure.
- [ ] **Step 3: Implement `PicturePoints::changed` and the call.**
- [ ] **Step 4: Run the tests.** Expect a pass.
- [ ] **Step 5: Commit** with the message "Award community points for approved pictures".

### Task 5: Game facts and maps

**Files:**
- Modify: `app/Supabase/Local/GameFunctions.php`, in `submitGameFacts` (lines 62-140)
- Modify: `app/Supabase/Local/MapFunctions.php`, in `submitOne` (about line 296) and `clear` (about line 434)
- Test: `tests/Feature/Points/FactsPointsTest.php`

Game facts:
- Record whether the game row was created and whether the `game_version` row was created.
- Before `return $results`, if the game or version was created, or any unit result has an outcome of `accepted` or `recorded`, award `GAME_FACTS` to `$submittedBy`.
- The subject is `$gameId.':'.$release`.
- Faction outcomes do not count, because they are always `accepted`.

Maps:
- When `$outcome` is `stored` or `replaced`, award `MAP` to `$submittedBy` with `$id` as the subject.
- In `clear`, after the delete, revoke `MAP` for `$mapId`.

- [ ] **Step 1: Write the failing tests.**
  - The first facts for a game give 5.
  - The same facts sent again give no new row.
  - Facts for a new release give 5 more.
  - Changed unit facts for a release already held, from a second account, give that account 5.
  - A new map gives 5. Sending it again gives no new row.
  - A higher `catalog_version` from a second account gives that account 5.
  - Clearing a map revokes its rows.
- [ ] **Step 2: Run the tests.** Expect a failure.
- [ ] **Step 3: Add the calls.**
- [ ] **Step 4: Run the tests.** Expect a pass.
- [ ] **Step 5: Commit** with the message "Award community points for new game facts and maps".

### Task 6: Archives and game ownership

**Files:**
- Modify: `app/Supabase/Local/MirrorFunctions.php`, in the report handling (lines 183-208)
- Modify: `app/Supabase/Local/ArchiveFunctions.php`, in `remove` before `return 'removed'`
- Modify: `app/Domain/Moderation/GameDecisions.php`, in `decideRequest` (lines 84-89)
- Test: `tests/Feature/Points/ArchiveAndOwnerPointsTest.php`

Archives:
- Before each `touch` that lists rows, select `id` and `uploader` for the same rows.
- After the touch, award `ARCHIVE` to each uploader for each id. A failed revival is caught already. Only award rows whose touch succeeded.
- In `remove`, revoke `ARCHIVE` for `$id`.

Ownership:
- Inside the `try`, after the owner update, revoke `GAME_OWNER` for `$request['game_id']`.
- Then award it to `$request['requested_by']`.

- [ ] **Step 1: Write the failing tests.**
  - An archive moved from `uploading` to `listed` by a mirror report gives its uploader 20.
  - The same report sent again gives no new row.
  - A `failed` archive revived to `listed` gives 20.
  - Removing the archive gives 0.
  - Approving an ownership request gives 100.
  - Approving a second account's request for the same game moves the 100 to the second account.
  - A declined request gives 0.
- [ ] **Step 2: Run the tests.** Expect a failure.
- [ ] **Step 3: Add the calls.**
- [ ] **Step 4: Run the tests.** Expect a pass.
- [ ] **Step 5: Commit** with the message "Award community points for archives and game ownership".

### Task 7: Report outcomes

**Files:**
- Create: `database/migrations/2026_11_29_120100_add_outcome_to_report.php`, adding a nullable `outcome string(16)` to `report`
- Modify: `app/Http/Controllers/Moderation/ReportsController.php`, in `act`
- Modify: `resources/views/moderation/reports.blade.php`, in the forms at lines 35-39, 50-56 and 58-63
- Check: whether `app/Supabase/Local/Policies.php` lists the columns a moderator may update on `report`. If it does, add `outcome`.
- Test: `tests/Feature/Points/ReportPointsTest.php`, using `ModerationTestCase`

Changes to `act`:
- Read `outcome` from the request. Accept `upheld` or `dismissed`. A `withdraw=true` request means `upheld`. Anything else is `dismissed`.
- Write `outcome` with `handled_at` and `handled_by`.
- When `upheld`, read the report's `reporter_id` and award `REPORT` with the report id as the subject.

View changes:
- Map report: replace "Mark handled" with two forms, Uphold (`outcome=upheld`) and Dismiss (`outcome=dismissed`).
- Item report: "Withdraw it" stays, and the controller treats it as upheld. "Leave it" posts `outcome=dismissed`.

- [ ] **Step 1: Write the failing tests.**
  - Upholding a map report records `upheld` and gives the reporter 5.
  - Dismissing it records `dismissed` and gives 0.
  - Withdrawing a reported item records `upheld` and gives the reporter 5.
  - "Leave it" gives 0.
  - A report from a signed-out visitor, with a null `reporter_id`, awards nobody.
- [ ] **Step 2: Run the tests.** Expect a failure.
- [ ] **Step 3: Add the migration, the controller change and the view change.**
- [ ] **Step 4: Run the tests and the existing moderation report tests.** Expect a pass.
- [ ] **Step 5: Commit** with the message "Let moderators uphold or dismiss reports, and award points for upheld ones".

### Task 8: First text on game and faction fields

**Files:**
- Modify: `app/Domain/Games/GameEdits.php`, in `editGameDetails` and `updateGame` (around lines 148 and 432), and in `editFactionText` (lines 85-117)
- Test: `tests/Feature/Points/FirstTextPointsTest.php`, following `tests/Feature/Games/GameEditTest.php`

Details:
- Before the update, read the held `description`.
- After a successful update (`$rows !== []`), if the held value was null or blank and the new value is not null, award `FIRST_TEXT` to `$userId` with the subject `game:<game id>:description`.

Faction text:
- Before the update, read the held `tagline` and `description` for that faction.
- After a successful update, award the same way, with the subjects `faction:<game id>:<key>:tagline` and `faction:<game id>:<key>:description`.

- [ ] **Step 1: Write the failing tests.**
  - Filling an empty description gives 5.
  - Editing a filled description gives 0.
  - Clearing it and filling it again gives no new row.
  - A second account filling it after it was cleared gives 0.
  - Filling a faction's tagline and description in one save gives 10.
- [ ] **Step 2: Run the tests.** Expect a failure.
- [ ] **Step 3: Add the reads and the calls.**
- [ ] **Step 4: Run the tests.** Expect a pass.
- [ ] **Step 5: Commit** with the message "Award community points for the first text in a game or faction field".

### Task 9: Backfill command

**Files:**
- Create: `app/Console/Commands/PointsBackfill.php`, with the signature `points:backfill {--dry-run}`. Follow the option and output style of an existing `*:copy` command.
- Test: `tests/Feature/Points/BackfillTest.php`

**Interfaces:**
- Consumes: `CommunityPoints::award` and the kind constants

It awards from:
- `game.owner_user_id`, as `GAME_OWNER` for the game id
- `asset` rows where `moderation = 'approved'` and `uploaded_by` is not null, as `UNIT_PICTURE` or `PICTURE` for the asset id
- `archive` rows where `state = 'listed'` and `uploader` is not null, as `ARCHIVE`
- `item` rows where `deleted_at` is null, as `ITEM` for `author_id`
- `map.submitted_by`, as `MAP`
- `game.submitted_by`, as `GAME_FACTS` with the game id as the subject
- `game.description` not blank, and `game_faction.tagline` and `description` not blank, as `FIRST_TEXT` for the game's `owner_user_id`, with the subjects from Task 8. Skip games with no owner.

Output is one line per kind with the number of awards and points, then the number of accounts. With `--dry-run`, it prints the same lines and writes nothing.

- [ ] **Step 1: Write the failing tests.**
  - With one row of each source, the run gives the expected totals per account.
  - A second run adds no rows.
  - `--dry-run` writes no rows and prints the totals.
  - Rejected pictures, removed archives and deleted items give nothing.
- [ ] **Step 2: Run the tests.** Expect a failure.
- [ ] **Step 3: Implement the command.**
- [ ] **Step 4: Run the tests, then the full suite with `php artisan test`.** Expect a pass.
- [ ] **Step 5: Commit** with the message "Add a command that backfills community points for past work".
