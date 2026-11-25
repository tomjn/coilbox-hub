# Hub engines: design

Status: draft for review, 2026-11-25.

Builds on `2026-11-16-hub-mirrors-design.md` and `2026-11-23-mirror-self-registration-design.md`.

## Purpose

Offer engine builds that the official Recoil releases do not cover, starting with macOS test builds. The mirrors hold the files. Coilbox's engine settings offers them with a clear warning. Only people a moderator trusts can upload one.

Coilbox has no engine source for macOS today. `fetchNewestRecoil` in `src/downloads/engineInstall.ts` returns nothing there.

## Scope

In scope:

- `.7z` engine archives named `recoil_<version>_<platform>.7z`, the same format as official releases.
- A per-user grant that allows engine uploads.
- Upload from Coilbox's engine settings.
- An engines list in the signed catalogue, engine support on mirrors, a public engines API, and two hub pages.

Out of scope:

- Upload from a script, CI or the hub's web pages.
- Long-lived upload tokens.
- Engines from anyone without the grant.
- Showing engine pages in the nav, the sitemap or search engines.

## Rules

Who may upload:

- A user with a row in `engine_uploader`.
- Any moderator, without a grant.
- Anyone else is refused before upload.

What an engine is:

- An `archive` row with kind `engine` and internal name `recoil`.
- The version and platform come from the filename. The archive holds no version file, so the filename is the only source.
- The platform must be one of `amd64-linux`, `amd64-windows` or `arm64-macos`.
- The uploader's note is plain text, 1 to 1,000 characters. The limit is a policy choice, not a measurement.

Versions:

- A hub engine may use the same version string as an official Recoil release, so a macOS build of `2026.09.03` matches lobbies hosted on `2026.09.03`.
- It is refused when an official release already has a build for the same version and platform. Coilbox checks this at upload against the Recoil release list it already loads. The hub does not call GitHub.

Duplicates. An upload is refused if any of these match an archive the hub already knows, including removed ones:

- the SHA-256 of the file
- the filename, compared case-insensitively
- the internal name, version and platform

Removal:

- Moderators can remove any engine. The uploader can withdraw their own.
- Revoking a grant does not remove engines the person already uploaded.
- Everything else is as in the mirror design: the hub stops linking at once, mirrors delete on their next sync, and the hash and filename stay blocked.

What the hub shows. The version, platform, size, SHA-256, listing date and note. The uploader is kept privately, as with maps.

The fixed warning, shown on both hub pages and in Coilbox:

> Unofficial test build. Not made or supported by the Recoil team. It may crash, desync in multiplayer or damage your settings. Use it only if you know why you want it.

## Hub (`hub-php`)

### Migration

New table `engine_uploader` in MariaDB:

| Column | Notes |
|---|---|
| `user_id` | primary key, the hub user's ID |
| `granted_by` | the moderator's user ID |
| `granted_at` | datetime |

Changes to `archive`:

- Add `platform`, `string(16)`, not null, default `''`. Maps and games keep the empty string. It cannot be nullable, because MariaDB treats every null as distinct in a unique index, so the uniqueness rule would stop working for maps and games.
- Replace `archive_name_version_idx` on (`internal_name`, `version`, `live`) with one on (`internal_name`, `version`, `platform`, `live`).
- `kind` is `string(8)`, which fits `engine`.

Changes to `mirror`:

- Add `takes_engines`, boolean, default false. Set from each mirror report.

The note goes in the existing `description` column.

The mirror and archive logic already runs against MariaDB, in `app/Supabase/Local/MirrorFunctions.php` and `ArchiveFunctions.php`, so no Supabase change is needed.

Listing an engine gives no community points. `MirrorFunctions.php:210` awards points for every listed archive, so it skips kind `engine`. Engine uploads come from a few trusted people, and points are for community contributions.

### Grants

A new "Engine uploaders" list in `/moderation`:

- Shows each grant with the user, who granted it and when.
- Add finds a user by username.
- Revoke deletes the row.

### Upload rules

`POST /api/v1/uploads` accepts `kind: "engine"` with `version`, `platform` and `note`, alongside the existing facts. `UploadFacts` checks:

1. The user has the grant or is a moderator, otherwise 403.
2. The filename matches `recoil_<version>_<platform>.7z` and agrees with the `version` and `platform` fields.
3. The platform is in the list.
4. The note's length is within the limit.
5. The duplicate rules above.

The hub picks a mirror only from those that are enabled, healthy and have `takes_engines` set. If none qualify, it refuses with a message saying no mirror takes engines yet.

### Catalogue

A new top-level `engines` list. Each entry holds filename, SHA-256, size, version, platform and note. Engines never go in `archives`. The builder at `MirrorFunctions.php:280` reads every listed archive today, so it splits them by kind.

Old mirrors check only `mirrors`, `archives` and `removed`, and accept top-level keys they do not know (`src/Catalogue.php:83` in `coilbox-mirror`). They keep syncing maps and games unchanged.

Engine removals go in the existing `removed` list. An old mirror only deletes removed files it finds in its `maps/` and `games/` folders, so engine removals do nothing there.

### Mirror reports and health

- Reports may carry `"kinds": ["map", "game", "engine"]`. The hub sets `takes_engines` when `engine` is present and clears it when it is absent.
- `HealthCheck` uses the `engines/` folder for engine archives.

### `GET /api/v1/engines?platform=…`

Public, no sign-in. `platform` is optional. Returns listed engines, newest first:

```json
{"engines": [{"version": "…", "platform": "arm64-macos", "filename": "…", "size": 0, "sha256": "…", "note": "…", "listed_at": "…", "page_url": "…", "sources": ["https://…/engines/…"]}]}
```

`sources` has one URL for each healthy mirror that holds the engine. An engine with no sources is left out.

### `GET /api/v1/profile`

Adds `can_upload_engines`, true for grant holders and moderators.

### Pages

Both pages use `'robots' => 'noindex'`. Neither appears in the nav or `App\Domain\Seo\Sitemap`.

- `/engines` shows the warning, then engines grouped by platform, newest first: version, size, listing date and the note. Each row links to its page.
- `/engines/{version}/{platform}` shows the warning, version, platform, size, SHA-256, listing date, the note, and a download link for each healthy mirror that holds it. A removed or unknown engine gives a 404.
- On the engine page, the uploader and moderators see an "Edit note" form and a "Withdraw" button. Moderators see "Remove" with a reason, as for other archives. Editing the note rebuilds the catalogue.

## Mirror (`coilbox-mirror`)

- Every mirror on the new release takes engines. There is no setting. An engine build is no larger than many maps.
- A new `engines/` folder, served by Apache with the same `.htaccess` as `maps/` and `games/`.
- `Token`, `Paths`, `Listing`, `UploadHandler`, `Sync` and `Requirements` learn the `engine` kind.
- `Catalogue` checks each entry of `engines` on its own. A bad entry is dropped and logged, and the rest of the catalogue is still accepted. One bad engine entry must not stop maps and games syncing.
- Reports include `kinds`, so the hub can tell mirrors on the new release from older ones.
- `PROTOCOL.md` documents the `engines` list, the `engine` kind and `kinds` in reports.

## Coilbox

- Engine settings gets a "Hub test builds" list below the Recoil and springfiles lists. It shows engines for the computer's own platform from `GET /api/v1/engines`, with the fixed warning above the list. Each row shows its note and links to its hub page.
- If an official release has the same version and platform, the hub engine is hidden.
- Install downloads from each source in turn, checks the SHA-256, then unpacks the same way as a Recoil release. A hash mismatch counts against that mirror and moves to the next one.
- When `can_upload_engines` is true, engine settings shows "Upload a test build". It opens a file picker for a `.7z`, then checks:
  - the filename matches the pattern
  - the platform is in the list
  - the archive holds `spring` or `spring.exe`
  - no official release has the same version and platform
- The user then writes the note, and the upload reuses the chunked, resumable mirror upload in `crates/tauri-plugin-coilbox-hub/src/uploads.rs`. Engine facts come from the filename and the archive listing, not unitsync, which cannot read engine archives.

## Errors

| What happens | What the person sees |
|---|---|
| No grant | Coilbox hides the upload button. The hub refuses with 403 if asked anyway |
| Bad filename, or filename disagrees with the facts | The hub names the expected pattern |
| Unknown platform | The hub lists the accepted platforms |
| Note empty or too long | The hub names the limit |
| Duplicate | The hub names the matching check and links the existing engine page |
| An official build exists for that version and platform | Coilbox refuses before asking the hub |
| No mirror takes engines | The hub says no mirror takes engines yet |
| Download or hash failure | Coilbox moves to the next mirror |

## Testing

- Hub: PHPUnit tests for the grant and its moderation list, each upload rule, the uniqueness rule across platforms, maps and games still refused on name and version, mirror choice by `takes_engines`, the catalogue `engines` list, `kinds` in reports, the engines API, `can_upload_engines`, both pages with `noindex`, absence from the sitemap, note editing and withdrawal.
- Mirror: PHPUnit tests for the engine kind, a bad engine entry dropped while maps still sync, and `kinds` in reports.
- Coilbox: tests in that repository, covered by its own plan.
- End to end: run the hub locally with two mirrors that take engines, upload a test engine, wait for both mirrors to hold it, withdraw it, and confirm both delete it. Then confirm a third mirror on the current release keeps syncing maps throughout.

## Build order

1. Hub: migration, grants and their moderation list, upload rules, catalogue, reports, API, pages.
2. Mirror release with engine support.
3. Coilbox: hub test builds list and install, then upload.

The hub can ship before any mirror upgrades. Engine uploads are refused until a mirror reports `engine` in `kinds`.

## Risks

- The hub trusts the version and platform in the filename. A build named for the wrong platform installs and fails to start. Only grant holders can upload, and removal is immediate.
- A hub build that shares a version string with an official release can join lobbies on that version and desync if it differs. The warning says so, and the grant limits who can publish one.