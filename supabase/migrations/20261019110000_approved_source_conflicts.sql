-- A source disagreement on a picture that is already approved (issue #149).
--
-- public.asset_source_conflict records a second account reporting different
-- bytes out of the same archive. The contact sheet rings a pending tile, and an
-- approved picture is not in the queue, so for that case the row was written
-- and nobody was shown it.
--
-- The fix is somewhere to look and not a state change. Putting the picture back
-- to pending on a stranger's report would let any signed in account de-publish
-- approved pictures one request at a time, which is what only the uploader
-- being able to replace a row exists to stop. So nothing here touches
-- public.asset. A moderator reads the list and rejects a picture through the
-- existing reject_asset if it deserves it.
--
-- ## Reading, and why through a function
--
-- The table grants anon and authenticated nothing and has row level security
-- with no policy, because a reported hash names bytes nobody has reviewed and
-- who reported them is not for a browser. That stays. The list is a security
-- definer function that asks is_moderator() before it reads, the same shape as
-- moderator_accounts, so the moderator's own session calls it and no policy or
-- grant on the table widens.
--
-- ## Clearing an entry
--
-- Two columns on the conflict row say a moderator has looked, and who. A new
-- column rather than a delete, because the row is evidence and the unique index
-- on (asset_id, reported_source_hash) is what stops a client looping on the
-- same upload from refilling the list: a deleted row would be written again by
-- the next identical report. Cleared, it stays cleared. The same picture
-- reported with different bytes is a different fact and is a new entry.
--
-- Clearing is logged in public.asset_event like every other moderation action,
-- with the actor read from the session. It moves nothing the public can see, so
-- it is a new action rather than a state change, and the check on the column is
-- widened to say so.

alter table public.asset_source_conflict
  add column acknowledged_at timestamptz,
  add column acknowledged_by uuid,
  add constraint asset_source_conflict_acknowledged_check
    check ((acknowledged_at is null) = (acknowledged_by is null));

comment on column public.asset_source_conflict.acknowledged_by is
  'The moderator who looked at this disagreement and cleared it from the list. A plain uuid for the reason reported_by is one. Set only by acknowledge_source_conflict.';

alter table public.asset_event drop constraint asset_event_action_check;
alter table public.asset_event add constraint asset_event_action_check
  check (action in (
    'seeded',
    'bypassed',
    'approved',
    'rejected',
    'returned',
    'conflict_acknowledged'
  ));

-- Approved pictures with a disagreement nobody has cleared, oldest first.
--
-- The page size is the caller's. The page passes the moderation queue's own
-- constant, so the database does not hold a second number to keep in step.
-- total is the count before the limit, so the page can say when the list is
-- longer than it shows.
--
-- uploaded_by is who uploaded the bytes the hub holds. reported_by is who
-- reported the other ones. Both are plain uuids and either can be null once an
-- account has closed.
create function public.approved_source_conflicts(p_limit integer)
returns table (
  conflict_id bigint,
  asset_id uuid,
  game text,
  unit_name text,
  map_name text,
  faction_key text,
  variant text,
  source_archive text,
  held_source_hash text,
  reported_source_hash text,
  reported_by uuid,
  uploaded_by uuid,
  at timestamptz,
  total bigint
)
language plpgsql
stable
security definer
set search_path = ''
as $$
begin
  if not public.is_moderator() then
    raise exception 'the list is for moderators' using errcode = '42501';
  end if;

  return query
  select
    c.id,
    c.asset_id,
    a.game,
    a.unit_name,
    a.map_name,
    a.faction_key,
    a.variant,
    c.source_archive,
    c.held_source_hash,
    c.reported_source_hash,
    c.reported_by,
    a.uploaded_by,
    c.at,
    count(*) over ()
  from public.asset_source_conflict as c
  join public.asset as a on a.id = c.asset_id
  where a.moderation = 'approved'
    and c.acknowledged_at is null
  order by c.at, c.id
  limit greatest(coalesce(p_limit, 0), 0);
end;
$$;

-- Clear one entry, and say whether anything was cleared.
--
-- False for an entry that does not exist or was cleared already, so a second
-- moderator with a stale tab does not log a second clearing. Only the
-- disagreement is touched. The picture stays exactly as it was.
create function public.acknowledge_source_conflict(p_conflict_id bigint)
returns boolean
language plpgsql
security definer
set search_path = ''
as $$
declare
  cleared_asset uuid;
  cleared_uploader uuid;
begin
  if not public.is_moderator() then
    raise exception 'clearing a disagreement needs can_moderate'
      using errcode = '42501';
  end if;

  update public.asset_source_conflict as c
  set acknowledged_at = now(), acknowledged_by = (select auth.uid())
  from public.asset as a
  where c.id = p_conflict_id
    and c.acknowledged_at is null
    and a.id = c.asset_id
  returning a.id, a.uploaded_by into cleared_asset, cleared_uploader;

  if cleared_asset is null then
    return false;
  end if;

  insert into public.asset_event (asset_id, action, actor, uploader)
  values (cleared_asset, 'conflict_acknowledged', (select auth.uid()), cleared_uploader);

  return true;
end;
$$;

revoke execute on function public.approved_source_conflicts(integer) from public;
revoke execute on function public.acknowledge_source_conflict(bigint) from public;

-- Through the moderator's own session, so authenticated is the grant and the
-- check in each body is the control. anon gets nothing.
grant execute on function public.approved_source_conflicts(integer) to authenticated;
grant execute on function public.acknowledge_source_conflict(bigint) to authenticated;
