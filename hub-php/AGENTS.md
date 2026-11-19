# Coilbox Hub in PHP

This folder is the hub, a Laravel app. It is the only hub. It runs on a DigitalOcean droplet at `https://hub.coilbox.org`. The Next.js app in the repository root used to be the hub. Since November 2026 it only forwards `coilbox-hub.vercel.app` traffic here: pages redirect, and `/api/v1/*` and `/i/*` are proxied so installed copies of Coilbox keep working. Do not run it, read it as a specification, or compare this app against it.

Since 19 November 2026 the hub uses Supabase only for sign in. Games, pictures, maps, mirrors, items, reports, profiles and capabilities all read MariaDB in production. Supabase still holds the old copies of those tables until #613, #617 and #630 drop them. The hub talks to Supabase over HTTP, the same PostgREST and Auth endpoints supabase-js uses.

## New data goes in MariaDB

New tables, columns, views and functions go in MariaDB, as Laravel migrations in `database/migrations`. MariaDB is the default database connection. Each deploy runs `php artisan migrate --force` before the new release goes live, so a migration must keep working with the release that is still live.

Supabase migrations in `supabase/migrations` are pre-approved by the owner. The CI `migrate` job does not apply them. Its `SUPABASE_*` secrets are not set, so it skips the push and still reports success. After the pull request merges, run `supabase db push --linked --dry-run`, then `supabase db push --linked --yes`, then `supabase migration list --linked` to confirm production recorded it.

## Moving data between the databases

Each move has a row in the `data_move` table and a pair of commands, such as `maps:copy` and `maps:store supabase`. Merging a move changes nothing in production. It takes effect when the owner runs its `*:copy` command on the droplet, after `--dry-run`.

To go back, switch in this order: items, mirrors, maps, then games. Each `*:store supabase` command refuses while a move that depends on it is still in MariaDB.

Locally, `scripts/local-services.sh up mariadb` starts MariaDB on port 3307, and `deploy/env.example` points at it. Tests run on SQLite in memory locally and on MariaDB in CI, so write queries both accept.

## The behaviour you must keep

The code and its tests are the specification. Before changing a route, read it, the domain class behind it and its tests. Keep the URLs, query parameters, status codes, headers, JSON shapes, sort orders and page sizes unless the issue says to change them.

The `/api/v1` responses are a contract with the Coilbox desktop app. A field renamed, dropped or reordered in a way a strict client notices breaks installed copies.

## Running things

- The lead session starts and stops local services with `scripts/local-services.sh up` and `down`. `up dev` runs this app at `http://localhost:8100`, `up mariadb` starts MariaDB on port 3307 and `up supabase` starts local Supabase. Other sessions do not start, stop or restart them, and do not run `php artisan serve` themselves.
- `php artisan test` runs the tests. Tests fake Supabase with `Http::fake()` and never call the network.
- `bun run build` rebuilds the CSS after adding Tailwind classes in new files.

## Checking a change

Tests come first. Then compare the running app with a reference:

1. For a change that moves data between databases, run this app against itself with the move switched on and off, such as the `data_move` switch in #615 and #618. The pages and API responses must match.
2. For a read route, compare the local app with production at `https://hub.coilbox.org` using `curl` and `diff`. Use read requests only. A POST that only reads, such as `/api/v1/maps/lookup`, `/api/v1/maps/have`, `/api/v1/assets/have` or `/api/v1/assets/pictures`, is fine when the controller writes nothing.
3. Never send a write (publish, submit facts, upload, moderate) to production, or to a local app pointed at production data.

`php artisan serve` does not pass environment variables to the server. To point the app at a database without editing `.env`, run `php -S 127.0.0.1:8100` from `public/` with the `DB_*` variables set.

## Code layout

- `app/Supabase/`: the HTTP client. `Supabase::anon()`, `Supabase::forToken($jwt)` and `Supabase::admin()` are the three clients, for anonymous, signed in and service role access. Use admin only where the code must bypass row level security, such as writes a signed in user may not make. `->from('item')->select(...)->eq(...)->get()` mirrors supabase-js, and `->rpc('name', [...])` calls a function. Errors throw `SupabaseException` with `errorCode` set to the PostgREST or Postgres code.
- `app/Supabase/Local/`: the game catalogue after its move to MariaDB (#597). `Client` hands requests for the game tables and their four functions to `Rest` and `GameFunctions`, which answer as PostgREST would, with the same row level security. The code that reads games still calls `->from('game_unit')`. `GameStore` says which database holds them, and `php artisan games:copy` switches it. Pictures moved the same way (#598): their tables and functions go to `Rest` and `PictureFunctions`, the `staged-pictures` bucket to `StagedObjects` on the `staged` disk, and `PictureStore` and `php artisan pictures:copy` switch them. Maps, map authors and map packs moved the same way (#599): their tables go to `Rest`, their views to `MapViews` and their functions to `MapFunctions`, and `MapStore` and `php artisan maps:copy` switch them. `MapShadow` keeps Supabase's `map` rows in step while reports or the mirrors still read them there. The hub mirrors moved the same way (#600): their tables go to `Rest` for reads, their functions to `MirrorFunctions` and `ArchiveFunctions`, and the mirror copies on map and game pages to `MirrorSources`. `MirrorStore` and `php artisan mirrors:copy` switch them, and the copy refuses to run until games and maps are in MariaDB. Published items, reports, profiles and moderator capabilities moved the same way (#601): their tables go to `Rest`, their checks and the `item_facet` view to `ItemRules`, and `is_moderator`, `has_capability`, `capability_access`, `moderator_accounts`, `set_item_featured` and `current_author_name` to `ItemFunctions`. `Profiles` gives a new account its profile when it signs in or sends a token, which Supabase's sign up trigger did. `ItemStore` and `php artisan items:copy` switch them, and the copy refuses to run until maps are in MariaDB. Once items and mirrors are both in MariaDB, `MapShadow` stops writing to Supabase. `Domain/Maps/Authors` holds the author key rules.
- `app/Support/HubCache.php`: cached shared reads. Wrap them in `HubCache::remember($key, [tags], HubCache::HOURS, fn () => ...)`, and call `HubCache::invalidate([...])` with the same tags after a write that changes them.
- `app/Support/Api.php`: JSON responses with the API's CORS and cache headers. `app/Support/Bearer.php`: the desktop app's bearer token check. `app/Support/Site.php`: the hub's own address.
- `app/Domain/<Area>/`: the rules for each area. `app/Http/Controllers/<Area>/`: thin controllers. Doc comments that name a Next module say where a class came from. They are history, so do not go looking for that code.
- `routes/web/<area>.php` and `routes/api/<area>.php`: one route file per area. Every file in those folders is loaded automatically. Only edit the route files you own.
- `resources/views/layouts/app.blade.php` is the shared layout. Pages set `title`, `description` and the other variables it reads as view data. Shared components go in `resources/views/components/`.
- `resources/vendor/`: JSON copied from the Coilbox repository.

## Writing code here

- PHP 8.3 locally, 8.4 on the server. Write code that runs on both.
- Match Laravel conventions. No extra Composer packages without a clear need.
- Plain service classes hold the rules. Keep controllers thin.
- Comments explain why, briefly. A writing hook rejects semicolons and em dashes in comments and Markdown text, and some words it calls jargon. Use full stops and plain words.
- Every module gets PHPUnit tests for its logic.
- Do not commit unless you lead the branch. The lead session commits.
