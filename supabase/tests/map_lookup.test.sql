-- What the hub answers about a map it holds (issue #188).
--
-- public.map_facts assembles the answer, because the answer is spread across
-- four tables and two of its parts cannot be built anywhere else: the alias hop
-- that decides which key a credit counts under, and the count of spellings that
-- decides what a merged author is called. So this is where those are proved,
-- against real rows.
--
-- The parsing, the request order and the cap are the route's and are tested in
-- lib/api/mapLookup.test.ts. The licence gate is the route's too: it reads
-- public.asset_licence, which service_role alone may read, and it applies
-- lib/assets/licence.ts rather than a second copy of that rule, so it is proved
-- in lib/maps/lookup.test.ts.
--
-- The failures the rules here can have are quiet ones. An author who stays
-- split across two keys still answers, under a key that lists half his maps. A
-- name taken from whichever archive was read first renames a mapper from map to
-- map. Points regrouped in the wrong order move a team's spawn. None of them
-- raises anything.

begin;
select plan(23);

create extension if not exists pgtap with schema extensions;

-- One map with everything on it, and one with nothing beyond what the table
-- demands. 6144 by 10240 elmos is twenty squares on the longer edge, so the
-- tags below are the view's own answer rather than a number chosen here.
insert into public.map (
  map_name, slug, display_name, description,
  width_elmos, height_elmos, world_height_min, world_height_max,
  min_wind, max_wind, tidal_strength, void_water, water_coverage,
  appearance, curated_tags, source_hash, source_archive, catalog_version, facts_digest
)
values
  ('Comet Catcher Remake 1.8', 'comet-catcher-remake-1-8', 'Comet Catcher Remake',
    'A remake of an old favourite.',
    6144, 10240, -120.5, 890,
    5, 40, 18, false, 0.31,
    '{"water": {"absorb": [0.1, 0.2, 0.3]}}'::jsonb, '{}',
    'src-comet', 'comet_catcher_remake_1.8.sd7', 3, 'digest-comet'),

  ('Quiet 1.0', 'quiet', null, null,
    4096, 4096, 0, 320,
    null, null, null, null, null,
    '{}'::jsonb, '{}',
    'src-quiet', 'quiet_1.0.sd7', 1, 'digest-quiet'),

  -- Four maps by one mapper, spelled three ways, and one of them under a key
  -- only a maintainer can merge.
  ('Alpha 1.0', 'alpha', null, null, 4096, 4096, 0, 320,
    null, null, null, null, null, '{}'::jsonb, '{}',
    'src-alpha', 'alpha_1.0.sd7', 1, 'digest-alpha'),
  ('Bravo 1.0', 'bravo', null, null, 4096, 4096, 0, 320,
    null, null, null, null, null, '{}'::jsonb, '{}',
    'src-bravo', 'bravo_1.0.sd7', 1, 'digest-bravo'),
  ('Charlie 1.0', 'charlie', null, null, 4096, 4096, 0, 320,
    null, null, null, null, null, '{}'::jsonb, '{}',
    'src-charlie', 'charlie_1.0.sd7', 1, 'digest-charlie'),
  ('Delta 1.0', 'delta', null, null, 4096, 4096, 0, 320,
    null, null, null, null, null, '{}'::jsonb, '{}',
    'src-delta', 'delta_1.0.sd7', 1, 'digest-delta'),

  -- One map crediting the same person twice, under the two keys.
  ('Echo 1.0', 'echo', null, null, 4096, 4096, 0, 320,
    null, null, null, null, null, '{}'::jsonb, '{}',
    'src-echo', 'echo_1.0.sd7', 1, 'digest-echo'),

  -- Two people, credited in an order that is not alphabetical.
  ('Foxtrot 1.0', 'foxtrot', null, null, 4096, 4096, 0, 320,
    null, null, null, null, null, '{}'::jsonb, '{}',
    'src-foxtrot', 'foxtrot_1.0.sd7', 1, 'digest-foxtrot');

-- Points inserted out of order deliberately. The answer is ordered by ordinal,
-- which is the team index on a start position, so a query that returned them in
-- insertion order would move a team's spawn without anything looking wrong.
insert into public.map_point (map_id, kind, ordinal, x, z, y, meta)
select map.id, point.kind, point.ordinal, point.x, point.z, point.y, point.meta
from public.map as map
cross join (values
  ('start', 1, 5632::real, 9728::real, null::real, null::jsonb),
  ('start', 0, 512::real, 512::real, null::real, null::jsonb),
  ('metal', 0, 1024::real, 2048::real, 40::real, '{"amount": 2, "radius": 48}'::jsonb)
) as point(kind, ordinal, x, z, y, meta)
where map.map_name = 'Comet Catcher Remake 1.8';

-- The credits, as the submission route writes them: the archive's own spelling
-- in raw, and the key the hub filed it under at the time.
insert into public.map_author (map_id, credit_index, raw, key)
select map.id, credit.credit_index, credit.raw, credit.key
from public.map as map
join (values
  ('Comet Catcher Remake 1.8', 0, 'Beherith', 'beherith'),
  ('Alpha 1.0', 0, 'Beherith', 'beherith'),
  ('Bravo 1.0', 0, 'Beherith', 'beherith'),
  ('Delta 1.0', 0, 'beherith', 'beherith'),
  ('Charlie 1.0', 0, 'Behe', 'behe'),
  ('Echo 1.0', 0, 'Behe', 'behe'),
  ('Echo 1.0', 1, 'Beherith', 'beherith'),
  ('Foxtrot 1.0', 0, 'Zeta', 'zeta'),
  ('Foxtrot 1.0', 1, 'Alpha Mapper', 'alpha mapper')
) as credit(map_name, credit_index, raw, key) on credit.map_name = map.map_name;

-- One map's facts, the way the route reads them out of the answer.
create function pg_temp.facts(p_name text) returns jsonb language sql as $$
  select entry.value -> 'facts'
  from jsonb_array_elements(public.map_facts(array[p_name])) as entry(value)
  where entry.value ->> 'map_name' = p_name;
$$;

-- ## The measurements

-- Asserted whole rather than field by field, so a column that stopped coming
-- back fails here rather than being noticed by a client. The reals are cast on
-- the expected side as well, because a value stored as real and one written as
-- a decimal literal are not the same number.
select is(
  pg_temp.facts('Comet Catcher Remake 1.8') - 'authors' - 'tags' - 'points' - 'appearance',
  jsonb_build_object(
    'slug', 'comet-catcher-remake-1-8',
    'display_name', 'Comet Catcher Remake',
    'description', 'A remake of an old favourite.',
    'width_elmos', 6144,
    'height_elmos', 10240,
    'world_height_min', (-120.5)::real,
    'world_height_max', (890)::real,
    'min_wind', (5)::real,
    'max_wind', (40)::real,
    'tidal_strength', (18)::real,
    'void_water', false,
    'water_coverage', (0.31)::real
  ),
  'a known map answers with the measurements the columns hold'
);

-- The columns are real, and a real widened on its way out would reach a client
-- as 0.3100000023841858. A caller showing a share of water as a percentage
-- would print eight digits nobody measured.
select is(
  pg_temp.facts('Comet Catcher Remake 1.8') ->> 'water_coverage',
  '0.31',
  'a measurement arrives as the number that was stored rather than widened on the way out'
);

-- An archive that filled in neither. Both are display only and map_name is what
-- a listing falls back to, so a null is an answer rather than a gap.
select is(
  pg_temp.facts('Quiet 1.0') -> 'display_name',
  'null'::jsonb,
  'a map whose archive named it nothing answers null rather than dropping the field'
);

-- Only the 3D view reads this, and nothing here has an opinion about what is in
-- it, so it comes back as it was stored.
select is(
  pg_temp.facts('Comet Catcher Remake 1.8') -> 'appearance',
  '{"water": {"absorb": [0.1, 0.2, 0.3]}}'::jsonb,
  'the appearance blob passes through whole'
);

-- ## Tags

-- Off public.map_listing, never recomputed. Twenty squares on the longer edge
-- is medium, a third of the map under the water line is a water map, and wind
-- averaging 22.5 beats what a solar collector makes.
select is(
  pg_temp.facts('Comet Catcher Remake 1.8') -> 'tags',
  '["medium", "water map", "windy"]'::jsonb,
  'the tags are the view''s, merged and sorted, so a caller never sees which half a tag came from'
);

select is(
  pg_temp.facts('Quiet 1.0') -> 'tags',
  '["small"]'::jsonb,
  'and a map that declared nothing still gets its size band'
);

-- ## Points

select is(
  pg_temp.facts('Comet Catcher Remake 1.8') -> 'points' -> 'start',
  '[{"x": 512, "z": 512, "y": null, "meta": null},
    {"x": 5632, "z": 9728, "y": null, "meta": null}]'::jsonb,
  'start positions come back in ordinal order, which is the team index and is the fact'
);

-- A metal spot without its amount and radius is no use to anything drawing one,
-- and meta is where the archive puts them.
select is(
  pg_temp.facts('Comet Catcher Remake 1.8') -> 'points' -> 'metal',
  '[{"x": 1024, "z": 2048, "y": 40, "meta": {"amount": 2, "radius": 48}}]'::jsonb,
  'a metal spot carries its height and whatever its kind puts in meta'
);

select is(
  pg_temp.facts('Comet Catcher Remake 1.8') -> 'points' -> 'geo',
  '[]'::jsonb,
  'a kind the map has none of is an empty array, so a caller never asks whether the key is there'
);

select is(
  pg_temp.facts('Quiet 1.0') -> 'points',
  '{"start": [], "metal": [], "geo": []}'::jsonb,
  'and a map with no points at all still answers with all three kinds'
);

-- ## Authors

select is(
  pg_temp.facts('Comet Catcher Remake 1.8') -> 'authors',
  '[{"key": "beherith", "name": "Beherith"}]'::jsonb,
  'a credited mapper comes back as the key the hub files him under and the name it shows him as'
);

select is(
  pg_temp.facts('Quiet 1.0') -> 'authors',
  '[]'::jsonb,
  'a map the archive credited nobody for answers an empty list rather than null'
);

-- The order is the archive's, which is not alphabetical and is not the hub's to
-- reorder.
select is(
  pg_temp.facts('Foxtrot 1.0') -> 'authors',
  '[{"key": "zeta", "name": "Zeta"},
    {"key": "alpha mapper", "name": "Alpha Mapper"}]'::jsonb,
  'two people come back in the order the archive credited them'
);

-- ## The alias hop, which is why this is a read time resolution

-- public.map_author.key is resolved when a submission is written, so before a
-- maintainer records the merge this map's credit counts on its own.
select is(
  pg_temp.facts('Charlie 1.0') -> 'authors',
  '[{"key": "behe", "name": "Behe"}]'::jsonb,
  'a key with no alias answers as itself'
);

insert into public.author_alias (from_key, to_key, note)
values ('behe', 'beherith', 'Same person, said so in the map thread.');

-- The stored key has not changed and will not until the map is submitted again.
-- A read that trusted the column would go on splitting one mapper across two
-- keys for as long as nobody resubmitted, which is forever for an archive
-- nobody has installed.
select is(
  (select array_agg(author.key) from public.map_author as author
    join public.map as map on map.id = author.map_id
    where map.map_name = 'Charlie 1.0'),
  ARRAY['behe'],
  'recording a merge does not rewrite the stored key, which is the evidence for it'
);

select is(
  pg_temp.facts('Charlie 1.0') -> 'authors' -> 0 ->> 'key',
  'beherith',
  'and the lookup answers under the merged key the moment the alias exists'
);

-- The name is the most common raw spelling among that author's maps, which is
-- the rule #183 set for the author's own page. Beherith is on two of his maps,
-- beherith on one and Behe on two, so the merged author is shown as Beherith
-- and a caller that links through to the hub renames nobody.
select is(
  pg_temp.facts('Charlie 1.0') -> 'authors' -> 0 ->> 'name',
  'Beherith',
  'and under the spelling most of his maps credit him by, not the one this archive used'
);

-- One map crediting the same person under both keys is one author. Listing him
-- twice would put the same mapper on the page twice, and the earliest credit
-- index is what carries his place in the order.
select is(
  pg_temp.facts('Echo 1.0') -> 'authors',
  '[{"key": "beherith", "name": "Beherith"}]'::jsonb,
  'two credits on one map that resolve to one person are one author'
);

-- ## Names the hub holds nothing for

select is(
  public.map_facts(ARRAY['Never Heard Of It 1.0']),
  '[]'::jsonb,
  'a name the hub has no row for is absent, which is the null the route answers with'
);

select is(
  (select count(*) from jsonb_array_elements(
    public.map_facts(ARRAY['Quiet 1.0', 'Never Heard Of It 1.0', 'Alpha 1.0'])))::int,
  2,
  'a batch answers for the names it holds and says nothing about the rest'
);

select is(
  public.map_facts(ARRAY[]::text[]),
  '[]'::jsonb,
  'and an empty request is an empty answer rather than the whole catalog'
);

-- ## Access
--
-- The route holds the secret key because the licence gate needs it, and the
-- gate is the other half of this answer. A caller that could reach the function
-- with the publishable key would read the facts of a map that has been taken
-- down.
select function_privs_are('public', 'map_facts', ARRAY['text[]'], 'service_role',
  ARRAY['EXECUTE'],
  'the lookup route can call map_facts');

select function_privs_are('public', 'map_facts', ARRAY['text[]'], 'anon',
  ARRAY[]::name[],
  'and a browser holding the publishable key cannot, because the licence gate is not in it');

select * from finish();
rollback;
