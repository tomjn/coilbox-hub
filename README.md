# Coilbox Hub

The hub is the website behind [Coilbox](https://github.com/tomjn/coilbox), the desktop app. It holds content that players share, facts the app has read from games and maps, and the pages that show both. It is a Laravel app in `hub-php/`, live at https://hub.coilbox.org. It uses MariaDB for the data that has moved, and Supabase for the rest, for sign in and for file storage.

The Next.js app in the repository root used to be the hub. It no longer serves visitors. On Vercel it only redirects old `coilbox-hub.vercel.app` addresses to `hub.coilbox.org`, as set up in `next.config.ts`. See [Deployment and migrations](#deployment-and-migrations).

What the hub holds:

- A gallery of things players make in Coilbox. The kinds are in `GALLERY_KINDS` in `hub-php/app/Domain/Container/Container.php`: preset, challenge, setup-pack, scenario, blueprint and mod-project. Each item has a link that opens it in the app.
- A map catalog, with a page for each map and curated map packs.
- Game pages with factions, units and build trees, and a changes page that compares two releases of a game.
- The pictures and other files these pages show.

## Who uses it

- Visitors browse the gallery, maps and games without an account. Start at `/gallery`, `/maps` and `/games`. An item is at `/item/[id]`, a map at `/map/[slug]` and a game at `/games/[shortname]`. A game has its units at `/games/[shortname]/units`, its build tree at `/games/[shortname]/tree`, and release comparisons at `/games/[shortname]/changes` and `/games/[shortname]/units/[unit]/compare`.
- The Coilbox desktop app talks to the v1 API under `/api/v1`. It lists and imports items, sends facts about games and maps, and uploads pictures. An item imports through the link `coilbox://import?url=https://<host>/i/<id>`.
- Authors sign in with Discord at `/publish` to publish an item, then change or withdraw it from the item's page. `/account` lets them delete their account and everything they published.
- Game owners edit their game's page at `/games/[shortname]/edit`. Any signed in user can ask to own a game from its page, and a moderator decides at `/moderation/games`.
- Moderators work under `/moderation`: reports, pictures, ownership requests, maps, map packs, authors, users and a trail of what was done to pictures. `/ops` shows storage allowances. Both return 404 unless the signed in account holds the `can_moderate` capability.

The public ways in that are not pages for people:

- `/developers` documents the API.
- `/feed.xml` is an Atom feed of the newest gallery items.
- `/sitemap.xml` lists the public pages.

## Run it locally

You need PHP 8.3 or later, [Composer](https://getcomposer.org), [bun](https://bun.sh), and a container runtime that the `docker` command talks to, such as Docker Desktop or colima. The Supabase CLI is only needed if you run Supabase locally. The script in step 2 also uses `lsof` and `pgrep`.

1. Install the dependencies and build the CSS and JavaScript.

   ```
   cd hub-php
   composer install
   bun install
   bun run build
   ```

2. Start MariaDB, and local Supabase if you want one, from the repository root. MariaDB listens on port 3307. Local Supabase runs the migrations in `supabase/migrations` on a new database.

   ```
   scripts/local-services.sh up mariadb
   scripts/local-services.sh up supabase
   ```

3. In `hub-php`, copy `deploy/env.example` to `.env`, then run `php artisan key:generate`. The three `SUPABASE_` settings take the Supabase project's URL, publishable key and service role key. For local Supabase, `supabase status -o json` prints them as the `API_URL`, `PUBLISHABLE_KEY` and `SERVICE_ROLE_KEY` fields. Then create the tables.

   ```
   php artisan migrate
   ```

4. Start the hub at http://localhost:8100.

   ```
   scripts/local-services.sh up dev
   ```

   `scripts/local-services.sh up` with no argument starts all three. `php artisan serve --port=8100` in `hub-php` also works.

5. Stop what you started.

   ```
   scripts/local-services.sh down
   ```

`scripts/local-services.sh status` shows what is running. The script starts Supabase without the containers the hub does not use (Studio, the mail catcher, realtime, edge functions, analytics and a few others), and runs `php artisan serve` in the background with its log at `.local-services/dev.log`. `down` stops only what `up` started. The project's agent instructions in `CLAUDE.md` require this script instead of `supabase start` or `php artisan serve`, because left running they use memory other projects need. You can run those yourself and stop them when you finish.

### There is no local sign in

Sign in goes through Discord and the Supabase project named in `.env`. Discord cannot finish against a local Supabase, and the hub has no stand-in for it yet. A local copy cannot sign anyone in, so `/publish`, `/account` and `/moderation` are out of reach locally. Public pages and the read API do not need an account.

### There is no seed data

A fresh database has no games, maps or gallery items, so the lists are empty. Nothing in the repository seeds a database, and `supabase/config.toml` points at a `supabase/seed.sql` that does not exist. To get data in, run the Coilbox desktop app against your local hub. It posts game and map facts through `/api/v1/games/facts` and `/api/v1/maps`. The app has one configurable address, the hub, so point it at `http://localhost:8100`.

The scripts in `scripts/` that end in `.ts` are for the maintainer and need more than a local checkout. `seed:assets`, `promote:assets` and `import:branding` push files to a separate assets repository. `cleanup:assets` and `backfill:*` write to a database. Each prints what it would do without `--write`, and the comment at the top of each file says what it needs.

## Tests and checks

Run the hub's tests from `hub-php`:

```
php artisan test
```

They run on SQLite in memory locally, fake Supabase with `Http::fake()` and make no network calls. CI runs them on MariaDB 11.8 after `php artisan migrate`, so write queries both accept.

To check a change against a running hub, see "Checking a change" in `hub-php/AGENTS.md`.

`supabase test db` runs the database tests in `supabase/tests` against the local Supabase, which must be running. CI runs it on a clean database after `supabase db start`. On a database with data in it, some files can fail, so run one file to check your setup: `supabase test db supabase/tests/item_facet.test.sql`.

CI in `.github/workflows/ci.yml` also runs these from the repository root, for the redirect app and its copied vendor files:

```
bun run check:vendor
bun run lint
bun run typecheck
bun test
bun run build
```

`check:vendor` compares the files copied from the Coilbox repository with their originals on GitHub. It needs network access and fails when either side has changed. `bun run build` reads `.env.local`, so build with no Supabase configured, as CI does:

```
NEXT_PUBLIC_SUPABASE_URL= NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY= SUPABASE_SERVICE_ROLE_KEY= bun run build
```

## Deployment and migrations

A push to `main` that changes `hub-php/` runs `.github/workflows/deploy-php.yml`. It builds the CSS and JavaScript, runs the tests, copies the release to the DigitalOcean droplet and switches the site to it. `hub-php/deploy/README.md` says how the droplet is set up and how to roll back. Each deploy runs `php artisan migrate --force` before the new release goes live, so a migration must keep working with the release that is still live. Renaming or removing a column takes two deploys.

New tables and columns go in MariaDB, as Laravel migrations in `hub-php/database/migrations`. Supabase migrations in `supabase/migrations` are for data that has not moved yet.

A push to `main` also runs the `migrate` job in `ci.yml` once the `check` and `database` jobs pass. It links the hosted Supabase project, prints `supabase db push --dry-run` to the log and the job summary, then runs `supabase db push`. The job runs in a GitHub environment named `production`. Add required reviewers to that environment in the repository settings to approve each run before it writes. It needs three secrets, set on the repository or on that environment:

- `SUPABASE_ACCESS_TOKEN` is a Supabase personal access token.
- `SUPABASE_DB_PASSWORD` is the production database password.
- `SUPABASE_PROJECT_REF` is the project ref of the hosted project.

If any are missing, the job logs a warning, lists the missing names in its summary, applies nothing and passes. You can still apply migrations by hand with `supabase db push --linked`.

Vercel deploys `main` too. `vercel.json` enables deployments for that branch and sets `github.silent`. The Vercel build is the redirect app, which forwards pages to `hub.coilbox.org` and proxies `/api/v1/*` and `/i/*` there, because installed copies of Coilbox still call `coilbox-hub.vercel.app` and drop their `Authorization` header on a redirect to another host. The rest of the Vercel project, including its environment variables, is not in this repository.

## Repository map

- `hub-php/` is the hub. `hub-php/AGENTS.md` describes its code layout and how to work on it.
- `supabase/` holds `config.toml`, the migrations and the database tests in `tests`.
- `scripts/` holds `local-services.sh`, `sync-vendor.ts` and the maintainer scripts named in `package.json`.
- `next.config.ts` holds the Vercel redirects. The rest of the root (`app/`, `components/`, `lib/`, `proxy.ts`) is the old Next.js app. It no longer serves visitors, and `hub-php/` is not checked against it.
- `docs/superpowers/` holds working plans and specs for past features.

There is no `CONTRIBUTING.md` or licence file in the repository.
