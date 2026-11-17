-- Upload reservations.
--
-- A signed-in Coilbox user asks the hub where to upload an archive. The hub
-- picks a healthy mirror, records the archive as uploading, and later turns it
-- into a listed archive when that mirror reports holding the file. These
-- functions are the only way a row in public.archive is created.
--
-- The hub route validates the shape of every fact before it calls, so these
-- functions trust them. begin_archive_upload takes the moderator flag as a
-- parameter and never checks capabilities itself.

-- ## The existing row behind a duplicate
--
-- Checked in a fixed order against rows that have not failed: the hash, then
-- the lowercased filename, then the internal name with its version. Returns
-- null when nothing clashes.
create function public.archive_duplicate(p_facts jsonb) returns jsonb
language sql
stable
security definer
set search_path = ''
as $$
  select jsonb_build_object(
    'check', found.check_name,
    'existing', jsonb_build_object(
      'id', found.id,
      'filename', found.filename,
      'kind', found.kind,
      'state', found.state,
      'internal_name', found.internal_name,
      'shortname', found.shortname
    )
  )
  from (
    select 1 as ord, 'sha256' as check_name, a.*
    from public.archive as a
    where a.state <> 'failed' and a.sha256 = p_facts->>'sha256'
    union all
    select 2, 'filename', a.*
    from public.archive as a
    where a.state <> 'failed' and a.filename_lower = lower(p_facts->>'filename')
    union all
    select 3, 'name_version', a.*
    from public.archive as a
    where a.state <> 'failed'
      and a.internal_name = p_facts->>'internal_name'
      and a.version = p_facts->>'version'
  ) as found
  order by found.ord
  limit 1;
$$;

revoke execute on function public.archive_duplicate(jsonb) from public, anon, authenticated;

-- ## Starting an upload

create function public.begin_archive_upload(
  p_user uuid,
  p_is_moderator boolean,
  p_facts jsonb,
  p_reservation_seconds integer
) returns jsonb
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_row public.archive;
  v_game public.game;
  v_mirror public.mirror;
  v_dup jsonb;
  v_check text;
  v_constraint text;
  v_reserved_until timestamptz := now() + p_reservation_seconds * interval '1 second';
begin
  -- Lapsed reservations free their hash, filename and name.
  update public.archive
  set state = 'failed'
  where state = 'uploading' and reserved_until < now();

  -- Asking again for a token: the same uploader, the same facts, still reserved.
  select * into v_row
  from public.archive
  where uploader = p_user
    and state = 'uploading'
    and sha256 = p_facts->>'sha256'
    and filename = p_facts->>'filename'
    and kind = p_facts->>'kind'
    and size = (p_facts->>'size')::bigint
  limit 1;

  if found then
    select * into v_mirror
    from public.mirror
    where id = v_row.upload_mirror_id and enabled and healthy;

    if not found then
      select * into v_mirror
      from public.mirror
      where enabled and healthy
      order by random()
      limit 1;
    end if;

    if not found then
      return jsonb_build_object('outcome', 'refused', 'reason', 'no_mirror');
    end if;

    update public.archive
    set reserved_until = v_reserved_until, upload_mirror_id = v_mirror.id
    where id = v_row.id;

    return jsonb_build_object(
      'outcome', 'reissued',
      'id', v_row.id,
      'mirror_id', v_mirror.id,
      'base_url', v_mirror.base_url,
      'reserved_until', extract(epoch from v_reserved_until)::bigint
    );
  end if;

  -- A game archive belongs to the game with exactly this shortname.
  if p_facts->>'kind' = 'game' then
    select * into v_game from public.game where shortname = p_facts->>'shortname' limit 1;

    if not found then
      return jsonb_build_object('outcome', 'refused', 'reason', 'unknown_game');
    end if;

    if v_game.owner_user_id is distinct from p_user and not coalesce(p_is_moderator, false) then
      return jsonb_build_object('outcome', 'refused', 'reason', 'not_owner');
    end if;
  end if;

  v_dup := public.archive_duplicate(p_facts);
  if v_dup is not null then
    return jsonb_build_object('outcome', 'refused', 'reason', 'duplicate') || v_dup;
  end if;

  select * into v_mirror
  from public.mirror
  where enabled and healthy
  order by random()
  limit 1;

  if not found then
    return jsonb_build_object('outcome', 'refused', 'reason', 'no_mirror');
  end if;

  begin
    insert into public.archive (
      sha256, filename, kind, internal_name, version, shortname, author, description,
      size, uploader, game_id, upload_mirror_id, reserved_until
    )
    values (
      p_facts->>'sha256',
      p_facts->>'filename',
      p_facts->>'kind',
      p_facts->>'internal_name',
      p_facts->>'version',
      p_facts->>'shortname',
      p_facts->>'author',
      p_facts->>'description',
      (p_facts->>'size')::bigint,
      p_user,
      v_game.id,
      v_mirror.id,
      v_reserved_until
    )
    returning * into v_row;
  exception when unique_violation then
    -- Another upload took the name between the check and the insert. Answer
    -- as a duplicate. The index that refused names the check, and the other
    -- row is looked up if it is visible.
    get stacked diagnostics v_constraint = constraint_name;
    v_check := case v_constraint
      when 'archive_sha256_idx' then 'sha256'
      when 'archive_filename_idx' then 'filename'
      else 'name_version'
    end;

    v_dup := public.archive_duplicate(p_facts);
    return jsonb_build_object('outcome', 'refused', 'reason', 'duplicate')
      || coalesce(v_dup, jsonb_build_object('check', v_check, 'existing', null));
  end;

  return jsonb_build_object(
    'outcome', 'created',
    'id', v_row.id,
    'mirror_id', v_mirror.id,
    'base_url', v_mirror.base_url,
    'reserved_until', extract(epoch from v_reserved_until)::bigint
  );
end;
$$;

-- ## Checking on an upload

create function public.archive_upload_status(
  p_user uuid,
  p_id uuid,
  p_reservation_seconds integer
) returns jsonb
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_row public.archive;
begin
  update public.archive
  set state = 'failed'
  where id = p_id and state = 'uploading' and reserved_until < now();

  select * into v_row from public.archive where id = p_id and uploader = p_user;

  if not found then
    return null;
  end if;

  if v_row.state = 'uploading' then
    update public.archive
    set reserved_until = now() + p_reservation_seconds * interval '1 second'
    where id = v_row.id
    returning * into v_row;
  end if;

  return jsonb_build_object(
    'id', v_row.id,
    'state', v_row.state,
    'filename', v_row.filename,
    'kind', v_row.kind,
    'reserved_until', extract(epoch from v_row.reserved_until)::bigint
  );
end;
$$;

-- ## Access

revoke execute on function public.begin_archive_upload(uuid, boolean, jsonb, integer) from public, anon, authenticated;
revoke execute on function public.archive_upload_status(uuid, uuid, integer) from public, anon, authenticated;
grant execute on function public.begin_archive_upload(uuid, boolean, jsonb, integer) to service_role;
grant execute on function public.archive_upload_status(uuid, uuid, integer) to service_role;
