-- Pictures for the things the gallery only names (issue #100). One row per
-- asset, and an asset is keyed on the unit or on the map, never on the archive
-- it came out of.
--
-- Two identities live in this table and they are shaped differently on purpose.
--
-- A unit is keyed on (game, unit_name, variant). Unit names are not unique
-- across games: BAR, XTA and BA all have a commander and a solar collector, the
-- units are similar and the pictures may differ. `game` is the game's shortname,
-- the same value item.game_key holds, and never a version. One set per game, and
-- a newer archive replaces the row rather than adding to it.
--
-- A map is keyed on (map_name, variant), with no game in it at all. The same map
-- archive is used across BAR, XTA and BA, so no map asset is ever scoped to one
-- game. `map_name` is the full canonical name the engine reports, version string
-- and all, and is never split: mapper conventions are inconsistent enough that a
-- parse failure either invents a duplicate or collides two genuinely different
-- maps, and a remade map has different terrain and needs its own row anyway. A
-- listing that wants to group revisions derives a family name for display only,
-- where being wrong is a cosmetic grouping error and nothing worse.
--
-- The two rules point opposite ways and are not unified for the sake of
-- consistency. A map arrives as one canonical string, so it is not split. A unit
-- arrives as two separate archive values, so they are not joined: a composite
-- key like `bar:armsolar` has to be taken apart again for every ordinary query,
-- such as every unit in a game, or rebuilding one game's atlas.
--
-- Nothing here is versioned, and there is no perceptual hashing, similarity
-- threshold or near duplicate detection anywhere in it. "Do we already have one
-- of these" is a lookup on one of the two unique indexes below.

create table public.asset (
  id uuid primary key default gen_random_uuid(),

  -- Identity. Exactly one of unit_name and map_name is set, and game is set with
  -- the first and absent with the second. See the three checks at the foot of
  -- the table.
  game text check (length(btrim(game)) between 1 and 64),
  unit_name text check (length(btrim(unit_name)) between 1 and 128),
  map_name text check (length(btrim(map_name)) between 1 and 256),
  variant text not null check (length(btrim(variant)) between 1 and 64),

  -- Two hashes, because extraction is deterministic and encoding is not.
  --
  -- source_hash is over the raw archive bytes and carries identity: dedupe, the
  -- batch have check (#103) and the anomaly check (#116) all compare on it. hash
  -- is over the encoded bytes and is the path component. Hashing only the output
  -- would silently break dedupe between users on different Coilbox or libwebp
  -- builds, and would read every encoder upgrade as a modified client.
  --
  -- Neither is unique. Two units in one game can legitimately share a picture,
  -- and a unique index here would refuse the second one.
  source_hash text not null check (length(btrim(source_hash)) between 1 and 128),
  hash text not null check (length(btrim(hash)) between 1 and 128),

  -- What produced the encoded bytes, so a later re-encode pass knows what it is
  -- looking at rather than inferring it from the file.
  encode_profile text not null check (length(btrim(encode_profile)) between 1 and 64),

  -- Tier relative, never a fully qualified URL. Promotion changes which host
  -- serves an asset but never the path component, so a stale reference 404s
  -- cleanly and re-resolves from the row rather than quietly returning the wrong
  -- image.
  path text not null check (length(btrim(path)) between 1 and 512),

  -- Where the bytes came from: extracted from an archive, rendered from a model,
  -- uploaded by a person. Free text rather than a literal list, unlike tier and
  -- moderation below, because the things that produce assets are still being
  -- written (#104, #105, #110) and a list guessed at now would refuse a
  -- legitimate row rather than catch a bug.
  origin text not null check (length(btrim(origin)) between 1 and 64),

  -- Which store actually holds it. Uploads land in Blob, the staging tier; the
  -- seed (#110) writes `static` straight to the durable tier without going near
  -- Blob, and promotion (#111) moves rows there afterwards.
  tier text not null default 'blob' check (tier in ('blob', 'static')),

  mime text not null check (length(btrim(mime)) between 1 and 128),
  bytes integer not null check (bytes > 0),

  -- The encoded image, in pixels.
  width integer not null check (width > 0),
  height integer not null check (height > 0),

  -- The map, in world units. Not the minimap texture, which is width and height
  -- above. Without these every overlay is subtly misaligned and the cause is
  -- hard to isolate, and the archive is the only thing that has them: nothing
  -- downstream of extraction can recover them. So they are mandatory on a map
  -- row rather than merely available, and meaningless on a unit row.
  map_width integer check (map_width > 0),
  map_height integer check (map_height > 0),

  -- Provenance only. Nothing keys, joins or filters on these; they answer where
  -- this came from and when it was last seen there.
  source_archive text not null check (length(btrim(source_archive)) between 1 and 256),
  seen_at timestamptz not null default now(),

  -- Null until #111 promotes the row out of Blob.
  promoted_at timestamptz,

  -- Null for anything seeded rather than uploaded. Set null rather than cascade:
  -- closing an account must not delete approved pictures the gallery is serving,
  -- unlike an item, which is the author's own work.
  uploaded_by uuid references auth.users (id) on delete set null,

  moderation text not null default 'pending'
    check (moderation in ('pending', 'approved', 'rejected')),

  -- How it came to be approved, once it is. Null while pending.
  approval_source text check (length(btrim(approval_source)) between 1 and 64),

  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),

  -- The two identity indexes below are partial, so neither of them ever sees a
  -- row that fills in both names, and such a row would sit in both keyspaces at
  -- once while colliding with nothing in either.
  constraint asset_identity_check check (num_nonnulls(unit_name, map_name) = 1),

  -- game belongs to the unit key and only to it. A map asset scoped to a game
  -- would be the same picture stored once per game that ships the map, and the
  -- three copies would drift.
  constraint asset_game_scope_check check ((game is null) = (unit_name is null)),

  constraint asset_map_size_check check (
    num_nonnulls(map_width, map_height) = case when map_name is null then 0 else 2 end
  ),

  -- The unit side of the variant vocabulary, which the issue settles: a
  -- buildpic, or a render from a given angle. The map side is minimaps and
  -- extracted overlay layers, and the issues that produce those name them, so
  -- writing half a vocabulary down would refuse rows that are not written yet.
  -- Widening this is a one line migration, the same as item_kind_check.
  constraint asset_unit_variant_check check (
    unit_name is null or variant = 'buildpic' or variant like 'render:%'
  )
);

-- The two identity keys. Partial, because each one covers only its own half of
-- the table, and unique, because both "do we already have one of these" and "a
-- newer archive replaces the row" depend on there being exactly one.
create unique index asset_unit_identity_idx
  on public.asset (game, unit_name, variant) where unit_name is not null;

create unique index asset_map_identity_idx
  on public.asset (map_name, variant) where map_name is not null;

-- Same reason as on item: the client that writes it is the one being authorised.
create trigger asset_touch_updated_at
  before update on public.asset
  for each row execute function public.touch_updated_at();

-- Row level security on with no policy at all, which refuses everyone. Grants
-- and policies are #102's, and it asserts both in supabase/tests. Leaving RLS
-- off until then would make the table's safety rest entirely on the absence of a
-- grant, which is the drift #59 found in production.
alter table public.asset enable row level security;
