-- What a map's edit page shows of its hub mirror uploads, and who may see it.
--
-- One map, owned by A. Three uploads of its archive: one listed and held by a
-- healthy and an unhealthy mirror, one removed, and one failed. A game archive
-- with the same name does not count.

begin;
select plan(7);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values
  ('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'owner@example.test'),
  ('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'stranger@example.test');

insert into public.map (
  id, map_name, slug, width_elmos, height_elmos, world_height_min, world_height_max,
  curated_tags, source_hash, source_archive, catalog_version, facts_digest, owner_user_id
)
values ('11111111-0000-4000-8000-000000000001', 'Plains 1.1', 'plains-1-1', 4096, 4096, 0, 100,
  '{}', 'src', 'plains_1.1.sd7', 1, 'digest', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');

insert into public.mirror (id, name, base_url, public_key, healthy)
values
  ('one', 'One', 'https://one.example', repeat('a', 43), true),
  ('sick', 'Sick', 'https://sick.example', repeat('c', 43), false);

insert into public.archive (sha256, filename, kind, internal_name, version, shortname, size, state, uploader, created_at, listed_at, removed_at, removal_reason)
values
  (repeat('a', 64), 'plains_1.1.sd7', 'map', 'Plains 1.1', '', null, 1, 'listed', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', '2026-11-12', '2026-11-12', null, null),
  (repeat('b', 64), 'plains_old.sd7', 'map', 'Plains 1.1', 'old', null, 1, 'removed', 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', '2026-11-10', '2026-11-10', '2026-11-11', 'withdrawn'),
  (repeat('c', 64), 'plains_bad.sd7', 'map', 'Plains 1.1', '', null, 1, 'failed', null, '2026-11-13', null, null, null),
  (repeat('d', 64), 'plains_game.sd7', 'game', 'Plains 1.1', 'game', 'plains', 1, 'listed', null, '2026-11-14', '2026-11-14', null, null);

insert into public.archive_mirror (archive_id, mirror_id)
select archive.id, mirror.id
from public.archive as archive
cross join public.mirror as mirror
where archive.filename = 'plains_1.1.sd7'
  and mirror.id in ('one', 'sick');

reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa","role":"authenticated"}';

select is(
  (select jsonb_agg(u ->> 'filename') from jsonb_array_elements(public.map_mirror_status('11111111-0000-4000-8000-000000000001')) as u),
  '["plains_1.1.sd7", "plains_old.sd7"]'::jsonb,
  'the owner sees the listed and removed map uploads, newest first, and no failed or game upload');

-- last_seen_at is now(), so it is left out of the comparison.
select is(
  (select jsonb_agg(m - 'last_seen_at') from jsonb_array_elements(public.map_mirror_status('11111111-0000-4000-8000-000000000001') -> 0 -> 'mirrors') as m),
  '[{"name": "One", "linked": true}, {"name": "Sick", "linked": false}]'::jsonb,
  'each mirror holding the listed upload, with whether the hub links it');

select is(
  public.map_mirror_status('11111111-0000-4000-8000-000000000001') -> 0 -> 'mine',
  'true'::jsonb,
  'the owner is told which upload is theirs');

select is(
  public.map_mirror_status('11111111-0000-4000-8000-000000000001') -> 1 ->> 'removal_reason',
  'withdrawn',
  'a removed upload says why');

select is(
  public.map_mirror_status('11111111-0000-4000-8000-0000000000ff'),
  null,
  'an unknown map, which nobody but a moderator can edit, gets nothing');

reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb","role":"authenticated"}';

select is(
  public.map_mirror_status('11111111-0000-4000-8000-000000000001'),
  null,
  'somebody who cannot edit the map gets nothing, even an upload of their own');

reset role;
set local role anon;
set local request.jwt.claims = '{"role":"anon"}';

select throws_ok(
  $$select public.map_mirror_status('11111111-0000-4000-8000-000000000001')$$,
  '42501', null,
  'anon may not call it');

select * from finish();
rollback;
