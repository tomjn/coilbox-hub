# Coilbox Hub in PHP

The hub at https://hub.coilbox.org, as a Laravel app. It replaces the Next.js app in the repository root and keeps using Supabase for the database, sign in and the staging picture bucket.

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

2. Copy `deploy/env.example` to `.env`, run `php artisan key:generate`, and fill in the three `SUPABASE_` settings.
3. Start it with `php artisan serve --port=8100`.

`php artisan test` runs the tests. They fake Supabase and make no network calls.

## Security

Report a security problem privately to the maintainer through GitHub's private vulnerability reporting on the tomjn/coilbox-hub repository, not in a public issue.
