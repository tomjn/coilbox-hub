-- Let a trusted account replace a picture it did not upload (issue #138).
--
-- Only the account that uploaded a picture may replace it (#106), because a
-- replacement puts the row back to pending and an open replace would let any
-- signed in account take approved pictures off the site one request at a time.
-- The cost is a corpus that goes stale: the first uploader may never run the
-- newer archive, and uploaded_by goes null when an account closes, so nobody
-- can refresh those pictures at all.
--
-- can_replace_any_asset is a grant of its own, like the three #101 split. An
-- account holding it may replace a unit picture, a map picture or a faction
-- logo that another account uploaded, or one whose uploader has closed their
-- account. The replacement still goes to the queue unless the account also
-- skips it, and it still faces every other refusal the upload route has:
-- size, type, the render ceiling, the storage quota, the hourly limit, a
-- rejected row and the same source bytes. The route asks has_capability()
-- before it lets the replacement through.
--
-- ## The replacing account takes the row over
--
-- The route writes uploaded_by as the replacing account. That is what makes
-- the storage quota and the hourly limit count the replacement against the
-- account that sent the bytes, since both are counted on uploaded_by. Left
-- with the previous uploader, a holder's replacements would count against
-- nobody's hourly limit and fill somebody else's quota.
--
-- It also means the previous uploader can no longer replace that picture
-- themselves. They are a stranger to it now, the same as any account that
-- did not send the bytes the hub holds.
--
-- ## What the table enforces
--
-- A row only changes hands to an account holding the capability. The route
-- checks has_capability() through the holder's own session, which also
-- applies the second factor rule. This trigger is the floor under it, for a
-- write made with the secret key that skipped the route. It reads the grant
-- directly because a secret key write has no session for has_capability() to
-- answer for.
--
-- uploaded_by going null is not a takeover. That is an account closing, through
-- the foreign key's `on delete set null`, and it must not be refused.

alter table public.user_capability drop constraint user_capability_capability_check;
alter table public.user_capability add constraint user_capability_capability_check
  check (capability in (
    'can_seed_unit_assets',
    'can_publish_unreviewed',
    'can_moderate',
    'can_replace_any_asset'
  ));

create function public.asset_refuse_unauthorised_takeover() returns trigger
language plpgsql
security definer
set search_path = ''
as $$
begin
  if new.uploaded_by is not null
    and new.uploaded_by is distinct from old.uploaded_by
    and not exists (
      select 1 from public.user_capability uc
      where uc.user_id = new.uploaded_by
        and uc.capability = 'can_replace_any_asset'
    )
  then
    raise exception 'replacing another account''s picture needs can_replace_any_asset'
      using errcode = '42501';
  end if;

  return new;
end;
$$;

revoke execute on function public.asset_refuse_unauthorised_takeover() from public;

create trigger asset_refuse_unauthorised_takeover
  before update of uploaded_by on public.asset
  for each row execute function public.asset_refuse_unauthorised_takeover();

-- ## The trail
--
-- A takeover is a new action, `replaced`, with the replacing account as the
-- actor and the previous uploader as the uploader, null when that account has
-- closed. It is recorded whatever the moderation state does, because the
-- question #115 asks of a trusted account gone bad is which pictures it took
-- from other people, and a takeover of a pending picture is one of those.
--
-- The return to the queue that usually follows is recorded as before. Its
-- actor falls back to uploaded_by, which is now the replacing account, so it
-- is put down to them and not to the previous uploader.
alter table public.asset_event drop constraint asset_event_action_check;
alter table public.asset_event add constraint asset_event_action_check
  check (action in (
    'seeded',
    'bypassed',
    'approved',
    'rejected',
    'returned',
    'conflict_acknowledged',
    'replaced'
  ));

-- The whole function, per the house rule. The takeover row is the only
-- change.
create or replace function public.record_asset_event() returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  recorded text;
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
