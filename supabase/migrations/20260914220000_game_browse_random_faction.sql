-- The games listing does not count Random as a side.
--
-- Games report a faction called Random so a lobby can pick one for you, and
-- the game's page, the unit filters and the build tree already leave it out
-- (lib/games/factions.ts). The count on the card still included it, so BAR
-- read as 4 factions above a page showing 3. The rule here is the one in that
-- file: the key or the name is "random", case and surrounding space ignored.
--
-- Only faction_count changes. The body is otherwise the view as
-- 20260914210000_game_browse_logo_staging.sql left it, columns in the same
-- order, because create or replace refuses a reordering.
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
  g.logo_staged_tier
from public.game as g;

-- ## Access
--
-- Repeated from the view's first migration, because create or replace does not
-- carry grants forward.
revoke all on public.game_browse from anon, authenticated, service_role;
grant select on public.game_browse to anon, authenticated, service_role;
