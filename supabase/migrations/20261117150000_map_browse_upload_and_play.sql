-- The maps page filters by play type and sorts by upload date, so the browse
-- view gains uploaded_at and play_types.
--
-- ## Why a function
--
-- public.archive grants nothing to the API roles, and map_browse runs with
-- security_invoker, so the view cannot read it as the caller. The security
-- definer function below reads it instead and returns only the one date. It
-- counts listed map archives only. A removed upload and a game archive that
-- happens to share the name do not count.
--
-- ## Play types
--
-- The rules are Coilbox's mapPlayTypes. A map is 1v1 when it has exactly two
-- start positions or carries a 1v1 tag in any letter case. It is team when it
-- has four or more start positions. A map can be both, or neither.
--
-- Both columns are appended after featured_at, because create or replace view
-- refuses a column added between existing ones. The body is that migration's,
-- unchanged apart from the two appended columns.

create function public.map_uploaded_at(p_map_name text)
returns timestamptz
language sql
stable
security definer
set search_path = ''
as $$
  select max(archive.listed_at)
  from public.archive as archive
  where archive.kind = 'map'
    and archive.state = 'listed'
    and archive.internal_name = p_map_name;
$$;

revoke all on function public.map_uploaded_at(text) from public;
grant execute on function public.map_uploaded_at(text) to anon, authenticated, service_role;

create or replace view public.map_browse
with (security_invoker = true)
as
-- One row per person per map, under the key that person counts as today. Two
-- credits on one map that resolve to one key are one author, the same reading
-- public.map_facts takes, and listing them twice would put the same mapper on
-- one card twice.
--
-- The earliest credit_index carries the order, because credit_index is the order
-- the archive credited them in and is not the hub's to reorder.
with credit as (
  select
    author.map_id,
    public.resolved_author_key(author.key) as key,
    min(author.credit_index) as credit_index
  from public.map_author as author
  group by author.map_id, public.resolved_author_key(author.key)
),

-- The two arrays a card needs, in the archive's own order and in step with each
-- other, so the name at position two belongs to the key at position two.
--
-- Two arrays rather than one jsonb array of pairs, because the keys are what the
-- author filter matches against and an array match is an index-shaped question
-- that jsonb is not.
--
-- A plain join, because every key here has at least one spelling: the credit row
-- being named is itself one of the rows public.author_display_name counted.
credited as (
  select
    credit.map_id,
    array_agg(credit.key order by credit.credit_index) as keys,
    array_agg(shown.name order by credit.credit_index) as names
  from credit
  join public.author_display_name as shown on shown.key = credit.key
  group by credit.map_id
),

-- How many the map is for, which is the count of team spawns the archive
-- declared and the only count there is. A map with none is a map the hub holds
-- an incomplete extraction of, and it comes out as no row here and a zero below.
start_point as (
  select
    point.map_id,
    count(*) as positions
  from public.map_point as point
  where point.kind = 'start'
  group by point.map_id
)

select
  -- Everything public.map_listing publishes, inherited rather than restated:
  -- the id, the name, the slug, the display name, the two dimensions and the
  -- merged tags array with all five rules behind it.
  listing.*,

  -- Recently added. Not when the map was made or released, which the hub has no
  -- way to know, and not seen_at, which moves whenever a client reports the map
  -- present and would reorder the whole catalog on nothing.
  map.created_at,

  -- The longer edge, which is the measure the size bands are cut from and
  -- therefore the only edge worth ordering on. In elmos rather than squares, so
  -- nothing rounds and two maps a half square apart sort apart.
  greatest(listing.width_elmos, listing.height_elmos) as longer_edge_elmos,

  -- A count rather than a null, because this is filtered on with a minimum and a
  -- null would drop a map out of `players=0` as well as out of `players=8`. What
  -- a page prints from it is the page's decision: playerCountLabel in
  -- lib/maps/labels.ts says nothing at all rather than "0 players", since a map
  -- with no start positions is an incomplete extraction rather than a map nobody
  -- can play.
  coalesce(start_point.positions, 0)::integer as start_positions,

  -- Empty rather than null on a map the archive credited nobody for, so a reader
  -- filtering by author never has to think about the difference and a card
  -- iterating the array draws nothing.
  coalesce(credited.keys, '{}') as author_keys,
  coalesce(credited.names, '{}') as author_names,

  -- Name, description and author, which is what #189 asks a free search to
  -- cover. The weights follow 20260809174257_item_search.sql: what the thing is
  -- called outranks what was written about it.
  --
  -- Both names are weighted A. map_name is identity and carries the version
  -- string, display_name is what the archive would rather be called, and a
  -- reader typing a map's name may have either one in mind.
  --
  -- The author terms are the names shown on the card rather than the raw
  -- credits, which is deliberate: searching a mapper's usual spelling finds the
  -- maps they signed under a clan tag or an older handle, because those credits
  -- resolve to the same key and the same shown name.
  setweight(to_tsvector('english', coalesce(listing.map_name, '')), 'A') ||
  setweight(to_tsvector('english', coalesce(listing.display_name, '')), 'A') ||
  setweight(to_tsvector('english', coalesce(map.description, '')), 'B') ||
  setweight(
    to_tsvector('english', array_to_string(coalesce(credited.names, '{}'), ' ')),
    'C'
  ) as search,

  -- Whether the catalog listing draws this one above the rest, and when a
  -- moderator said so (#394). Appended here rather than sat beside search,
  -- so this migration touches nothing about the columns above it.
  map.featured_at,

  -- When a listed map archive was last uploaded, or null if there never was one.
  public.map_uploaded_at(listing.map_name) as uploaded_at,

  -- Never null. An empty array is a map that is neither.
  array_remove(array[
    case when coalesce(start_point.positions, 0) = 2
      or exists (select 1 from unnest(listing.tags) as t(tag) where lower(t.tag) = '1v1')
      then '1v1' end,
    case when coalesce(start_point.positions, 0) >= 4 then 'team' end
  ], null) as play_types

from public.map_listing as listing
-- A plain join on the id, for the two columns the listing view does not carry
-- and browse needs. public.map_listing is one row per map, so this adds no rows.
join public.map as map on map.id = listing.id
left join credited on credited.map_id = listing.id
left join start_point on start_point.map_id = listing.id;

-- Repeated from the view's first migration, because create or replace does not
-- carry grants forward.
revoke all on public.map_browse from anon, authenticated, service_role;
grant select on public.map_browse to anon, authenticated, service_role;
