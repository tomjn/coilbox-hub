-- A few units per game, picked at random, for a game that has never reported
-- its start units.
--
-- The listing card draws the unit each side starts with, because a BAR
-- commander beside a Spring 1944 army HQ tells two games apart where two logos
-- only name them (lib/games/sides.ts). A game whose client has not sent
-- start_units draws nothing there, and the card ends in a band of empty card.
-- Those games still have a thousand units in the catalog, so the card shows a
-- handful of them instead: not the right units, but real ones, and a reader
-- learns more from three pictures of tanks than from a gap.
--
-- ## Why a function and not a query
--
-- PostgREST has no per-group limit, so "three units for each of these six
-- games" is either six round trips or one read of every unit row the six games
-- own. A lateral does it in one query and sends back eighteen rows, and
-- `order by random()` inside the lateral is what makes the three independent
-- picks rather than three names that happen to sort next to each other - which
-- in these games means three spellings of the same tank.
--
-- Security invoker, so the row level security on public.game and
-- public.game_unit decides what a caller sees, exactly as it does for the reads
-- this stands beside. The function adds no reach: it selects the columns
-- lib/games/sides.ts already reads through PostgREST.
--
-- Volatile, which is the default and is left unmarked deliberately: random()
-- means two calls with one argument list are two different answers, and
-- labelling that stable would licence the planner to fold them together.
--
-- p_count is clamped so a caller cannot ask for a game's whole unit list one
-- lateral at a time. Six is above anything a card has room for.
create function public.game_sample_units(p_shortnames text[], p_count integer)
returns table (
  shortname text,
  unit_name text,
  full_name text,
  faction_key text
)
language sql
set search_path = ''
as $$
  select g.shortname, sample.unit_name, sample.full_name, sample.faction_key
  from public.game as g
  cross join lateral (
    select gu.unit_name, gu.full_name, gu.faction_key
    from public.game_unit as gu
    where gu.game_id = g.id and gu.removed_at is null
    order by random()
    limit least(greatest(coalesce(p_count, 0), 0), 6)
  ) as sample
  where g.shortname = any (p_shortnames);
$$;

-- ## Access
--
-- Asked in a browser, by the games listing and by a game's own page, both
-- reading as anon or as the visitor's session. 20260827120000 turned the
-- blanket default off, so this grant is the whole of who may call it.
revoke execute on function public.game_sample_units(text[], integer) from public;
grant execute on function public.game_sample_units(text[], integer) to anon, authenticated;
