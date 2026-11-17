-- The two columns the maps page filters and sorts on: when a map was last
-- listed as an upload, and which play types its start positions and tags give
-- it. Run as anon, the role the catalog page reads through.
--
-- Four maps. A has 2 starts and two listed uploads plus a removed one. B has 4
-- starts and no map upload, only a game archive that shares its name. C has 6
-- starts and a curated 1V1 tag. D has no starts.

begin;
select plan(8);

create extension if not exists pgtap with schema extensions;

insert into public.map (
  map_name, slug,
  width_elmos, height_elmos, world_height_min, world_height_max,
  curated_tags,
  source_hash, source_archive, catalog_version, facts_digest
)
values
  ('Map A 1.0', 'map-a', 4096, 4096, 0, 100, '{}', 'src-a', 'a.sd7', 1, 'digest-a'),
  ('Map B 1.0', 'map-b', 4096, 4096, 0, 100, '{}', 'src-b', 'b.sd7', 1, 'digest-b'),
  ('Map C 1.0', 'map-c', 4096, 4096, 0, 100, '{1V1}', 'src-c', 'c.sd7', 1, 'digest-c'),
  ('Map D 1.0', 'map-d', 4096, 4096, 0, 100, '{}', 'src-d', 'd.sd7', 1, 'digest-d');

insert into public.map_point (map_id, kind, ordinal, x, z)
select map.id, 'start', series.n, series.n * 100, series.n * 100
from public.map as map
join (values ('map-a', 2), ('map-b', 4), ('map-c', 6)) as count(slug, total)
  on count.slug = map.slug
cross join lateral generate_series(0, count.total - 1) as series(n);

insert into public.archive (sha256, filename, kind, internal_name, version, shortname, size, state, listed_at)
values
  (repeat('a', 64), 'a_1.sd7', 'map', 'Map A 1.0', '', null, 1, 'listed', '2026-11-10'),
  (repeat('b', 64), 'a_2.sd7', 'map', 'Map A 1.0', '2', null, 1, 'listed', '2026-11-12'),
  (repeat('c', 64), 'a_3.sd7', 'map', 'Map A 1.0', '3', null, 1, 'removed', '2026-11-15'),
  (repeat('d', 64), 'b_game.sd7', 'game', 'Map B 1.0', '', 'mapb', 1, 'listed', '2026-11-16');

reset role;
set local role anon;
set local request.jwt.claims = '{"role":"anon"}';

select is(
  (select play_types from public.map_browse where slug = 'map-a'),
  '{1v1}'::text[],
  'two start positions is a 1v1 map');

select is(
  (select play_types from public.map_browse where slug = 'map-b'),
  '{team}'::text[],
  'four start positions is a team map');

select is(
  (select play_types from public.map_browse where slug = 'map-c'),
  '{1v1,team}'::text[],
  'six start positions with a 1V1 tag is both');

select is(
  (select play_types from public.map_browse where slug = 'map-d'),
  '{}'::text[],
  'a map with no start positions has no play types');

select is(
  (select uploaded_at from public.map_browse where slug = 'map-a'),
  '2026-11-12'::timestamptz,
  'uploaded_at is the latest listed map upload, ignoring a removed one');

select is(
  (select uploaded_at from public.map_browse where slug = 'map-b'),
  null::timestamptz,
  'a game archive with the same name is not a map upload');

select is(
  (select uploaded_at from public.map_browse where slug = 'map-d'),
  null::timestamptz,
  'a map never uploaded has a null uploaded_at');

select throws_ok(
  $$select * from public.archive$$,
  '42501',
  null,
  'anon still cannot read the archive table directly');

select * from finish();
rollback;
