-- Record a picture's bytes changing while it stays approved (issue #115).
--
-- Until now nothing could do that. A replacement always put the row back to
-- pending, which the trigger records as `returned`, and the approval that
-- followed was recorded again. Uploads from a moderator or from an account
-- holding can_publish_unreviewed now skip the queue, so their replacement goes
-- from approved to approved. The trigger in 20260814220100 skips any update
-- whose moderation state does not change, and would have put new bytes in
-- front of the public with nothing in the trail.
--
-- So an update that changes `hash` on a row that is approved afterwards counts
-- as a transition, and is recorded under the approval source like any other
-- approval. A pending row whose bytes change stays unrecorded, as before, since
-- nobody can see it.
--
-- The whole function, per the house rule, and otherwise unchanged.
create or replace function public.record_asset_event() returns trigger
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
