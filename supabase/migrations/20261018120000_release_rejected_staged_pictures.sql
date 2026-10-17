-- Free the bucket object of an editorially rejected picture (issue #348).
--
-- A rejected `asset` row kept its `path` on the `bucket` tier, so
-- `unclaimed_staged_objects` and `reserve_staged_deletions` counted it as a claim
-- and the daily sweep never listed the object. Every rejected upload then took
-- up part of the bucket's 1 GB for good.
--
-- The row is not touched. Its moderation state, rejection kind, `path` and
-- `asset_event` trail stay exactly as they are, so who rejected it and why is
-- still on record. Only the claim on the object ends, and only for one case:
--
--   editorial   released once the rejection is older than the grace period below
--   safety      never released. The bytes may be evidence, and the row is frozen
--   unrecorded  never released. Nobody wrote down why, so nothing says it is junk
--
-- The age of the rejection is `updated_at`. The rejecting update sets it, and
-- every later write to the row only moves it forward, which delays the release
-- and never brings it early.
--
-- `return_asset` has to agree with the sweep, or a moderator could send a
-- picture back to the queue whose object is already gone. It refuses a row once
-- the grace period has passed, using the same function, so the two cannot
-- disagree. An editorial rejection past the grace period needs the picture
-- uploaded again.

-- Whether a row's staged object is still wanted by the row itself. The grace
-- period is the interval in this one function.
create function public.asset_holds_staged_bytes(moderation text, rejection_kind text, updated_at timestamptz)
returns boolean
language sql
stable
set search_path = ''
as $$
  select not (
    asset_holds_staged_bytes.moderation = 'rejected'
    and asset_holds_staged_bytes.rejection_kind = 'editorial'
    and asset_holds_staged_bytes.updated_at < now() - interval '14 days'
  );
$$;

revoke execute on function public.asset_holds_staged_bytes(text, text, timestamptz) from public;

-- Replaces the function in 20260914170000. The only change is the first claim.
create or replace function public.reserve_staged_deletions(object_paths text[], queued_is_claimed boolean)
returns table (object_path text)
language plpgsql
security definer
set search_path = ''
as $$
declare
  wanted text;
begin
  for wanted in
    select distinct p from unnest(reserve_staged_deletions.object_paths) as p
    where p is not null
    order by p
  loop
    perform public.lock_staged_path(wanted);
  end loop;

  return query
  insert into public.staged_object_deletion as d (path)
  select distinct p
  from unnest(reserve_staged_deletions.object_paths) as p
  where p is not null
    and not exists (
      select 1 from public.asset as a
      where a.path = p and a.tier <> 'static'
        and public.asset_holds_staged_bytes(a.moderation, a.rejection_kind, a.updated_at)
    )
    and not (
      reserve_staged_deletions.queued_is_claimed
      and exists (select 1 from public.asset as a where a.blob_path = p)
    )
    and not exists (
      select 1 from public.game as g
      where (g.logo_path = p and g.logo_staged_tier = 'bucket')
         or (g.banner_path = p and g.banner_staged_tier = 'bucket')
    )
  on conflict (path) do update set reserved_at = d.reserved_at
  returning d.path;
end;
$$;

-- Replaces the function in 20260914170000. The only change is the first claim.
create or replace function public.unclaimed_staged_objects(max_objects integer)
returns table (object_path text, bytes bigint, created_at timestamptz)
language sql
stable
security definer
set search_path = ''
as $$
  select o.name, coalesce((o.metadata ->> 'size')::bigint, 0), o.created_at
  from storage.objects as o
  where o.bucket_id = 'staged-pictures'
    and not exists (
      select 1 from public.asset as a
      where a.path = o.name and a.tier <> 'static'
        and public.asset_holds_staged_bytes(a.moderation, a.rejection_kind, a.updated_at)
    )
    and not exists (
      select 1 from public.asset as a
      where a.blob_path = o.name
    )
    and not exists (
      select 1 from public.game as g
      where (g.logo_path = o.name and g.logo_staged_tier = 'bucket')
         or (g.banner_path = o.name and g.banner_staged_tier = 'bucket')
    )
  order by o.created_at, o.name
  limit unclaimed_staged_objects.max_objects;
$$;

-- Replaces the function in 20260814220200. The only change is the last
-- condition, which refuses a row whose object the sweep may already have
-- deleted. A row on the durable tier keeps its bytes there, so it is not held
-- back.
create or replace function public.return_asset(asset_id uuid) returns boolean
language plpgsql
security definer
set search_path = ''
as $$
declare
  returned uuid;
begin
  if not public.is_moderator() then
    raise exception 'returning a picture to the queue needs can_moderate'
      using errcode = 'insufficient_privilege';
  end if;

  update public.asset
  set moderation = 'pending', rejection_kind = null, approval_source = null
  where id = return_asset.asset_id
    and moderation = 'rejected'
    and rejection_kind <> 'safety'
    and (
      tier = 'static'
      or public.asset_holds_staged_bytes(moderation, rejection_kind, updated_at)
    )
  returning id into returned;

  return returned is not null;
end;
$$;
