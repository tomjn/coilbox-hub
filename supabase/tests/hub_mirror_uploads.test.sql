-- Upload reservations: who may reserve an archive, what counts as a
-- duplicate, how a reservation lapses, and who may call the functions.
--
-- begin_archive_upload takes the moderator flag as a parameter and never
-- checks capabilities itself, so these tests pass the flag directly.

begin;
select plan(34);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values
  ('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'owner@example.test'),
  ('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'stranger@example.test');

insert into public.game (id, shortname, owner_user_id)
values ('0f8fad5b-0008-4000-8000-000000000001', 'TG', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');

-- A map file is only taken for a map the hub has facts for.
insert into public.map (
  map_name, slug, width_elmos, height_elmos, world_height_min, world_height_max,
  source_hash, source_archive, catalog_version, facts_digest
)
select name, 'map-' || ord, 4096, 4096, 0, 100, 'src', 'src.sd7', 1, 'digest'
from unnest(array['Map One 1.0', 'Map Two 1.0', 'Other 1.0', 'Fresh 1.0', 'Race 1.0'])
  with ordinality as names(name, ord);

-- jj-darkstars is seeded unhealthy. One healthy mirror and one that is healthy
-- but disabled.
insert into public.mirror (id, name, base_url, public_key, enabled, healthy)
values
  ('m-ok', 'Healthy', 'https://ok.example.test', 'B17CkPdtRVPSfwdwz9CenRCdM6QSUBFMarO-10NppNY', true, true),
  ('m-off', 'Disabled', 'https://off.example.test', 'B17CkPdtRVPSfwdwz9CenRCdM6QSUBFMarO-10NppNY', false, true);

create function pg_temp.facts(
  p_sha text, p_filename text, p_name text, p_version text,
  p_kind text default 'map', p_shortname text default null
) returns jsonb language sql as $$
  select jsonb_build_object(
    'sha256', p_sha, 'size', 1000, 'filename', p_filename, 'kind', p_kind,
    'internal_name', p_name, 'version', p_version, 'shortname', p_shortname,
    'author', null, 'description', null
  )
$$;

create temporary table r (label text primary key, j jsonb);
grant all on r to public;

-- ## A map upload

insert into r select 'map1', public.begin_archive_upload(
  'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', false,
  pg_temp.facts(repeat('1', 64), 'map1.sd7', 'Map One 1.0', '1.0'), 86400);

select is((select j->>'outcome' from r where label = 'map1'), 'created', 'a map upload by a stranger is created');
select is(
  (select state from public.archive where id = (select (j->>'id')::uuid from r where label = 'map1')),
  'uploading', 'the new archive is uploading');
select is(
  (select upload_mirror_id from public.archive where id = (select (j->>'id')::uuid from r where label = 'map1')),
  'm-ok', 'the healthy enabled mirror is the one recorded');
select is((select j->>'base_url' from r where label = 'map1'), 'https://ok.example.test', 'the answer carries the mirror base URL');

select is(
  (public.begin_archive_upload(
    'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', true,
    pg_temp.facts(repeat('7', 64), 'unknown.sd7', 'Unknown 1.0', '1.0'), 86400))->>'reason',
  'unknown_map', 'a map the hub has no facts for is refused, even for a moderator');
select is(
  (select count(*) from public.archive where sha256 = repeat('7', 64)),
  0::bigint, 'no row is left behind for an unknown map');

-- ## Mirror choice

update public.mirror set healthy = false where id = 'm-ok';

select is(
  (public.begin_archive_upload(
    'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', false,
    pg_temp.facts(repeat('2', 64), 'map2.sd7', 'Map Two 1.0', '1.0'), 86400))->>'reason',
  'no_mirror', 'a disabled mirror is never picked, so no healthy mirror gives no_mirror');
select is(
  (select count(*) from public.archive where sha256 = repeat('2', 64)),
  0::bigint, 'no row is left behind when no mirror is available');

update public.mirror set healthy = true where id = 'm-ok';

-- ## Games

select is(
  (public.begin_archive_upload(
    'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', false,
    pg_temp.facts(repeat('3', 64), 'game1.sd7', 'Game One', '1', 'game', 'TG'), 86400))->>'reason',
  'not_owner', 'a stranger cannot upload a game archive for a game they do not own');

insert into r select 'game1', public.begin_archive_upload(
  'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', true,
  pg_temp.facts(repeat('3', 64), 'game1.sd7', 'Game One', '1', 'game', 'TG'), 86400);

select is((select j->>'outcome' from r where label = 'game1'), 'created', 'a moderator may upload a game archive for any game');
select is(
  (select game_id from public.archive where id = (select (j->>'id')::uuid from r where label = 'game1')),
  '0f8fad5b-0008-4000-8000-000000000001'::uuid, 'the game archive is linked to its game');

select is(
  (public.begin_archive_upload(
    'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', true,
    pg_temp.facts(repeat('4', 64), 'nope.sd7', 'Nope', '1', 'game', 'NOPE'), 86400))->>'reason',
  'unknown_game', 'a game that does not exist is refused');

-- ## Duplicates

insert into r select 'dup_sha', public.begin_archive_upload(
  'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', false,
  pg_temp.facts(repeat('1', 64), 'other.sd7', 'Other 1.0', '1.0'), 86400);

select is((select j->>'reason' from r where label = 'dup_sha'), 'duplicate', 'the same hash from another user is a duplicate');
select is((select j->>'check' from r where label = 'dup_sha'), 'sha256', 'the duplicate names the hash check');
select is(
  (select j->'existing'->>'id' from r where label = 'dup_sha'),
  (select j->>'id' from r where label = 'map1'), 'the duplicate names the existing archive');

select is(
  (public.begin_archive_upload(
    'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', false,
    pg_temp.facts(repeat('5', 64), 'MAP1.SD7', 'Fresh 1.0', '1.0'), 86400))->>'check',
  'filename', 'a filename differing only in case is a duplicate');

select is(
  (public.begin_archive_upload(
    'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', false,
    pg_temp.facts(repeat('6', 64), 'fresh6.sd7', 'Map One 1.0', '1.0'), 86400))->>'check',
  'name_version', 'the same internal name and version under a new hash and name is a duplicate');

-- ## Asking again

update public.archive set reserved_until = now() + interval '1 second'
where id = (select (j->>'id')::uuid from r where label = 'map1');

insert into r select 'map1_again', public.begin_archive_upload(
  'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', false,
  pg_temp.facts(repeat('1', 64), 'map1.sd7', 'Map One 1.0', '1.0'), 3600);

select is((select j->>'outcome' from r where label = 'map1_again'), 'reissued', 'the same facts from the same uploader are reissued');
select is(
  (select j->>'id' from r where label = 'map1_again'),
  (select j->>'id' from r where label = 'map1'), 'a reissue keeps the upload ID');
select cmp_ok(
  (select (j->>'reserved_until')::bigint from r where label = 'map1_again'),
  '>', extract(epoch from now())::bigint + 1000,
  'a reissue renews the reservation');

-- ## Lapsed reservations

update public.archive set reserved_until = now() - interval '1 minute'
where id = (select (j->>'id')::uuid from r where label = 'map1');

select is(
  (public.begin_archive_upload(
    'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', false,
    pg_temp.facts(repeat('1', 64), 'map1.sd7', 'Map One 1.0', '1.0'), 86400))->>'outcome',
  'created', 'a lapsed reservation frees its hash for another upload');
select is(
  (select state from public.archive where id = (select (j->>'id')::uuid from r where label = 'map1')),
  'failed', 'the lapsed row is marked failed');

-- ## Status

select is(
  public.archive_upload_status(
    'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', (select (j->>'id')::uuid from r where label = 'game1'), 86400),
  null::jsonb, 'another user gets null for an upload that is not theirs');
select is(
  public.archive_upload_status(
    'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', '99999999-9999-4999-8999-999999999999', 86400),
  null::jsonb, 'an upload that does not exist gives null');

update public.archive set reserved_until = now() + interval '1 second'
where id = (select (j->>'id')::uuid from r where label = 'game1');

insert into r select 'status', public.archive_upload_status(
  'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', (select (j->>'id')::uuid from r where label = 'game1'), 3600);

select is((select j->>'state' from r where label = 'status'), 'uploading', 'the uploader sees the state');
select cmp_ok(
  (select (j->>'reserved_until')::bigint from r where label = 'status'),
  '>', extract(epoch from now())::bigint + 1000,
  'asking for the status renews an uploading reservation');

select is(
  public.archive_upload_status(
    'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', (select (j->>'id')::uuid from r where label = 'map1'), 3600)->>'state',
  'failed', 'a lapsed upload reads as failed');

-- ## A race for one name

-- The other transaction's row would be committed before this insert fails. The
-- trigger stands in for it, and the function must answer duplicate, not raise.
create function public.test_race_row() returns trigger
language plpgsql
as $$
begin
  insert into public.archive (sha256, filename, kind, internal_name, version, size)
  values (new.sha256, 'racer.sd7', 'map', 'Racer 1.0', '1.0', 5);
  return new;
end;
$$;

create trigger test_race
  before insert on public.archive
  for each row
  when (new.filename = 'race.sd7')
  execute function public.test_race_row();

insert into r select 'race', public.begin_archive_upload(
  'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', false,
  pg_temp.facts(repeat('e', 64), 'race.sd7', 'Race 1.0', '1.0'), 86400);

select is((select j->>'reason' from r where label = 'race'), 'duplicate', 'a unique violation on insert reads as a duplicate');
select is((select j->>'check' from r where label = 'race'), 'sha256', 'the race answer names the index that refused');

drop trigger test_race on public.archive;

-- ## Who may call

set local role anon;
select throws_ok(
  $$select public.begin_archive_upload('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', false, '{}'::jsonb, 1)$$,
  '42501', null, 'anon cannot call begin_archive_upload');
select throws_ok(
  $$select public.archive_upload_status('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', '99999999-9999-4999-8999-999999999999', 1)$$,
  '42501', null, 'anon cannot call archive_upload_status');
reset role;

set local role authenticated;
select throws_ok(
  $$select public.begin_archive_upload('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', false, '{}'::jsonb, 1)$$,
  '42501', null, 'authenticated cannot call begin_archive_upload');
select throws_ok(
  $$select public.archive_upload_status('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', '99999999-9999-4999-8999-999999999999', 1)$$,
  '42501', null, 'authenticated cannot call archive_upload_status');
reset role;

set local role service_role;
select lives_ok(
  $$select public.archive_upload_status('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', '99999999-9999-4999-8999-999999999999', 1)$$,
  'service_role can call archive_upload_status');
reset role;

select * from finish();
rollback;
