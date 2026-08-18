-- The tags a map earns from what was measured (issue #184).
--
-- public.map_listing turns measurements into the words a reader browses by, and
-- every rule in it is a threshold. A threshold is exactly the kind of code that
-- looks right and is off by one: a boundary that should exclude and includes, a
-- null that reads as a zero, an average taken over the wrong pair of numbers.
-- None of those show up as an error. They show up as a map filed under the wrong
-- kind, which nobody notices until a player says so.
--
-- So every band is tested at its edge as well as inside it, and every rule is
-- tested with the measurement missing.
--
-- The whole tags array is asserted rather than one tag at a time. That proves
-- what a map does not get as well as what it does, and it proves the array is
-- sorted and deduplicated at the same time.
--
-- The grants are not tested here. table_privileges.test.sql asserts them
-- directly, the same as for every table in the catalog, and what this file
-- covers is that a visitor holding the publishable key really does read the view
-- through row level security.

begin;
select plan(29);

create extension if not exists pgtap with schema extensions;

-- One map per rule and per boundary. Every map is 4096 elmos square, which is
-- eight squares and therefore small, unless it is one of the maps testing the
-- size bands. That keeps the expected array short enough to read.
--
-- world_height_max is 320 throughout because nothing reads it. world_height_min
-- is the column that says whether a map has water at all, so it is 0 on the dry
-- maps and -40 on the wet ones.
insert into public.map (
  map_name, slug, width_elmos, height_elmos, world_height_min, world_height_max,
  min_wind, max_wind, tidal_strength, void_water, water_coverage, curated_tags,
  source_hash, source_archive, catalog_version, facts_digest
)
values
  -- No sea at all, and a tide nobody can build in.
  ('Void 1.0', 'void', 4096, 4096, 0, 320,
    null, null, 500, true, null, '{}',
    'src-void', 'void_1.0.sd7', 1, 'digest-void'),

  -- A fifth of the map under the line exactly, and a hair over it.
  ('Fifth 1.0', 'coverage-at-threshold', 4096, 4096, -40, 320,
    null, null, null, false, 0.20, '{}',
    'src-fifth', 'fifth_1.0.sd7', 1, 'digest-fifth'),
  ('Wetter 1.0', 'coverage-above', 4096, 4096, -40, 320,
    null, null, null, false, 0.21, '{}',
    'src-wetter', 'wetter_1.0.sd7', 1, 'digest-wetter'),

  -- Wind averaging 22.5, 12.5 and exactly 20.
  ('Windy 1.0', 'windy', 4096, 4096, 0, 320,
    5, 40, null, false, null, '{}',
    'src-windy', 'windy_1.0.sd7', 1, 'digest-windy'),
  ('Calm 1.0', 'not-windy', 4096, 4096, 0, 320,
    5, 20, null, false, null, '{}',
    'src-calm', 'calm_1.0.sd7', 1, 'digest-calm'),
  ('Middling 1.0', 'wind-at-threshold', 4096, 4096, 0, 320,
    10, 30, null, false, null, '{}',
    'src-middling', 'middling_1.0.sd7', 1, 'digest-middling'),

  -- A strong tide on a map with nowhere to put a tidal generator.
  ('Dry High Tide 1.0', 'dry-high-tide', 4096, 4096, 0, 320,
    null, null, 500, false, null, '{}',
    'src-dry-tide', 'dry_high_tide_1.0.sd7', 1, 'digest-dry-tide'),

  -- The same tide against wind averaging 35 and against wind averaging 25.
  ('Tide Loses 1.0', 'tide-loses', 4096, 4096, -40, 320,
    30, 40, 30, false, null, '{}',
    'src-tide-loses', 'tide_loses_1.0.sd7', 1, 'digest-tide-loses'),
  ('Tide Wins 1.0', 'tide-wins', 4096, 4096, -40, 320,
    10, 40, 30, false, null, '{}',
    'src-tide-wins', 'tide_wins_1.0.sd7', 1, 'digest-tide-wins'),

  -- A tide of exactly 20, which is what a solar collector makes.
  ('Tide At Threshold 1.0', 'tide-at-threshold', 4096, 4096, -40, 320,
    0, 0, 20, false, null, '{}',
    'src-tide-threshold', 'tide_at_threshold_1.0.sd7', 1, 'digest-tide-threshold'),

  -- Eight squares by twenty four, which is the map the issue names: small by
  -- area and large to play.
  ('Long Thin 1.0', 'eight-by-twentyfour', 4096, 12288, 0, 320,
    null, null, null, false, null, '{}',
    'src-long-thin', 'long_thin_1.0.sd7', 1, 'digest-long-thin'),

  -- The two band edges and the first square past each of them.
  ('Twelve 1.0', 'twelve-squares', 6144, 6144, 0, 320,
    null, null, null, false, null, '{}',
    'src-twelve', 'twelve_1.0.sd7', 1, 'digest-twelve'),
  ('Thirteen 1.0', 'thirteen-squares', 6656, 6656, 0, 320,
    null, null, null, false, null, '{}',
    'src-thirteen', 'thirteen_1.0.sd7', 1, 'digest-thirteen'),
  ('Twenty 1.0', 'twenty-squares', 10240, 10240, 0, 320,
    null, null, null, false, null, '{}',
    'src-twenty', 'twenty_1.0.sd7', 1, 'digest-twenty'),
  ('Twentyone 1.0', 'twentyone-squares', 10752, 10752, 0, 320,
    null, null, null, false, null, '{}',
    'src-twentyone', 'twentyone_1.0.sd7', 1, 'digest-twentyone'),

  -- An archive that declared none of it, which is ordinary.
  ('Unknown 1.0', 'nothing-known', 4096, 4096, 0, 320,
    null, null, null, null, null, '{}',
    'src-unknown', 'unknown_1.0.sd7', 1, 'digest-unknown'),

  -- Hand written tags, and hand written tags that repeat a measured one.
  ('Curated 1.0', 'curated', 4096, 4096, 0, 320,
    null, null, null, false, null, '{asymmetric,chokepoint}',
    'src-curated', 'curated_1.0.sd7', 1, 'digest-curated'),
  ('Curated Duplicate 1.0', 'curated-duplicate', 4096, 4096, 0, 320,
    null, null, null, false, null, '{small,asymmetric}',
    'src-curated-dup', 'curated_duplicate_1.0.sd7', 1, 'digest-curated-dup');

-- ## Water

-- void_water settles every water question on the row. The tide of 500 is there
-- to prove it settles the tidal question too, since a tidal generator has
-- nowhere to stand on a map with no sea.
--
-- "whatever its coverage" can only be tested with no coverage at all, because
-- map_void_water_coverage_check refuses a share of water alongside void water: a
-- number there would be a second answer to a question void_water has already
-- answered.
select is(
  (select tags from public.map_listing where slug = 'void'),
  ARRAY['small', 'void map']::text[],
  'a map with void water is a void map, and is neither a water map nor tidal however strong its tide'
);

-- Above a fifth, not at it. A map exactly on the line is the one a careless
-- comparison gets wrong, and it is also the one a float comparison gets wrong:
-- 0.20 stored as a real reads as fractionally more than 0.20 when both sides are
-- promoted to double precision.
select is(
  (select tags from public.map_listing where slug = 'coverage-at-threshold'),
  ARRAY['small']::text[],
  'a map with exactly a fifth of it under the water line is not a water map'
);

select is(
  (select tags from public.map_listing where slug = 'coverage-above'),
  ARRAY['small', 'water map']::text[],
  'and a map with more than a fifth is'
);

-- ## Wind

-- The midpoint of the range, which is the wind a generator makes over a game.
-- 5 and 40 average 22.5, so wind beats the 20 a solar collector makes.
select is(
  (select tags from public.map_listing where slug = 'windy'),
  ARRAY['small', 'windy']::text[],
  'a map whose wind averages above what a solar collector makes is windy'
);

-- 5 and 20 average 12.5. A rule reading max_wind instead of the average would
-- call this one windy on the strength of a single good minute.
select is(
  (select tags from public.map_listing where slug = 'not-windy'),
  ARRAY['small']::text[],
  'and a map whose wind peaks above 20 but averages below it is not'
);

select is(
  (select tags from public.map_listing where slug = 'wind-at-threshold'),
  ARRAY['small']::text[],
  'wind averaging exactly what solar makes is not worth a tag, since it beats nothing'
);

-- ## Tide

-- Any water at all, which is what world_height_min answers. This map declares no
-- void water and still has none, because nothing on it reaches below zero.
select is(
  (select tags from public.map_listing where slug = 'dry-high-tide'),
  ARRAY['small']::text[],
  'a map with no water is never tidal, however strong the tide, because there is nowhere to build'
);

-- Tidal has to be the best of the three, so a tide of 30 against wind averaging
-- 35 loses. The map is still windy, which is the point: the two tags are the
-- same question and only one of them can be the answer.
select is(
  (select tags from public.map_listing where slug = 'tide-loses'),
  ARRAY['small', 'windy']::text[],
  'a tide weaker than the average wind is not worth tagging, and the wind still is'
);

select is(
  (select tags from public.map_listing where slug = 'tide-wins'),
  ARRAY['small', 'tidal', 'windy']::text[],
  'and the same tide against weaker wind is tidal, on a map that is windy as well'
);

select is(
  (select tags from public.map_listing where slug = 'tide-at-threshold'),
  ARRAY['small']::text[],
  'a tide of exactly what solar makes beats nothing either, the same as the wind'
);

-- ## Size

-- The longer edge rather than the area, which is the whole reason this map is in
-- the fixtures. On area it is 192 squares and would band beside a 14x14.
select is(
  (select tags from public.map_listing where slug = 'eight-by-twentyfour'),
  ARRAY['large']::text[],
  'an eight by twenty four map bands as large, because it is the longer edge that plays large'
);

select is(
  (select tags from public.map_listing where slug = 'twelve-squares'),
  ARRAY['small']::text[],
  'a map whose longer edge is exactly twelve squares is small'
);

select is(
  (select tags from public.map_listing where slug = 'thirteen-squares'),
  ARRAY['medium']::text[],
  'and one square more is medium'
);

select is(
  (select tags from public.map_listing where slug = 'twenty-squares'),
  ARRAY['medium']::text[],
  'a map whose longer edge is exactly twenty squares is medium'
);

select is(
  (select tags from public.map_listing where slug = 'twentyone-squares'),
  ARRAY['large']::text[],
  'and one square more is large'
);

-- Every map lands in a band, so the array is never empty even when the archive
-- declared nothing else.
select is(
  (select count(*) from public.map_listing
    where tags && ARRAY['small', 'medium', 'large'])::int,
  (select count(*) from public.map)::int,
  'every map in the catalog gets exactly one of the three size bands'
);

-- ## Nothing measured

-- A comparison against null is null, not false, so an absent measurement has to
-- drop out of the array rather than land in it as a zero. A map that declared
-- nothing gets its size band and nothing else.
select is(
  (select tags from public.map_listing where slug = 'nothing-known'),
  ARRAY['small']::text[],
  'a map that declared no wind, no tide, no water and no void water gets only its size'
);

-- The other half of the same problem. A null that survives into the array is a
-- hole every reader has to filter, and an array of nothing but nulls would
-- aggregate to a null array rather than an empty one.
select is(
  (select array_position(tags, null) from public.map_listing where slug = 'nothing-known'),
  null,
  'and the array holds no null where a measurement was missing'
);

select isnt(
  (select tags from public.map_listing where slug = 'nothing-known'),
  null,
  'nor is the array itself null'
);

-- ## Curated tags

-- What no measurement captures, merged into the same array, so a reader browsing
-- by tag does not have to know which half a tag came from.
select is(
  (select tags from public.map_listing where slug = 'curated'),
  ARRAY['asymmetric', 'chokepoint', 'small']::text[],
  'a tag a maintainer wrote comes back beside the measured ones, sorted in with them'
);

select is(
  (select tags from public.map_listing where slug = 'curated-duplicate'),
  ARRAY['asymmetric', 'small']::text[],
  'and a curated tag that says the same thing as a measured one appears once'
);

select is(
  (select cardinality(tags) from public.map_listing where slug = 'curated-duplicate')::int,
  2,
  'which is two tags rather than three'
);

-- ## The view itself

select has_view('public', 'map_listing',
  'the tags are a view, so a threshold that moves cannot leave the catalog disagreeing with its own rules');

-- A view runs as its owner by default, and this one's owner bypasses row level
-- security. That would make it a way round whatever policy public.map carries,
-- which is a hole that opens the day somebody narrows the policy and nothing
-- about the view changes.
select is(
  (select 'security_invoker=true' = any(pg_class.reloptions)
    from pg_class where oid = 'public.map_listing'::regclass),
  true,
  'and it reads as whoever queries it, so it cannot see rows that reader could not select directly'
);

-- Nothing writes through it. The tags are computed, so the view is not one
-- Postgres will update on its own, and a map is written through the route as
-- public.map.
select is(
  (select is_insertable_into::text from information_schema.tables
    where table_schema = 'public' and table_name = 'map_listing'),
  'NO',
  'nothing can be written through the view, since the tags are computed rather than stored'
);

-- ## Read as the roles PostgREST uses

-- The grants are asserted in table_privileges.test.sql. What matters here is
-- that a visitor holding the publishable key really does get rows out of the
-- view, which needs the grant, the select on public.map behind it and
-- map_read_all all three.
reset role;
set local role anon;
set local request.jwt.claims = '{"role":"anon"}';

select is(
  (select count(*) from public.map_listing where slug = 'tide-wins')::int, 1,
  'a visitor reads a map out of the listing'
);

select is(
  (select tags from public.map_listing where slug = 'tide-wins'),
  ARRAY['small', 'tidal', 'windy']::text[],
  'and gets the same tags the owner of the view does'
);

reset role;
set local role authenticated;
set local request.jwt.claims = '{"role":"authenticated"}';

select is(
  (select tags from public.map_listing where slug = 'tide-wins'),
  ARRAY['small', 'tidal', 'windy']::text[],
  'an account reads exactly what a visitor reads, because there is nothing here to hide'
);

reset role;
set local role service_role;

select is(
  (select tags from public.map_listing where slug = 'tide-wins'),
  ARRAY['small', 'tidal', 'windy']::text[],
  'and so does the route, which is what #188 answers a lookup from'
);

reset role;

select * from finish();
rollback;
