-- The banner joins the games listing, so /api/v1/games can publish it.
--
-- The listing published a game's logo and card but not its banner, because
-- public.game_browse never carried the banner columns. A client drawing a game's
-- header had to scrape the game's page for it.
--
-- It is additive. The three columns are appended, which create or replace view
-- allows, so nothing existing moves and it can be applied before the code that
-- reads it ships. Everything above them is the view as
-- 20260918170000_game_download_sources.sql left it.
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
    as downloads,

  -- The card picture, resolved by the same three columns every other game
  -- picture uses.
  g.card_path,
  g.card_hash,
  g.card_staged_tier,

  -- The banner the game's page draws as its header, resolved the same way.
  g.banner_path,
  g.banner_hash,
  g.banner_staged_tier
from public.game as g;

revoke all on public.game_browse from anon, authenticated, service_role;
grant select on public.game_browse to anon, authenticated, service_role;
