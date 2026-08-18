-- One mapper, one name, wherever the hub prints it (issue #189).
--
-- public.map_facts already worked this out for a lookup: the name a merged
-- author is shown under is the most common raw spelling among that author's
-- credits, ties broken by the spelling itself so the answer is settled rather
-- than whichever row the planner reached first. 20260818140000_map_lookup.sql
-- states the rule and why it counts across the whole catalog rather than per
-- map.
--
-- The listing in #189 prints the same name on every card, so it needs the same
-- answer. Copying the two CTEs into the listing view would put the rule in two
-- places, and the way that drift would show is a mapper called one thing on a
-- card and another on the map's own page, which nobody reads as a bug in a
-- query. So the rule moves here and both callers read it.
--
-- ## A view rather than a function
--
-- A function taking a key would be called once per row, and the listing draws a
-- page of cards from a catalog of roughly 3,575 maps. A view is one grouped
-- read that a query joins to, which is what both callers actually want: the
-- lookup joins it to a handful of keys and the listing joins it to a page of
-- them.
--
-- ## Security invoker
--
-- The same reading public.map_listing and public.resolved_author_key give. A
-- view runs with its owner's privileges by default, and its owner here is the
-- migration role, which bypasses row level security. Everybody who reads this
-- already reads public.map_author and public.author_alias directly: anon and
-- authenticated hold select and pass the read all policies, and service_role
-- carries bypassrls. Running as the owner would add a privilege nothing needs,
-- and would quietly become a way round map_author_read_all the day somebody
-- narrows it.

create view public.author_display_name
with (security_invoker = true)
as
-- Every spelling in the catalog, under the key it counts as today rather than
-- the key it was filed under when it was submitted, with a count of the credits
-- it appears on.
--
-- The whole of public.map_author, which is one row per credit. The alternative
-- is a lookup per author, and that cannot be written without asking
-- public.author_alias which keys merge into this one, which is the hop rule
-- copied out a second time.
with spelling as (
  select
    public.resolved_author_key(author.key) as key,
    author.raw,
    count(*) as credits
  from public.map_author as author
  group by 1, 2
)

-- The one spelling to show. Most credits wins, and the spelling itself breaks
-- ties, so a mapper credited once as `Beherith` and once as `beherith` gets the
-- same name on every request.
select distinct on (spelling.key)
  spelling.key,
  spelling.raw as name
from spelling
order by spelling.key, spelling.credits desc, spelling.raw;

-- ## Access
--
-- Whatever these roles hold is taken away first, so a privilege some hosted
-- default handed out is gone rather than sitting underneath the grant below
-- where nothing would notice it. That is the discipline every table and view has
-- followed since #59 found production holding grants these migrations never
-- wrote.
revoke all on public.author_display_name from anon, authenticated, service_role;

-- Read for everybody, because the view publishes nothing public.map_author does
-- not. Every reader already holds select on that table and passes
-- map_author_read_all, and a spelling is what the table stores in raw.
--
-- service_role as well as the two browser roles, because public.map_facts joins
-- it and only service_role may call that.
--
-- Select only, and no other grant is possible to want: the name is computed, so
-- this is not a view Postgres will update on its own.
grant select on public.author_display_name to anon, authenticated, service_role;

-- public.map_facts reads the rule rather than restating it.
--
-- Everything else about the function is unchanged, and it is written out in full
-- because create or replace has to restate the whole body.
-- 20260818140000_map_lookup.sql is still where the reasoning for the rest of it
-- lives.
--
-- The answer does not move. The spelling CTE this replaces counted the same
-- credits over the same table and picked with the same tie break; all it did
-- besides was narrow itself to the keys the request touched, which the join
-- below does instead.
create or replace function public.map_facts(p_names text[]) returns jsonb
language sql
stable
set search_path = ''
as $$
  with wanted as (
    select map.*
    from public.map as map
    where map.map_name = any(p_names)
  ),

  -- Every credit on the maps asked about, under the key it counts as today
  -- rather than the key it was filed under when it was submitted.
  credit as (
    select
      wanted.id as map_id,
      author.credit_index,
      public.resolved_author_key(author.key) as key
    from wanted
    join public.map_author as author on author.map_id = wanted.id
  ),

  -- One entry per person per map. Two credits on one map that resolve to one
  -- key are one author, which is what an archive crediting `Beherith` and
  -- `[BAR]Beherith` in the same string comes to, and listing them twice would
  -- put the same mapper on the page twice.
  --
  -- The earliest credit_index carries the order, because credit_index is the
  -- order the archive credited them in and is not the hub's to reorder.
  merged as (
    select
      credit.map_id,
      credit.key,
      min(credit.credit_index) as credit_index
    from credit
    group by credit.map_id, credit.key
  ),

  -- A plain join rather than a left join, because every key here has at least
  -- one spelling: the credit row being answered is itself one of the rows the
  -- view counted.
  authors as (
    select
      merged.map_id,
      jsonb_agg(
        jsonb_build_object('key', merged.key, 'name', shown.name)
        order by merged.credit_index
      ) as entries
    from merged
    join public.author_display_name as shown on shown.key = merged.key
    group by merged.map_id
  ),

  -- The points, grouped by kind and ordered within each kind. The order is the
  -- stored ordinal, which is the team index on a start position and carries
  -- meaning, so it is never sorted on anything else.
  --
  -- meta comes through whole. It is where a metal spot's amount and radius and
  -- a geo vent's feature type live, and a caller drawing metal spots needs
  -- them.
  points as (
    select
      grouped.map_id,
      jsonb_object_agg(grouped.kind, grouped.positions) as kinds
    from (
      select
        point.map_id,
        point.kind,
        jsonb_agg(
          jsonb_build_object('x', point.x, 'z', point.z, 'y', point.y, 'meta', point.meta)
          order by point.ordinal
        ) as positions
      from public.map_point as point
      join wanted on wanted.id = point.map_id
      group by point.map_id, point.kind
    ) as grouped
    group by grouped.map_id
  )

  -- One element per map the hub holds, each carrying the name it was found
  -- under and the facts beneath it. A name with no row is simply absent, and
  -- the route turns that into the null the caller reads as "the hub has never
  -- heard of this map".
  select coalesce(
    jsonb_agg(
      jsonb_build_object(
        'map_name', wanted.map_name,
        'facts', jsonb_build_object(
          'slug', wanted.slug,
          'display_name', wanted.display_name,
          'description', wanted.description,
          'authors', coalesce(authors.entries, '[]'::jsonb),
          'width_elmos', wanted.width_elmos,
          'height_elmos', wanted.height_elmos,
          'world_height_min', wanted.world_height_min,
          'world_height_max', wanted.world_height_max,
          'min_wind', wanted.min_wind,
          'max_wind', wanted.max_wind,
          'tidal_strength', wanted.tidal_strength,
          'void_water', wanted.void_water,
          'water_coverage', wanted.water_coverage,

          -- Off the view, never recomputed. public.map_listing is where a
          -- measurement becomes a word a reader browses by, and it merges the
          -- curated tags in, so a caller never has to know which half a tag
          -- came from.
          'tags', to_jsonb(listing.tags),

          -- The three kinds are always present, so a caller reads
          -- points.metal without asking whether the key exists. The empty
          -- object is the base rather than the answer, so a kind added to
          -- map_point_kind_check later appears here on its own.
          'points', '{"start": [], "metal": [], "geo": []}'::jsonb
            || coalesce(points.kinds, '{}'::jsonb),

          'appearance', wanted.appearance
        )
      )
    ),
    '[]'::jsonb
  )
  from wanted
  join public.map_listing as listing on listing.id = wanted.id
  left join authors on authors.map_id = wanted.id
  left join points on points.map_id = wanted.id;
$$;

-- create or replace keeps the grants 20260818140000_map_lookup.sql set, so the
-- lookup route is still the only caller. They are restated anyway, because a
-- reader of this file should not have to find out elsewhere whether replacing
-- the function widened who may run it.
revoke execute on function public.map_facts(text[]) from public;
grant execute on function public.map_facts(text[]) to service_role;
