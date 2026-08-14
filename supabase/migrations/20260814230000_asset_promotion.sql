-- Moving a row from the staging tier to the durable one, in an order that can
-- be interrupted (issue #111).
--
-- The rule the whole job is built around is that an object must never be
-- absent from both tiers. Promotion writes the object into the assets repo,
-- then moves the row, then deletes the staging object, and a run that dies
-- part way through has to leave the picture reachable rather than gone.
--
-- Ordering the three steps that way is most of it and it is not all of it.
-- The gap is between the row moving and the staging object being deleted. The
-- row's `path` is the only record of where the staging object is: it is the
-- pathname Blob returned, which carries a random suffix nobody can derive
-- (20260814200000), so once promotion overwrites it with the content addressed
-- path the staging object cannot be named again. A run that dies in that gap
-- would leave an object in the store that nothing points at, and #113 says
-- what nothing claims is found by enumerating from Postgres and never with
-- `list()`, so an object Postgres has forgotten is an object nothing can ever
-- find.
--
-- So the staging path is kept rather than overwritten, and the deletion is
-- driven from it. `blob_path` is a short queue of one thing: bytes in the
-- staging tier whose row has already moved. The promoting run empties it, and
-- if that run dies the next one empties it instead, which is why the job's
-- first act is to drain whatever is in there.

alter table public.asset add column blob_path text
  check (length(btrim(blob_path)) between 1 and 512);

comment on column public.asset.blob_path is
  'The staging object still to be deleted, set by promote_assets when it overwrites path with the content addressed one and cleared by clear_promoted_blob_paths once the object is gone. Non-null means the bytes are in both tiers, which is the safe direction to be interrupted in. Nothing else reads it: which tier serves the picture is `tier`, and the path a URL is built from is `path`.';

-- Deliberately not tied to `tier` or to `promoted_at`. A promoted row can be
-- replaced by a newer archive before the deletion runs, and `writePendingAsset`
-- puts it back to `tier = 'blob'` with a fresh staging path, at which point the
-- old object is genuinely an orphan and this column is the only thing that
-- still names it. A constraint saying only a static row may carry one would
-- refuse that update and strand the object instead.

-- Move a batch of rows to the durable tier, all of them or none.
--
-- One statement rather than an update per row, because the guarantee is about
-- the batch and not about each row: a run that died half way through a loop
-- would leave some rows moved and some not, and the caller holding the staging
-- paths in memory would have no way to tell which deletions were owed. One
-- statement is one transaction, so the batch either happened or did not.
--
-- Answers with the rows it actually moved and the staging path each one had.
-- That is the caller's delete list, and it is deliberately not the list the
-- caller asked about: three of the conditions below can turn a row down, and
-- deleting the staging object of a row that did not move would be the exact
-- failure this file exists to prevent.
--
--   tier = 'blob'         a row already promoted has nothing to promote, and
--                         its `path` is a durable path, not a staging one
--   moderation approved   the caller selected approved rows, and moderation is
--                         somebody else's to change in between. A picture
--                         rejected while this run was fetching bytes must not
--                         be moved, and in particular must not have its
--                         staging copy deleted on the strength of a durable
--                         copy the run has just committed
--   blob_path is null     a row whose last promotion has not been cleaned up
--                         yet. Overwriting the queue entry would lose the
--                         object it names, which is the whole point of the
--                         column. It waits for the next run
--
-- Security invoker, so the caller's own privileges apply. service_role already
-- holds select and update on public.asset (20260814180000) and is the only
-- caller. Nothing here needs to reach past the grants that are already there.
create function public.promote_assets(ids uuid[], paths text[])
returns table (id uuid, blob_path text)
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
      -- The right hand side of a SET reads the old row, so this is the staging
      -- path the statement is in the middle of overwriting.
      blob_path = a.path
  from unnest(promote_assets.ids, promote_assets.paths) as wanted(id, path)
  where a.id = wanted.id
    and a.tier = 'blob'
    and a.moderation = 'approved'
    and a.blob_path is null
  returning a.id, a.blob_path;
end;
$$;

-- Forget a staging object that has been deleted.
--
-- Separate from promote_assets and called after the deletion rather than with
-- it, so the queue entry outlives every failure between the two. Answers with
-- how many it cleared, which is how the caller knows the row it thought it was
-- cleaning up was still the row it promoted.
--
-- Safe on a row whose entry is already null, and safe on a row a replacement
-- has since put back on the staging tier: it clears the queue entry and leaves
-- `path`, `tier` and everything else alone.
create function public.clear_promoted_blob_paths(ids uuid[]) returns integer
language plpgsql
set search_path = ''
as $$
declare
  cleared integer;
begin
  with done as (
    update public.asset
    set blob_path = null
    where id = any(clear_promoted_blob_paths.ids) and blob_path is not null
    returning id
  )
  select count(*)::integer into cleared from done;

  return cleared;
end;
$$;

-- Execute is granted to PUBLIC on every new function, so these revokes are the
-- access control and not a tidy-up. Neither of these is a decision a session
-- makes: promotion runs on a schedule with the secret key and no user behind
-- it, unlike the moderation functions in 20260814220200, which exist precisely
-- so a moderator's own session makes the write.
revoke execute on function public.promote_assets(uuid[], text[]) from public;
revoke execute on function public.clear_promoted_blob_paths(uuid[]) from public;

grant execute on function public.promote_assets(uuid[], text[]) to service_role;
grant execute on function public.clear_promoted_blob_paths(uuid[]) to service_role;
