-- Mirror copies of maps and games, listed as download sources.
--
-- A map or game archive that a healthy mirror holds becomes one more download
-- source, after the ones its owner wrote. The hub never stores the file. It
-- links the mirror's own copy and gives the archive's SHA-256, so the client
-- can check what it fetched.

-- ## Encoding a file name for a URL
--
-- Matches PHP's rawurlencode. Every byte outside A-Z a-z 0-9 - _ . ~ becomes
-- an uppercase %XX, working on the UTF-8 bytes so a name such as "é" encodes
-- as %C3%A9.
create function public.url_path_segment(p text) returns text
language sql
immutable
strict
set search_path = ''
as $$
  select coalesce(string_agg(
    case
      when b.byte between 48 and 57
        or b.byte between 65 and 90
        or b.byte between 97 and 122
        or b.byte in (45, 95, 46, 126)
      then chr(b.byte)
      else '%' || upper(lpad(to_hex(b.byte), 2, '0'))
    end,
    '' order by b.pos
  ), '')
  from (
    select get_byte(bytes.value, i) as byte, i as pos
    from (select pg_catalog.convert_to(p, 'UTF8') as value) as bytes,
      generate_series(0, pg_catalog.length(bytes.value) - 1) as i
  ) as b;
$$;

revoke execute on function public.url_path_segment(text) from public;
grant execute on function public.url_path_segment(text) to anon, authenticated, service_role;

-- ## The sources
--
-- Security definer because archive and archive_mirror are closed to every
-- browser role. The functions return only what is public anyway: the mirror's
-- address and the archive's name and hash.
--
-- A mirror counts when it is enabled, healthy and has reported holding the
-- archive. Entries are ordered by mirror id so the list is stable.
create function public.map_mirror_sources(p_map_name text) returns jsonb
language sql
stable
security definer
set search_path = ''
as $$
  with chosen as (
    select a.id, a.filename, a.sha256
    from public.archive as a
    where a.kind = 'map'
      and a.state = 'listed'
      and a.internal_name = p_map_name
    order by a.listed_at desc, a.id
    limit 1
  )
  select coalesce(
    jsonb_agg(
      jsonb_build_object(
        'kind', 'url',
        'value', m.base_url || '/maps/' || public.url_path_segment(chosen.filename),
        'filename', chosen.filename,
        'sha256', chosen.sha256
      )
      order by m.id
    ),
    '[]'::jsonb
  )
  from chosen
  join public.archive_mirror as am on am.archive_id = chosen.id
  join public.mirror as m on m.id = am.mirror_id
  where m.enabled and m.healthy;
$$;

create function public.game_mirror_sources(p_game_id uuid) returns jsonb
language sql
stable
security definer
set search_path = ''
as $$
  with chosen as (
    select a.id, a.filename, a.sha256
    from public.archive as a
    where a.kind = 'game'
      and a.state = 'listed'
      and a.game_id = p_game_id
    order by a.listed_at desc, a.id
    limit 1
  )
  select coalesce(
    jsonb_agg(
      jsonb_build_object(
        'kind', 'url',
        'value', m.base_url || '/games/' || public.url_path_segment(chosen.filename),
        'filename', chosen.filename,
        'sha256', chosen.sha256
      )
      order by m.id
    ),
    '[]'::jsonb
  )
  from chosen
  join public.archive_mirror as am on am.archive_id = chosen.id
  join public.mirror as m on m.id = am.mirror_id
  where m.enabled and m.healthy;
$$;

revoke execute on function public.map_mirror_sources(text) from public;
revoke execute on function public.game_mirror_sources(uuid) from public;
grant execute on function public.map_mirror_sources(text) to anon, authenticated, service_role;
grant execute on function public.game_mirror_sources(uuid) to anon, authenticated, service_role;

-- ## map_facts carries the mirror sources
--
-- Restated from 20261115140000_map_download_sources.sql. Only the 'downloads'
-- expression changes: the mirror sources follow the owner's list.
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
  ),

  -- The map's own download sources, in the order to try them. The keys a kind
  -- has no use for are absent, the contract game downloads follow.
  downloads as (
    select
      source.map_id,
      jsonb_agg(
        jsonb_strip_nulls(jsonb_build_object(
          'kind', source.kind,
          'value', source.value,
          'asset', source.asset,
          'filename', source.filename
        ))
        order by source.sort_order, source.id
      ) as entries
    from public.map_download_source as source
    join wanted on wanted.id = source.map_id
    group by source.map_id
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

          'appearance', wanted.appearance,
          'downloads', coalesce(downloads.entries, '[]'::jsonb) || public.map_mirror_sources(wanted.map_name)
        )
      )
    ),
    '[]'::jsonb
  )
  from wanted
  join public.map_listing as listing on listing.id = wanted.id
  left join authors on authors.map_id = wanted.id
  left join points on points.map_id = wanted.id
  left join downloads on downloads.map_id = wanted.id;
$$;

revoke execute on function public.map_facts(text[]) from public;
grant execute on function public.map_facts(text[]) to service_role;

-- ## game_browse carries the mirror sources
--
-- Restated from 20261021120000_game_base_game.sql. Only the downloads column
-- changes: the mirror sources follow the owner's list. Columns and their order
-- are unchanged.
create or replace view public.game_browse
with (security_invoker = true) as
select
  g.shortname,
  g.display_name,
  g.description,

  -- How many sides the game has, Random left out.
  (select count(*) from public.game_faction as gf
    where gf.game_id = g.id
      and lower(btrim(gf.key)) <> 'random'
      and lower(btrim(gf.name)) <> 'random')::integer
    as faction_count,

  -- How many playable units, retired ones excluded.
  (select count(*) from public.game_unit as gu
    where gu.game_id = g.id and gu.removed_at is null)::integer
    as unit_count,

  -- How much community content is published for it (#244).
  (select count(*) from public.item as i
    where i.game_key = g.shortname and i.deleted_at is null)::integer
    as item_count,

  -- Tier relative path to the game's logo, or null when none is held (#239).
  g.logo_path,

  -- The hash of the logo's bytes, and which store holds a copy still waiting
  -- for promotion (#345).
  g.logo_hash,
  g.logo_staged_tier,

  -- Whether the listing draws this one above the divider.
  g.featured_at,

  -- Where coilbox fetches the game, best source first, as
  -- [{kind, value, asset?, filename?}] (#396). Always an array, empty when the
  -- game names nowhere, so a caller never has to tell null from none.
  (select coalesce(
      jsonb_agg(
        jsonb_strip_nulls(jsonb_build_object(
          'kind', s.kind,
          'value', s.value,
          'asset', s.asset,
          'filename', s.filename
        ))
        order by s.sort_order, s.id
      ),
      '[]'::jsonb)
    from public.game_download_source as s
    where s.game_id = g.id)
    || public.game_mirror_sources(g.id)
    as downloads,

  -- The card picture, resolved by the same three columns every other game
  -- picture uses.
  g.card_path,
  g.card_hash,
  g.card_staged_tier,

  -- The banner the game's page draws as its header, resolved the same way.
  g.banner_path,
  g.banner_hash,
  g.banner_staged_tier,

  -- The shortname of the game this one is built on, or null (#546).
  g.base_game
from public.game as g;

revoke all on public.game_browse from anon, authenticated, service_role;
grant select on public.game_browse to anon, authenticated, service_role;
