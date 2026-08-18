-- What a reader browsing the catalog filters and sorts on (issue #189).
--
-- /maps is one page with five filters and four sort orders, and every one of
-- them has to be a column PostgREST can name. public.map_listing already carries
-- the tags, so this view adds the six things the tags cannot answer: when a map
-- arrived, how long its longer edge is, how many start positions it has, which
-- authors it counts under, what they are called, and the text a free search runs
-- over.
--
-- That list is the rule 20260818120000_map_listing.sql set for itself, applied
-- again: anything a listing filters or sorts on gets a column of its own, and
-- only that. Nothing here is added because it might be useful later.
--
-- ## A second view rather than six more columns on the first
--
-- Two things settled this, and one shape fixes both.
--
-- public.map_facts joins public.map_listing to read a tags array, and
-- /api/v1/maps/lookup is what every coilbox client calls to draw a lobby for a
-- map the player has not installed. Putting a start position aggregate and a
-- to_tsvector over every description in the catalog onto that view would make
-- the cheapest, most called read on the hub pay for a page it never renders.
-- The browse page should pay for what browsing needs.
--
-- The other is that create or replace view has to be handed the entire body. A
-- migration extending public.map_listing would restate all five tag rules
-- verbatim, and that is a second copy of the catalog's thresholds in a second
-- file. The next person moving one would have two places to move it and no
-- reason to suspect the second.
--
-- So public.map_listing is untouched, and this view selects from it. The tag
-- rules stay in one file and are inherited rather than copied, which is the
-- whole reason to build on it rather than beside it: a view reading public.map
-- directly would have to derive the tags again.
--
-- Two views is not a contradiction of the header rule. The lookup and the browse
-- page are two listings with different needs, and the rule is served better by
-- the cheap one not carrying the expensive one's columns.
--
-- The inheritance is `listing.*`, which Postgres expands to the columns that
-- exist today. A column added to public.map_listing later does not appear here
-- until this view is replaced, which is the right way round: what browse
-- publishes is a decision rather than a consequence.
--
-- ## The size filter is not a column
--
-- `small`, `medium` and `large` are already in tags, derived from the longer
-- edge by public.map_listing. A size filter is therefore the same array match a
-- tag filter is, and `?size=small` and `?tag=small` reach the database as the
-- same query. A column repeating the band would be a second answer to a question
-- the array has already answered, and the two would disagree the first time a
-- threshold moved.
--
-- longer_edge_elmos exists for the other half of that: banding is a filter and
-- the edge itself is a sort, and no ordering can be recovered from three words.
--
-- ## The author filter matches a resolved key
--
-- author_keys holds public.resolved_author_key of every credit on the map, which
-- is the key the credit counts as today rather than the one it was filed under
-- when it was submitted. A merge a maintainer records therefore takes effect on
-- the listing the moment it is recorded, with nothing resubmitted, which is the
-- whole argument 20260818110000_author_keys.sql makes for keeping that rule in
-- one place.
--
-- A reader arrives with a spelling rather than a key. The page turns it into one
-- by calling public.author_key and then public.resolved_author_key, both of
-- which anon may execute, and matches the answer against this array. Normalising
-- the name in TypeScript instead would be the second copy of the rule that
-- migration warns about, and the failure is silent: a listing showing nothing
-- while the credits sit in the table, correctly keyed, under a key the reader
-- spelled a hair differently.
--
-- ## Search is computed here, not stored
--
-- public.item stores its search vector as a generated column, which is the
-- better answer when everything being searched is on the row. Here it is not: a
-- map's authors are rows in public.map_author, and a generated column cannot see
-- another table. So the vector is built in the view.
--
-- That means a scan, and it is the same scan public.map_listing's header already
-- accepts and explains for the tag rules, at roughly 3,575 maps. It is a scan
-- only browsing pays for now, which is the point of the split above. If the
-- catalog outgrows it this view becomes materialised, with an index on the
-- vector, and none of the rules in either migration change.

-- Start positions are counted on every listing read and sorted on, and the index
-- the table already has leads with map_id, so counting them meant reading every
-- point of every kind. This is the same count over the only kind that has one: a
-- start is a team spawn, and the other two kinds are metal spots and geo vents,
-- of which a large map has hundreds.
create index map_point_start_idx on public.map_point (map_id) where kind = 'start';

create view public.map_browse
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
  ) as search

from public.map_listing as listing
-- A plain join on the id, for the two columns the listing view does not carry
-- and browse needs. public.map_listing is one row per map, so this adds no rows.
join public.map as map on map.id = listing.id
left join credited on credited.map_id = listing.id
left join start_point on start_point.map_id = listing.id;

-- ## Access
--
-- Whatever these roles hold is taken away first, so a privilege some hosted
-- default handed out is gone rather than sitting underneath the grants below
-- where nothing would notice it. That is the discipline every table and view has
-- followed since #59 found production holding grants these migrations never
-- wrote.
revoke all on public.map_browse from anon, authenticated, service_role;

-- anon and authenticated are the browse page, which reads with the visitor's own
-- client because nothing here needs the secret key. Everything the view derives
-- comes off public.map, public.map_author, public.map_point and
-- public.author_alias, all of which those roles already select through a read
-- all policy.
--
-- service_role holds select for the reason public.map_listing grants it: a route
-- listing maps server side would otherwise have to carry its own copy of these
-- rules, which is the drift these views exist to prevent. Nothing reads it that
-- way today - public.map_facts joins public.map_listing and not this view, which
-- is what keeps the lookup cheap - and the grant discloses nothing service_role
-- cannot already select.
--
-- Select only, and no other grant is possible to want: every column added here
-- is computed, so this is not a view Postgres will update on its own, and a
-- write to it would be a write to public.map, which only the routes may do.
grant select on public.map_browse to anon, authenticated, service_role;
