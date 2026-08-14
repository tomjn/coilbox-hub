-- Who put a picture in front of the public, who took it away again, and when
-- (issue #115).
--
-- The asset row holds the current state and only the current state. Approving
-- something overwrites the fact that it was pending, rejecting it overwrites
-- the fact that it was approved, and a replacement overwrites both. That is
-- fine for serving the gallery and useless for the question this issue is
-- about, which is what happened and who did it.
--
-- The case the issue puts is a trusted account gone bad, either because
-- somebody took it or because it was never acting in good faith. Unwinding
-- that means listing everything the account put live and everything it turned
-- away, and the current state of the table cannot answer either. So every
-- transition that changes what the public can see is appended here, and
-- nothing ever edits or removes a row.
--
-- ## Append only, by construction rather than by convention
--
-- No role holds insert, update or delete on this table, service_role included.
-- The only writer is the trigger at the foot of this file, which is security
-- definer and therefore writes as the table owner. So a compromised secret key
-- can approve and reject things, and every one of those decisions lands here,
-- and it cannot go back and tidy up after itself.
--
-- The foreign key onto public.asset has no cascade for the same reason.
-- Nothing can delete an asset today, and if something ever could, this table
-- would refuse until somebody deleted the audit trail first, deliberately, as
-- the table owner.
create table public.asset_event (
  id bigint generated always as identity primary key,

  asset_id uuid not null references public.asset (id),

  -- What the transition was, in terms of what the public can see.
  --
  --   seeded    inserted already approved, by an account seeding the corpus
  --   bypassed  inserted already approved, because the uploader holds
  --             can_publish_unreviewed and the queue was waived for them
  --   approved  a moderator approved it out of the queue
  --   rejected  refused, with the kind below saying which sort of refusal
  --   returned  put back to pending, either by a moderator undoing an
  --             editorial rejection or by a newer archive replacing the bytes
  --
  -- `seeded` and `bypassed` are the trusted paths the issue asks to have
  -- logged, and they are two values rather than one for the reason #101 splits
  -- the two capabilities: seeding content and waiving a safety control are
  -- different decisions and a log that cannot tell them apart cannot answer
  -- which one was abused.
  --
  -- An ordinary upload landing as pending is not here. It is already recorded
  -- on the asset row itself, by uploaded_by and created_at, and it has put
  -- nothing in front of anybody.
  action text not null check (action in (
    'seeded',
    'bypassed',
    'approved',
    'rejected',
    'returned'
  )),

  -- The kind, copied off the asset at the moment of the decision, and the
  -- reason this table exists at all rather than a timestamp column on the row.
  -- The same list as asset_rejection_kind_check, including `unrecorded`, which
  -- nothing writes and which is here so the two lists stay one vocabulary.
  rejection_kind text check (rejection_kind in ('safety', 'editorial', 'unrecorded')),

  -- Who did it, from the session that asked, and never from anything a caller
  -- supplied. The moderation functions in 20260814220200 are security definer
  -- and read auth.uid() through this trigger, so an actor cannot be typed into
  -- a form.
  --
  -- Null when nobody was signed in, which today means a write made with the
  -- secret key outside a session: the upload route replacing bytes, and
  -- whatever the seed (#110) turns out to be. The route's own writes carry the
  -- uploader below instead, and the seed will have to name its actor when it
  -- lands.
  actor uuid,

  -- Who uploaded the picture, as the asset row said at the time.
  --
  -- No foreign key, on purpose, and it is the only column here that needed a
  -- decision. asset.uploaded_by is a foreign key with `on delete set null`, so
  -- an uploader closing their account empties it, and the account most likely
  -- to be closed in a hurry is the one that uploaded something that got
  -- rejected on safety grounds. A plain uuid survives that.
  --
  -- What it does not survive is auth.users losing the row, which takes the
  -- Discord identity behind the uuid with it. Keeping that identity through an
  -- account deletion is a decision with a good deal more weight than a column
  -- type, and it is the maintainer's to make rather than this migration's.
  uploader uuid,

  at timestamptz not null default now(),

  -- A rejection says which kind, and nothing else may.
  constraint asset_event_kind_check
    check ((rejection_kind is not null) = (action = 'rejected'))
);

-- The two questions this table gets asked. What has happened lately, for the
-- trail page, and everything one account is behind, for unwinding it. An
-- account is behind an event either as the actor or as the uploader, so both
-- are indexed and neither query has to read the table.
create index asset_event_at_idx on public.asset_event (at desc);
create index asset_event_actor_idx on public.asset_event (actor);
create index asset_event_uploader_idx on public.asset_event (uploader);

-- Authoritative rather than additive, in the style of 20260814180000, so a
-- privilege some hosted default handed out is gone rather than sitting
-- underneath the one grant below.
revoke all on public.asset_event from anon, authenticated, service_role;

-- Read only, and only server side. The trail page reads it with the secret key
-- the same way the contact sheet reads the queue, so nothing about who
-- moderated what reaches a browser that has not been checked first.
grant select on public.asset_event to service_role;

-- On with no policy, which refuses everyone holding the publishable key. The
-- table's safety must not rest on the absence of a grant, which is the drift
-- #59 found in production.
alter table public.asset_event enable row level security;

-- The only writer.
--
-- A trigger rather than a line in each of the places that moderate, because
-- the places that moderate are not all written yet. #110 seeds the corpus and
-- #111 promotes rows, and a log that depends on each of them remembering is a
-- log with a hole in it exactly where somebody bypassed the queue. Here there
-- is nothing to remember.
--
-- Security definer so it can write a table nobody holds insert on. It reads
-- auth.uid() rather than a parameter, and auth.uid() reads the request's own
-- JWT claim, which is unaffected by the definer switch, so the actor is
-- whoever the session says they are.
create function public.record_asset_event() returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  recorded text;
begin
  if tg_op = 'UPDATE'
    and new.moderation is not distinct from old.moderation
    and new.rejection_kind is not distinct from old.rejection_kind
  then
    return null;
  end if;

  recorded := case new.moderation
    when 'approved' then case new.approval_source
      when 'seed' then 'seeded'
      when 'bypass' then 'bypassed'
      else 'approved'
    end
    when 'rejected' then 'rejected'
    -- Pending, which is only worth a row when it is a state something came
    -- back from. A first upload arriving pending is on the asset row already.
    else case when tg_op = 'UPDATE' then 'returned' else null end
  end;

  if recorded is null then
    return null;
  end if;

  insert into public.asset_event (asset_id, action, rejection_kind, actor, uploader)
  values (
    new.id,
    recorded,
    new.rejection_kind,
    coalesce((select auth.uid()), new.uploaded_by),
    new.uploaded_by
  );

  return null;
end;
$$;

create trigger asset_record_event
  after insert or update on public.asset
  for each row execute function public.record_asset_event();
