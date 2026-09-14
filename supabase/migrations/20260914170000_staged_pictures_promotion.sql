-- Promoting and sweeping pictures out of the Supabase bucket (issue #335).
--
-- #332 moved uploads into the private `staged-pictures` bucket and left every
-- deleter pointed at Blob. This migration gives promotion and the sweep what
-- they need to delete from the bucket without taking a picture a row still
-- names.
--
-- ## Which store a queued staging path is in
--
-- `blob_path` is promotion's drain queue (20260814230000). A bucket row's
-- staging path has no suffix, so on its own it does not say which store to
-- delete from. `blob_path_tier` says so. Null means Blob, which is where every
-- path queued before this migration is, so nothing is backfilled and no row's
-- `updated_at` moves.
--
-- ## Why a deletion is reserved first
--
-- Identical bytes share one content addressed object, so an upload can reuse an
-- object a deleter has just decided nothing claims. Checking the claims again
-- right before the delete narrows that window and does not close it, because
-- the upload's put and its row write are two requests.
--
-- So a deleter reserves each path in `staged_object_deletion` under a
-- transaction scoped advisory lock on that path, and only if no row claims it.
-- A write that would make a row claim a bucket path takes the same lock and is
-- refused while a reservation is outstanding. Whichever commits first wins:
-- either the claim exists and the path is not reserved, or the reservation
-- exists and the claim is refused, and the upload is answered with a failure
-- its client can retry once the object is gone. The deleter removes the object
-- through the Storage API and then releases the reservation. A run that dies in
-- between leaves the reservation, which keeps refusing claims until the next
-- run finishes the delete.
--
-- ## What claims a bucket object
--
-- - `asset.path` on a row that is not on the durable tier, whatever its
--   moderation state
-- - `asset.blob_path`, which is promotion's queue. It is a claim against the
--   sweep, which has no way to check the durable tier is serving the bytes. It
--   is not a claim against the drain, which is that queue's own deleter and has
--   checked
-- - `game.logo_path` or `game.banner_path` whose staged tier is `bucket`
--
-- public.unclaimed_staged_objects and public.reserve_staged_deletions both
-- apply exactly this list. staged_pictures_promotion.test.sql proves each
-- line against both.

alter table public.asset add column blob_path_tier text
  check (blob_path_tier in ('blob', 'bucket'));

alter table public.asset add constraint asset_blob_path_tier_needs_path
  check (blob_path_tier is null or blob_path is not null);

comment on column public.asset.blob_path_tier is
  'Which staging store holds blob_path: blob (Vercel Blob) or bucket (the staged-pictures Supabase bucket). Null with a blob_path means Blob, for paths queued before 20260914170000. Set and cleared with blob_path.';

-- ## Promotion
--
-- Replaces the function in 20260814230000. The only changes are that a bucket
-- row may move, and that the store its staging path is in is kept alongside
-- it. The conditions are otherwise the same and are explained there.
--
-- Dropped rather than replaced because the answer gains a column.
drop function public.promote_assets(uuid[], text[]);

create function public.promote_assets(ids uuid[], paths text[])
returns table (id uuid, blob_path text, blob_path_tier text)
language plpgsql
set search_path = ''
as $$
begin
  if coalesce(array_length(promote_assets.ids, 1), 0)
    <> coalesce(array_length(promote_assets.paths, 1), 0) then
    raise exception 'promote_assets wants one path per id'
      using errcode = 'invalid_parameter_value';
  end if;

  return query
  update public.asset as a
  set path = wanted.path,
      tier = 'static',
      promoted_at = now(),
      -- The right hand side of a SET reads the old row, so these are the
      -- staging path and store the statement is in the middle of overwriting.
      blob_path = a.path,
      blob_path_tier = a.tier
  from unnest(promote_assets.ids, promote_assets.paths) as wanted(id, path)
  where a.id = wanted.id
    and a.tier <> 'static'
    and a.moderation = 'approved'
    and a.blob_path is null
  returning a.id, a.blob_path, a.blob_path_tier;
end;
$$;

-- Replaces the function in 20260814230000 so the store goes with the path.
create or replace function public.clear_promoted_blob_paths(ids uuid[]) returns integer
language plpgsql
set search_path = ''
as $$
declare
  cleared integer;
begin
  with done as (
    update public.asset
    set blob_path = null,
        blob_path_tier = null
    where id = any(clear_promoted_blob_paths.ids) and blob_path is not null
    returning id
  )
  select count(*)::integer into cleared from done;

  return cleared;
end;
$$;

-- ## Reservations

create table public.staged_object_deletion (
  path text primary key check (length(btrim(path)) between 1 and 512),
  reserved_at timestamptz not null default now()
);

revoke all on public.staged_object_deletion from anon, authenticated, service_role;

-- Read by the sweep, to finish what a dead run reserved. The functions below
-- are the only writers.
grant select on public.staged_object_deletion to service_role;

alter table public.staged_object_deletion enable row level security;

-- The lock a reservation and a claim on one bucket path both take. Held until
-- the transaction ends. A hash collision between two paths only makes one wait
-- for the other.
create function public.lock_staged_path(object_path text) returns void
language sql
set search_path = ''
as $$
  select pg_advisory_xact_lock(hashtextextended('staged-pictures/' || lock_staged_path.object_path, 0));
$$;

-- Reserve the paths nothing claims, and answer with them. That answer is the
-- caller's delete list, and it is deliberately not the list it asked about.
--
-- `queued_is_claimed` is true for the sweep and false for promotion's drain.
-- Every row whose `blob_path` names an object has the same durable path, so
-- once the drain has seen that path served, no queue entry has a reason to
-- keep the object. The rows it did not clear are cleared by a later drain.
--
-- A path already reserved comes back again, so a run can finish a delete an
-- earlier run died in the middle of.
--
-- Locks are taken in path order, one statement each, so the claim check below
-- is a later statement and sees every claim committed while it waited.
create function public.reserve_staged_deletions(object_paths text[], queued_is_claimed boolean)
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

-- Forget reservations whose objects have been deleted. Answers with how many.
create function public.release_staged_deletions(object_paths text[]) returns integer
language plpgsql
security definer
set search_path = ''
as $$
declare
  released integer;
begin
  delete from public.staged_object_deletion
  where path = any(release_staged_deletions.object_paths);

  get diagnostics released = row_count;
  return released;
end;
$$;

-- Refuse a claim on a bucket path that is reserved for deletion.
create function public.refuse_reserved_staged_paths(object_paths text[]) returns void
language plpgsql
set search_path = ''
as $$
declare
  wanted text;
begin
  for wanted in
    select distinct p from unnest(refuse_reserved_staged_paths.object_paths) as p
    where p is not null
    order by p
  loop
    perform public.lock_staged_path(wanted);

    if exists (select 1 from public.staged_object_deletion as d where d.path = wanted) then
      raise exception 'staged object % is being deleted, so it cannot be claimed until that finishes', wanted
        using errcode = 'object_in_use';
    end if;
  end loop;
end;
$$;

-- Security definer so any writer of the two tables can read the reservations
-- without holding select on them.
create function public.asset_refuse_reserved_staged_path() returns trigger
language plpgsql
security definer
set search_path = ''
as $$
begin
  perform public.refuse_reserved_staged_paths(array[new.path]);
  return new;
end;
$$;

create trigger asset_refuse_reserved_staged_path
  before insert or update of path, tier on public.asset
  for each row
  when (new.tier = 'bucket')
  execute function public.asset_refuse_reserved_staged_path();

create function public.game_refuse_reserved_staged_path() returns trigger
language plpgsql
security definer
set search_path = ''
as $$
begin
  perform public.refuse_reserved_staged_paths(array[
    case when new.logo_staged_tier = 'bucket' then new.logo_path end,
    case when new.banner_staged_tier = 'bucket' then new.banner_path end
  ]);
  return new;
end;
$$;

create trigger game_refuse_reserved_staged_path
  before insert or update of logo_path, logo_staged_tier, banner_path, banner_staged_tier on public.game
  for each row
  when (new.logo_staged_tier = 'bucket' or new.banner_staged_tier = 'bucket')
  execute function public.game_refuse_reserved_staged_path();

-- ## The sweep
--
-- Objects in the bucket nothing claims, oldest first. The bucket can be listed
-- from Postgres, so unlike Blob nothing has to be queued as it is orphaned: a
-- superseded upload, an upload whose row write failed, a game picture whose
-- extension changed and a promoted object the drain has released all turn up
-- here.
--
-- Security definer because it reads storage.objects, whose grants are
-- Supabase's to change rather than these migrations'.
create function public.unclaimed_staged_objects(max_objects integer)
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

-- Execute is granted to PUBLIC on every new function, so these revokes are the
-- access control. Every caller is a scheduled job on the secret key.
revoke execute on function public.promote_assets(uuid[], text[]) from public;
revoke execute on function public.clear_promoted_blob_paths(uuid[]) from public;
revoke execute on function public.lock_staged_path(text) from public;
revoke execute on function public.reserve_staged_deletions(text[], boolean) from public;
revoke execute on function public.release_staged_deletions(text[]) from public;
revoke execute on function public.refuse_reserved_staged_paths(text[]) from public;
revoke execute on function public.asset_refuse_reserved_staged_path() from public;
revoke execute on function public.game_refuse_reserved_staged_path() from public;
revoke execute on function public.unclaimed_staged_objects(integer) from public;

grant execute on function public.promote_assets(uuid[], text[]) to service_role;
grant execute on function public.reserve_staged_deletions(text[], boolean) to service_role;
grant execute on function public.release_staged_deletions(text[]) to service_role;
grant execute on function public.unclaimed_staged_objects(integer) to service_role;
