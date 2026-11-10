# Coilbox Hub in PHP

This folder is the hub rebuilt as a Laravel app. It replaces the Next.js app in the repository root, which Vercel stopped serving in November 2026. It runs on a DigitalOcean droplet at `https://hub.coilbox.org`.

For now it keeps Supabase for the database, sign in and the staging picture bucket. It talks to Supabase over HTTP, the same PostgREST and Auth endpoints supabase-js uses, so row level security and the database functions behave exactly as they do for the Next app. A later phase moves the rules out of Postgres and into this app, then moves the data to MariaDB. Do not start that work here.

## The Next app is the specification

Every route here ports a route from the Next app in the repository root. Read the Next route, the `lib/` code behind it and the components it draws before writing the PHP. Port behaviour faithfully: the same URLs, query parameters, status codes, headers, JSON shapes, sort orders, page sizes, wording and Tailwind classes. Comments in the Next code explain why things are the way they are. Keep the reasoning when it still applies, shorter.

The `/api/v1` responses are a contract with the Coilbox desktop app. A field renamed, dropped or reordered in a way a strict client notices breaks installed copies.

## Running things

- The Next app runs at `http://localhost:3000` against the production database, as a read-only reference. The PHP app runs at `http://localhost:8100` with `php artisan serve --port=8100`. The lead session starts and stops both. Do not start, stop or restart either server, and do not run `scripts/local-services.sh`.
- Never send a write to either server (POST to publish, submit facts, upload, moderate). Both point at production. Read requests are fine, including the POST routes that only read, such as `/api/v1/maps/lookup`, `/api/v1/maps/have`, `/api/v1/assets/have` and `/api/v1/assets/pictures`, as long as the Next code confirms they write nothing.
- `scripts/parity.sh GET '/api/v1/items?kind=preset'` compares one request on both servers and prints a diff. Use it for every read route and every variant of its parameters. Never use it on a route that writes.
- `php artisan test` runs the tests. Tests fake Supabase with `Http::fake()` and never call the network.
- `bun run build` rebuilds the CSS after adding Tailwind classes in new files.

## Code layout

- `app/Supabase/`: the HTTP client. `Supabase::anon()`, `Supabase::forToken($jwt)` and `Supabase::admin()` match the Next app's `createAnonClient`, `createClientForToken` and `createAdminClient`. Use admin only where the Next code used `createAdminClient`. `->from('item')->select(...)->eq(...)->get()` mirrors supabase-js, and `->rpc('name', [...])` calls a function. Errors throw `SupabaseException` with `errorCode` set to the PostgREST or Postgres code.
- `app/Support/HubCache.php`: the Next app's `"use cache"` loaders. Wrap shared reads in `HubCache::remember($key, [tags], HubCache::HOURS, fn () => ...)` with the same tags the Next loader used, and call `HubCache::invalidate([...])` where the Next code called `updateTag` or `revalidateTag`.
- `app/Support/Api.php`: JSON responses with the API's CORS and cache headers. `app/Support/Bearer.php`: the desktop app's bearer token check. `app/Support/Site.php`: the hub's own address.
- `app/Domain/<Area>/`: ported `lib/` logic, one class per Next module where that reads naturally. `app/Http/Controllers/<Area>/`: thin controllers.
- `routes/web/<area>.php` and `routes/api/<area>.php`: one route file per area. Every file in those folders is loaded automatically. Only edit the route files you own.
- `resources/views/layouts/app.blade.php` is the shared layout. Pages set `title`, `description` and the other variables it reads as view data. Shared components go in `resources/views/components/`.
- `resources/vendor/`: JSON copied from the Next app's `lib/**/vendor` folders.

## Writing code here

- PHP 8.3 locally, 8.4 on the server. Write code that runs on both.
- Match Laravel conventions. No extra Composer packages without a clear need.
- Plain service classes hold the rules. Keep controllers thin.
- Comments explain why, briefly. A writing hook rejects semicolons and em dashes in comments and Markdown text, and some words it calls jargon. Use full stops and plain words.
- Every ported module gets PHPUnit tests for its logic, ported from the Next app's `*.test.ts` where they exist.
- Do not commit. The lead session commits.
