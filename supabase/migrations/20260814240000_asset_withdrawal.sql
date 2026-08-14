-- What has to come out of the durable tier, when a safety rejection arrives
-- too late to stop it going in (issue #153).
--
-- Promotion (#111) writes approved pictures into tomjn/coilbox-assets, whose
-- history is permanent and public, seven days after they are approved. A
-- safety problem is exactly the kind that gets reported long after somebody
-- approved the picture, so rejecting the row afterwards changes the row and
-- nothing else: the file is still in the repository, still in every clone of
-- it, and still being served.
--
-- The row cannot say so either, and that is the part that needed a migration.
-- asset_safety_rejection_is_final (20260814220000) freezes moderation,
-- rejection_kind, path, tier, hash and source_hash on a safety rejected row,
-- for every writer including service_role and the table owner. That freeze is
-- the whole demonstration that a safety decision cannot be destroyed or
-- overruled, so it is not being widened here and #115 is explicit that it must
-- not be. What it also means is that there is no column left to write "and the
-- file still needs taking down" into.
--
-- ## Why a table of its own
--
-- The same answer public.asset_source_conflict (20260814220400) gives, for the
-- same reason: a side table never touches the frozen row, so this needs no
-- exception to the rule and no argument about whether one is safe.
--
-- Not public.asset_event. That table is append only and records decisions, and
-- this is not a decision. It is a piece of work that is either outstanding or
-- done, so it has to be editable, and a log with an editable row in it stops
-- being a log.
--
-- The two are meant to be read together. asset_event holds the fact that a
-- safety rejection happened and who made it, permanently and unalterably. This
-- table holds the consequence, as a queue, and is deliberately mutable because
-- work gets done. Losing this table loses the queue and not the evidence.
--
-- ## What this records and what it does not do
--
-- It records. Nothing here removes a file, and the promotion job does not
-- either.
--
-- Taking the file off the published site is a commit against another
-- repository. Taking it out of the history is `git filter-repo` and a force
-- push that breaks every clone. Neither belongs in a job that runs daily on a
-- stored credential, and doing only the first would be worse than doing
-- neither: the site would stop serving it while the blob stayed fetchable at
-- its old commit, which reads as finished and is not. So both are done by hand,
-- together, by whoever holds the assets repository, and this table is what says
-- they are owed and lets them say when they are not.
--
-- NOTICE.md in the assets repo already tells people how to ask for a takedown.
-- This is the half of that promise the database can keep.

create table public.asset_withdrawal (
  id bigint generated always as identity primary key,

  asset_id uuid not null references public.asset (id),

  -- The durable path the file is at, copied rather than joined.
  --
  -- The freeze does hold `path` still on a safety rejected row, so a join would
  -- work today. Copying does not depend on that: this record has to go on
  -- naming the file after any future change to which columns are frozen, and a
  -- takedown record that has to be correlated with a live row to mean anything
  -- is a takedown record that can stop meaning anything.
  path text not null check (length(btrim(path)) between 1 and 512),

  at timestamptz not null default now(),

  -- When somebody confirmed the file is gone from the published site and from
  -- the history. Null is the whole of the queue: null means outstanding.
  --
  -- Written by public.record_asset_withdrawn and by nothing else, so the fact
  -- that a person did the work is recorded by the person, rather than inferred
  -- by a job from a 404.
  withdrawn_at timestamptz
);

-- One row per asset. A safety rejection is final, so an asset can be in here
-- once, and a second attempt is the trigger firing on an update that changed
-- nothing worth recording.
create unique index asset_withdrawal_asset_idx on public.asset_withdrawal (asset_id);

-- The queue, which is the only query this table gets.
create index asset_withdrawal_outstanding_idx on public.asset_withdrawal (at)
  where withdrawn_at is null;

revoke all on public.asset_withdrawal from anon, authenticated, service_role;

-- Read only for the secret key. The promotion job reads the queue and says it
-- out loud on every run, and the writing is done by the two functions below,
-- which are security definer for the reason the audit trigger is: what may go
-- in and what may change about it should not depend on a caller being careful.
--
-- Nothing holding the publishable key reads this at all. It names a file the
-- hub is trying to take down, which is not a list to publish.
grant select on public.asset_withdrawal to service_role;

alter table public.asset_withdrawal enable row level security;

-- The only thing that puts a row in here.
--
-- A trigger rather than a line in public.reject_asset, for the reason
-- public.record_asset_event gives: reject_asset is not the only writer. The
-- secret key holds update on public.asset, so a rejection written straight
-- through PostgREST would bypass a rule that lived in the function, and the
-- moderation grid is not the last thing that will ever reject a picture.
-- Security definer so it can write a table nobody holds insert on, which means
-- a compromised secret key can safety reject a promoted picture and cannot stop
-- the takedown being recorded.
--
-- Only on update, and that is not a gap. tier only becomes 'static' through
-- public.promote_assets, which takes approved rows, so the sole route from
-- "these bytes are in a public git history" to "these bytes were rejected on
-- safety grounds" is an update to a row that is already there. A row inserted
-- already static and already safety rejected is somebody recording evidence
-- about bytes the hub never promoted, and there is nothing for this to ask for.
--
-- Safety only, and that is a decision rather than an oversight. An editorial
-- rejection is somebody's call and public.return_asset exists to undo it, so
-- recording a withdrawal for one would mean retracting it again the moment the
-- picture came back, and a takedown queue that things leave by being reconsidered
-- is not one anybody can trust. A safety rejection cannot be undone, so a row
-- here is owed from the moment it is written. An editorially rejected picture
-- also stops being served by the resolver, which is what an editorial call is
-- asking for.
create function public.record_asset_withdrawal() returns trigger
language plpgsql
security definer
set search_path = ''
as $$
begin
  if new.moderation <> 'rejected' or new.rejection_kind <> 'safety' then
    return null;
  end if;

  -- Already recorded on the update that made it a safety rejection. Nothing can
  -- reach this twice today, because the freeze refuses any later change to
  -- moderation or rejection_kind, and asking is cheaper than depending on it.
  if old.moderation = 'rejected' and old.rejection_kind = 'safety' then
    return null;
  end if;

  -- Still in the staging tier, where a rejection already reaches the object:
  -- Blob is deletable, no history holds a copy, and what nothing points at is
  -- #113's to clear. This table is only about the tier that cannot forget.
  --
  -- A row that was promoted and has since been replaced by a newer archive is
  -- back on the staging tier and is not recorded either. The bytes this
  -- rejection is about are the replacement's, in Blob. The superseded object
  -- left behind in the durable tier was approved and has been judged by nobody,
  -- so it is an orphan for #113 rather than a takedown.
  if new.tier <> 'static' then
    return null;
  end if;

  insert into public.asset_withdrawal (asset_id, path)
  values (new.id, new.path)
  on conflict (asset_id) do nothing;

  return null;
end;
$$;

create trigger asset_record_withdrawal
  after update on public.asset
  for each row execute function public.record_asset_withdrawal();

-- Say that the file is gone, once somebody has actually removed it.
--
-- Answers with how many rows it settled, so a caller naming an asset that was
-- never in the queue, or was settled last week, hears zero rather than nothing.
--
-- One direction only. There is no way to put a settled row back to outstanding,
-- because "we took it down and then we did not" is not a state a takedown queue
-- has: a file that came back is a new incident and wants a new record and a new
-- explanation. Recording it too early is the mistake this cannot undo, which is
-- why it is a deliberate call by the person who did the work rather than a job
-- inferring it from the published site answering 404.
--
-- Security definer, because service_role holds select on the table and nothing
-- holds update, and giving it update would let anything with the secret key
-- empty the queue in either direction.
create function public.record_asset_withdrawn(ids uuid[]) returns integer
language plpgsql
security definer
set search_path = ''
as $$
declare
  settled integer;
begin
  with done as (
    update public.asset_withdrawal
    set withdrawn_at = now()
    where asset_id = any(record_asset_withdrawn.ids) and withdrawn_at is null
    returning id
  )
  select count(*)::integer into settled from done;

  return settled;
end;
$$;

-- Execute is granted to PUBLIC on every new function, so these revokes are the
-- access control and not a tidy-up.
revoke execute on function public.record_asset_withdrawal() from public;
revoke execute on function public.record_asset_withdrawn(uuid[]) from public;

-- Not `authenticated`. Confirming a takedown is not a moderation decision made
-- in a browser: it is somebody reporting what they did to a git repository they
-- hold, so it goes through the same server side credential the promotion job
-- runs on rather than through a screen.
grant execute on function public.record_asset_withdrawn(uuid[]) to service_role;
