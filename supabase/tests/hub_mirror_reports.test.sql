-- Mirror reports: which uploads a report lists, which copies it records,
-- which reports the hub refuses, and who may call the functions.
--
-- The payload is already verified and validated by the hub before the
-- function sees it, so these tests pass well formed payloads.

begin;
select plan(43);

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

-- ## Catalogue source

insert into public.archive (id, sha256, filename, kind, internal_name, version, size, state, removed_at, removal_reason)
values
  ('11111111-0000-4000-8000-00000000000c', repeat('c', 64), 'c.sd7', 'map', 'C 1.0', '1.0', 333, 'uploading', null, null),
  ('11111111-0000-4000-8000-00000000000d', repeat('d', 64), 'd.sd7', 'map', 'D 1.0', '1.0', 444, 'removed', '2026-01-01 00:00:00+00', null);

-- Start from a fresh catalogue state, so the sequence numbers below do not
-- depend on catalogues an earlier run built in this database.
update public.catalogue_state
set next_seq = 1, seq = null, generated_at = null, body = null, sig = null,
    built_changes = -1, next_key_public = null, next_key_since_seq = null
where id;

create temporary table src (label text primary key, j jsonb);
grant all on src to public;

insert into src select 'first', public.catalogue_source();
insert into src select 'second', public.catalogue_source();

select is((select (j->>'seq')::bigint from src where label = 'first'), 1::bigint, 'the first catalogue_source call returns seq 1');
select is((select (j->>'seq')::bigint from src where label = 'second'), 2::bigint, 'the second call returns seq 2');
select is(
  (select next_seq from public.catalogue_state), 3::bigint,
  'each call moves next_seq on by one');

select is(
  (select count(*) from jsonb_array_elements((select j from src where label = 'first')->'mirrors') as e where e->>'id' = 'm-off'),
  0::bigint, 'a disabled mirror is not listed');
select is(
  (select count(*) from jsonb_array_elements((select j from src where label = 'first')->'mirrors') as e where e->>'id' = 'm-a'),
  1::bigint, 'an enabled mirror is listed whatever its health');
select is(
  (select array_agg(e->>'id' order by ord) from jsonb_array_elements((select j from src where label = 'first')->'mirrors') with ordinality as t(e, ord)),
  (select array_agg(id order by id) from public.mirror where enabled),
  'mirrors are ordered by id');

select is(
  (select array_agg(e->>'filename' order by ord) from jsonb_array_elements((select j from src where label = 'first')->'archives') with ordinality as t(e, ord)),
  array['a.sd7', 'b.sd7'],
  'only listed archives are listed');
select is(
  (select jsonb_typeof(e->'size') from jsonb_array_elements((select j from src where label = 'first')->'archives') as e limit 1),
  'number', 'size is a JSON number');

select is(
  (select (j->'removed') from src where label = 'first'),
  '[{"sha256":"dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd","filename":"d.sd7","reason":"removed","removed_at":1767225600}]'::jsonb,
  'a removed archive is listed with a default reason and epoch seconds');

-- ## Storing a catalogue

select is(
  public.store_catalogue(5, 10, 1000, '{"a":1}', 'sig5', null),
  true, 'store_catalogue writes the first catalogue');
select is(
  public.store_catalogue(4, 12, 1001, '{"a":0}', 'sig4', null),
  false, 'a lower seq is not written');
select is(
  (select body from public.catalogue_state), '{"a":1}',
  'the higher seq keeps its body');
select is(
  (select sig from public.catalogue_state), 'sig5',
  'the higher seq keeps its signature');
select is(
  (select built_changes from public.catalogue_state), 12::bigint,
  'built_changes still moves forward on a refused write');

select public.store_catalogue(6, 12, 1002, '{"a":2}', 'sig6', 'NEXTKEY');
select is(
  (select next_key_since_seq from public.catalogue_state), 6::bigint,
  'the first catalogue carrying a next key records its seq');
select public.store_catalogue(7, 12, 1003, '{"a":3}', 'sig7', 'NEXTKEY');
select is(
  (select next_key_since_seq from public.catalogue_state), 6::bigint,
  'the same next key keeps the first seq');
select public.store_catalogue(8, 12, 1004, '{"a":4}', 'sig8', null);
select ok(
  (select next_key_public is null and next_key_since_seq is null from public.catalogue_state),
  'a null next key clears both columns');
select public.store_catalogue(3, 12, 1005, '{"a":5}', 'sig3', 'OTHERKEY');
select ok(
  (select next_key_public is null and next_key_since_seq is null from public.catalogue_state),
  'a refused write does not record a next key');

set local role anon;
select throws_ok(
  $$select public.catalogue_source()$$,
  '42501', null, 'anon cannot call catalogue_source');
reset role;

set local role authenticated;
select throws_ok(
  $$select public.store_catalogue(1, 1, 1, 'x', 'y', null)$$,
  '42501', null, 'authenticated cannot call store_catalogue');
reset role;

select * from finish();
rollback;
