-- A rejection is a state with a reason, and one of the two reasons is final
-- (issue #115).
--
-- Nothing here deletes anything, and that is the first half of the issue.
-- Nobody holds delete on public.asset, service_role included
-- (20260814180000), so a rejection can only ever be an update. If something
-- uploaded here turns out to be illegal, the bytes and the row are still
-- there to be handed to whoever asks for them, and having destroyed them
-- first is the wrong position to be in. The routes the issue names for that
-- are the IWF and NCA-CEOP in the UK, and NCMEC given US based
-- infrastructure. Which of them applies to what is not something this
-- migration decides.
--
-- The second half is that "rejected" on its own is not enough. A moderator
-- turning away a screenshot of somebody's desktop and a moderator turning
-- away child sexual abuse material both wrote the same three columns before
-- this migration, and afterwards nothing in the database could tell the two
-- apart. Those are very different things to be able to demonstrate, so the
-- kind is recorded at the moment the decision is made rather than
-- reconstructed later from memory.
--
--   safety     the picture is the problem. Illegal or seriously harmful
--              content, which is not a judgement call and is never overturned
--   editorial  the upload is the problem. Out of scope, the wrong game, a
--              duplicate, junk. Somebody's call, and reversible
--
-- Novelty and troll maps are content the hub allows, so they are neither of
-- these. Filtering them is a tag on the asset and a question for whoever
-- writes it, not a reason to refuse one.
--
-- `unrecorded` is the third value and nothing may ever write it again. It is
-- what the rows rejected before this migration get, because the grid recorded
-- no reason at all until now and inventing one either accuses a moderator of
-- finding illegal content they did not find, or waves away a rejection that
-- might have been the other kind. The honest value is the one that says the
-- reason was not written down.
alter table public.asset add column rejection_kind text;

-- Whatever was rejected under the old grid, in the state the old grid left it.
-- Runs before the constraint below, so the constraint applies to a table that
-- already satisfies it, and before 20260814220100 adds the audit trigger, so
-- this backfill does not manufacture audit events for decisions it knows
-- nothing about.
update public.asset
set rejection_kind = 'unrecorded'
where moderation = 'rejected';

alter table public.asset add constraint asset_rejection_kind_check
  check (rejection_kind in ('safety', 'editorial', 'unrecorded'));

-- Tied to the state in both directions, unlike approval_source, which is
-- deliberately loose on a rejected row so it can go on saying how the picture
-- was approved before somebody rejected it.
--
-- A rejected row without a kind is the hole this migration exists to close. A
-- pending or approved row with one is a row whose state and whose reason
-- disagree, and the reason is the more alarming of the two to find in a
-- database.
alter table public.asset add constraint asset_rejection_state_check
  check ((rejection_kind is not null) = (moderation = 'rejected'));

-- What makes a safety rejection final, rather than merely intended to be.
--
-- The alternative is a rule in the application, and the application is not
-- where this belongs. The moderation grid, the upload route and the seed all
-- write this table as service_role, and each one of them would have to
-- remember. Here there is one rule, it applies to every writer including the
-- secret key, and the only way past it is to be the table owner and disable
-- the trigger, which is a deliberate act against the production database
-- rather than a request.
--
-- Frozen: the decision itself, and everything that says which bytes it was
-- about. So the row cannot be approved, cannot be re-labelled as editorial,
-- and cannot be quietly re-pointed at a different object. lib/assets/upload.ts
-- already refuses to replace any rejected row, and this is the layer under it
-- that does not depend on a route remembering.
--
-- Not frozen: uploaded_by. It is a foreign key onto auth.users with
-- `on delete set null`, so freezing it would turn "delete my account" into an
-- unexplained failure for exactly the accounts this cares about. What keeps
-- the identity is public.asset_event in 20260814220100, which records the
-- uploader as a plain uuid with no foreign key, so closing an account does not
-- reach into it. Whether that is enough of a record is the maintainer's call
-- and #115 does not settle it.
create function public.refuse_safety_rejection_override() returns trigger
language plpgsql
set search_path = ''
as $$
begin
  if old.moderation = 'rejected' and old.rejection_kind = 'safety' and (
    new.moderation is distinct from old.moderation
    or new.rejection_kind is distinct from old.rejection_kind
    or new.path is distinct from old.path
    or new.tier is distinct from old.tier
    or new.hash is distinct from old.hash
    or new.source_hash is distinct from old.source_hash
  ) then
    raise exception 'asset % was rejected on safety grounds, which is final', old.id
      using errcode = 'check_violation';
  end if;

  return new;
end;
$$;

-- Fires before asset_touch_updated_at, which is alphabetical order and is what
-- Postgres uses, so a refused update does not get as far as touching the row.
create trigger asset_safety_rejection_is_final
  before update on public.asset
  for each row execute function public.refuse_safety_rejection_override();
