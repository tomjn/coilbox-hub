-- service_role bypasses row level security, but bypassing RLS is not the same
-- as holding a grant: Postgres still checks table and column privileges
-- first, and this project's tables carry no privilege for service_role by
-- default, the same as anon and authenticated. Nothing needed service_role on
-- public.item before now; every existing admin use of the key
-- (app/account/actions.ts, app/dev/sign-in/route.ts) calls the Auth admin API
-- instead of reading or writing a table directly.
--
-- scripts/backfill-game-names.ts (issue #38) is the first thing that does,
-- since a one off backfill has to read every row's container regardless of
-- author or withdrawn state, and RLS restricts both. It only ever writes
-- game_name, so the update grant is scoped to that column, the same as the
-- author grants beside it.
grant select on public.item to service_role;
grant update (game_name) on public.item to service_role;
