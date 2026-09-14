-- The games listing says where a game's logo is staged, and which bytes it is
-- (issue #345).
--
-- Until promotion runs, a new logo is only in the private staging bucket, and
-- the card drew it from GitHub Pages, which does not have it yet. The card now
-- draws a staged logo from the hub's own route, whose URL names the hash. So
-- the view has to publish the hash and the staged tier beside the path.
--
-- Both columns are appended, for the reason 20260823120000_game_browse_logo.sql
-- gives: create or replace refuses a column inserted between existing ones.
-- Neither discloses anything new. Anon can already select every column of
-- public.game, and security_invoker keeps the game's read policy in force.
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
