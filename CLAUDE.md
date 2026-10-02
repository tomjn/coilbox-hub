@AGENTS.md

## Local services

Local Supabase and the dev server stay running after a session ends unless you stop them. Left running, they use memory other projects on this machine need.

1. Start them only with `scripts/local-services.sh up`. Add `supabase` or `dev` to start one of them. A hook blocks `supabase start`, `bun dev` and the other direct forms.
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
