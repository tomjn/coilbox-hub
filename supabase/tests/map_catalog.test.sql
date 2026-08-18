-- The shape of the map catalog (issue #182). Six tables, and the rules that
-- keep them honest are constraints rather than code.
--
-- Nothing in TypeScript can enforce them, and the failures they prevent are all
-- of the quiet kind: a second row under one canonical name splits a map's facts
-- in two and neither half is wrong to look at, a point kind nobody reads draws
-- nothing, and a world height range the wrong way round reads every sample
-- upside down while looking entirely plausible. So they are asserted here.
--
-- Grants and policies are not tested here. map_access.test.sql covers the
-- behaviour and table_privileges.test.sql asserts the grants directly.

begin;
select plan(38);

create extension if not exists pgtap with schema extensions;

-- Somebody to have submitted one, so the account deletion behaviour has a real
-- foreign key to exercise rather than a null.
insert into auth.users (id, instance_id, aud, role, email)
values ('44444444-4444-4444-4444-444444444444', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'mapper@example.test');

-- The happy path first, so a later failure can be told apart from the table
-- refusing everything.
select lives_ok(
  $$insert into public.map (id, map_name, slug, display_name, map_version, archive_filename, width_elmos, height_elmos, world_height_min, world_height_max, min_wind, max_wind, tidal_strength, void_water, void_ground, water_coverage, source_hash, source_archive, catalog_version, facts_digest, submitted_by)
    values ('0f8fad5b-0001-4000-8000-000000000001', 'Comet Catcher Remake 1.8', 'comet-catcher-remake-1-8', 'Comet Catcher Remake', '1.8', 'comet_catcher_remake_1.8.sd7', 8192, 8192, -40.5, 320.25, 5, 25, 20, false, false, 0.42, 'src-comet', 'comet_catcher_remake_1.8.sd7', 1, 'digest-comet', '44444444-4444-4444-4444-444444444444')$$,
  'a map is one row of facts under one canonical name'
);

-- Identity. The full name the engine reports is the key, so a second row under
-- it would split one map's facts in two with nothing to say which half to read.
select throws_ok(
  $$insert into public.map (map_name, slug, width_elmos, height_elmos, world_height_min, world_height_max, source_hash, source_archive, catalog_version, facts_digest)
    values ('Comet Catcher Remake 1.8', 'comet-catcher-remake-1-8-again', 8192, 8192, 0, 320, 'src-dup', 'a.sd7', 1, 'digest-dup')$$,
  '23505',
  null,
  'a second row under the same canonical name is refused'
);

-- The version string stays in the name, so a revision is a different map and
-- gets its own row rather than replacing the one before it.
select lives_ok(
  $$insert into public.map (id, map_name, slug, width_elmos, height_elmos, world_height_min, world_height_max, source_hash, source_archive, catalog_version, facts_digest)
    values ('0f8fad5b-0001-4000-8000-000000000002', 'Comet Catcher Remake 1.9', 'comet-catcher-remake-1-9', 8192, 8192, 0, 320, 'src-comet-19', 'comet_catcher_remake_1.9.sd7', 1, 'digest-comet-19')$$,
  'a later revision of a map is its own row, not a replacement'
);

-- A slug addresses a map, so two maps sharing one make a URL ambiguous.
select throws_ok(
  $$insert into public.map (map_name, slug, width_elmos, height_elmos, world_height_min, world_height_max, source_hash, source_archive, catalog_version, facts_digest)
    values ('Tangerine 1.1', 'comet-catcher-remake-1-8', 4096, 4096, 0, 320, 'src-t', 'a.sd7', 1, 'digest-t')$$,
  '23505',
  null,
  'two maps cannot share a slug'
);

-- An empty name satisfies not null and mints an identity nothing can look up.
select throws_ok(
  $$insert into public.map (map_name, slug, width_elmos, height_elmos, world_height_min, world_height_max, source_hash, source_archive, catalog_version, facts_digest)
    values ('   ', 'blank', 4096, 4096, 0, 320, 'src-b', 'a.sd7', 1, 'digest-b')$$,
  '23514',
  null,
  'a blank map name is refused rather than stored as an identity'
);

-- Only the archive has the world heights, so a row without them can never be
-- repaired from anything downstream.
select throws_ok(
  $$insert into public.map (map_name, slug, width_elmos, height_elmos, world_height_min, source_hash, source_archive, catalog_version, facts_digest)
    values ('Tangerine 1.1', 'tangerine-1-1', 4096, 4096, 0, 'src-t2', 'a.sd7', 1, 'digest-t2')$$,
  '23502',
  null,
  'a map without its world height range is refused'
);

select throws_ok(
  $$insert into public.map (map_name, slug, width_elmos, height_elmos, world_height_min, world_height_max, source_hash, source_archive, catalog_version, facts_digest)
    values ('Tangerine 1.1', 'tangerine-1-1', 4096, 4096, 320, 0, 'src-t3', 'a.sd7', 1, 'digest-t3')$$,
  '23514',
  null,
  'a reversed world height range is refused'
);

-- A flat map has one height, so the ends may meet.
select lives_ok(
  $$insert into public.map (map_name, slug, width_elmos, height_elmos, world_height_min, world_height_max, source_hash, source_archive, catalog_version, facts_digest)
    values ('Flatland 1.0', 'flatland-1-0', 1024, 1024, 100, 100, 'src-flat', 'flatland_1.0.sd7', 1, 'digest-flat')$$,
  'a flat map may have a height range of no width'
);

select throws_ok(
  $$insert into public.map (map_name, slug, width_elmos, height_elmos, world_height_min, world_height_max, min_wind, max_wind, source_hash, source_archive, catalog_version, facts_digest)
    values ('Windy 1.0', 'windy-1-0', 1024, 1024, 0, 320, 25, 5, 'src-wind', 'a.sd7', 1, 'digest-wind')$$,
  '23514',
  null,
  'a reversed wind range is refused'
);

-- A map with no sea has no share of it to report, and a number here would be a
-- second answer to a question void_water has already answered.
select throws_ok(
  $$insert into public.map (map_name, slug, width_elmos, height_elmos, world_height_min, world_height_max, void_water, water_coverage, source_hash, source_archive, catalog_version, facts_digest)
    values ('Dry 1.0', 'dry-1-0', 1024, 1024, 0, 320, true, 0.0, 'src-dry', 'a.sd7', 1, 'digest-dry')$$,
  '23514',
  null,
  'a void water map cannot also report how much water it has'
);

select lives_ok(
  $$insert into public.map (map_name, slug, width_elmos, height_elmos, world_height_min, world_height_max, void_water, source_hash, source_archive, catalog_version, facts_digest)
    values ('Dry 1.0', 'dry-1-0', 1024, 1024, 0, 320, true, 'src-dry', 'dry_1.0.sd7', 1, 'digest-dry')$$,
  'and simply leaves the share unreported'
);

-- A fraction, not a percentage, so a reader never has to guess which.
select throws_ok(
  $$insert into public.map (map_name, slug, width_elmos, height_elmos, world_height_min, world_height_max, water_coverage, source_hash, source_archive, catalog_version, facts_digest)
    values ('Wet 1.0', 'wet-1-0', 1024, 1024, 0, 320, 42, 'src-wet', 'a.sd7', 1, 'digest-wet')$$,
  '23514',
  null,
  'a water share outside zero to one is refused'
);

select is(
  (select appearance from public.map where slug = 'flatland-1-0'), '{}'::jsonb,
  'a map with nothing recorded about how it looks carries an empty object, not a null'
);

select is(
  (select curated_tags from public.map where slug = 'flatland-1-0'), '{}'::text[],
  'and no curated tags until somebody adds one'
);

select has_trigger('public', 'map', 'map_touch_updated_at',
  'updated_at is maintained by the table, not trusted from whoever wrote the row');

-- ## Points

select lives_ok(
  $$insert into public.map_point (map_id, kind, ordinal, x, z, y, meta)
    values
      ('0f8fad5b-0001-4000-8000-000000000001', 'start', 0, 512, 512, null, null),
      ('0f8fad5b-0001-4000-8000-000000000001', 'start', 1, 7680, 7680, null, null),
      ('0f8fad5b-0001-4000-8000-000000000001', 'metal', 0, 1024, 980, 42.5, '{"amount": 2.4, "radius": 48}'),
      ('0f8fad5b-0001-4000-8000-000000000001', 'geo', 0, 4096, 4096, 30, '{"feature": "geovent"}')$$,
  'the three kinds of point a map has all sit in one table'
);

-- A kind nobody reads draws nothing, and the symptom is a missing marker rather
-- than an error.
select throws_ok(
  $$insert into public.map_point (map_id, kind, ordinal, x, z)
    values ('0f8fad5b-0001-4000-8000-000000000001', 'spawn', 2, 100, 100)$$,
  '23514',
  null,
  'a point kind outside the vocabulary is refused'
);

-- Re-reading an archive replaces a map's points, so the same slot twice is a
-- doubled set rather than a correction.
select throws_ok(
  $$insert into public.map_point (map_id, kind, ordinal, x, z)
    values ('0f8fad5b-0001-4000-8000-000000000001', 'start', 0, 200, 200)$$,
  '23505',
  null,
  'one point per position per kind'
);

select lives_ok(
  $$insert into public.map_point (map_id, kind, ordinal, x, z)
    values ('0f8fad5b-0001-4000-8000-000000000001', 'metal', 1, 3000, 3000)$$,
  'and the ordinals of one kind say nothing about another kind'
);

-- The engine resolves a spawn height from the terrain rather than storing one.
select is(
  (select y from public.map_point
    where map_id = '0f8fad5b-0001-4000-8000-000000000001' and kind = 'start' and ordinal = 0),
  null,
  'a start position has no height of its own, and that is not a missing value'
);

-- The reason the metal spots are rows: counting them is a count.
select is(
  (select count(*) from public.map_point
    where map_id = '0f8fad5b-0001-4000-8000-000000000001' and kind = 'metal')::int,
  2,
  'how many metal spots a map has is a count on an indexed column'
);

-- ## Authors

select lives_ok(
  $$insert into public.map_author (map_id, credit_index, raw, key)
    values
      ('0f8fad5b-0001-4000-8000-000000000001', 0, 'Jools', 'jools'),
      ('0f8fad5b-0001-4000-8000-000000000001', 1, '[TA]Bob', 'bob')$$,
  'a map keeps every credit the archive gave, in the order it gave them'
);

select throws_ok(
  $$insert into public.map_author (map_id, credit_index, raw, key)
    values ('0f8fad5b-0001-4000-8000-000000000001', 0, 'Somebody Else', 'somebody-else')$$,
  '23505',
  null,
  'and one credit per position, so re-reading an archive rewrites them rather than appending'
);

-- The raw string is the evidence and the key is what the hub groups on, so both
-- are kept and neither is derived from the other at read time.
select is(
  (select raw from public.map_author
    where map_id = '0f8fad5b-0001-4000-8000-000000000001' and key = 'bob'),
  '[TA]Bob',
  'a mapper keeps their own spelling of their name alongside the key the hub files it under'
);

-- ## Aliases

select lives_ok(
  $$insert into public.author_alias (from_key, to_key, note, set_by)
    values ('bob', 'bobtheta', 'Same person, signed one map with a clan tag', '44444444-4444-4444-4444-444444444444')$$,
  'a maintainer can record that two keys are one person'
);

select throws_ok(
  $$insert into public.author_alias (from_key, to_key) values ('jools', 'jools')$$,
  '23514',
  null,
  'an alias to itself is a hop to nowhere and is refused'
);

-- The merge is a fact about the catalog and outlives the account that made it.
select is(
  (select count(*) from public.map_author
    where map_id = '0f8fad5b-0001-4000-8000-000000000001' and key = 'bob')::int,
  1,
  'and recording it leaves the credit exactly as it was computed, so the evidence survives the merge'
);

-- ## Mirrors

select lives_ok(
  $$insert into public.map_mirror_host (name, url_template, note)
    values ('Example mirror', 'https://mirror.example.test/maps/{archive_filename}', 'A stand in for a real one')$$,
  'a download host is a row rather than a constant'
);

select is(
  (select enabled from public.map_mirror_host where name = 'Example mirror'), true,
  'a mirror is offered as soon as it is added, and turned off rather than deleted'
);

select throws_ok(
  $$insert into public.map_mirror_host (name, url_template)
    values ('Example mirror', 'https://other.example.test/{archive_filename}')$$,
  '23505',
  null,
  'one row per host, so turning a mirror off cannot leave a second copy enabled'
);

-- ## Conflicts

insert into public.map_source_conflict (map_id, source_archive, held_source_hash, reported_source_hash, reported_by)
values ('0f8fad5b-0001-4000-8000-000000000001', 'comet_catcher_remake_1.8.sd7', 'src-comet', 'src-other', '44444444-4444-4444-4444-444444444444');

select throws_ok(
  $$insert into public.map_source_conflict (map_id, source_archive, held_source_hash, reported_source_hash)
    values ('0f8fad5b-0001-4000-8000-000000000001', 'comet_catcher_remake_1.8.sd7', 'src-comet', 'src-other')$$,
  '23505',
  null,
  'the same reported facts again are the same record, not a second one'
);

select throws_ok(
  $$insert into public.map_source_conflict (map_id, source_archive, held_source_hash, reported_source_hash)
    values ('0f8fad5b-0001-4000-8000-000000000001', 'comet_catcher_remake_1.8.sd7', 'src-comet', 'src-comet')$$,
  '23514',
  null,
  'hashes that agree are not a disagreement'
);

-- ## What the catalog is not keyed to

-- A minimap can arrive years before anybody submits the facts, or years after.
-- A foreign key in either direction would refuse whichever came first, so there
-- is none and both sides key on map_name.
select lives_ok(
  $$insert into public.asset (map_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, map_width, map_height, source_archive)
    values ('Nobody Has The Facts 1.0', 'minimap', 'src-orphan', 'enc-orphan', 'webp-q80-512', 'map/orphan/minimap/enc-orphan.webp', 'extracted', 'image/webp', 40000, 512, 512, 4096, 4096, 'nobody.sd7')$$,
  'a picture of a map the catalog has never heard of is an ordinary row'
);

select is(
  (select count(*) from public.asset where map_name = 'Flatland 1.0')::int, 0,
  'and a map the catalog holds needs no picture to exist'
);

-- ## Cascades

-- A point and a credit are facts about their map and nothing else, so they go
-- with it. Nothing deletes a map today, which is why no role holds delete on it.
insert into public.map_point (map_id, kind, ordinal, x, z)
values ('0f8fad5b-0001-4000-8000-000000000002', 'start', 0, 10, 10);

insert into public.map_author (map_id, credit_index, raw, key)
values ('0f8fad5b-0001-4000-8000-000000000002', 0, 'Jools', 'jools');

delete from public.map where slug = 'comet-catcher-remake-1-9';

select is(
  (select count(*) from public.map_point
    where map_id = '0f8fad5b-0001-4000-8000-000000000002')::int,
  0,
  'deleting a map takes its points with it, since a point about no map says nothing'
);

select is(
  (select count(*) from public.map_author
    where map_id = '0f8fad5b-0001-4000-8000-000000000002')::int,
  0,
  'and its credits, which describe that map and no other'
);

-- Closing an account must not take the catalog with it, the same as an asset.
delete from auth.users where id = '44444444-4444-4444-4444-444444444444';

select is(
  (select count(*) from public.map where slug = 'comet-catcher-remake-1-8')::int, 1,
  'deleting the submitter leaves the map in place'
);

select is(
  (select submitted_by from public.map where slug = 'comet-catcher-remake-1-8'), null,
  'and forgets who submitted it'
);

select * from finish();
rollback;
