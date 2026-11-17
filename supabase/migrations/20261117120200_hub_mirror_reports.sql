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

-- ## Catalogue

-- Takes the next sequence number and reads the lists under one row lock. The
-- update comes first and holds the lock until the caller's transaction ends.
-- Each read after it sees every change committed before the lock was granted,
-- and a later change cannot bump the counter until this call has finished. So
-- the stored change count never runs ahead of the data it was built from.
create function public.catalogue_source() returns jsonb
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_seq bigint;
  v_changes bigint;
  v_mirrors jsonb;
  v_archives jsonb;
  v_removed jsonb;
begin
  update public.catalogue_state
  set next_seq = next_seq + 1
  where id
  returning next_seq - 1, changes into v_seq, v_changes;

  select coalesce(jsonb_agg(jsonb_build_object(
    'id', m.id, 'base_url', m.base_url, 'public_key', m.public_key
  ) order by m.id), '[]'::jsonb)
  into v_mirrors
  from public.mirror as m
  where m.enabled;

  select coalesce(jsonb_agg(jsonb_build_object(
    'filename', a.filename, 'kind', a.kind, 'sha256', a.sha256, 'size', a.size,
    'internal_name', a.internal_name, 'version', a.version, 'shortname', a.shortname,
    'author', a.author, 'description', a.description
  ) order by a.listed_at, a.id), '[]'::jsonb)
  into v_archives
  from public.archive as a
  where a.state = 'listed';

  select coalesce(jsonb_agg(jsonb_build_object(
    'sha256', a.sha256, 'filename', a.filename,
    'reason', coalesce(a.removal_reason, 'removed'),
    'removed_at', floor(extract(epoch from a.removed_at))::bigint
  ) order by a.removed_at, a.id), '[]'::jsonb)
  into v_removed
  from public.archive as a
  where a.state = 'removed';

  return jsonb_build_object(
    'seq', v_seq, 'changes', v_changes,
    'mirrors', v_mirrors, 'archives', v_archives, 'removed', v_removed
  );
end;
$$;

-- A lower sequence never replaces a higher one, so two overlapping rebuilds
-- leave the one that read the most recent data. The change count only moves
-- forward either way.
create function public.store_catalogue(
  p_seq bigint, p_changes bigint, p_generated_at bigint, p_body text, p_sig text, p_next_key text
) returns boolean
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_state public.catalogue_state;
  v_written boolean;
begin
  select * into v_state from public.catalogue_state where id for update;

  v_written := v_state.seq is null or p_seq > v_state.seq;

  update public.catalogue_state
  set built_changes = greatest(built_changes, p_changes),
      seq = case when v_written then p_seq else seq end,
      generated_at = case when v_written then p_generated_at else generated_at end,
      body = case when v_written then p_body else body end,
      sig = case when v_written then p_sig else sig end
  where id;

  if p_next_key is distinct from v_state.next_key_public then
    update public.catalogue_state
    set next_key_public = p_next_key,
        next_key_since_seq = case when p_next_key is null then null else p_seq end
    where id;
  end if;

  return v_written;
end;
$$;

-- ## Access

revoke execute on function public.record_mirror_report(text, jsonb) from public, anon, authenticated;
revoke execute on function public.set_mirror_health(text, boolean) from public, anon, authenticated;
grant execute on function public.record_mirror_report(text, jsonb) to service_role;
grant execute on function public.set_mirror_health(text, boolean) to service_role;
revoke execute on function public.catalogue_source() from public, anon, authenticated;
revoke execute on function public.store_catalogue(bigint, bigint, bigint, text, text, text) from public, anon, authenticated;
grant execute on function public.catalogue_source() to service_role;
grant execute on function public.store_catalogue(bigint, bigint, bigint, text, text, text) to service_role;
