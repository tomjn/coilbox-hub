-- The mirror_urls column the map cards draw a download button from. Run as
-- anon, the role the catalog page reads through.
--
-- Map A is held by two healthy mirrors and one unhealthy one. Map B has no
-- upload at all.

begin;
select plan(3);

create extension if not exists pgtap with schema extensions;

insert into public.map (
  map_name, slug,
  width_elmos, height_elmos, world_height_min, world_height_max,
  curated_tags,
  source_hash, source_archive, catalog_version, facts_digest
)
values
  ('Map A 1.0', 'map-a', 4096, 4096, 0, 100, '{}', 'src-a', 'a.sd7', 1, 'digest-a'),
  ('Map B 1.0', 'map-b', 4096, 4096, 0, 100, '{}', 'src-b', 'b.sd7', 1, 'digest-b');

insert into public.mirror (id, name, base_url, public_key, healthy)
values
  ('one', 'One', 'https://one.example', repeat('a', 43), true),
  ('two', 'Two', 'https://two.example/files', repeat('b', 43), true),
  ('sick', 'Sick', 'https://sick.example', repeat('c', 43), false);

insert into public.archive (sha256, filename, kind, internal_name, version, shortname, size, state, listed_at)
values (repeat('a', 64), 'map a.sd7', 'map', 'Map A 1.0', '', null, 1, 'listed', '2026-11-10');

insert into public.archive_mirror (archive_id, mirror_id)
select archive.id, mirror.id
from public.archive as archive
cross join public.mirror as mirror;

reset role;
set local role anon;
set local request.jwt.claims = '{"role":"anon"}';

select is(
  (select mirror_urls from public.map_browse where slug = 'map-a'),
  '{https://one.example/maps/map%20a.sd7,https://two.example/files/maps/map%20a.sd7}'::text[],
  'every healthy mirror holding the map, in mirror order, with the filename encoded');

select is(
  (select mirror_urls from public.map_browse where slug = 'map-b'),
  '{}'::text[],
  'a map no mirror holds has an empty array');

select throws_ok(
  $$select * from public.archive_mirror$$,
  '42501',
  null,
  'anon still cannot read archive_mirror directly');

select * from finish();
rollback;
