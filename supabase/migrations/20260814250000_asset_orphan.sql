-- Staging objects nothing points at, and the numbers the store is watched by
-- (issue #113).
--
-- ## Why a queue and not a scan
--
-- Vercel Blob on Hobby gets 2,000 advanced operations a month, list() is one of
-- them, and going over removes Blob access for 30 days with no way to pay
-- through it. So the store is never asked what it holds. What nothing claims is
-- worked out from Postgres, which is only possible while Postgres still knows
-- the name of every object, and a pathname carries a random suffix nobody can
-- derive (20260814200000). The moment a path is overwritten and not written
-- down somewhere else, the object it named is unreachable forever.
--
-- 20260814230000 already keeps one such name, in asset.blob_path, for the gap
-- between a row moving to the durable tier and its staging copy being deleted.
-- That column is a queue of one entry per row and it is full for exactly as long
-- as that gap lasts. It cannot also hold the case below, because both can be
-- outstanding for the same row at the same time.
--
-- ## The case this table is for
--
-- A newer archive replacing a picture (#106). writePendingAsset updates the row
-- in place: a fresh object goes into the store, path is overwritten with its
-- pathname, and the object the row named a moment ago is left behind with
-- nothing naming it. Postgres is the only party that ever knew where it was, and
-- it forgets in the same statement.
--
-- So the old name is copied out by a trigger, in that statement, rather than by
-- the route that happened to cause it. The secret key holds update on
-- public.asset, so a replacement written straight through PostgREST would
-- bypass a rule that lived in a function, and the upload route is not the last
-- thing that will ever change a path.
--
-- The second case is an upload whose object was stored and whose row was never
-- written. The route deletes the object when that happens, and
-- public.record_unclaimed_object is where it says so when the delete fails too.
-- That one cannot be complete and does not pretend to be: an upload that dies
-- between put() and any record at all leaves an object no query can find, which
-- is the price of never calling list() and is written down rather than papered
-- over.
--
-- ## Why the row outlives the object
--
-- deleted_at rather than a delete, following public.asset_withdrawal
-- (20260814240000). An 'unclaimed' row is a put() that spent an advanced
-- operation and left no asset row to count, so the operations meter in
-- lib/assets/meters.ts adds them, and a queue that empties itself would take
-- that number with it.

create table public.asset_orphan (
  id bigint generated always as identity primary key,

  -- The staging pathname, suffix and all, exactly as Blob knows it. Never a
  -- durable path: the durable tier is a git history and nothing here deletes
  -- from it. That is public.asset_withdrawal's queue and it is a person's work.
  path text not null check (length(btrim(path)) between 1 and 512),

  -- What the object takes up, copied off the row that is losing it, so the
  -- storage meter can count objects that are still in the store and no longer
  -- described by any asset row.
  bytes integer not null check (bytes > 0),

  -- 'superseded' is a newer archive replacing the bytes, and is recorded by the
  -- trigger below in the same statement that overwrites the path.
  -- 'unclaimed' is an object stored for a row that was never written, recorded
  -- by the upload route when its own compensating delete also failed.
  reason text not null check (reason in ('superseded', 'unclaimed')),

  at timestamptz not null default now(),

  -- When the object was actually deleted from the store. Null is the queue.
  deleted_at timestamptz
);

-- One row per object. A path is unique in the store, so a second record of the
-- same one is the same fact twice.
create unique index asset_orphan_path_idx on public.asset_orphan (path);

-- The sweep, which is the only query that walks this table.
create index asset_orphan_outstanding_idx on public.asset_orphan (at)
  where deleted_at is null;

revoke all on public.asset_orphan from anon, authenticated, service_role;

-- Read only for the secret key, like public.asset_withdrawal. A row here names a
-- reachable object in a public store holding bytes nobody has reviewed, so it is
-- not a list to hand a browser.
grant select on public.asset_orphan to service_role;

alter table public.asset_orphan enable row level security;

-- The name of a staging object that has just stopped being named.
--
-- Security definer so it can write a table nobody holds insert on, which means
-- no writer of public.asset can decline to leave the record.
--
-- Three conditions, and the third is the one that keeps this out of promotion's
-- way. public.promote_assets also overwrites the path of a staging row, and it
-- puts the old value in blob_path in the same statement, so the object is
-- already named and recording it here would queue one deletion twice.
create function public.record_superseded_object() returns trigger
language plpgsql
security definer
set search_path = ''
as $$
begin
  -- Only the staging tier. A durable path being overwritten is bytes in a git
  -- history, which nothing here may delete.
  if old.tier <> 'blob' then
    return null;
  end if;

  -- Nothing moved.
  if new.path is not distinct from old.path then
    return null;
  end if;

  -- Promotion kept the name itself.
  if new.blob_path is not distinct from old.path then
    return null;
  end if;

  insert into public.asset_orphan (path, bytes, reason)
  values (old.path, old.bytes, 'superseded')
  on conflict (path) do nothing;

  return null;
end;
$$;

create trigger asset_record_superseded_object
  after update on public.asset
  for each row execute function public.record_superseded_object();

-- An object stored for a row that was never written.
--
-- Called by the upload route, and only after its own delete has failed. The
-- ordinary path is that a failed row write is followed by a free delete and
-- there is nothing to record, so a row in here through this door means two
-- things went wrong in one request.
--
-- Answers whether it recorded anything, so a repeat of the same path is visibly
-- not a second object.
-- The parameters are not called `path` and `bytes`, because `on conflict (path)`
-- cannot tell a plpgsql variable of that name from the column.
create function public.record_unclaimed_object(object_path text, object_bytes integer)
returns boolean
language plpgsql
security definer
set search_path = ''
as $$
declare
  wrote integer;
begin
  insert into public.asset_orphan (path, bytes, reason)
  values (record_unclaimed_object.object_path, record_unclaimed_object.object_bytes, 'unclaimed')
  on conflict (path) do nothing;

  get diagnostics wrote = row_count;
  return wrote > 0;
end;
$$;

-- Say the objects are gone, once they actually are.
--
-- Called after the deletion rather than with it, for the same reason
-- public.clear_promoted_blob_paths is: the entry has to outlive every failure
-- between the two, and deleting an object that is already gone is something Blob
-- accepts without complaint. Answers with how many it settled.
create function public.clear_asset_orphans(ids bigint[]) returns integer
language plpgsql
security definer
set search_path = ''
as $$
declare
  cleared integer;
begin
  with done as (
    update public.asset_orphan
    set deleted_at = now()
    where id = any(clear_asset_orphans.ids) and deleted_at is null
    returning id
  )
  select count(*)::integer into cleared from done;

  return cleared;
end;
$$;

-- What the two stores are holding, in one call.
--
-- A function because PostgREST refuses aggregates by default, which is the
-- reason public.account_asset_bytes (20260814190000) is one too. Grouped rather
-- than totalled because #113 is explicit that a single growing number says
-- nothing: buildpics are negligible, the map corpus is fixed at about 3,575, and
-- renders are the only class that can move.
--
-- Every moderation state, deliberately. A rejected picture's bytes are still in
-- the store taking up the allowance, and a meter that only counted the approved
-- ones would read a store full of refused uploads as empty.
--
-- Outstanding orphans come back under a tier of their own. They are in the
-- staging store and are described by no asset row, so leaving them out would
-- under-report the one meter whose overrun is a 30 day outage.
--
-- Security invoker. service_role already holds select on both tables and
-- carries bypassrls, so a definer would add a privilege nothing needs.
create function public.asset_storage_usage()
returns table (tier text, variant text, objects bigint, bytes bigint)
language sql
stable
set search_path = ''
as $$
  select a.tier, a.variant, count(*)::bigint, coalesce(sum(a.bytes), 0)::bigint
  from public.asset as a
  group by a.tier, a.variant
  union all
  select 'orphan', o.reason, count(*)::bigint, coalesce(sum(o.bytes), 0)::bigint
  from public.asset_orphan as o
  where o.deleted_at is null
  group by o.reason;
$$;

-- Execute is granted to PUBLIC on every new function, so these revokes are the
-- access control and not a tidy-up.
revoke execute on function public.record_superseded_object() from public;
revoke execute on function public.record_unclaimed_object(text, integer) from public;
revoke execute on function public.clear_asset_orphans(bigint[]) from public;
revoke execute on function public.asset_storage_usage() from public;

-- Not `authenticated`. None of these is a decision anybody makes on a screen:
-- two are the upload route and the sweep running on the secret key, and the
-- third is a report the ops page reads server side for the same reason
-- lib/assets/queue.ts reads the moderation queue server side.
grant execute on function public.record_unclaimed_object(text, integer) to service_role;
grant execute on function public.clear_asset_orphans(bigint[]) to service_role;
grant execute on function public.asset_storage_usage() to service_role;
