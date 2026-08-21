-- Community content joins the game page (issue #244).
--
-- A game's shortname is already the tag its community files things under:
-- every item row carries `game_key`, and the gallery has filtered on it since
-- #50. What was missing is the connection on the other side of that join -
-- somebody reading about BA had no way to see that the hub also holds forty
-- blueprints for it.
--
-- So the count moves into the view where the other counts live, for the same
-- reason they did in 20260821120000: it is an aggregate over another table,
-- computing it per request in TypeScript would mean a request per page, and
-- every reader shares one answer.
--
-- Withdrawn items do not count. The row level security on public.item already
-- hides them from visitors, but the view is security invoker and reads by
-- shortname rather than through a policy scoped to this table, so the filter is
-- stated here once instead of trusted to whoever queries it. A count that said
-- forty while the gallery showed thirty-nine would be the wrong kind of honest.

create or replace view public.game_browse
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
    as unit_count,

  -- How much community content is published for it.
  (select count(*) from public.item as i
    where i.game_key = g.shortname and i.deleted_at is null)::integer
    as item_count
from public.game as g;

-- Grants are unchanged: select to everybody who could already read every table
-- behind the view, and nothing else, which 20260821120000 records the reasoning
-- for. Create or replace leaves them exactly where they were.
