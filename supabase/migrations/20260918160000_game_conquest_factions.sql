-- A game's own conquest factions, authored by a person rather than reported
-- by a client (#393).
--
-- ## Not public.game_faction
--
-- game_faction is one row per side a game reports, unique on (game_id, key)
-- and replaced wholesale by submit_game_facts on every facts submission
-- (20260821110000_game_facts.sql). A conquest faction is a different fact
-- about a game: a person names it, several entries can name one in-game side
-- - generateGalaxy asks factionSpecs for one faction per enemy plus the
-- player, up to four, while a catalog entry like Balanced Annihilation's
-- supplies two sides - and it has to survive the next facts resubmission
-- rather than being swept up in it. Reusing game_faction would mean either a
-- unique constraint that refuses the repeat this needs, or a table that a
-- client's own submission quietly deletes from under the person who wrote it.
--
-- ## One jsonb column, the shape public.game.links already set
--
-- lib/conquest/names.ts's FactionPreset is a name plus an optional colour and
-- an optional side, assigned in order. That is exactly the links column's own
-- shape - an open ended ordered list nothing filters on - so this follows it
-- rather than opening a new table: one jsonb array on public.game, order is
-- array order, no unique index on anything inside it, and shape is parsed in
-- lib/games/catalog.ts (parseConquestFactions) the same defensive way
-- parseGameLinks reads links. A new table would buy joinability this issue
-- has no use for - using the list to draw a shared galaxy is #397 - and would
-- still need its own ordering column to answer "which comes first".
--
-- Editable by the same pen as the rest of a game's words: the owner and
-- moderator update policies from 20260821130000_game_ownership.sql and
-- 20260914200000_game_edit_moderator.sql already cover every column this
-- migration's grant names, so no new policy is needed, only a wider grant.

alter table public.game
  add column conquest_factions jsonb not null default '[]'
    constraint game_conquest_factions_array_check check (jsonb_typeof(conquest_factions) = 'array');

comment on column public.game.conquest_factions is
  'Ordered [{name, color?, side?}], authored by the game''s owner or a moderator (#393). Untouched by submit_game_facts, unlike game_faction.';

-- The owner's pen widens by one column, the same way download_kind and
-- download_value joined it in 20260918120000_game_featured_download_card.sql:
-- column grants accumulate rather than replace, so revoking update outright
-- first and then granting the complete list keeps every migration's grant
-- statement readable as the whole current set rather than a diff against
-- migrations before it.
revoke update on public.game from authenticated;
grant update (display_name, description, links, download_kind, download_value, conquest_factions)
  on public.game to authenticated;
