-- Issue #59 found a production project holding grants these migrations never
-- wrote, and 20260810120000 shut that off for tables. It left functions and
-- sequences alone, and Supabase CLI v2.116.0 is what made the gap visible:
-- auto_expose_new_tables now defaults to true, so a database built locally
-- carries `alter default privileges in schema public grant all on functions to
-- anon, authenticated, service_role` the way a hosted project does. Five
-- assertions across account_asset_bytes, asset_withdrawal, map_lookup and
-- map_moderation went red on 2026-08-27 because of it.
--
-- The red tests are the smaller half. Every one of these functions has been
-- created under that default on production since the day it was written, so
-- anon there very likely holds execute on public.map_facts,
-- public.account_asset_bytes and public.clear_map_facts today: a licence gate,
-- another account's storage total and a moderation action, each reachable with
-- the publishable key. The `revoke execute ... from public` those migrations
-- wrote does not touch a grant made to a role by name, which is why the two
-- looked alike in the test suite and were not.
--
-- Same fix and same shape as 20260810120000. This migration is authoritative
-- for every function in public: whatever anon, authenticated and service_role
-- hold is revoked outright, then only the grants the rest of these migrations
-- actually rely on are put back, so a privilege the stray default handed out is
-- gone rather than patched function by function. Sequences take the revoke with
-- nothing after it, because no role has ever been meant to hold one, and
-- usage on a sequence is the ability to burn or reset the ids behind it. Then
-- the default itself is turned off, so the function created after this one
-- starts from nothing.

revoke all on all functions in schema public
  from anon, authenticated, service_role;

revoke all on all sequences in schema public
  from anon, authenticated, service_role;

-- Asked in a browser. The three visibility helpers are called by policies on
-- the game tables rather than by a page, the two key lookups are how a credit
-- becomes an author, and has_capability and is_moderator are the questions a
-- signed in session asks about itself. record_item_imported counts a download
-- and is the one write of the set.
grant execute on function public.author_key(text) to anon, authenticated;
grant execute on function public.resolved_author_key(text) to anon, authenticated;
grant execute on function public.game_row_visible(uuid) to anon, authenticated;
grant execute on function public.game_version_visible(uuid, text) to anon, authenticated;
grant execute on function public.game_unit_revision_visible(bigint, text) to anon, authenticated;
grant execute on function public.has_capability(text) to anon, authenticated;
grant execute on function public.is_moderator() to anon, authenticated;
grant execute on function public.record_item_imported(uuid) to anon, authenticated;

-- A signed in account. The moderation grid's three decisions, each of which
-- checks the capability itself rather than trusting the grant, and the name an
-- item is published under.
grant execute on function public.approve_assets(uuid[]) to authenticated;
grant execute on function public.reject_asset(uuid, text) to authenticated;
grant execute on function public.return_asset(uuid) to authenticated;
grant execute on function public.current_author_name() to authenticated;

-- The routes, holding the secret key. Submission and lookup, the storage and
-- promotion bookkeeping, and the two moderation actions that are decisions made
-- on a server rather than on a screen.
grant execute on function public.submit_map_facts(jsonb, uuid) to service_role;
grant execute on function public.submit_game_facts(jsonb, uuid) to service_role;
grant execute on function public.map_facts(text[]) to service_role;
grant execute on function public.author_credits(text) to service_role;
grant execute on function public.author_key(text) to service_role;
grant execute on function public.resolved_author_key(text) to service_role;
grant execute on function public.account_asset_bytes(uuid) to service_role;
grant execute on function public.asset_storage_usage() to service_role;
grant execute on function public.promote_assets(uuid[], text[]) to service_role;
grant execute on function public.clear_promoted_blob_paths(uuid[]) to service_role;
grant execute on function public.record_asset_withdrawn(uuid[]) to service_role;
grant execute on function public.clear_asset_orphans(bigint[]) to service_role;
grant execute on function public.record_unclaimed_object(text, integer) to service_role;
grant execute on function public.reusable_staging_object(text) to service_role;
grant execute on function public.clear_map_facts(uuid) to service_role;

-- Turn off the source of the drift, not just its current effect, so the next
-- function and the next sequence start from nothing, the same as they already
-- do on a database built before v2.116.0 changed the default.
alter default privileges in schema public
  revoke all on functions from anon, authenticated, service_role;

alter default privileges in schema public
  revoke all on sequences from anon, authenticated, service_role;
