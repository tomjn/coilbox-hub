-- Production and a database built from these migrations do not hold the
-- same privileges on public.report and public.moderator (issue #59):
-- `anon` reads both as empty in production and is refused outright here.
-- Every table in public turns out to be affected, not just those two.
--
-- The cause is default privileges. This project predates a narrower cloud
-- default -- config.toml's commented-out `auto_expose_new_tables` already
-- documents the newer behaviour -- and still carries something equivalent
-- to `alter default privileges in schema public grant all on tables to
-- anon, authenticated, service_role` for the role migrations run as. That
-- materialises into an ordinary grant, select included, the moment any
-- table is created, indistinguishable afterwards from a grant this
-- repository wrote on purpose. Reproducing it locally by hand (granting
-- `anon` select on report and moderator) turns the 401 the RLS test suite
-- expects into a silent, empty 200: exactly production's behaviour, and
-- exactly what a table with a missing or wrong policy would expose.
--
-- The fix has two parts. First, this migration is now authoritative for
-- every table in public: whatever anon, authenticated and service_role
-- hold is revoked outright, then only the grants the rest of this
-- repository's migrations actually rely on are put back, so any privilege
-- the stray default handed out -- on these three tables or a later one --
-- is gone rather than patched table by table. Second, the default itself
-- is turned off, so the table after this one starts from nothing, the same
-- as a database built fresh from these migrations already does.

revoke all on public.item from anon, authenticated, service_role;
revoke all on public.moderator from anon, authenticated, service_role;
revoke all on public.report from anon, authenticated, service_role;

-- public.item: unchanged from 20260809151352, 20260809190500 and
-- 20260810090000. anon and authenticated both read; authenticated
-- publishes the columns publishItem actually sets, edits the words around
-- an item and withdraws it, and deletes outright. service_role holds
-- nothing, per 20260810100000.
grant select on public.item to anon, authenticated;
grant insert (
  kind, kind_version, title, description, game_name, map_name, tags,
  container, author_id, game_key
) on public.item to authenticated;
grant update (title, description, tags, deleted_at) on public.item to authenticated;
grant delete on public.item to authenticated;

-- public.moderator: unchanged from 20260809183738. Nobody gets a table
-- grant here, not even authenticated. is_moderator() reads the table as
-- its own definer, and that function is the only way anon or authenticated
-- ever learn anything derived from it.

-- public.report: unchanged from 20260809183738. Anyone may insert; only
-- authenticated may select or update, and report_read_moderators /
-- report_handle_moderators are what actually narrow that down to a
-- moderator.
grant insert on public.report to anon, authenticated;
grant select, update on public.report to authenticated;

-- Turn off the source of the drift, not just its current effect, so a
-- table created after this one starts from nothing, the same as it already
-- does on a database built fresh from these migrations.
alter default privileges in schema public
  revoke all on tables from anon, authenticated, service_role;
