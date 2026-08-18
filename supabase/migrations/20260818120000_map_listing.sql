-- What kind of map it is, worked out from what was measured (issue #184).
--
-- public.map holds measurements: a wind range, a tidal strength, a share of
-- water, a size in elmos, whether void water is set. A reader asks for small
-- maps, water maps and void maps. Nothing in the catalog turns one into the
-- other, so today every listing would carry its own copy of the thresholds and
-- two of them would disagree the first time one moved.
--
-- public.map_listing is that translation written once, and it is where every
-- listing query reads from.
--
-- ## A view rather than a column
--
-- A stored array of tags needs every row rewritten whenever a threshold moves,
-- and a threshold that moves without the rewrite leaves the catalog disagreeing
-- with its own rules while every row still looks fine. A view cannot be stale.
--
-- The cost is a scan. The catalog is roughly 3,575 maps, which is the figure
-- 20260814170100_asset_licence_all_maps.sql measured for the minimap seed, and
-- five decisions per row over that many rows is not worth indexing around. If
-- the catalog ever outgrows it the view becomes materialised and none of the
-- rules below change.
--
-- ## Nulls give no tag rather than a wrong one
--
-- min_wind, max_wind, tidal_strength, water_coverage and void_water are all
-- nullable, because mapinfo leaves them out and the engine falls back to its
-- own defaults. Every rule below is a comparison, a comparison against null is
-- null rather than false, and a case with no true branch yields null. So an
-- absent measurement drops out of the array on its own and nothing has to
-- special case it.
--
-- The one thing that does not fall out for free is the array itself. A null
-- element would leave a hole a reader has to filter, and an array with no
-- elements aggregates to null rather than to an empty array. Both are handled
-- once, where the two halves are merged.
--
-- ## Curated tags come through the same view
--
-- map.curated_tags is what a maintainer put there for anything no measurement
-- captures: asymmetric, 1v1, chokepoint. This view returns one merged, sorted,
-- deduplicated array, so a reader browsing by tag does not need to know which
-- half a tag came from, and a curated tag that says the same thing as a derived
-- one appears once.
--
-- ## Security invoker
--
-- A view runs with its owner's privileges by default, and its owner here is the
-- migration role, which bypasses row level security. That would make the view a
-- way around whatever policy public.map carries, silently, and the reader would
-- never see the difference. map_read_all is a read all policy today, so it
-- changes no answer, and that is exactly why to settle it now rather than the
-- day somebody narrows the policy and finds this view still returning
-- everything.
--
-- So security invoker, for the reason public.resolved_author_key gives for the
-- same choice: everybody who reads this view already reads public.map directly.
-- anon and authenticated hold select on it and pass map_read_all, and
-- service_role carries bypassrls. Running as the owner would add a privilege
-- nothing needs.

create view public.map_listing
with (security_invoker = true)
as
with constant as (
  select
    -- Windy is the point where wind beats solar. A solar collector produces 20
    -- energy and a wind generator produces the wind strength, so above 20 the
    -- tag means what a player means by it: build wind here.
    --
    -- This is a game constant rather than an engine one. Solar output is a
    -- number a game's unit definitions choose, so it is named here and a game
    -- that changes its solar collector is a one line change. It is named for
    -- the other reason too: the tidal rule below asks the same question and
    -- measures against the same 20, so the two rules cannot drift apart.
    --
    -- Cast to real, which is what min_wind, max_wind and tidal_strength are.
    -- A bare decimal literal is numeric, and comparing a real against numeric
    -- promotes both to double precision, where a value stored as real reads as
    -- fractionally more or less than the number that was written.
    20::real as solar_collector_energy,

    -- The engine's map square. Map dimensions are whole squares of 512 elmos
    -- and every map size a player ever says out loud is a count of them, so the
    -- bands are stated in squares and converted here. Unlike solar output this
    -- one is the engine's and no game changes it.
    512 as elmos_per_square
),

-- One row per map, carrying the array the rules produced with its gaps still in
-- it. The gaps are closed once below rather than five times here.
derived as (
  select
    map.id,
    map.map_name,
    map.slug,
    map.display_name,
    map.width_elmos,
    map.height_elmos,
    map.curated_tags,

    array[
      -- A map with no sea at all. void_water is the archive saying so outright,
      -- and it is the first thing to check because it makes every other water
      -- question moot.
      case when map.void_water is true then 'void map' end,

      -- A fifth of the map under the water line, which is the point at which
      -- water stops being scenery and starts deciding how the map is played.
      --
      -- water_coverage is a stored column rather than something this view
      -- derives, and it has to be. It is the share of height samples below
      -- zero, and only the extractor holding the height grid can count them.
      --
      -- The void water guard states the rule rather than covering a case that
      -- can arise: map_void_water_coverage_check already refuses a coverage
      -- alongside void water, so the coverage is null on such a map and the
      -- comparison would yield nothing anyway. It is written out because the
      -- rule is "not a void map, and more than a fifth water", and a reader
      -- should not have to find a constraint in another migration to know that.
      --
      -- The threshold is cast to real for the reason the solar constant is:
      -- against a numeric literal both sides promote to double precision, and a
      -- map at exactly 0.20 comes out above 0.20.
      case
        when map.void_water is not true and map.water_coverage > 0.20::real
        then 'water map'
      end,

      -- The midpoint of the map's own wind range, which is the wind a player
      -- can expect a generator to make over a game rather than the best or
      -- worst minute of one.
      case
        when (map.min_wind + map.max_wind) / 2 > constant.solar_collector_energy
        then 'windy'
      end,

      -- The same question as windy with one more condition. A tidal generator
      -- stands in water, so the tag means nothing on a map with none however
      -- strong the tide, and on a map with water it is worth saying only when
      -- tidal is the best of the three: above solar, and above the wind.
      --
      -- The water test here is deliberately not water map's. A tidal generator
      -- needs somewhere wet to stand and nothing more, so any water at all is
      -- enough, and a map can be worth building tidal on without a fifth of it
      -- being sea.
      --
      -- Any water means void water is not set and the terrain reaches below
      -- zero, which is what world_height_min says. That column says a map goes
      -- under the line somewhere without saying how much of it does, and how
      -- much of it does is the question water map asks and this one does not.
      -- water_coverage would be the wrong column here twice over: its threshold
      -- is the fifth that earns water map, and it is null on a map with no
      -- water, so a puddle and a desert would read the same.
      case
        when map.void_water is not true
          and map.world_height_min < 0
          and map.tidal_strength > constant.solar_collector_energy
          and map.tidal_strength > (map.min_wind + map.max_wind) / 2
        then 'tidal'
      end,

      -- The longer edge, not the area. An 8x24 map is a big map that plays
      -- large, and banding on area puts it at 192 squares next to a 14x14. The
      -- longer edge gets that right, and puts a 20x20 and a 4x20 in the same
      -- band, which is wrong less often.
      --
      -- Compared in elmos rather than converted to squares, so nothing rounds.
      -- width_elmos and height_elmos only have to be positive, so an edge that
      -- is not a whole number of squares is a row the table accepts, and
      -- dividing it down would band it one size smaller than it plays.
      --
      -- Every map lands in one of the three, which is why this is the one rule
      -- that cannot yield nothing: width_elmos and height_elmos are not null.
      case
        when greatest(map.width_elmos, map.height_elmos)
          <= 12 * constant.elmos_per_square then 'small'
        when greatest(map.width_elmos, map.height_elmos)
          <= 20 * constant.elmos_per_square then 'medium'
        else 'large'
      end
    ] as derived_tags

  from public.map as map
  cross join constant
)

-- What a listing needs to draw a row, and not a copy of the table.
--
-- id is the row, and what anything joining to the catalog keys on. slug is the
-- link. map_name is identity, the label a listing falls back to when there is no
-- display name, and the value public.asset joins on to find a picture of the
-- map. display_name is what the archive would rather be called. width_elmos and
-- height_elmos are the shape a minimap is drawn at, and the two numbers the size
-- band came from, so a reader can see why a map banded the way it did.
--
-- Everything else a map's own page shows is a lookup on public.map by id.
-- Mirroring more of the table here would be a second column list to keep in
-- step with the first, and a select * would make every column added to
-- public.map a column this view publishes without anybody deciding to.
select
  derived.id,
  derived.map_name,
  derived.slug,
  derived.display_name,
  derived.width_elmos,
  derived.height_elmos,

  -- The two halves as one array: sorted, deduplicated, never null and never
  -- holding a null.
  --
  -- The null filter does both jobs. It drops the gaps the rules left, and it
  -- drops a null a maintainer left in curated_tags, which is a text[] with no
  -- element constraint on it. The coalesce covers the other end: aggregating no
  -- rows at all yields null, and a map with no tags has an empty array rather
  -- than no answer.
  (
    select coalesce(array_agg(distinct tag order by tag), '{}')
    from unnest(derived.derived_tags || derived.curated_tags) as merged(tag)
    where tag is not null
  ) as tags

from derived;

-- ## Access
--
-- The same rule as the tables behind it: whatever these roles hold is taken away
-- first, so a privilege some hosted default handed out is gone rather than
-- sitting underneath the grant below where nothing would notice it. That is the
-- discipline every table has followed since #59 found production holding grants
-- these migrations never wrote.
revoke all on public.map_listing from anon, authenticated, service_role;

-- Read for everybody, because the view publishes nothing public.map does not.
-- Every reader already holds select on the table and passes map_read_all, and
-- the view derives tags from columns those readers can already see.
--
-- service_role as well as the two browser roles. The lookup in #188 answers
-- from this view server side, and a route that could not read it would have to
-- carry its own copy of the rules, which is the drift this view exists to
-- prevent.
--
-- Select only, and no other grant is possible to want: the tags are computed, so
-- this is not a view Postgres will update on its own, and a write to it would be
-- a write to public.map, which only the routes may do.
grant select on public.map_listing to anon, authenticated, service_role;
