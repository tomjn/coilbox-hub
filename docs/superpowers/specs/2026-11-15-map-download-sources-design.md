# Map download sources

A map page only offers "Look for it on hakora", a URL guessed from the archive filename. Nobody can say where a map actually lives. This adds per-map download sources, edited by the map's owner, an owner of one of its authors, or a moderator, and published to Coilbox through the map lookup API.

## Decisions

- Owners and moderators edit sources. Maps had no owner by design (`20260818130000_map_submission.sql`), and that still holds for facts. Ownership here grants editing of sources only.
- Ownership is per map or per author key. Owning an author covers every map credited to it, now and later, following author merges.
- Kinds are `url` (with a save-as filename) and `github` (with an optional release file part). No `rapid`.
- Sources reach the map page and `POST /api/v1/maps/lookup`. Coilbox using them is a separate change in the client repo.
- Out of scope: Coilbox offering map sources automatically, as `game_download_offer` does for games. A public author page.
- Only `hub-php/` and Supabase change. The Next.js reference app does not.

## Data

- `map_download_source`: `map_id`, `kind in ('url','github')`, `value`, `asset`, `filename`, `sort_order`, with the same constraints as `game_download_source`. Insert and delete only, the form replaces the whole list. At most 12 per map.
- `map.owner_user_id`, nullable, set null when the account closes.
- `author_owner`: `key` (an author key, primary key), `user_id`.
- `map_ownership_request`: `map_id` or `author_key`, exactly one set. `requested_by`, `requested_by_name`, `note`, `status` open, approved or declined, decided by and when. One open request per person per target.
- `can_edit_map(map_id)`: true for a moderator, the map's owner, or the owner of the resolved key of any author credited on the map.
- RLS on `map_download_source` uses `can_edit_map` for insert and delete. Everyone may read.
- Approving a request is a moderator-checked action, no browser grant can set an owner.

## Screens

- Map page: a Download section with the sources, above the existing mirror guesses. An "Edit this map" link for anyone `can_edit_map` would pass. A request ownership form for signed-in visitors, offering this map if it has no owner and each credited author with no owner as "all maps by X". The cached page holds the owner ids, the controller decides per viewer.
- `/map/{slug}/edit`: the ordered download sources editor, reusing the game editor's rows without rapid.
- `/moderation/games` lists map and author ownership requests alongside game ones, with approve and decline.

## API and caching

- `map_facts` gains `downloads`, in order. The lookup result's `map` gains `downloads: [{kind, value, filename?, asset?}]`. Additive, so the lookup stays version 1.
- Saving sources or deciding a map request flushes that map's cache tag. Deciding an author request flushes the maps tag.

## Tests

- pgTAP: source RLS for owner, author owner, moderator and stranger, kind check, request rules, approval needs a moderator, ownership through a merge, `downloads` in lookup and absent for a taken-down map.
- PHPUnit: edit page access, save and row errors, page sections, request form targets, moderation decisions, parsing refuses rapid.
