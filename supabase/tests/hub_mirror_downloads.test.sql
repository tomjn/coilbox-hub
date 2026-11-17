-- Mirror copies of maps and games as download sources, and the URL encoder
-- behind them.
--
-- Run as the roles PostgREST uses, because a grant and a policy are separate
-- layers and a test run as the database owner proves neither.

begin;
select plan(19);

create extension if not exists pgtap with schema extensions;

-- ## url_path_segment

select is(
  public.url_path_segment('Test Map (v1.2) é.sd7'),
  'Test%20Map%20%28v1.2%29%20%C3%A9.sd7',
  'url_path_segment encodes a name as rawurlencode does'
);

select is(
  public.url_path_segment('Az09-_.~'),
  'Az09-_.~',
  'url_path_segment leaves the unreserved characters alone'
);

select is(
  public.url_path_segment('a/b?c#d%e+f&g'),
  'a%2Fb%3Fc%23d%25e%2Bf%26g',
  'url_path_segment encodes the characters a URL treats as syntax'
);

select is(public.url_path_segment(''), '', 'url_path_segment of an empty name is empty');

-- ## Fixtures

insert into public.mirror (id, name, base_url, public_key, enabled, healthy)
values
  ('aa-mirror', 'A', 'https://a.example.test', 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', true, true),
  ('zz-mirror', 'Z', 'https://z.example.test/files', 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB', true, true),
  ('off-mirror', 'Off', 'https://off.example.test', 'CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC', false, true),
  ('sick-mirror', 'Sick', 'https://sick.example.test', 'DDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDD', true, false),
  ('empty-mirror', 'Empty', 'https://empty.example.test', 'EEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEE', true, true);

insert into public.map (
  id, map_name, slug, width_elmos, height_elmos, world_height_min, world_height_max,
  appearance, curated_tags, source_hash, source_archive, catalog_version, facts_digest
)
values
  ('11111111-0000-4000-8000-0000000000a1', 'Mirror Map 1.0', 'mirror-map-1-0', 4096, 4096, 0, 100,
    '{}'::jsonb, '{}', 'src-mm', 'mirror_map_1.0.sd7', 1, 'digest-mm'),
  ('11111111-0000-4000-8000-0000000000a2', 'Pending Map 1.0', 'pending-map-1-0', 4096, 4096, 0, 100,
    '{}'::jsonb, '{}', 'src-pm', 'pending_map_1.0.sd7', 1, 'digest-pm'),
  ('11111111-0000-4000-8000-0000000000a3', 'Bare Map 1.0', 'bare-map-1-0', 4096, 4096, 0, 100,
    '{}'::jsonb, '{}', 'src-bm', 'bare_map_1.0.sd7', 1, 'digest-bm');

insert into public.map_download_source (map_id, kind, value, filename)
values ('11111111-0000-4000-8000-0000000000a1', 'url', 'https://owner.example.test/mm.sd7', 'mm.sd7');

insert into public.game (id, shortname)
values
  ('0f8fad5b-0008-4000-8000-000000000001', 'MG'),
  ('0f8fad5b-0008-4000-8000-000000000002', 'NG');

insert into public.game_download_source (game_id, kind, value)
values ('0f8fad5b-0008-4000-8000-000000000001', 'rapid', 'mg:stable');

-- An older listed copy of the map, which the newer one replaces.
insert into public.archive (id, sha256, filename, kind, internal_name, version, size, state, listed_at, shortname, game_id)
values
  ('aaaaaaaa-0000-4000-8000-000000000001', repeat('1', 64), 'old.sd7', 'map', 'Mirror Map 1.0', '0', 10, 'listed', now() - interval '2 days', null, null),
  ('aaaaaaaa-0000-4000-8000-000000000002', repeat('a', 64), 'Test Map (v1.2) é.sd7', 'map', 'Mirror Map 1.0', '1', 10, 'listed', now() - interval '1 day', null, null),
  ('aaaaaaaa-0000-4000-8000-000000000003', repeat('b', 64), 'pending.sd7', 'map', 'Pending Map 1.0', '1', 10, 'uploading', null, null, null),
  ('aaaaaaaa-0000-4000-8000-000000000004', repeat('c', 64), 'mg_1.0.sdz', 'game', 'Mirror Game', '1.0', 10, 'listed', now() - interval '1 day', 'MG', '0f8fad5b-0008-4000-8000-000000000001');

insert into public.archive_mirror (archive_id, mirror_id)
values
  ('aaaaaaaa-0000-4000-8000-000000000001', 'aa-mirror'),
  ('aaaaaaaa-0000-4000-8000-000000000002', 'zz-mirror'),
  ('aaaaaaaa-0000-4000-8000-000000000002', 'aa-mirror'),
  ('aaaaaaaa-0000-4000-8000-000000000002', 'off-mirror'),
  ('aaaaaaaa-0000-4000-8000-000000000002', 'sick-mirror'),
  ('aaaaaaaa-0000-4000-8000-000000000003', 'aa-mirror'),
  ('aaaaaaaa-0000-4000-8000-000000000004', 'zz-mirror'),
  ('aaaaaaaa-0000-4000-8000-000000000004', 'aa-mirror'),
  ('aaaaaaaa-0000-4000-8000-000000000004', 'sick-mirror');

-- ## Maps, as the hub reads them

set local role service_role;

select is(
  public.map_mirror_sources('Mirror Map 1.0'),
  '[{"kind": "url", "value": "https://a.example.test/maps/Test%20Map%20%28v1.2%29%20%C3%A9.sd7",
     "filename": "Test Map (v1.2) é.sd7", "sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"},
    {"kind": "url", "value": "https://z.example.test/files/maps/Test%20Map%20%28v1.2%29%20%C3%A9.sd7",
     "filename": "Test Map (v1.2) é.sd7", "sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}]'::jsonb,
  'a map lists the newest archive on each enabled, healthy mirror holding it, by mirror id'
);

select is(
  public.map_mirror_sources('Pending Map 1.0'),
  '[]'::jsonb,
  'an archive still uploading is not listed'
);

select is(
  public.map_mirror_sources('Bare Map 1.0'),
  '[]'::jsonb,
  'a map with no archive gets an empty list'
);

select is(
  (select entry.value -> 'facts' -> 'downloads'
   from jsonb_array_elements(public.map_facts(array['Mirror Map 1.0'])) as entry(value)),
  '[{"kind": "url", "value": "https://owner.example.test/mm.sd7", "filename": "mm.sd7"},
    {"kind": "url", "value": "https://a.example.test/maps/Test%20Map%20%28v1.2%29%20%C3%A9.sd7",
     "filename": "Test Map (v1.2) é.sd7", "sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"},
    {"kind": "url", "value": "https://z.example.test/files/maps/Test%20Map%20%28v1.2%29%20%C3%A9.sd7",
     "filename": "Test Map (v1.2) é.sd7", "sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}]'::jsonb,
  'map_facts lists the owner source first, then the mirror copies, with sha256'
);

select is(
  (select entry.value -> 'facts' -> 'downloads'
   from jsonb_array_elements(public.map_facts(array['Bare Map 1.0'])) as entry(value)),
  '[]'::jsonb,
  'map_facts gives an empty list when there is nothing to download'
);

select is(
  (select entry.value -> 'facts' -> 'downloads'
   from jsonb_array_elements(public.map_facts(array['Pending Map 1.0'])) as entry(value)),
  '[]'::jsonb,
  'map_facts does not list an archive that is still uploading'
);

reset role;

-- A mirror that turns unhealthy or is disabled drops out.
update public.mirror set healthy = false where id = 'zz-mirror';
update public.mirror set enabled = false where id = 'aa-mirror';

select is(
  public.map_mirror_sources('Mirror Map 1.0'),
  '[]'::jsonb,
  'an unhealthy or disabled mirror drops out'
);

update public.mirror set healthy = true, enabled = true where id in ('zz-mirror', 'aa-mirror');

-- ## Anonymous visitors

set local role anon;

select is(
  jsonb_array_length(public.map_mirror_sources('Mirror Map 1.0')),
  2,
  'anon can call map_mirror_sources'
);

select throws_ok(
  $$select * from public.archive$$,
  '42501',
  null,
  'anon cannot select from public.archive'
);

select throws_ok(
  $$select * from public.archive_mirror$$,
  '42501',
  null,
  'anon cannot select from public.archive_mirror'
);

-- ## Games

select is(
  (select downloads from public.game_browse where shortname = 'MG'),
  '[{"kind": "rapid", "value": "mg:stable"},
    {"kind": "url", "value": "https://a.example.test/games/mg_1.0.sdz", "filename": "mg_1.0.sdz",
     "sha256": "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc"},
    {"kind": "url", "value": "https://z.example.test/files/games/mg_1.0.sdz", "filename": "mg_1.0.sdz",
     "sha256": "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc"}]'::jsonb,
  'anon sees a game''s downloads ending with its healthy mirror copies'
);

select is(
  (select downloads from public.game_browse where shortname = 'NG'),
  '[]'::jsonb,
  'a game with no sources and no archive still has an empty list'
);

select is(
  public.game_mirror_sources('0f8fad5b-0008-4000-8000-000000000002'),
  '[]'::jsonb,
  'game_mirror_sources is empty for a game with no archive'
);

reset role;

select is(
  (select array_agg(column_name::text order by ordinal_position)
   from information_schema.columns
   where table_schema = 'public' and table_name = 'game_browse'),
  array['shortname', 'display_name', 'description', 'faction_count', 'unit_count', 'item_count',
        'logo_path', 'logo_hash', 'logo_staged_tier', 'featured_at', 'downloads',
        'card_path', 'card_hash', 'card_staged_tier', 'banner_path', 'banner_hash',
        'banner_staged_tier', 'base_game'],
  'game_browse keeps its columns and their order'
);

select is(
  (select has_function_privilege('anon', 'public.map_facts(text[])', 'execute')),
  false,
  'anon still cannot execute map_facts'
);

select * from finish();
rollback;
