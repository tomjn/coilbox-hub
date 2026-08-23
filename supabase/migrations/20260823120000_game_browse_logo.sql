-- The games listing carries each game's logo (issue #239).
--
-- Ownership (#229) put logo_path on public.game, and the listing card kept its
-- typographic look, because the view behind the listing did not publish the
-- column and the listing reads the view. This adds it: one passthrough column,
-- no aggregate, so nothing about how the view computes changes.
--
-- The banner stays off the listing on purpose. A card grid wants a small mark
-- above a name, and a wide picture there would set every row's height to the
-- tallest art in the catalog. The game page already draws the banner where
-- width belongs.
create or replace view public.game_browse
with (security_invoker = true) as
select
  g.shortname,
  g.display_name,
  g.description,
  g.logo_path,

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
-- holding grants these migrations never wrote. Repeated from the view's first
-- migration because create or replace does not carry grants forward.
revoke all on public.game_browse from anon, authenticated, service_role;

-- Select only, and no other grant is possible to want: every column added here
-- is derived from tables anon can already read, so the view discloses nothing
-- new to anybody. security_invoker keeps it that way by running under whoever
-- queries it, so the read-all policies on the tables behind it apply rather
-- than the owner's rights.
grant select on public.game_browse to anon, authenticated, service_role;
