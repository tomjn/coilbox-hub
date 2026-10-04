# Coilbox Hub

The hub is the website behind [Coilbox](https://github.com/tomjn/coilbox), the desktop app. It holds content that players share, facts the app has read from games and maps, and the pages that show both. It is a Next.js app with Supabase for the database, sign in and file storage.

What the hub holds:

- A gallery of things players make in Coilbox. The kinds come from `GALLERY_KINDS` in `lib/container/index.ts`: preset, challenge, setup-pack, scenario, blueprint and mod-project. Each item has a link that opens it in the app.
- A map catalog, with a page for each map and curated map packs.
- Game pages with factions, units and build trees, and a changes page that compares two releases of a game.
- The pictures and other files these pages show.

## Who uses it

- Visitors browse the gallery, maps and games without an account. Start at `/gallery`, `/maps` and `/games`. An item is at `/item/[id]`, a map at `/map/[slug]` and a game at `/games/[shortname]`. A game has its units at `/games/[shortname]/units`, its build tree at `/games/[shortname]/tree`, and release comparisons at `/games/[shortname]/changes` and `/games/[shortname]/units/[unit]/compare`.
- The Coilbox desktop app talks to the v1 API under `/api/v1`. It lists and imports items, sends facts about games and maps, and uploads pictures. An item imports through the link `coilbox://import?url=https://<host>/i/<id>`.
- Authors sign in with Discord at `/publish` to publish an item, then change or withdraw it from the item's page. `/account` lets them delete their account and everything they published.
- Game owners edit their game's page at `/games/[shortname]/edit`. Any signed in user can ask to own a game from its page, and a moderator decides at `/moderation/games`.
- Moderators work under `/moderation`: reports, pictures, ownership requests, maps, map packs, authors, users and a trail of what was done to pictures. `/ops` shows storage allowances. Both return 404 unless the signed in account has a row in `public.moderator`.

The public ways in that are not pages for people:

- `/developers` documents the API.
- `/feed.xml` is an Atom feed of the newest gallery items.
- `/sitemap.xml` lists the public pages.

## Run it locally

You need [bun](https://bun.sh), the [Supabase CLI](https://supabase.com/docs/guides/cli) and a container runtime that the `docker` command talks to, such as Docker Desktop or colima. The script in step 4 also uses `lsof` and `pgrep`.

1. Install the dependencies.

   ```
   bun install
   ```

2. Start the local Supabase stack. This runs the migrations in `supabase/migrations` on a new database.

   ```
   scripts/local-services.sh up supabase
   ```

3. Create `.env.development.local` in the repository root. Git ignores it. `next dev` reads it and `next build` does not. It needs three variables, and `supabase status` prints the values.

   - `NEXT_PUBLIC_SUPABASE_URL` is `API_URL`.
   - `NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY` is `PUBLISHABLE_KEY`.
   - `SUPABASE_SERVICE_ROLE_KEY` is `SERVICE_ROLE_KEY`. The dev sign in route and account deletion need it.

4. Start the dev server.

   ```
   scripts/local-services.sh up dev
   ```

   Running `scripts/local-services.sh up` with no argument does steps 2 and 4 together. The site is at http://localhost:3000.

5. Sign in at http://localhost:3000/dev/sign-in. Discord sign in cannot finish against a local Supabase, so this route signs the browser in as `dev@coilbox.local` and redirects to `/publish`. It returns 404 unless `NODE_ENV` is `development` and `NEXT_PUBLIC_SUPABASE_URL` points at `localhost`, `127.0.0.1` or `[::1]`.

6. Stop what you started.

   ```
   scripts/local-services.sh down
   ```

`scripts/local-services.sh status` shows what is running. The script runs `supabase start` without the containers the hub does not use (Studio, the mail catcher, realtime, edge functions, analytics and a few others) and runs `bun run dev -- -p 3000` in the background, with its log at `.local-services/dev.log`. `down` stops only what `up` started. The project's agent instructions in `CLAUDE.md` require this script instead of `supabase start` or `bun dev`, because left running they use memory other projects need. You can run `supabase start` and `bun dev` yourself, and `supabase stop` when you finish.

### There is no seed data

A fresh database has no games, maps or gallery items, so the lists are empty. Nothing in the repository seeds a database, and `supabase/config.toml` points at a `supabase/seed.sql` that does not exist. To get data in:

- Publish gallery items at `/publish` after signing in. You need a share code or an exported file from Coilbox.
- Run the Coilbox desktop app against your local hub. It posts game and map facts through `/api/v1/games/facts` and `/api/v1/maps`. The app has one configurable address, the hub, so point it at `http://localhost:3000`.

The scripts in `scripts/` are for the maintainer and need more than a local checkout. `seed:assets`, `promote:assets` and `import:branding` push files to a separate assets repository. `cleanup:assets` and `backfill:*` write to a database. Each prints what it would do without `--write`, and the comment at the top of each file says what it needs.

To use `/moderation` locally, sign in once, then add your account as a moderator with SQL against the `DB_URL` from `supabase status`:

```sql
insert into public.moderator (user_id)
select id from auth.users where email = 'dev@coilbox.local';
```

## Tests and checks

CI in `.github/workflows/ci.yml` runs these. Run them from the repository root.

```
bun run check:vendor
bun run lint
bun run typecheck
bun test
bun run build
```

- `check:vendor` compares the files copied from the Coilbox repository (the container format and the code that draws galaxies and runs) with their originals on GitHub. It needs network access and fails when either side has changed.
- `bun test` runs the unit tests, which sit beside the code as `*.test.ts` and `*.test.tsx`.
- `supabase test db` runs the database tests in `supabase/tests` against the local database, so Supabase must be running. CI runs it on a clean database after `supabase db start`. On a database with data in it, some files can fail, so run one file to check your setup: `supabase test db supabase/tests/item_facet.test.sql`.

`next build` reads `.env.local`. If that file points at a hosted Supabase project, a local build reads from that project. Either build with no Supabase configured, which is how CI runs it:

```
NEXT_PUBLIC_SUPABASE_URL= NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY= SUPABASE_SERVICE_ROLE_KEY= bun run build
```

Or build against the local stack:

```
set -a; . ./.env.development.local; set +a; bun run build
```

## Deployment and migrations

Vercel deploys `main`. `vercel.json` enables deployments for that branch and sets `github.silent`. The rest of the Vercel project, including its environment variables, is not in this repository.

Migrations live in `supabase/migrations` and are applied to the hosted project by hand with `supabase db push --linked`. Merging a migration does not apply it. Deploys do not run it. Two open issues track this: [#211](https://github.com/tomjn/coilbox-hub/issues/211) and [#416](https://github.com/tomjn/coilbox-hub/issues/416).

## Next.js in this repository

This is Next.js 16.3.0 and it differs from older versions. `AGENTS.md` says to read the guide in `node_modules/next/dist/docs/` before writing code, and that is the documentation to trust. Three things catch people out:

- `cacheComponents` is on in `next.config.ts`. A page that reads request data, such as cookies, outside a `<Suspense>` boundary fails `next build`. `app/layout.tsx` does this for the parts of the header that depend on who is signed in, and a new page that reads the session needs the same.
- Loaders that fetch shared data use `"use cache"` and tag themselves with names from `lib/cache/tags.ts`. A cached function cannot read cookies, so these build an anonymous client (`lib/supabase/anon.ts`) and take their filters as arguments. Code that writes data must invalidate the matching tag, with `updateTag` in a server action or `revalidateTag` in a route handler.
- `proxy.ts` replaces what older versions call middleware. It refreshes the session on every request and returns a 503 when Supabase is not configured.

## Repository map

- `app/` holds the routes: pages, route handlers and server actions. The API is under `app/api/v1`.
- `components/` holds shared React components.
- `lib/` holds the logic behind the routes, one directory per area, such as `gallery`, `maps`, `games`, `assets`, `moderation` and `supabase`. Tests sit beside the code. The `vendor` directories in `lib/assets`, `lib/games`, `lib/maps` and `lib/workshop` hold files copied from the Coilbox repository.
- `supabase/` holds `config.toml`, the migrations and the database tests in `tests`.
- `scripts/` holds `local-services.sh`, `sync-vendor.ts` and the maintainer scripts named in `package.json`.
- `docs/superpowers/` holds working plans and specs for past features.
- `proxy.ts` is the request proxy described above.

There is no `CONTRIBUTING.md` or licence file in the repository.
