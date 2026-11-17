-- Mirror reports: which uploads a report lists, which copies it records,
-- which reports the hub refuses, and who may call the functions.
--
-- The payload is already verified and validated by the hub before the
-- function sees it, so these tests pass well formed payloads.

begin;
select plan(23);

create extension if not exists pgtap with schema extensions;

insert into public.mirror (id, name, base_url, public_key, enabled, healthy)
values
  ('m-a', 'A', 'https://a.example.test', 'B17CkPdtRVPSfwdwz9CenRCdM6QSUBFMarO-10NppNY', true, true),
  ('m-b', 'B', 'https://b.example.test', 'B17CkPdtRVPSfwdwz9CenRCdM6QSUBFMarO-10NppNY', true, true),
  ('m-off', 'Off', 'https://off.example.test', 'B17CkPdtRVPSfwdwz9CenRCdM6QSUBFMarO-10NppNY', false, true);

-- Two uploads, both issued for m-a.
insert into public.archive (id, sha256, filename, kind, internal_name, version, size, state, upload_mirror_id, reserved_until)
values
  ('11111111-0000-4000-8000-00000000000a', repeat('a', 64), 'a.sd7', 'map', 'A 1.0', '1.0', 111, 'uploading', 'm-a', now() + interval '1 day'),
  ('11111111-0000-4000-8000-00000000000b', repeat('b', 64), 'b.sd7', 'map', 'B 1.0', '1.0', 222, 'uploading', 'm-a', now() + interval '1 day');

create function pg_temp.report(p_event text, p_sent_at bigint, p_seq bigint, p_held text[])
returns jsonb language sql as $$
  select jsonb_build_object(
    'v', 1, 'event', p_event,
    'upload', case when p_event = 'upload' then 'up-1' else null end,
    'sent_at', p_sent_at, 'catalogue_seq', p_seq, 'catalogue_generated_at', null,
    'held', to_jsonb(p_held), 'unknown', '[]'::jsonb
  )
$$;

create temporary table r (label text primary key, j jsonb);
grant all on r to public;

-- ## A sync report carries a file the upload report missed

insert into r select 'sync_a', public.record_mirror_report(
  'm-a', pg_temp.report('sync', 1000, null, array[repeat('a', 64)]));

select is((select j->>'outcome' from r where label = 'sync_a'), 'recorded', 'a sync report is recorded');
select is((select (j->>'listed')::int from r where label = 'sync_a'), 1, 'a sync report with upload null lists the uploading archive');
select is(
  (select state from public.archive where id = '11111111-0000-4000-8000-00000000000a'),
  'listed', 'the archive is listed');
select is(
  (select reserved_until from public.archive where id = '11111111-0000-4000-8000-00000000000a'),
  null::timestamptz, 'listing clears the reservation');
select is(
  (select count(*) from public.archive_mirror
    where archive_id = '11111111-0000-4000-8000-00000000000a' and mirror_id = 'm-a'),
  1::bigint, 'archive_mirror gains the row');
select is(
  (select j->'check' from r where label = 'sync_a'),
  '{"filename":"a.sd7","kind":"map","size":111}'::jsonb,
  'check names a listed archive the mirror holds');

-- ## Another mirror's copy does not list an upload issued elsewhere

insert into r select 'sync_b', public.record_mirror_report(
  'm-b', pg_temp.report('sync', 1000, null, array[repeat('b', 64)]));

select is((select (j->>'listed')::int from r where label = 'sync_b'), 0, 'a hash held by a different mirror lists nothing');
select is(
  (select state from public.archive where id = '11111111-0000-4000-8000-00000000000b'),
  'uploading', 'the upload stays uploading');

-- ## The event type does not matter

insert into r select 'upload_b', public.record_mirror_report(
  'm-a', pg_temp.report('upload', 1001, 5, array[repeat('a', 64), repeat('b', 64)]));

select is((select (j->>'listed')::int from r where label = 'upload_b'), 1, 'an upload report lists the archive for the mirror it was issued for');
select is(
  (select last_catalogue_seq from public.mirror where id = 'm-a'),
  5::bigint, 'the mirror''s catalogue sequence is recorded');

-- ## Stale and equal reports

insert into r select 'stale', public.record_mirror_report(
  'm-a', pg_temp.report('sync', 999, 9, array[]::text[]));

select is((select j->>'outcome' from r where label = 'stale'), 'stale', 'a report older than the last accepted is stale');
select is(
  (select last_catalogue_seq from public.mirror where id = 'm-a'),
  5::bigint, 'a stale report changes nothing');
select is(
  (select count(*) from public.archive_mirror where mirror_id = 'm-a'),
  2::bigint, 'a stale report leaves the held copies alone');

select is(
  (public.record_mirror_report('m-a', pg_temp.report('sync', 1001, 6, array[repeat('a', 64), repeat('b', 64)])))->>'outcome',
  'recorded', 'a report with an equal sent_at is recorded');

-- ## A copy that goes away

insert into r select 'gone', public.record_mirror_report(
  'm-a', pg_temp.report('sync', 1002, 6, array[repeat('a', 64)]));

select is(
  (select count(*) from public.archive_mirror
    where archive_id = '11111111-0000-4000-8000-00000000000b' and mirror_id = 'm-a'),
  0::bigint, 'a later report without the hash deletes the copy row');
select is(
  (select count(*) from public.archive_mirror
    where archive_id = '11111111-0000-4000-8000-00000000000a' and mirror_id = 'm-a'),
  1::bigint, 'the copy still held is kept');

insert into r select 'empty', public.record_mirror_report(
  'm-a', pg_temp.report('sync', 1003, 6, array[]::text[]));

select is((select j->'check' from r where label = 'empty'), 'null'::jsonb, 'check is null when the mirror holds no listed archive');

-- ## Mirrors the hub does not accept

select is(
  (public.record_mirror_report('m-off', pg_temp.report('sync', 1, null, array[]::text[])))->>'outcome',
  'unknown_mirror', 'a disabled mirror''s report is refused');
select is(
  (public.record_mirror_report('nope', pg_temp.report('sync', 1, null, array[]::text[])))->>'outcome',
  'unknown_mirror', 'a mirror the hub does not know is refused');

-- ## Health

select public.set_mirror_health('m-b', false);

select is((select healthy from public.mirror where id = 'm-b'), false, 'set_mirror_health sets healthy');
select isnt((select last_check_at from public.mirror where id = 'm-b'), null::timestamptz, 'set_mirror_health sets last_check_at');

-- ## Who may call

set local role anon;
select throws_ok(
  $$select public.record_mirror_report('m-a', '{}'::jsonb)$$,
  '42501', null, 'anon cannot call record_mirror_report');
reset role;

set local role authenticated;
select throws_ok(
  $$select public.set_mirror_health('m-a', true)$$,
  '42501', null, 'authenticated cannot call set_mirror_health');
reset role;

select * from finish();
rollback;
