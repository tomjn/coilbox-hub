-- What a reader browsing the catalog filters and sorts on (issue #189).
--
-- 20260818160000_map_browse.sql adds six columns to public.map_listing, and
-- every one of them can be wrong without anything raising. A longer edge read
-- off the width sorts a 4 x 20 map beside a 4 x 4. A start count that includes
-- metal spots calls a small map a hundred player map. An author key taken
-- straight off the column leaves a merged mapper's listing showing half his
-- maps. A search vector missing a field answers nothing for a word that is
-- plainly on the page.
--
-- None of those show up as an error. They show up as a listing that is quietly
-- wrong, so each one is asserted against real rows here.
--
-- The tag rules are not retested. They are unchanged, and map_listing.test.sql
-- is where every threshold is proved at its edge. The grants are not tested
-- here either: table_privileges.test.sql asserts them directly.

begin;
select plan(30);

create extension if not exists pgtap with schema extensions;

-- Four maps by one mapper spelled three ways, one map crediting him twice under
-- two keys, one crediting two people in an order that is not alphabetical, one
-- crediting nobody, and two shaped to test the longer edge.
insert into public.map (
  map_name, slug, display_name, description,
  width_elmos, height_elmos, world_height_min, world_height_max,
  min_wind, max_wind, tidal_strength, void_water, water_coverage, curated_tags,
  source_hash, source_archive, catalog_version, facts_digest
)
values
  ('Comet Catcher Remake 1.8', 'comet-catcher-remake-1-8', 'Comet Catcher Remake',
    'A remake of an old favourite.',
    6144, 10240, -120.5, 890,
    null, null, null, false, null, '{}',
    'src-comet', 'comet_catcher_remake_1.8.sd7', 1, 'digest-comet'),

  -- Wider than it is tall, so a longer edge read off the height gets it wrong.
  ('Long Thin 1.0', 'long-thin', null, null,
    12288, 4096, 0, 320,
    null, null, null, false, null, '{}',
    'src-long-thin', 'long_thin_1.0.sd7', 1, 'digest-long-thin'),

  ('Quiet 1.0', 'quiet', null, null,
    4096, 4096, 0, 320,
    null, null, null, null, null, '{}',
    'src-quiet', 'quiet_1.0.sd7', 1, 'digest-quiet'),

  ('Alpha 1.0', 'alpha', null, null, 4096, 4096, 0, 320,
    null, null, null, null, null, '{}',
    'src-alpha', 'alpha_1.0.sd7', 1, 'digest-alpha'),
  ('Bravo 1.0', 'bravo', null, null, 4096, 4096, 0, 320,
    null, null, null, null, null, '{}',
    'src-bravo', 'bravo_1.0.sd7', 1, 'digest-bravo'),
  ('Charlie 1.0', 'charlie', null, null, 4096, 4096, 0, 320,
    null, null, null, null, null, '{}',
    'src-charlie', 'charlie_1.0.sd7', 1, 'digest-charlie'),
  ('Delta 1.0', 'delta', null, null, 4096, 4096, 0, 320,
    null, null, null, null, null, '{}',
    'src-delta', 'delta_1.0.sd7', 1, 'digest-delta'),
  ('Echo 1.0', 'echo', null, null, 4096, 4096, 0, 320,
    null, null, null, null, null, '{}',
    'src-echo', 'echo_1.0.sd7', 1, 'digest-echo'),
  ('Foxtrot 1.0', 'foxtrot', null, null, 4096, 4096, 0, 320,
    null, null, null, null, null, '{}',
    'src-foxtrot', 'foxtrot_1.0.sd7', 1, 'digest-foxtrot'),

  -- One mapper nobody else in the fixtures shares a syllable with, so a search
  -- for the name proves the author terms are in the vector rather than matching
  -- something else.
  ('Golf 1.0', 'golf', null, null, 4096, 4096, 0, 320,
    null, null, null, null, null, '{}',
    'src-golf', 'golf_1.0.sd7', 1, 'digest-golf');

-- Two start positions and one of each of the other kinds, so a count that
-- forgot to filter on kind answers four.
insert into public.map_point (map_id, kind, ordinal, x, z, y, meta)
select map.id, point.kind, point.ordinal, point.x, point.z, point.y, point.meta
from public.map as map
cross join (values
  ('start', 0, 512::real, 512::real, null::real, null::jsonb),
  ('start', 1, 5632::real, 9728::real, null::real, null::jsonb),
  ('metal', 0, 1024::real, 2048::real, 40::real, '{"amount": 2}'::jsonb),
  ('geo', 0, 2048::real, 2048::real, null::real, null::jsonb)
) as point(kind, ordinal, x, z, y, meta)
where map.map_name = 'Comet Catcher Remake 1.8';

-- The credits as the submission route writes them: the archive's own spelling
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
  ('Foxtrot 1.0', 1, 'Alpha Mapper', 'alpha mapper'),
  ('Golf 1.0', 0, 'Marmoset', 'marmoset')
) as credit(map_name, credit_index, raw, key) on credit.map_name = map.map_name;

-- Which maps a free search finds, which is the question the page asks the
-- vector. websearch_to_tsquery takes what a person would actually type, quotes
-- and all, and never throws on punctuation.
create function pg_temp.found(p_query text) returns text[] language sql as $$
  select coalesce(array_agg(listing.slug order by listing.slug), '{}')
  from public.map_listing as listing
  where listing.search @@ websearch_to_tsquery('english', p_query);
$$;

-- ## The longer edge

-- 6144 across and 10240 down, so the edge is the height. The size bands are cut
-- from this measure, and a sort that read the width instead would order the
-- catalog by a number no band uses.
select is(
  (select longer_edge_elmos from public.map_listing where slug = 'comet-catcher-remake-1-8'),
  10240,
  'the longer edge of a map taller than it is wide is its height'
);

select is(
  (select longer_edge_elmos from public.map_listing where slug = 'long-thin'),
  12288,
  'and the longer edge of a wider map is its width'
);

-- ## Start positions

-- Four points on the map and two of them are spawns. A count that forgot the
-- kind would call this a four player map, and nothing about a four would look
-- wrong.
select is(
  (select start_positions from public.map_listing where slug = 'comet-catcher-remake-1-8'),
  2,
  'start positions count team spawns and not the metal spots and geo vents beside them'
);

-- A zero rather than a null, because this is filtered on with a minimum and a
-- null would drop the map out of every comparison including `at least none`.
select is(
  (select start_positions from public.map_listing where slug = 'quiet'),
  0,
  'a map with no points at all has no start positions rather than an unknown number of them'
);

-- ## Recently added

select is(
  (select created_at from public.map_listing where slug = 'quiet'),
  (select created_at from public.map where slug = 'quiet'),
  'the date a listing sorts on is the date the row was written'
);

-- ## Authors

select is(
  (select author_keys from public.map_listing where slug = 'comet-catcher-remake-1-8'),
  ARRAY['beherith'],
  'a credited map carries the key its mapper counts under'
);

select is(
  (select author_names from public.map_listing where slug = 'comet-catcher-remake-1-8'),
  ARRAY['Beherith'],
  'and the spelling a card shows him by'
);

select is(
  (select author_keys from public.map_listing where slug = 'quiet'),
  ARRAY[]::text[],
  'a map the archive credited nobody for has an empty list of keys rather than no list'
);

select is(
  (select author_names from public.map_listing where slug = 'quiet'),
  ARRAY[]::text[],
  'and an empty list of names, so a card drawing them draws nothing'
);

-- The order is the archive's, which is not alphabetical and is not the hub's to
-- reorder.
select is(
  (select author_keys from public.map_listing where slug = 'foxtrot'),
  ARRAY['zeta', 'alpha mapper'],
  'two people come back in the order the archive credited them'
);

select is(
  (select author_names from public.map_listing where slug = 'foxtrot'),
  ARRAY['Zeta', 'Alpha Mapper'],
  'and the names are in step with the keys, so the second name belongs to the second key'
);

-- ## The alias hop, which is why this is a read time resolution

select is(
  (select author_keys from public.map_listing where slug = 'charlie'),
  ARRAY['behe'],
  'a key with no alias listed against it answers as itself'
);

select is(
  (select author_names from public.map_listing where slug = 'charlie'),
  ARRAY['Behe'],
  'under the only spelling that key has'
);

insert into public.author_alias (from_key, to_key, note)
values ('behe', 'beherith', 'Same person, said so in the map thread.');

-- The stored key has not changed and will not until the map is submitted again.
-- A listing that trusted the column would go on splitting one mapper across two
-- keys for as long as nobody resubmitted, which is forever for an archive
-- nobody has installed.
select is(
  (select author_keys from public.map_listing where slug = 'charlie'),
  ARRAY['beherith'],
  'and the listing files it under the merged key the moment the alias exists, with nothing resubmitted'
);

-- Beherith is on four of his maps, beherith on one and Behe on two, so the
-- merged author is shown under the spelling most of his maps credit him by
-- rather than the one this archive used.
select is(
  (select author_names from public.map_listing where slug = 'charlie'),
  ARRAY['Beherith'],
  'shown under the spelling most of his maps use, which is the name his own page uses'
);

-- One map crediting the same person under both keys is one author. Listing him
-- twice would put the same mapper on one card twice.
select is(
  (select author_keys from public.map_listing where slug = 'echo'),
  ARRAY['beherith'],
  'two credits on one map that resolve to one person are one author'
);

select is(
  (select cardinality(author_names) from public.map_listing where slug = 'echo')::int,
  1,
  'and one name to show, not two'
);

-- ## Free search

select is(
  pg_temp.found('comet'),
  ARRAY['comet-catcher-remake-1-8'],
  'a search finds a map by its canonical name'
);

select is(
  pg_temp.found('catcher remake'),
  ARRAY['comet-catcher-remake-1-8'],
  'and by the name the archive would rather be called'
);

select is(
  pg_temp.found('favourite'),
  ARRAY['comet-catcher-remake-1-8'],
  'and by a word the mapper wrote about it'
);

select is(
  pg_temp.found('marmoset'),
  ARRAY['golf'],
  'and by who made it'
);

-- The author terms are the shown names rather than the raw credits, so a merge
-- makes every map that person signed findable under the spelling people know
-- them by. This archive credits `Behe` and nothing else.
select ok(
  'charlie' = any(pg_temp.found('beherith')),
  'a map credited under a merged key is found by the spelling the mapper is shown under'
);

select is(
  pg_temp.found('helicopter'),
  ARRAY[]::text[],
  'and a word that is in no name, no description and no credit finds nothing'
);

-- ## The size filter is the tag array

-- `small`, `medium` and `large` are derived tags, so a size filter is the same
-- array match a tag filter is. A column repeating the band would be a second
-- answer to a question the array has already answered.
select hasnt_column('public', 'map_listing', 'size',
  'a size filter reads the tags rather than a column of its own, so a threshold that moves cannot leave the two disagreeing');

-- ## The view the name rule moved to

select has_view('public', 'author_display_name',
  'the spelling an author is shown under is one rule, so a card and a lookup cannot name the same mapper differently');

select is(
  (select name from public.author_display_name where key = 'beherith'),
  'Beherith',
  'which is the most common spelling across the whole catalog, not whichever archive was read first'
);

-- A view runs as its owner by default, and this one's owner bypasses row level
-- security. That would make it a way round map_author_read_all, which is a hole
-- that opens the day somebody narrows that policy.
select is(
  (select 'security_invoker=true' = any(pg_class.reloptions)
    from pg_class where oid = 'public.author_display_name'::regclass),
  true,
  'and it reads as whoever queries it, so it cannot see credits that reader could not select directly'
);

-- create or replace view rewrites the whole definition, and dropping the option
-- would restore exactly the privilege escalation map_listing.test.sql tests for.
select is(
  (select 'security_invoker=true' = any(pg_class.reloptions)
    from pg_class where oid = 'public.map_listing'::regclass),
  true,
  'and replacing map_listing kept it reading as whoever queries it'
);

-- ## Read as the role a browser holds

-- The listing is drawn with the publishable key, so every new column has to
-- come back through the grant, the select on the tables behind it and the read
-- all policies on all three.
reset role;
set local role anon;
set local request.jwt.claims = '{"role":"anon"}';

select is(
  (select start_positions from public.map_listing where slug = 'comet-catcher-remake-1-8'),
  2,
  'a visitor reads the start count off the listing'
);

select is(
  (select author_names from public.map_listing where slug = 'charlie'),
  ARRAY['Beherith'],
  'and the merged author name, which needs public.map_author and public.author_alias both'
);

reset role;

select * from finish();
rollback;
