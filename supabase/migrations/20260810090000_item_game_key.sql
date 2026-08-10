-- Two items targeting the same game could still read as two different games
-- (issue #50). describe() (lib/gallery/publish.ts) already prefers the
-- modinfo shortname over the exact archive name, because the archive name
-- pins one build and carries a version, but a single game_name column served
-- both what a person reads and what a listing groups by. When one row had a
-- shortname and another for the same game only had the archive name -
-- unavoidable per lib/container/gameIdentity.ts:22-25, since the shortname
-- only exists in the game's modinfo and coilbox reads that from the
-- installed archive - the two rows spelled the game differently and the
-- gallery filtered them as two games rather than one.
--
-- game_name keeps its old meaning: what a person reads, shortname preferred,
-- falling back to the archive name so an item still shows something. This
-- new column is what a listing groups and filters by, and it is only ever
-- the shortname - the one spelling stable across a game's releases. An item
-- that only carries the exact archive name gets no key here rather than
-- being fed into the facet under a value nothing else shares, so a new
-- release of a game never mints a facet of its own.
alter table public.item add column game_key text;

-- Replaces item_live_game_idx: filtering now runs against game_key (see
-- applyFilters in lib/gallery/query.ts), so an index on game_name serves no
-- query any more.
drop index if exists public.item_live_game_idx;
create index item_live_game_key_idx on public.item (game_key) where deleted_at is null;

-- Same shape as the game_name grants beside these: publishItem computes both
-- columns at insert time (lib/gallery/publish.ts), and the backfill
-- (scripts/backfill-game-names.ts, extended for issue #50) is the only thing
-- allowed to rewrite game_key on an existing row.
grant insert (game_key) on public.item to authenticated;
grant update (game_key) on public.item to service_role;
