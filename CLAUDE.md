@AGENTS.md

## Production database access

Before telling the owner that a migration or other Supabase change needs applying, check whether you can apply it yourself. The Supabase CLI on this machine is usually linked to the production project.

1. Run `supabase migration list --linked`. It is read only, and it shows which local migrations production does not have yet.
2. If that connects, read every pending migration, including ones from earlier work that were never applied, and say what each one changes.
3. Apply them with `supabase db push --linked` once the owner has asked for the change to ship, for example by approving the PR or asking for a merge. Do not stop to hand the push back to them.
4. Run `supabase migration list --linked` again to confirm production recorded them.

Only report a migration as something the owner has to apply when the CLI cannot reach the project.
