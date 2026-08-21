-- What the games listing shows about each game (issue #225).
--
-- A card says how many factions and units a game counts, and neither number is
-- a column of public.game: they are aggregates over two other tables, one of
-- them filtered by retirement. Computing them in the page would mean either a
-- request per game or pulling every unit row back to count it in TypeScript,
-- and both grow with the catalog rather than with the page.
--
-- So the numbers are computed once, here, where every reader shares them.
--
-- ## Why a view and not columns on public.game
--
-- The same reasoning 20260818160000_map_browse.sql records for not putting its
-- aggregates on public.map: a count that has to stay correct is a query, not a
-- stored value, and storing one buys a consistency problem with every
-- submission. The route writes facts; this view reads them.
--
-- Retired units do not count. A balance patch that removed a unit did not make
-- the game smaller in any way a reader of a listing cares about, and the
-- encyclopedia that does show retired units filters them itself. The count a
-- card carries answers "how much is there to look at", which is the live set.
create view public.game_browse
with (security_invoker = true) as
select
  g.shortname,
  g.display_name,
  g.description,

  -- How many sides the game has, as the archive names them.
  (select count(*) from public.game_faction as gf where gf.game_id = g.id)::integer
    as faction_count,

  -- How many playable units, retired ones excluded.
  (select count(*) from public.game_unit as gu
    where gu.game_id = g.id and gu.removed_at is null)::integer
    as unit_count
from public.game as g;

-- ## Access
--
-- Authoritative revokes first, the discipline since #59 found production
-- holding grants these migrations never wrote.
revoke all on public.game_browse from anon, authenticated, service_role;

-- Select only, and no other grant is possible to want: every column added here
-- is derived from tables anon can already read, so the view discloses nothing
-- new to anybody. security_invoker keeps it that way by running under whoever
-- queries it, so the read-all policies on the tables behind it apply rather
-- than the owner's rights.
grant select on public.game_browse to anon, authenticated, service_role;
