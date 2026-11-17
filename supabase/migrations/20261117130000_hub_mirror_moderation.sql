-- Hub mirrors, step 4: removing archives, mismatch reports, and uploads that
-- a late mirror report brings back.
--
-- Spec: docs/superpowers/specs/2026-11-16-hub-mirrors-design.md, Removal and
-- Mismatch reports. Plan: docs/superpowers/specs/2026-11-17-hub-mirrors-moderation-plan.md.
--
-- Removing an archive sets its state to removed. The trigger from
-- 20261117120000_hub_mirrors.sql counts that as a catalogue change, the next
-- catalogue lists it as removed, and every mirror deletes the file on its next
-- sync. The download lists only ever show listed archives, so the hub stops
-- linking it at once.

-- ## Removal reasons
--
-- withdrawn is what an uploader or a game's owner gets. The rest are a
-- moderator's, with other as the catch all, which needs a note saying why.

alter table public.archive
  add constraint archive_removal_reason_code_check check (
    removal_reason in ('withdrawn', 'broken', 'wrong_facts', 'rights', 'harmful', 'other')
  ),
  -- Private. Moderators read it. It never goes into the catalogue.
  add column removal_note text check (char_length(removal_note) between 1 and 2000);

-- ## Mismatch reports
--
-- Anonymous. Coilbox sends one when a download's hash matched the catalogue
-- but the archive inside said something else. found keeps only the fields
-- that disagree with the hub's facts.
--
-- The address is kept while the report is open, so one address cannot fill
-- the queue, and cleared when a moderator handles it, as asset_upload_ip
-- clears an address once there is nothing left to report.

create table public.archive_mismatch (
  id bigint generated always as identity primary key,
  archive_id uuid not null references public.archive (id) on delete cascade,
  found jsonb not null check (jsonb_typeof(found) = 'object'),
  ip inet,
  created_at timestamptz not null default now(),
  handled_at timestamptz,
  handled_by uuid references auth.users (id) on delete set null,
  handled_how text check (handled_how in ('removed', 'dismissed')),

  constraint archive_mismatch_handled_check
    check ((handled_at is null) = (handled_how is null))
);

comment on table public.archive_mismatch is
  'Anonymous reports that an archive does not hold what the hub says it does.';

create unique index archive_mismatch_open_ip_idx
  on public.archive_mismatch (archive_id, ip) where handled_at is null;
create index archive_mismatch_open_idx
  on public.archive_mismatch (archive_id) where handled_at is null;
create index archive_mismatch_ip_idx
  on public.archive_mismatch (ip, created_at) where ip is not null;

alter table public.archive_mismatch enable row level security;
revoke all on public.archive_mismatch from anon, authenticated, service_role;

-- ## Removing an archive

-- What a moderator sees about one archive, with links to the copies on
-- healthy mirrors so they can download it and look.
create function public.archive_moderation_row(p_archive public.archive) returns jsonb
language sql
stable
security definer
set search_path = ''
as $$
  select jsonb_build_object(
    'id', p_archive.id,
    'sha256', p_archive.sha256,
    'filename', p_archive.filename,
    'kind', p_archive.kind,
    'internal_name', p_archive.internal_name,
    'version', p_archive.version,
    'shortname', p_archive.shortname,
    'author', p_archive.author,
    'size', p_archive.size,
    'listed_at', p_archive.listed_at,
    'copies', coalesce((
      select jsonb_agg(
        m.base_url || '/' || p_archive.kind || 's/' || public.url_path_segment(p_archive.filename)
        order by m.id
      )
      from public.archive_mirror as am
      join public.mirror as m on m.id = am.mirror_id
      where am.archive_id = p_archive.id and m.enabled and m.healthy
    ), '[]'::jsonb)
  )
$$;

create function public.remove_archive(p_id uuid, p_reason text, p_note text) returns text
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_user uuid := auth.uid();
  v_moderator boolean := public.is_moderator();
  v_archive public.archive;
  v_owner uuid;
  v_note text := nullif(btrim(coalesce(p_note, '')), '');
begin
  if v_user is null then
    return 'not_allowed';
  end if;

  select * into v_archive from public.archive where id = p_id for update;
  if not found then
    return 'not_found';
  end if;

  if p_reason is null
    or p_reason not in ('withdrawn', 'broken', 'wrong_facts', 'rights', 'harmful', 'other') then
    return 'bad_reason';
  end if;

  select g.owner_user_id into v_owner from public.game as g where g.id = v_archive.game_id;

  if not v_moderator then
    if p_reason <> 'withdrawn'
      or (v_archive.uploader is distinct from v_user and v_owner is distinct from v_user) then
      return 'not_allowed';
    end if;
    -- A note is for moderators to read. Nobody else writes one.
    v_note := null;
  end if;

  if v_archive.state <> 'listed' then
    return 'not_listed';
  end if;
  if p_reason = 'other' and v_note is null then
    return 'note_required';
  end if;
  if char_length(v_note) > 2000 then
    return 'note_too_long';
  end if;

  update public.archive
  set state = 'removed',
      removed_at = now(),
      removal_reason = p_reason,
      removal_note = v_note,
      removed_by = v_user
  where id = p_id;

  update public.archive_mismatch
  set handled_at = now(), handled_by = v_user, handled_how = 'removed', ip = null
  where archive_id = p_id and handled_at is null;

  return 'removed';
end;
$$;

create function public.dismiss_archive_mismatches(p_archive uuid) returns integer
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_count integer;
begin
  if auth.uid() is null or not public.is_moderator() then
    return 0;
  end if;

  update public.archive_mismatch
  set handled_at = now(), handled_by = auth.uid(), handled_how = 'dismissed', ip = null
  where archive_id = p_archive and handled_at is null;

  get diagnostics v_count = row_count;
  return v_count;
end;
$$;

-- The caller's own uploads, and every upload of a game they own.
create function public.my_archives() returns jsonb
language sql
stable
security definer
set search_path = ''
as $$
  select coalesce(jsonb_agg(jsonb_build_object(
    'id', a.id,
    'filename', a.filename,
    'kind', a.kind,
    'state', a.state,
    'internal_name', a.internal_name,
    'version', a.version,
    'created_at', a.created_at,
    'listed_at', a.listed_at,
    'removed_at', a.removed_at,
    'removal_reason', a.removal_reason,
    'mine', a.uploader is not distinct from auth.uid(),
    'via_game', g.owner_user_id is not distinct from auth.uid()
  ) order by a.created_at desc, a.id), '[]'::jsonb)
  from public.archive as a
  left join public.game as g on g.id = a.game_id
  where auth.uid() is not null
    and a.state <> 'failed'
    and (a.uploader = auth.uid() or g.owner_user_id = auth.uid())
$$;

-- The moderation page: reported archives first, then the newest uploads,
-- filtered by part of the file name. Null for anybody but a moderator.
create function public.moderation_archives(p_query text) returns jsonb
language plpgsql
stable
security definer
set search_path = ''
as $$
declare
  v_query text := lower(btrim(coalesce(p_query, '')));
  v_reported jsonb;
  v_recent jsonb;
begin
  if auth.uid() is null or not public.is_moderator() then
    return null;
  end if;

  select coalesce(jsonb_agg(
    public.archive_moderation_row(a) || jsonb_build_object('reports', o.reports)
    order by o.first_at, a.id
  ), '[]'::jsonb)
  into v_reported
  from (
    select mm.archive_id,
      jsonb_agg(jsonb_build_object('found', mm.found, 'created_at', mm.created_at) order by mm.created_at, mm.id) as reports,
      min(mm.created_at) as first_at
    from public.archive_mismatch as mm
    where mm.handled_at is null
    group by mm.archive_id
  ) as o
  join public.archive as a on a.id = o.archive_id
  where a.state = 'listed';

  select coalesce(jsonb_agg(public.archive_moderation_row(r) order by r.listed_at desc, r.id), '[]'::jsonb)
  into v_recent
  from (
    select *
    from public.archive as a
    where a.state = 'listed'
      and (v_query = '' or strpos(a.filename_lower, v_query) > 0)
    order by a.listed_at desc, a.id
    limit 100
  ) as r;

  return jsonb_build_object('reported', v_reported, 'recent', v_recent);
end;
$$;

-- ## Recording a mismatch report

-- 20 an hour from one address copies the publish limit in
-- 20260809181909_publish_rate_limit.sql: a ceiling on automation, far past
-- anything one player meets.
create function public.report_archive_mismatch(p_sha256 text, p_found jsonb, p_ip inet) returns jsonb
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_archive public.archive;
  v_facts jsonb;
  v_diff jsonb := '{}'::jsonb;
  v_key text;
  v_recent integer;
begin
  select * into v_archive from public.archive where sha256 = p_sha256 and state = 'listed';
  if not found then
    return jsonb_build_object('outcome', 'unknown_archive');
  end if;

  v_facts := to_jsonb(v_archive);
  foreach v_key in array array['kind', 'shortname', 'internal_name', 'version'] loop
    if p_found ? v_key and (p_found->>v_key) is distinct from (v_facts->>v_key) then
      v_diff := v_diff || jsonb_build_object(v_key, p_found->v_key);
    end if;
  end loop;

  if v_diff = '{}'::jsonb then
    return jsonb_build_object('outcome', 'agrees');
  end if;

  if p_ip is not null then
    if exists (
      select 1 from public.archive_mismatch
      where archive_id = v_archive.id and ip = p_ip and handled_at is null
    ) then
      return jsonb_build_object('outcome', 'already');
    end if;

    select count(*) into v_recent
    from public.archive_mismatch
    where ip = p_ip and created_at > now() - interval '1 hour';

    if v_recent >= 20 then
      return jsonb_build_object('outcome', 'rate_limited');
    end if;
  end if;

  begin
    insert into public.archive_mismatch (archive_id, found, ip)
    values (v_archive.id, v_diff, p_ip);
  exception when unique_violation then
    return jsonb_build_object('outcome', 'already');
  end;

  return jsonb_build_object('outcome', 'recorded');
end;
$$;

-- ## Reports that bring an upload back
--
-- An upload whose mirror report failed is listed by the mirror's next sync
-- report. If that comes after the reservation lapsed, the row is failed by
-- then and was never listed. Now a report revives it, as long as nothing else
-- has taken its hash, name or name and version since. The unique indexes
-- decide that, so a revive that would clash is skipped.
--
-- The answer also says whether anything a download list shows changed, so the
-- hub can drop its cached map and game pages only when it has to.

create or replace function public.record_mirror_report(p_mirror text, p_payload jsonb) returns jsonb
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_mirror public.mirror;
  v_sent_at bigint := (p_payload->>'sent_at')::bigint;
  v_held text[];
  v_listed integer;
  v_revived integer := 0;
  v_inserted integer;
  v_deleted integer;
  v_failed record;
  v_count integer;
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

  -- After the live uploads, so an upload in progress wins over an old one.
  -- The newest failed row for each hash is the one tried.
  for v_failed in
    select distinct on (a.sha256) a.id
    from public.archive as a
    where a.state = 'failed'
      and a.upload_mirror_id = p_mirror
      and a.sha256 = any (v_held)
    order by a.sha256, a.created_at desc, a.id
  loop
    begin
      update public.archive
      set state = 'listed', listed_at = now(), reserved_until = null
      where id = v_failed.id and state = 'failed';

      get diagnostics v_count = row_count;
      v_revived := v_revived + v_count;
    exception when unique_violation then
      null;
    end;
  end loop;

  update public.archive_mirror as am
  set last_seen_at = now()
  from public.archive as a
  where am.mirror_id = p_mirror
    and a.id = am.archive_id
    and a.state = 'listed'
    and a.sha256 = any (v_held);

  insert into public.archive_mirror (archive_id, mirror_id)
  select a.id, p_mirror
  from public.archive as a
  where a.state = 'listed'
    and a.sha256 = any (v_held)
    and not exists (
      select 1 from public.archive_mirror as am
      where am.archive_id = a.id and am.mirror_id = p_mirror
    )
  on conflict (archive_id, mirror_id) do nothing;

  get diagnostics v_inserted = row_count;

  delete from public.archive_mirror as am
  where am.mirror_id = p_mirror
    and not exists (
      select 1
      from public.archive as a
      where a.id = am.archive_id and a.sha256 = any (v_held)
    );

  get diagnostics v_deleted = row_count;

  select jsonb_build_object('filename', a.filename, 'kind', a.kind, 'size', a.size)
  into v_check
  from public.archive_mirror as am
  join public.archive as a on a.id = am.archive_id
  where am.mirror_id = p_mirror and a.state = 'listed'
  order by random()
  limit 1;

  return jsonb_build_object(
    'outcome', 'recorded',
    'listed', v_listed + v_revived,
    'changed', v_listed + v_revived + v_inserted + v_deleted > 0,
    'check', v_check
  );
end;
$$;

-- Now answers whether the mirror's health changed, for the same reason.
drop function public.set_mirror_health(text, boolean);

create function public.set_mirror_health(p_mirror text, p_healthy boolean) returns boolean
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_was boolean;
begin
  select healthy into v_was from public.mirror where id = p_mirror for update;

  update public.mirror
  set healthy = p_healthy, last_check_at = now()
  where id = p_mirror;

  return found and v_was is distinct from p_healthy;
end;
$$;

-- ## Access

revoke execute on function public.archive_moderation_row(public.archive) from public, anon, authenticated, service_role;

revoke execute on function public.remove_archive(uuid, text, text) from public, anon;
revoke execute on function public.dismiss_archive_mismatches(uuid) from public, anon;
revoke execute on function public.my_archives() from public, anon;
revoke execute on function public.moderation_archives(text) from public, anon;
grant execute on function public.remove_archive(uuid, text, text) to authenticated;
grant execute on function public.dismiss_archive_mismatches(uuid) to authenticated;
grant execute on function public.my_archives() to authenticated;
grant execute on function public.moderation_archives(text) to authenticated;

revoke execute on function public.report_archive_mismatch(text, jsonb, inet) from public, anon, authenticated;
grant execute on function public.report_archive_mismatch(text, jsonb, inet) to service_role;

revoke execute on function public.set_mirror_health(text, boolean) from public, anon, authenticated;
grant execute on function public.set_mirror_health(text, boolean) to service_role;
