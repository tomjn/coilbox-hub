# Coilbox Hub in PHP

The hub at https://hub.coilbox.org, a Laravel app. It is the only hub. The Next.js app in the repository root only redirects old `coilbox-hub.vercel.app` addresses here. The hub keeps using Supabase for some data, sign in and the staging picture bucket, and moves data to MariaDB one area at a time.

- `AGENTS.md` says how the code is laid out and how to work on it.
- `deploy/README.md` says how the droplet is set up and how releases go out.

## Run it locally

You need PHP 8.3 or later, Composer and bun.

1. Install the dependencies and build the CSS and JavaScript:

   ```
   composer install
   bun install
   bun run build
   ```

2. Start MariaDB from the repository root with `scripts/local-services.sh up mariadb`. It listens on port 3307, which `deploy/env.example` expects.
3. Copy `deploy/env.example` to `.env`, run `php artisan key:generate`, fill in the three `SUPABASE_` settings, then run `php artisan migrate`.
4. Start it with `php artisan serve --port=8100`, or from the repository root with `scripts/local-services.sh up dev`.

`php artisan test` runs the tests. They fake Supabase and make no network calls.

Signing in goes through Discord and the Supabase project named in `.env`. There is no local sign in yet, so a local copy cannot sign anyone in unless Discord and that project are set up to send people back to `http://localhost:8100/auth/callback`. Public pages do not need an account.

## Security

Report a security problem privately to the maintainer through GitHub's private vulnerability reporting on the tomjn/coilbox-hub repository, not in a public issue.
