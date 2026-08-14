-- A third subject: every map that has no row of its own (issue #121).
--
-- The game side works because there are three games and each has a repository
-- to read. The map side has neither. There is no central licence for Recoil
-- maps, no field anywhere that could carry one, and roughly 3,575 maps in the
-- minimap seed. BAR's own maps-metadata repository tracks the author of all 226
-- maps it covers and contains the string "licen" zero times in 22,350 lines,
-- across six schemas none of which defines a licence field. So a minimap
-- carries whatever the individual mapper chose, which is almost always nothing
-- written down, and no amount of further reading turns that into per map
-- answers.
--
-- That left the largest and most useful batch in the seed unable to publish
-- anything at all, which is a decision to make rather than a bug to fix. The
-- maintainer made it on 2026-08-14: maps are allowed by default.
--
-- Shape. A boolean rather than a sentinel `map_name`, because every sentinel
-- string is a real map name somebody could ship, and because `map_name` is
-- documented as the full canonical name and nothing else. `all_maps` joins
-- `game` and `map_name` in the same num_nonnulls subject check, so a row is
-- still about exactly one thing, and the partial unique index below keeps there
-- being exactly one of it.
--
-- What this costs. A per map row is no longer the only answer a lookup can
-- find, so a lookup for a map is now two questions rather than one: the map's
-- own row, then this one. Per map rows still exist and still win, which is what
-- makes the rare map that does state terms recordable, and what makes any
-- single map revocable without touching the default. See
-- lib/assets/licence.ts:licenceForMap.
--
-- What this deliberately does not do. There is no equivalent for games. Three
-- games with three repositories is a researchable set, and a blanket game row
-- would let a fourth game publish on the strength of nobody having looked.

alter table public.asset_licence
  add column all_maps boolean check (all_maps);

alter table public.asset_licence
  drop constraint asset_licence_subject_check;

alter table public.asset_licence
  add constraint asset_licence_subject_check
    check (num_nonnulls(game, map_name, all_maps) = 1);

-- One default, for the same reason there is one row per game: two would let a
-- lookup return either answer, and the wrong half of that is a permanent
-- publication.
create unique index asset_licence_all_maps_idx
  on public.asset_licence (all_maps) where all_maps;

-- `licence` and `licence_url` stay null, and that is the finding rather than an
-- omission. There is no document to name and no page to link. The permission
-- rests entirely on `decision`, which is what the widened evidence check in
-- 20260814170000 exists to allow.
insert into public.asset_licence
  (all_maps, licence, licence_url, checked_at, checked_by,
   decision, decided_at, redistribute_extracted, redistribute_rendered, notes)
values
  (
    true,
    null,
    null,
    '2026-08-14T00:00:00Z',
    'claude agent, issue #121',
    $decision$Maintainer decision, 2026-08-14, in his words: "for almost all maps you will find no licensing information". Maps are allowed by default, for extraction and for rendering, on the same grounds he gave for the games: other Vercel hosted sites in the Beyond All Reason community already publish this material, so the hub doing it is community practice rather than a novel risk.

This is a decision about what the hub is willing to publish. It is not a claim that any mapper granted permission, and nothing here should be read as one. Any individual map can be taken back out by inserting a row for its canonical name with the permissions set to `denied`, which needs no evidence and does not disturb this row.$decision$,
    '2026-08-14T00:00:00Z',
    'allowed',
    'allowed',
    $note$No central licence source exists for Recoil maps and no field anywhere carries one.

Checked: the SpringFiles API, which returns no licence field per map, and https://github.com/beyond-all-reason/maps-metadata, which tracks author on 226 entries and contains no occurrence of "licen" in 22,350 lines across its six schemas.

The one automated route to a genuine per map answer is unused so far: some mappers ship a readme or licence file inside the .sd7 or .sdz. Reading those during the seed would turn this default into a real answer for the subset that bothered, and each of those becomes a per map row that overrides this one.

Decision recorded at https://github.com/tomjn/coilbox-hub/issues/121$note$
  );
