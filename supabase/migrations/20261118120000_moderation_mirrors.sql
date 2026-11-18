-- The moderation mirrors page: list the hub's mirrors with their health, add
-- one, remove one, and switch one back on. Each function answers nothing to
-- anybody but a moderator. The catalogue triggers on public.mirror already
-- mark the catalogue stale, so the next catalogue request signs a new one.

create function public.moderation_mirrors() returns jsonb
language plpgsql
stable
security definer
set search_path = ''
as $$
begin
  if auth.uid() is null or not public.is_moderator() then
    return null;
  end if;

  return jsonb_build_object(
    'seq', (select cs.seq from public.catalogue_state as cs),
    'mirrors', coalesce((
      select jsonb_agg(jsonb_build_object(
        'id', m.id,
        'name', m.name,
        'base_url', m.base_url,
        'public_key', m.public_key,
        'enabled', m.enabled,
        'healthy', m.healthy,
        'last_report_at', m.last_report_at,
        'last_check_at', m.last_check_at,
        'last_catalogue_seq', m.last_catalogue_seq,
        'created_at', m.created_at,
        'copies', (select count(*) from public.archive_mirror as am where am.mirror_id = m.id),
        'uploads', (select count(*) from public.archive as a where a.upload_mirror_id = m.id)
      ) order by m.created_at, m.id)
      from public.mirror as m
    ), '[]'::jsonb)
  );
end;
$$;

-- Answers 'added' or why not. The checks repeat the table's own, so a
-- moderator gets a reason instead of a constraint error.
create function public.moderation_add_mirror(p_id text, p_name text, p_base_url text, p_public_key text) returns text
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_id text := btrim(coalesce(p_id, ''));
  v_name text := btrim(coalesce(p_name, ''));
  v_url text := btrim(coalesce(p_base_url, ''));
  v_key text := btrim(coalesce(p_public_key, ''));
begin
  if auth.uid() is null or not public.is_moderator() then
    return 'not_allowed';
  end if;
  if v_id !~ '^[a-z0-9][a-z0-9-]{0,63}$' then
    return 'bad_id';
  end if;
  if char_length(v_name) not between 1 and 128 then
    return 'bad_name';
  end if;
  if v_url !~ '^https?://[^/?#\s]+(/[^?#\s]*)?$' or v_url ~ '/$' then
    return 'bad_url';
  end if;
  if v_key !~ '^[A-Za-z0-9_-]{43}$' then
    return 'bad_key';
  end if;
  if exists (select 1 from public.mirror where id = v_id) then
    return 'id_taken';
  end if;

  insert into public.mirror (id, name, base_url, public_key)
  values (v_id, v_name, v_url, v_key);

  return 'added';
end;
$$;

-- Deletes the mirror and the record of what it holds. A mirror that uploads
-- went through stays as a row, switched off, because each of those archives
-- names it. Answers 'removed', 'switched_off', 'not_found' or 'not_allowed'.
create function public.moderation_remove_mirror(p_id text) returns text
language plpgsql
security definer
set search_path = ''
as $$
begin
  if auth.uid() is null or not public.is_moderator() then
    return 'not_allowed';
  end if;

  perform 1 from public.mirror where id = p_id for update;
  if not found then
    return 'not_found';
  end if;

  if exists (select 1 from public.archive where upload_mirror_id = p_id) then
    update public.mirror set enabled = false where id = p_id;
    return 'switched_off';
  end if;

  delete from public.mirror where id = p_id;
  return 'removed';
end;
$$;

-- Switches a mirror back on. It stays unhealthy until its next report passes
-- the hub's check. Answers 'switched_on', 'not_found' or 'not_allowed'.
create function public.moderation_enable_mirror(p_id text) returns text
language plpgsql
security definer
set search_path = ''
as $$
begin
  if auth.uid() is null or not public.is_moderator() then
    return 'not_allowed';
  end if;

  update public.mirror set enabled = true, healthy = false where id = p_id;
  if not found then
    return 'not_found';
  end if;

  return 'switched_on';
end;
$$;

-- ## Access

revoke execute on function public.moderation_mirrors() from public, anon;
revoke execute on function public.moderation_add_mirror(text, text, text, text) from public, anon;
revoke execute on function public.moderation_remove_mirror(text) from public, anon;
revoke execute on function public.moderation_enable_mirror(text) from public, anon;
grant execute on function public.moderation_mirrors() to authenticated;
grant execute on function public.moderation_add_mirror(text, text, text, text) to authenticated;
grant execute on function public.moderation_remove_mirror(text) to authenticated;
grant execute on function public.moderation_enable_mirror(text) to authenticated;
