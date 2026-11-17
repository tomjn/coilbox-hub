-- Mirror reports.
--
-- A mirror tells the hub which archives it holds. The hub has already checked
-- the signature and the shape of the payload before it calls these functions.
-- A report lists any upload the mirror was issued a token for, records which
-- listed archives the mirror holds, and answers with one archive for the hub
-- to spot check.
--
-- A report's list of unknown files is validated by the hub and then ignored.
-- Nothing about unknown files is stored.

create function public.record_mirror_report(p_mirror text, p_payload jsonb) returns jsonb
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_mirror public.mirror;
  v_sent_at bigint := (p_payload->>'sent_at')::bigint;
  v_held text[];
  v_listed integer;
  v_check jsonb;
begin
  -- The row lock makes two reports from one mirror apply one after the other.
  select * into v_mirror
  from public.mirror
  where id = p_mirror and enabled
  for update;

  if not found then
    return jsonb_build_object('outcome', 'unknown_mirror');
  end if;

  -- An equal value is accepted. An upload report and a sync report can share
  -- a second, and applying a report twice changes nothing.
  if v_sent_at < v_mirror.last_report_sent_at then
    return jsonb_build_object('outcome', 'stale');
  end if;

  select coalesce(array_agg(h), array[]::text[])
  into v_held
  from jsonb_array_elements_text(p_payload->'held') as h;

  update public.mirror
  set last_report_at = now(),
      last_report_sent_at = v_sent_at,
      last_catalogue_seq = (p_payload->>'catalogue_seq')::bigint,
      last_catalogue_generated_at = (p_payload->>'catalogue_generated_at')::bigint
  where id = p_mirror;

  -- The event type does not matter. If the upload report failed and only a
  -- later sync report carries the file, the archive is listed all the same.
  update public.archive
  set state = 'listed', listed_at = now(), reserved_until = null
  where state = 'uploading'
    and upload_mirror_id = p_mirror
    and sha256 = any (v_held);

  get diagnostics v_listed = row_count;

  insert into public.archive_mirror (archive_id, mirror_id)
  select a.id, p_mirror
  from public.archive as a
  where a.state = 'listed' and a.sha256 = any (v_held)
  on conflict (archive_id, mirror_id) do update set last_seen_at = now();

  delete from public.archive_mirror as am
  where am.mirror_id = p_mirror
    and not exists (
      select 1
      from public.archive as a
      where a.id = am.archive_id and a.sha256 = any (v_held)
    );

  select jsonb_build_object('filename', a.filename, 'kind', a.kind, 'size', a.size)
  into v_check
  from public.archive_mirror as am
  join public.archive as a on a.id = am.archive_id
  where am.mirror_id = p_mirror and a.state = 'listed'
  order by random()
  limit 1;

  return jsonb_build_object('outcome', 'recorded', 'listed', v_listed, 'check', v_check);
end;
$$;

create function public.set_mirror_health(p_mirror text, p_healthy boolean) returns void
language sql
security definer
set search_path = ''
as $$
  update public.mirror
  set healthy = p_healthy, last_check_at = now()
  where id = p_mirror;
$$;

-- ## Access

revoke execute on function public.record_mirror_report(text, jsonb) from public, anon, authenticated;
revoke execute on function public.set_mirror_health(text, boolean) from public, anon, authenticated;
grant execute on function public.record_mirror_report(text, jsonb) to service_role;
grant execute on function public.set_mirror_health(text, boolean) to service_role;
