-- The backfill has run against production, so the grants it needed go away
-- again (issue #43).
--
-- Before 20260809200000 the service role held nothing on this table. That was
-- deliberate: bypassing row level security and holding a table privilege are
-- two separate layers, and the second one being shut is what stopped an admin
-- key reaching item rows at all. The two places that use the admin key,
-- app/account/actions.ts and app/dev/sign-in/route.ts, both call the Auth
-- admin API rather than this table, so nothing needs these.
--
-- A future one off job that does need them adds them back, runs, and revokes
-- them, rather than leaving them lying around for the one after that.
revoke update (game_name) on public.item from service_role;
revoke update (game_key) on public.item from service_role;
revoke select on public.item from service_role;
