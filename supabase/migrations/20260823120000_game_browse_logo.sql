-- The games listing carries each game's logo (issue #239).
--
-- Ownership (#229) put logo_path on public.game, and the listing card kept its
-- typographic look, because the view behind the listing did not publish the
-- column and the listing reads the view. This adds it.
--
-- Create or replace maps a view's old columns onto its new ones by position,
-- so a new column may only be appended, never inserted - Postgres refuses an
-- insertion as a rename (42P16). logo_path therefore sits after the aggregates
-- here rather than beside the identity columns it logically belongs with; the
-- hub selects by name, so nothing downstream sees the difference.
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
  g.logo_path
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
