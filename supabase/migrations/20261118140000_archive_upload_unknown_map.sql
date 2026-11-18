-- A map archive is taken only for a map the hub already has facts for.
--
-- The hub lists a map only when it has a row in public.map, and links a
-- mirror's copy to that row by name. A file uploaded for a map with no row was
-- stored on a mirror and shown nowhere. Refusing it with unknown_map, as
-- unknown_game already does for a game, tells the client to send the map's
-- facts first. create or replace keeps the grants the first migration set.

create or replace function public.begin_archive_upload(
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

  -- A map archive needs the map's facts first, because a map is listed only
  -- when it has a row here.
  if p_facts->>'kind' = 'map'
    and not exists (select 1 from public.map where map_name = p_facts->>'internal_name') then
    return jsonb_build_object('outcome', 'refused', 'reason', 'unknown_map');
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
