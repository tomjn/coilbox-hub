-- Two rows pointing at one staging object, and the rule that keeps the object
-- alive while either of them needs it (issue #132).
--
-- ## What changed upstream, and why this is safe now
--
-- A path is content addressed: the leaf is the hash of the encoded bytes. Until
-- 20260814230000 that hash was the client's word, so two rows sharing a path
-- meant either the same bytes or an uploader who had declared somebody else's
-- hash. Reusing an object on the strength of the second would have handed an
-- account a stranger's picture. #154 made the hub compute the hash from the
-- bytes it received, so a shared hash now means shared bytes and nothing else.
--
-- That is what makes it worth skipping the put(). An upload whose bytes are
-- already in the staging store spends one advanced operation out of 2,000 a
-- month to write the store what it already holds, and placeholder buildpics
-- repeat across a whole roster.
--
-- ## Objects, not references
--
-- Sharing means the deletion question changes from "has this row moved on" to
-- "has every row moved on". There were two ways to answer it, and this migration
-- picks the first:
--
-- 1. public.asset_orphan keeps recording objects, one row per pathname, and the
--    question of who still needs one is asked of public.asset, which is where
--    the references actually are.
-- 2. It records references, one row per (asset, pathname), and an object goes
--    when its last reference is settled.
--
-- The second is a reference count, and a reference count is a second copy of
-- something Postgres already knows exactly. Every insert, replacement, promotion
-- and rejection would have to keep it in step, and a count that drifts high
-- leaks an object forever while one that drifts low deletes a picture somebody
-- is looking at. The first needs no new state at all: `path` on a staging row is
-- the reference, and `select` is the count.
--
-- So the rule is one sentence, and both deleters obey it: a staging object may
-- be queued, and may be deleted, only when no asset row names it. The trigger
-- below is where that is enforced on the way in, lib/assets/orphan.ts asks again
-- before the sweep deletes, and lib/assets/promote.ts asks the same question
-- before it drains. The repetition is deliberate for the reason the sweep's
-- existing check is: deletion is the one step nothing here can undo.

-- The reuse lookup runs on every accepted upload and is a lookup by content
-- hash, so it gets the index. Partial, because a durable row's bytes are in a
-- git history and are never a candidate.
create index asset_staging_hash_idx on public.asset (hash) where tier = 'blob';

-- The name of a staging object that has just stopped being named, unless
-- somebody else is still naming it.
--
-- Replaces the function in 20260814250000. The first three conditions are
-- unchanged: not the durable tier, not a no-op update, and not an object
-- promotion has already put in blob_path itself.
--
-- The fourth is #132's. With two rows able to share an object, the row losing a
-- path is no longer evidence that the object is unclaimed, and queueing it would
-- put a live picture in front of a sweep. The sweep would refuse it, because it
-- checks, but that is the backstop catching an entry that should never have been
-- written, and the entry would sit outstanding forever being counted as storage
-- nothing claims.
create or replace function public.record_superseded_object() returns trigger
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

  -- Somebody else is still serving those bytes, or has already queued them.
  if exists (
    select 1
    from public.asset as other
    where other.id <> old.id
      and ((other.tier = 'blob' and other.path = old.path) or other.blob_path = old.path)
  ) then
    return null;
  end if;

  insert into public.asset_orphan (path, bytes, reason)
  values (old.path, old.bytes, 'superseded')
  on conflict (path) do nothing;

  return null;
end;
$$;

-- The staging object already holding these bytes, or null when there is none.
--
-- Answers the one question the upload route asks before it spends an advanced
-- operation. `hash` is over the encoded bytes and the hub computes it, so a row
-- coming back here is a row whose object is byte for byte what this upload is
-- about to write.
--
-- The two `not exists` clauses are what keep the answer from being a path that
-- is on its way out, or already gone. An object named by some row's `blob_path`
-- is promotion's to delete once the durable tier is serving it, and an object
-- the orphan queue has ever heard of is one the sweep is about to delete or has
-- deleted already. Either would be handed to a new row moments before the bytes
-- went. Both are rare and both are cheap to exclude, so they are excluded here
-- rather than raced against.
--
-- The one race left is the other order: an upload reads this, and the object's
-- last row is replaced before the new row is written, so the trigger sees
-- nothing claiming the path and queues it. Nothing is lost, because the sweep
-- asks the table again and keeps it, but the entry stays outstanding and the
-- storage meter counts those bytes twice. That is a wrong number rather than a
-- missing picture, and closing it properly needs the row and the put() in one
-- transaction, which they are not.
--
-- Security invoker. service_role already holds select on both tables and carries
-- bypassrls, so a definer would add a privilege nothing needs. Same reasoning as
-- public.asset_storage_usage.
create function public.reusable_staging_object(object_hash text) returns text
language sql
stable
set search_path = ''
as $$
  select a.path
  from public.asset as a
  where a.hash = reusable_staging_object.object_hash
    and a.tier = 'blob'
    and a.promoted_at is null
    and not exists (
      select 1 from public.asset as other where other.blob_path = a.path
    )
    and not exists (
      select 1 from public.asset_orphan as queued where queued.path = a.path
    )
  order by a.created_at, a.id
  limit 1;
$$;

-- Execute is granted to PUBLIC on every new function, so these are the access
-- control and not a tidy-up. The trigger's is re-stated because a replaced
-- function is worth being explicit about rather than trusting to inheritance.
revoke execute on function public.record_superseded_object() from public;
revoke execute on function public.reusable_staging_object(text) from public;

-- Not `authenticated`. This is the upload route running on the secret key, and
-- the answer names a reachable object in a public store holding bytes nobody has
-- reviewed.
grant execute on function public.reusable_staging_object(text) to service_role;
