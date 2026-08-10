-- Issue #51: coilbox pings this hub once it applies an import that started
-- from a hub link (coilbox/coilbox#1361). A pasted code or an imported file
-- carries no item id and cannot trigger this, and nothing before that
-- release ever will, permanently: an existing build has no ping to send.
--
-- The column is a plain counter, but the write path cannot be a plain
-- update: issue #27 revoked the broad grant, so anon (this request carries
-- no session) cannot update public.item at all, and even authenticated only
-- holds title, description, tags and deleted_at. A security definer
-- function that touches only import_count, the same shape as
-- current_author_name() in 20260809190500, stands in for the grant a
-- column-level update would otherwise need.
--
-- It reports whether a live row matched, which is how the route tells a
-- nonexistent id apart from a withdrawn one: both leave nothing to update,
-- so both read back as "not found" without the function knowing which.
alter table public.item add column import_count integer not null default 0;

create function public.record_item_imported(target_id uuid) returns boolean
language sql
security definer
set search_path = ''
as $$
  update public.item
  set import_count = import_count + 1
  where id = target_id and deleted_at is null
  returning true;
$$;

-- Callable with no session: the route this backs has no authentication, by
-- design (issue #51's contract). anon needs to reach it directly.
grant execute on function public.record_item_imported(uuid) to anon, authenticated;

-- No grant changes to public.item itself: import_count is covered by the
-- table-wide select grant already in 20260810120000, and is deliberately
-- absent from the update grant beside it, so a client can read the count
-- but never write it directly.
