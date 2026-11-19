-- record_download was granted to anon, so anyone holding the public key could
-- send a fresh seen key on every call and add unlimited counts. The hub's PHP
-- now calls it with the service role, which is the only role left with execute.
--
-- The game lookup also requires a visible game. The function runs as its owner
-- and skips row level security, so without this a hidden game counted and the
-- answer told a caller its shortname exists.

revoke execute on function public.record_download(text, text, text) from public, anon, authenticated;
grant execute on function public.record_download(text, text, text) to service_role;

create or replace function public.record_download(p_kind text, p_target text, p_seen_key text)
returns boolean
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_target uuid;
  v_inserted integer;
begin
  if p_kind = 'map' then
    select id into v_target from public.map where map_name = p_target;
  elsif p_kind = 'game' then
    select id into v_target from public.game
    where shortname = p_target and hidden_at is null;
  elsif p_kind = 'item' then
    select id into v_target from public.item
    where id::text = p_target and deleted_at is null;
  end if;

  if v_target is null then
    return false;
  end if;

  delete from public.download_seen where day < current_date - 1;

  insert into public.download_seen (day, seen_key)
  values (current_date, p_seen_key)
  on conflict do nothing;
  get diagnostics v_inserted = row_count;

  if v_inserted = 1 then
    insert into public.download_day (kind, target_id, day, count)
    values (p_kind, v_target, current_date, 1)
    on conflict (kind, target_id, day)
    do update set count = public.download_day.count + 1;

    if p_kind = 'item' then
      update public.item set import_count = import_count + 1 where id = v_target;
    end if;
  end if;

  return true;
end;
$$;

-- create or replace keeps the grants, repeated so the file reads on its own.
revoke execute on function public.record_download(text, text, text) from public, anon, authenticated;
grant execute on function public.record_download(text, text, text) to service_role;

-- Nothing calls record_item_imported since the hub moved to record_download.
-- It has the same hole, so close it. The function stays for old callers to
-- fail on rather than 404.
revoke execute on function public.record_item_imported(uuid) from public, anon, authenticated;
