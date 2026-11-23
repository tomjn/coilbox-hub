# Community points

Accounts earn community points for adding to the hub. The aim is to encourage people to contribute. You can see your own points on your profile and Coilbox can show them through the API. Nobody else sees them yet, and there is nothing to spend them on.

## Rules

Each award is a row identified by the account, the kind of contribution and the item it was for (the subject). One account earns each kind once per subject. Sending data the hub already has earns nothing.

| Kind | Points | Subject | Earned when | Taken back when |
|---|---|---|---|---|
| `game_owner` | 100 | game id | the account becomes the game's owner | the game gets a new owner, or none |
| `archive` | 20 | archive id | the hub accepts a mirror upload of a map or game archive | a moderator removes it |
| `item` | 20 | item id | a gallery item is published, on the web or through the API | the author withdraws it, or a moderator removes it |
| `picture` | 20 | asset id | a screenshot, map picture, faction logo or game logo is approved, or on upload if it skips the queue | it is rejected, or another account replaces it |
| `unit_picture` | 1 | asset id | a unit picture (an `asset` with `unit_name` set) is approved | it is rejected, or another account replaces it |
| `game_facts` | 5 | game version id | the hub stores new or changed facts for that version | never |
| `map` | 5 | map id | the hub stores a new map, or changes one | a moderator clears the map |
| `report` | 5 | report id | a moderator upholds the report | never |
| `first_text` | 5 | field, such as `game:<id>:description` | an empty field gets its first text | never |

`first_text` covers the game description, each faction's tagline and each faction's description. It is earned once per field across all accounts, so clearing a field and filling it again, or a new owner doing so, earns nothing. Whoever fills the field earns it, owner or moderator. The game's display name and links do not count.

These earn nothing:

- Mirror reports and archive mismatch reports that Coilbox sends on its own.
- Ownership requests.
- An owner's other edits to their game page. The 100 for owning the game covers them.
- Moderator actions.
- Download pings.

## Data

A MariaDB migration in `hub-php/database/migrations` adds `community_point`:

- `id`
- `user_id`, `char(36)`
- `kind`, `string(32)`
- `subject`, `string(255)`
- `points`, small integer
- `created_at`
- a unique key on (`user_id`, `kind`, `subject`) and an index on `user_id`

Each row keeps its own points, so changing a value later only affects new awards.

A second migration adds a nullable `outcome` column (`upheld` or `dismissed`) to `report`.

## Code

`App\Domain\Points\CommunityPoints` holds the points for each kind and these methods:

- `award(string $userId, string $kind, string $subject): void` inserts a row and ignores the insert if the row exists. For `first_text` it first checks whether any account holds that kind and subject.
- `revoke(string $kind, string $subject): void` deletes the rows for that kind and subject, whoever holds them.
- `total(string $userId): int` returns the sum of the account's points.

Each write path calls `award` after its own write succeeds. Each reject, withdraw, remove or ownership change calls `revoke`. Ownership changes revoke the old owner's award before awarding the new owner. If a points write fails, the hub calls `report()` and the contribution still goes through.

The call sites, to be confirmed against the code while planning:

- Game ownership: moderation decisions on ownership requests and any other place that sets `game.owner_user_id`.
- Archives: `Mirrors\UploadController` when the archive is accepted, and `Moderation\ArchivesController::remove`.
- Gallery items: `Publish\PublishController`, `Gallery\ItemsApiController::store`, `Publish\EditController::withdraw` and the item withdraw in `Moderation\ReportsController::act`.
- Pictures: `Assets\AssetApiController::upload` when it skips the queue, and approve, reject and replace in `Moderation\PicturesController` and the picture upload rules.
- Game facts: `Games\GameApiController::facts` when `Submit::submitGameFacts` writes something.
- Maps: `Maps\MapApiController::submit` when `Submit::write` writes something, and `Moderation\MapsController::clear`.
- Reports: `Moderation\ReportsController::act`.
- First text: `Games\GameEditController::details` and `factionText`.

## Moderation of reports

The single handled action on a report becomes two buttons, Uphold and Dismiss. Each sets `outcome` as well as `handled_at` and `handled_by`. Withdrawing a reported item counts as upheld. Upholding awards the reporter 5 points.

## API

`GET /api/v1/profile/points` returns `{"points": 125}`.

- It takes the bearer token that `/api/v1/profile` takes, and answers 401 without one.
- It is `private, no-store`, so no shared cache holds one person's score.
- It is a new endpoint so the shape of `/api/v1/profile`, which installed copies of Coilbox read, does not change.
- It returns the total only.

## Profile page

When you view your own profile at `/profile/{id}`, the page shows your points under your name, such as "125 community points". Other visitors, signed in or not, see nothing. The points cannot be clicked or spent. `/account` does not change.

The number is golden yellow. A `--points` token with the value `#fbbf24` (Tailwind's `amber-400`) goes next to the other colour tokens in `hub-php/resources/css/app.css`. Its contrast on the background `#1b1b1f` is 10.28 to 1.

## Backfill

`php artisan points:backfill` awards points for past work. `--dry-run` prints the total for each kind and the number of accounts, and writes nothing. It uses `award`, so running it again adds nothing. It awards for:

- games with an owner (`game.owner_user_id`)
- approved pictures (`asset.uploaded_by`), split into unit pictures and the rest
- accepted archives (`archive.uploader`)
- gallery items that are not deleted (`item.author_id`)
- maps by submitter (`map.submitted_by`)
- games by submitter (`game.submitted_by`), as one `game_facts` award each, with the game id as the subject
- filled game descriptions and faction text, credited to the game's owner when it has one

Past reports have no outcome, and past fact updates have no record of who sent them, so neither earns anything.

## Testing

PHPUnit tests, which run on SQLite locally and MariaDB in CI:

- `CommunityPoints`: a second award adds no row, revoke removes the row, and the total sums correctly. `first_text` is earned once across accounts.
- One test for each award and take back point in the call sites list.
- The API returns `{"points": N}` with `private, no-store`, and 401 without a token.
- The profile page shows points to the account owner only.
- The report buttons record `upheld` and `dismissed`, and only `upheld` awards points.
- The backfill awards the expected rows, and its dry run writes nothing.
