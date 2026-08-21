-- What the hub knows about a game itself, rather than about pictures of its
-- units (issue #223).
--
-- public.asset files a buildpic or a top down render under a game's shortname,
-- and item.game_key groups published work by the same string, but both treat
-- it as opaque. Nothing anywhere answers what the game is called, who owns it,
-- which factions it has or what any unit in it does, because those are facts
-- about the game and not about its pictures. So the shortname gets tables of
-- its own, the way map_name did in 20260818100000.
--
-- ## One shortname is one game, permanently
--
-- The identity rule public.asset sets out applies here unchanged. BA and
-- Balanced Annihilation are one game wearing two names, so shortname is the
-- whole of the identity and display_name is display only, falling back to the
-- shortname wherever a reader needs something. An archive name would pin one
-- build and carry a version, which is exactly what the game_key migration
-- refused to group items on, and the same refusal holds here.
--
-- ## Versions are facts about facts
--
-- Where a map's canonical name carries its own version (a remade map is a new
-- map), a game's deliberately does not: the shortname stays stable across
-- releases while balance patches rewrite stats, add units and retire them. One
-- mutable row per unit would overwrite what previous versions said, and the
-- overwritten value would be gone rather than wrong, so current facts and
-- versioned facts live apart.
--
-- public.game_unit holds the facts as the newest submission reported them,
-- with source_version naming the release they were read from. A unit absent
-- from a submission that declared itself complete gets removed_at set rather
-- than deleted, because a balance patch retiring a unit is itself a fact about
-- the game. public.game_unit_revision holds what each version said, one row
-- per unit per version. Keying history by version rather than by change makes
-- both questions a reader actually asks - what were these stats in this
-- release, and how do two releases differ - single lookups, and lets a
-- re-extraction replace a row it got wrong without inventing a phantom
-- revision.
--
-- ## No foreign key to public.asset or public.item
--
-- All three sides key on the shortname independently, for the same reason
-- public.map does not reference public.asset: pictures can arrive years before
-- anybody submits the facts or the other way round, and a foreign key either
-- way would refuse whichever came first. Within the catalog the references
-- cascade, because a faction or a unit is not a fact about anything except its
-- game, and a version or a revision belongs to its unit.

create table public.game (
  id uuid primary key default gen_random_uuid(),

  -- Identity, and the only column that carries it. The same value
  -- public.asset.game and public.item.game_key hold for the same game, bounded
  -- the way asset.game is bounded.
  shortname text not null check (length(btrim(shortname)) between 1 and 64),

  -- What the game calls itself, when anybody has said. Nullable because a
  -- backfilled game starts as a shortname alone, and every reader falls back.
  display_name text check (length(btrim(display_name)) between 1 and 256),

  description text check (length(description) <= 4000),

  -- Where to find the game and its people, as labelled rows a reader can
  -- follow: [{label, url}]. A blob rather than typed columns because the set
  -- of places worth linking is open ended and nothing filters on it, which is
  -- the test map.appearance passes. Must be an array, because an object here
  -- is a caller bug that would render as one broken link instead of failing
  -- loudly at the boundary that should have caught it.
  links jsonb not null default '[]'
    constraint game_links_array_check check (jsonb_typeof(links) = 'array'),

  -- The unit each faction starts from, which is what a build tree groups by.
  -- Null until a client that knows reports it, and null rather than empty so
  -- never reported and reported none stay distinguishable.
  start_units text[],

  -- Who submitted the facts, set by the route from the bearer token. Set null
  -- rather than cascade, for the reason map.submitted_by gives: closing an
  -- account must not take the catalog rows the hub is serving with it.
  submitted_by uuid references auth.users (id) on delete set null,

  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create unique index game_shortname_idx on public.game (shortname);

create trigger game_touch_updated_at
  before update on public.game
  for each row execute function public.touch_updated_at();

-- Every release of the game anybody has reported facts for, and the list a
-- version picker offers without walking revisions to derive it. last_seen_at
-- moves on every report so a picker can sort by freshness, and neither stamp
-- is ever the client's clock.
create table public.game_version (
  id bigint generated always as identity primary key,

  -- Cascades, like every reference inside the catalog: versions are facts
  -- about one game and nothing else.
  game_id uuid not null references public.game (id) on delete cascade,

  version text not null check (length(btrim(version)) between 1 and 64),

  first_seen_at timestamptz not null default now(),
  last_seen_at timestamptz not null default now()
);

create unique index game_version_identity_idx
  on public.game_version (game_id, version);

-- Who a game's sides are, as the archive spells them. key is the normalised
-- form routes group and units point at, name is what a person reads, and both
-- exist for the reason map_author keeps raw and key: the spelling a game's
-- author chose is theirs, and grouping on it verbatim would split one side
-- across two labels the first time somebody capitalised differently.
--
-- A faction is a replaced set: a resubmission rewrites the list, so
-- service_role alone holds delete, and a logo is a durable tier path seeded
-- hub side until client uploads exist for them.
create table public.game_faction (
  id bigint generated always as identity primary key,

  game_id uuid not null references public.game (id) on delete cascade,

  key text not null check (length(btrim(key)) between 1 and 128),

  name text not null check (length(btrim(name)) between 1 and 256),

  -- Tier relative, never a fully qualified URL, for the reason
  -- lib/assets/cdn.ts records: the host is not data and moving hosts is not a
  -- migration.
  logo_path text check (length(btrim(logo_path)) between 1 and 512),
  logo_hash text check (length(btrim(logo_hash)) between 1 and 128)
);

create unique index game_faction_identity_idx
  on public.game_faction (game_id, key);

-- One unit of one game, holding the facts as the newest complete reading
-- reported them. Identity matches how public.asset keys a unit's pictures,
-- which is what makes an encyclopedia page a join at read time rather than a
-- reconciliation job.
create table public.game_unit (
  id bigint generated always as identity primary key,

  game_id uuid not null references public.game (id) on delete cascade,

  unit_name text not null check (length(btrim(unit_name)) between 1 and 128),

  -- What the def calls it, when it says. Nullable because plenty of defs fill
  -- in nothing and unit_name is what a listing falls back to.
  full_name text check (length(btrim(full_name)) between 1 and 256),

  -- Plain text rather than a foreign key, because a unit can arrive before
  -- its faction or after the faction list changed, and a dangling pointer to a
  -- side that went away is an ordinary state a reader renders as ungrouped,
  -- not a broken row.
  faction_key text check (length(btrim(faction_key)) between 1 and 128),

  -- The edges of the build graph, lowercased the way the worker reports def
  -- keys. An edge to a unit nobody has heard of is dropped by readers, not by
  -- this table, so the fact of having been reported survives a later
  -- extraction fixing a typo.
  build_options text[] not null default '{}',

  -- Whatever the extraction measured, schemaless for the same reason
  -- map.appearance is: nothing filters or sorts on it yet, forty columns of
  -- numbers would be worse, and the vocabulary lives in the client that
  -- produces it. Unknown keys render honestly rather than refusing the row.
  stats jsonb not null default '{}',

  -- Computed by the route over the normalised entry, never sent by a client,
  -- so "the facts are unchanged" is one equality instead of a field by field
  -- comparison that grows a bug every time a column is added.
  facts_digest text not null check (length(btrim(facts_digest)) between 1 and 128),

  -- The release the current facts were read from, kept beside them so a page
  -- can say how fresh the numbers are without joining anything.
  source_version text check (length(btrim(source_version)) between 1 and 64),

  -- When a submission that declared itself complete stopped listing this
  -- unit. Never deleted: retirement is a fact about the game, and the grid
  -- that hides retired units by default still owes their page to anybody with
  -- an old replay.
  removed_at timestamptz,

  last_seen_at timestamptz not null default now(),

  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create unique index game_unit_identity_idx
  on public.game_unit (game_id, unit_name);

-- The encyclopedia filters by faction, and the faction strip counts units per
-- side, so both reads want this rather than a scan of the game's units.
create index game_unit_faction_idx on public.game_unit (game_id, faction_key);

create trigger game_unit_touch_updated_at
  before update on public.game_unit
  for each row execute function public.touch_updated_at();

-- What each version said, one row per unit per version. Unique on (unit,
-- version) rather than on a digest because the question is "this release",
-- not "this set of facts": a re-extraction producing different facts for the
-- same version replaces the row, because the old ones were wrong rather than
-- historical, while the same facts arriving for a new release write a new row
-- even though the digest matches.
create table public.game_unit_revision (
  id bigint generated always as identity primary key,

  -- Cascades, though nothing deletes a unit any more: a revision is a fact
  -- about its unit and has nothing left to say once the unit is gone.
  unit_id bigint not null references public.game_unit (id) on delete cascade,

  version text not null check (length(btrim(version)) between 1 and 64),

  full_name text check (length(btrim(full_name)) between 1 and 256),
  faction_key text check (length(btrim(faction_key)) between 1 and 128),
  build_options text[] not null default '{}',
  stats jsonb not null default '{}',

  facts_digest text not null check (length(btrim(facts_digest)) between 1 and 128),

  -- When this version was first reported. A replacement on re-extraction
  -- leaves it alone, so it dates the knowledge and not the correction.
  recorded_at timestamptz not null default now()
);

create unique index game_unit_revision_identity_idx
  on public.game_unit_revision (unit_id, version);

-- ## Access
--
-- The same model as the map catalog, for the same reasons. Row level security
-- on every table, no policy anywhere without a grant behind it, authoritative
-- revokes first so whatever hosted defaults hand out is taken away rather than
-- sitting underneath the grants below where nothing would notice it.
--
-- Every row is world readable. A catalog row describes a game that is already
-- published everywhere else, and the one thing a browser must not do is write:
-- facts_digest, source_version and the faction keys are computed by the route,
-- and a row written straight through PostgREST with the publishable key would
-- read as unchanged facts forever and belong to nobody.
--
-- Delete goes to service_role on game_faction alone, because a faction list is
-- a replaced set and a side a balance patch removed has to lose its row.
-- Units are marked retired rather than deleted, and a game, a version and a
-- revision are records of things that existed, so nothing may remove them yet.

revoke all on public.game from anon, authenticated, service_role;
revoke all on public.game_version from anon, authenticated, service_role;
revoke all on public.game_faction from anon, authenticated, service_role;
revoke all on public.game_unit from anon, authenticated, service_role;
revoke all on public.game_unit_revision from anon, authenticated, service_role;

grant select on public.game to anon, authenticated;
grant select on public.game_version to anon, authenticated;
grant select on public.game_faction to anon, authenticated;
grant select on public.game_unit to anon, authenticated;
grant select on public.game_unit_revision to anon, authenticated;

create policy game_read_all on public.game
  for select to anon, authenticated
  using (true);

create policy game_version_read_all on public.game_version
  for select to anon, authenticated
  using (true);

create policy game_faction_read_all on public.game_faction
  for select to anon, authenticated
  using (true);

create policy game_unit_read_all on public.game_unit
  for select to anon, authenticated
  using (true);

create policy game_unit_revision_read_all on public.game_unit_revision
  for select to anon, authenticated
  using (true);

grant select, insert, update on public.game to service_role;
grant select, insert, update on public.game_version to service_role;
grant select, insert, update, delete on public.game_faction to service_role;
grant select, insert, update on public.game_unit to service_role;
grant select, insert, update on public.game_unit_revision to service_role;

alter table public.game enable row level security;
alter table public.game_version enable row level security;
alter table public.game_faction enable row level security;
alter table public.game_unit enable row level security;
alter table public.game_unit_revision enable row level security;
