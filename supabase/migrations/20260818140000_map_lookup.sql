-- Everything the hub knows about a map, in one read (issue #188).
--
-- Coilbox draws a battle lobby for a map the player has not installed, so it
-- has a canonical name and nothing else. /api/v1/maps/lookup is where it turns
-- that name into facts, and this function is what the route reads.
--
-- ## Why the read is a function rather than four PostgREST requests
--
-- A map's answer is spread across four places. public.map holds the
-- measurements, public.map_listing holds the tags derived from them,
-- public.map_point holds a few hundred positions and public.map_author holds
-- the credits. A route assembling that over PostgREST would fetch every point
-- row and every credit row for up to 500 maps and regroup them in TypeScript,
-- which is the grouping the database has already done.
--
-- Two of the parts cannot be done outside the database at all.
--
-- The first is the alias hop. public.map_author.key is resolved when a
-- submission is written, so a merge recorded afterwards is not in the stored
-- key, and a read that trusted the column would go on splitting one mapper
-- across two keys until every map they made was submitted again. Resolving it
-- here is what makes a merge take effect the moment a maintainer records it.
-- 20260818110000_author_keys.sql argues at length why the rule lives in one
-- place, and a copy of the hop in the route would be the drift it warns about.
--
-- The second is the name a merged author is shown under, which counts spellings
-- across the whole catalog. See below.
--
-- ## What is deliberately not here
--
-- The licence gate. A map with a per map deny row answers null, exactly as if
-- the hub had never heard of it, and that decision is made in the route against
-- lib/assets/licence.ts. public.asset_licence is readable by service_role
-- alone, so the route is already reading it, and the rule for reading a licence
-- row is written down once in TypeScript. A second copy of it in SQL is a
-- second answer to the same question.
--
-- So this function answers "what does the hub hold", and the route answers "may
-- the hub say so".

create function public.map_facts(p_names text[]) returns jsonb
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

  -- Every spelling in the catalog that resolves to one of those keys, with a
  -- count of the maps it appears on.
  --
  -- This reads the whole of public.map_author, which is one row per credit
  -- across roughly 3,575 maps. The alternative is a lookup per author against
  -- an index, and it cannot be written without asking public.author_alias which
  -- keys merge into this one, which is the hop rule copied out a second time.
  -- One scan of a small table is the cheaper mistake to avoid.
  spelling as (
    select
      public.resolved_author_key(author.key) as key,
      author.raw,
      count(*) as credits
    from public.map_author as author
    where public.resolved_author_key(author.key) in (select credit.key from credit)
    group by 1, 2
  ),

  -- The one spelling a merged author is shown under: the most common raw form
  -- among that author's maps, which is the rule #183 set for an author's own
  -- page. The two agree deliberately. A caller that shows the name from a
  -- lookup and then links to the hub renames nobody on the way.
  --
  -- Ties break on the spelling itself, so the answer is settled rather than
  -- whichever row the planner reached first. A mapper credited once as
  -- `Beherith` and once as `beherith` gets the same name on every request.
  popular as (
    select distinct on (spelling.key)
      spelling.key,
      spelling.raw
    from spelling
    order by spelling.key, spelling.credits desc, spelling.raw
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
  -- one spelling: the credit row being answered is itself one of the rows
  -- counted above.
  authors as (
    select
      merged.map_id,
      jsonb_agg(
        jsonb_build_object('key', merged.key, 'name', popular.raw)
        order by merged.credit_index
      ) as entries
    from merged
    join popular on popular.key = merged.key
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
  --
  -- The facts object is the wire shape, assembled here rather than in the
  -- route, because assembling it there would mean shipping four tables' rows
  -- over PostgREST to rebuild the grouping this query already did.
  --
  -- Nothing about provenance is in it. source_hash, source_archive,
  -- catalog_version and facts_digest are how the hub decides what to store and
  -- mean nothing to a client drawing a lobby, submitted_by names an account,
  -- and /api/v1/maps/have already answers the only question a client asks about
  -- what the hub holds.
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

-- ## Access
--
-- Execute is granted to PUBLIC on every new function, so the revoke is the
-- access control rather than a tidy-up, which is the discipline every migration
-- has followed since #59 found production holding grants these migrations never
-- wrote.
revoke execute on function public.map_facts(text[]) from public;

-- The lookup route and nothing else. Everything this reads is already public:
-- anon holds select on all four tables and on the view, so a browser could
-- assemble the same answer by hand.
--
-- The grant is narrow anyway, because the answer is only half a decision. The
-- other half is the licence gate, which is the route's and needs
-- public.asset_licence, and that table is readable by service_role alone. A
-- caller that could reach this function directly would read the facts of a map
-- that has been taken down, which is exactly what the gate exists to prevent.
grant execute on function public.map_facts(text[]) to service_role;
