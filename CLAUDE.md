@AGENTS.md

## Which app is the hub

The live hub is the Laravel app in `hub-php/`, served from `https://hub.coilbox.org`. Read `hub-php/AGENTS.md` before working there. Page, style and copy changes go in `hub-php/`.

The Next.js app in the repository root no longer serves visitors. On Vercel it only forwards traffic to `hub.coilbox.org`, set up in `next.config.ts`:

- Pages get a permanent redirect, keeping the path and query string.
- `/api/v1/*` and `/i/*` are proxied, not redirected. Installed copies of Coilbox call `coilbox-hub.vercel.app`, and their HTTP client drops the `Authorization` header on a redirect to another host.

Locally, without `VERCEL` set, the Next app still serves every page. It is the reference the PHP port is checked against. Do not add features or restyle pages there. A Next.js "N" badge in the corner of a screenshot means you are looking at this app, not the live hub.

## New data goes in MariaDB

New tables, columns, views and functions go in the hub's MariaDB, as Laravel migrations in `hub-php/database/migrations`. A change to Supabase is only for data that has not moved yet, and needs the owner's agreement. `hub-php/AGENTS.md` has the details.

## Local services

Local Supabase, the hub's MariaDB and the dev server stay running after a session ends unless you stop them. Left running, they use memory other projects on this machine need.

1. Start them only with `scripts/local-services.sh up`. Add `supabase`, `mariadb` or `dev` to start one of them. A hook blocks `supabase start`, `bun dev` and the other direct forms.
2. Run `scripts/local-services.sh status` first. If something is already running, the script leaves it alone and `down` will not stop it.
3. Run `scripts/local-services.sh down` before your final message. It stops only what `up` started. Do not use `pkill -f "next dev"`, which also kills dev servers in other projects.
4. Only the lead session starts services. A subagent that needs them asks the lead, or uses what is already running.
5. Do not hand work back with services still running unless the owner asked to use them. If they did, say so in the final message.

`up` starts Supabase without Studio, the mail catcher, realtime, edge functions and analytics, because the hub uses none of them. If the owner wants Studio, they run `supabase start` in their own terminal.

A `SessionEnd` hook runs `down` as a fallback when no other Claude session is open in this checkout. It writes what it did to `.local-services/session-end.log`.

## Production database access

Before telling the owner that a migration or other Supabase change needs applying, check whether you can apply it yourself. The Supabase CLI on this machine is usually linked to the production project.

1. Run `supabase migration list --linked`. It is read only, and it shows which local migrations production does not have yet.
2. If that connects, read every pending migration, including ones from earlier work that were never applied, and say what each one changes.
3. Apply them with `supabase db push --linked` once the owner has asked for the change to ship, for example by approving the PR or asking for a merge. Do not stop to hand the push back to them.
4. Run `supabase migration list --linked` again to confirm production recorded them.

Only report a migration as something the owner has to apply when the CLI cannot reach the project.
