-- Keep the uploader's name with a safety rejection, through an account
-- deletion (issue #146).
--
-- This settles the question 20260814220000_asset_rejection_kind.sql and
-- 20260814220100_asset_event.sql left open. Closing an account deletes the
-- auth.users row, asset.uploaded_by goes null through `on delete set null`, and
-- asset_event.uploader was left as a uuid with nothing behind it. The account
-- most likely to be closed in a hurry is the one that uploaded something
-- illegal, and a bare uuid is not much to put in a report.
--
-- The maintainer's decision: keep enough to say who it was and nothing else.
-- So a safety rejection now carries two names on its asset_event row.
--
--   uploader_display_name   public.profile.display_name, the name the hub
--                           showed for the account
--   uploader_provider_name  the sign in username out of
--                           auth.users.raw_user_meta_data, the first of
--                           preferred_username, user_name, full_name and name
--                           that holds anything. Discord, the only provider the
--                           hub signs in with, puts the username in full_name
--
-- Not kept: the email address, the provider id, the avatar, any other
-- metadata key, the session or anything else on auth.users. The account
-- deletion goes ahead exactly as before and removes all of that, the profile
-- row included. asset_safety_rejection_uploader_name.test.sql proves both.
--
-- An editorial rejection keeps nothing. It is a call about the upload, not
-- about the person, and is no reason to hold anybody's name past their
-- deletion. A check constraint holds that, so not even the table owner can put
-- a name on any other row by hand.
--
-- ## Why at the rejection and not at the deletion
--
-- The names are copied by record_asset_event the moment the safety rejection
-- is written, rather than by a trigger on auth.users when the account is
-- deleted.
--
-- 1. It survives a deletion with nothing else to go right. A delete trigger
--    runs inside the auth server's own transaction, so any fault in it turns
--    "delete my account" into an unexplained failure, which is the outcome
--    20260814220000 already chose uploaded_by's freedom to avoid.
-- 2. The names are the ones the account had when the decision was made. A
--    report wants the name the moderator saw, not whatever the account was
--    renamed to while it was being closed.
-- 3. It is one write in the one place that already records the decision,
--    rather than a second trigger that has to find every safety rejection an
--    account ever had.
--
-- The cost is that the names sit on the row while the account is still open,
-- where a delete trigger would only write them at the end. While the account
-- is open the hub holds both names anyway, the display name on every item the
-- account publishes and the username on its auth.users row, and only a
-- moderator can read the copy here.
--
-- ## Who can read them
--
-- Nothing changes. asset_event grants select to service_role only, row level
-- security is on with no policy, and the one reader is /moderation/trail,
-- which asks is_moderator() first.
--
-- ## Existing safety rejections
--
-- Backfilled below where the account still exists, with the names it has
-- today, since the names it had at the time were never written down. A safety
-- rejection whose uploader has already closed their account has nothing left
-- to recover and stays without names.

alter table public.asset_event
  add column uploader_display_name text,
  add column uploader_provider_name text;

alter table public.asset_event add constraint asset_event_uploader_name_check
  check (
    (uploader_display_name is null and uploader_provider_name is null)
    or (action = 'rejected' and rejection_kind = 'safety')
  );

-- The whole function, per the house rule. The names on a safety rejection are
-- the only change from 20261019120000.
create or replace function public.record_asset_event() returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  recorded text;
  v_display_name text;
  v_provider_name text;
begin
  if tg_op = 'UPDATE'
    and new.uploaded_by is not null
    and new.uploaded_by is distinct from old.uploaded_by
  then
    insert into public.asset_event (asset_id, action, actor, uploader)
    values (new.id, 'replaced', new.uploaded_by, old.uploaded_by);
  end if;

  if tg_op = 'UPDATE'
    and new.moderation is not distinct from old.moderation
    and new.rejection_kind is not distinct from old.rejection_kind
    and not (new.moderation = 'approved' and new.hash is distinct from old.hash)
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

  -- Who uploaded it, by name, on a safety rejection only (#146).
  if recorded = 'rejected' and new.rejection_kind = 'safety' and new.uploaded_by is not null then
    select p.display_name into v_display_name
      from public.profile p
      where p.user_id = new.uploaded_by;

    select coalesce(
        nullif(btrim(u.raw_user_meta_data ->> 'preferred_username'), ''),
        nullif(btrim(u.raw_user_meta_data ->> 'user_name'), ''),
        nullif(btrim(u.raw_user_meta_data ->> 'full_name'), ''),
        nullif(btrim(u.raw_user_meta_data ->> 'name'), '')
      ) into v_provider_name
      from auth.users u
      where u.id = new.uploaded_by;
  end if;

  insert into public.asset_event (
    asset_id, action, rejection_kind, actor, uploader,
    uploader_display_name, uploader_provider_name
  )
  values (
    new.id,
    recorded,
    new.rejection_kind,
    coalesce((select auth.uid()), new.uploaded_by),
    new.uploaded_by,
    v_display_name,
    v_provider_name
  );

  return null;
end;
$$;

-- Existing safety rejections whose uploader still has an account. Run as the
-- table owner, which is the only role that can update this table.
update public.asset_event e
set
  uploader_display_name = (
    select p.display_name from public.profile p where p.user_id = e.uploader
  ),
  uploader_provider_name = (
    select coalesce(
      nullif(btrim(u.raw_user_meta_data ->> 'preferred_username'), ''),
      nullif(btrim(u.raw_user_meta_data ->> 'user_name'), ''),
      nullif(btrim(u.raw_user_meta_data ->> 'full_name'), ''),
      nullif(btrim(u.raw_user_meta_data ->> 'name'), '')
    )
    from auth.users u where u.id = e.uploader
  )
where e.action = 'rejected'
  and e.rejection_kind = 'safety'
  and exists (select 1 from auth.users u where u.id = e.uploader);
