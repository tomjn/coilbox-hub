-- What the hub knows about a map, rather than what it knows about a picture of
-- one (issue #182).
--
-- public.asset knows a map well enough to file a picture under it and no
-- better: a name, a variant, and the size in elmos without which an overlay is
-- misaligned. Everything else a person would want to know about a map - who
-- made it, how much of it is water, where the metal is - was somebody else's
-- list, and since #180 stopped showing pictures the hub does not hold itself,
-- that somewhere else is nowhere. So the facts get tables of their own.
--
-- Identity is the rule public.asset already sets out at length, and it is the
-- same rule here for the same reason. map_name is the full canonical name the
-- engine reports, version string and all, and it is never parsed. A mapper who
-- changes a map releases it as a new map with a version appended, which is why
-- map lists show the same map several times over. One canonical name is one
-- archive, permanently, and a remade map is a new row rather than an edit to an
-- old one. A listing that wants to group revisions derives a family name for
-- display, where being wrong is a cosmetic grouping error and nothing worse.
--
-- ## No foreign key to public.asset
--
-- Both sides key on map_name independently and neither references the other. A
-- minimap can arrive before anybody submits the facts, or years after, and a
-- foreign key either way would refuse whichever came first. The join is on
-- map_name at read time, and one side missing is an ordinary state rather than
-- a broken row.

create table public.map (
  id uuid primary key default gen_random_uuid(),

  -- Identity, and the only column that carries it. The same value
  -- public.asset.map_name holds for the same map, which is what makes the two
  -- tables joinable without a key between them.
  map_name text not null check (length(btrim(map_name)) between 1 and 256),

  -- The name in a URL. The route computes it from map_name and a client never
  -- sends one, so it is unique for the same reason map_name is: it addresses
  -- one map. No shape is imposed here. Map names carry punctuation, accents and
  -- scripts in every combination, so a pattern written now would refuse a name
  -- nobody has met yet rather than catch a bug, and the route is the one place
  -- that has to agree with itself about how a name becomes a slug.
  slug text not null check (length(btrim(slug)) between 1 and 256),

  -- What mapinfo calls the map and what the mapper wrote about it. Display
  -- only, and both nullable, because plenty of archives fill in neither and
  -- map_name is what a listing falls back to.
  display_name text check (length(btrim(display_name)) between 1 and 256),
  description text check (length(description) <= 4000),

  -- The version the archive declares, kept beside map_name rather than parsed
  -- out of it. It sorts revisions of one family next to each other and nothing
  -- keys on it, so a missing or malformed value costs a listing its ordering
  -- and never loses the map.
  map_version text check (length(btrim(map_version)) between 1 and 64),

  -- A real filename, which a mirror template needs to build a download link.
  -- This is not source_archive below. That one is the archive's declared name
  -- and is provenance, exactly as it is on public.asset. This one is a file a
  -- mirror actually serves, and it never doubles as identity, because two
  -- mirrors can hold one map under different filenames.
  archive_filename text check (length(btrim(archive_filename)) between 1 and 256),

  -- The map in world units, the same elmos public.asset.map_width holds. Every
  -- overlay and every point below is in this coordinate space, so a listing
  -- that draws a map at all needs both.
  width_elmos integer not null check (width_elmos > 0),
  height_elmos integer not null check (height_elmos > 0),

  -- The world heights the terrain spans. Mandatory here, unlike on
  -- public.asset, where they live only on an overlay:height row because that is
  -- the only row whose ramp means anything without them. The reasoning is
  -- map_width's: the archive is the only thing that has them, and nothing
  -- downstream of extraction can recover them, so a row without them can never
  -- be repaired and is refused instead.
  world_height_min real not null,
  world_height_max real not null,

  -- Wind and tide, which decide how a map plays before anybody has looked at
  -- it. Nullable because mapinfo leaves them out and the engine falls back to
  -- its own defaults, and a zero written in place of an absent value would read
  -- as a map with no wind at all.
  min_wind real,
  max_wind real,
  tidal_strength real,

  -- Two maps with no sea and no land respectively. Both are ordinary, both
  -- change what a listing should say, and neither is derivable from anything
  -- else on the row.
  void_water boolean,
  void_ground boolean,

  -- The share of height samples below zero, as a fraction rather than a
  -- percentage, so no reader has to guess which. Null on a map with no water at
  -- all, since a coverage of zero on a void water map would claim the sampling
  -- ran and found dry land, which is a different fact from not applying.
  water_coverage real check (water_coverage between 0 and 1),

  -- Everything only the 3D view reads: sixteen float triples of water, sky,
  -- sun, fog and ground light that nothing will ever filter or sort on. Forty
  -- typed columns for that is worse than a blob, and the rule the rest of this
  -- table follows is the other half of the same decision: anything a listing
  -- filters or sorts on gets a column of its own, and only that.
  appearance jsonb not null default '{}',

  -- Tags a maintainer put there, not tags the archive declared. The archive's
  -- own words are in description, and mixing the two would make a curated
  -- listing rewritable by whoever packaged the map.
  curated_tags text[] not null default '{}',

  -- Provenance, the same pair public.asset carries. source_hash is over the raw
  -- archive bytes, source_archive is what the archive declared itself to be,
  -- and nothing keys or joins on either.
  source_hash text not null check (length(btrim(source_hash)) between 1 and 128),
  source_archive text not null check (length(btrim(source_archive)) between 1 and 256),

  -- Which extraction produced this row. When the hub learns to read a field it
  -- previously ignored, every row written before that is stale in a way nothing
  -- on the row itself would show, and this is what a re-extraction pass selects
  -- on.
  catalog_version integer not null check (catalog_version > 0),

  -- The hub computes this over the normalised entry including the points, and a
  -- client never sends one. It is what makes "the same facts" a single equality
  -- rather than a field by field comparison, which is the kind of comparison
  -- that grows a bug every time a column is added and reads as "nothing
  -- changed" when it does.
  facts_digest text not null check (length(btrim(facts_digest)) between 1 and 128),

  -- Null for anything seeded rather than submitted. Set null rather than
  -- cascade, for the reason public.asset.uploaded_by gives: closing an account
  -- must not take the catalog entries the hub is serving with it.
  submitted_by uuid references auth.users (id) on delete set null,

  -- When a client last reported this map present, which is not when the row was
  -- written. A map nobody has seen for a year is still a map.
  seen_at timestamptz not null default now(),

  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),

  -- A flat map has one height, so the ends may meet. Reversed, every sample
  -- reads upside down and nothing about the result looks wrong, which is the
  -- same trap asset_height_range_order_check covers on the overlay row.
  constraint map_world_height_check check (world_height_max >= world_height_min),

  -- Only when the archive declares both. A reversed pair would make every wind
  -- reading a listing shows the wrong way round.
  constraint map_wind_range_check check (
    min_wind is null or max_wind is null or max_wind >= min_wind
  ),

  -- A map with no water has no share of it to report. A number here alongside
  -- void_water would be two answers to one question, and a reader has no way to
  -- tell which one the extractor meant.
  constraint map_void_water_coverage_check check (
    void_water is not true or water_coverage is null
  )
);

-- One row per canonical name, which is the whole of the identity rule above,
-- and the index every read starts from because map_name is what public.asset
-- joins on.
create unique index map_identity_idx on public.map (map_name);

-- One row per slug as well, because a slug addresses a map and two rows sharing
-- one would make a URL ambiguous rather than merely ugly.
create unique index map_slug_idx on public.map (slug);

-- Same reason as on item and asset: the client that writes it is the one being
-- authorised.
create trigger map_touch_updated_at
  before update on public.map
  for each row execute function public.touch_updated_at();

-- Start positions are not the only points a map has, and the three kinds are
-- not the same shape.
--
-- A start is a team spawn and its ordinal is the team index, so the order is
-- the fact. A geo is a geothermal vent and carries the feature type name. A
-- metal spot carries an amount and a radius, and a large map has well over a
-- hundred of them.
--
-- One jsonb array on public.map would put three shapes under one key, and
-- "maps with more than twenty metal spots" would be a jsonb walk over every row
-- in the table instead of a count on an indexed column. Three tables would
-- repeat the same five columns three times to no benefit, since every reader
-- wants all three kinds at once when drawing a map.
create table public.map_point (
  -- A bigint rather than a uuid, because this table is a few hundred rows per
  -- map and nothing outside the database ever names a point by its id.
  id bigint generated always as identity primary key,

  -- Cascades, unlike every other reference in this migration. A point is not a
  -- fact about anything except its map, so it has nothing left to say once the
  -- map row is gone.
  map_id uuid not null references public.map (id) on delete cascade,

  -- Widening this later is a one line migration, the same as item_kind_check.
  -- Writing kinds down before anything produces them would refuse rows rather
  -- than catch a mistake.
  kind text not null check (kind in ('start', 'metal', 'geo')),

  -- Position within its kind, starting at zero. For a start position this is
  -- the team index and carries meaning, and for the other two it is the order
  -- the archive listed them in, which is stable but arbitrary.
  ordinal integer not null check (ordinal >= 0),

  -- World coordinates in elmos, named the way the engine reports them: x across
  -- and z along, with y the vertical. Calling the second one y here would put
  -- the hub one silent axis swap away from every map it draws.
  x real not null,
  z real not null,

  -- Nullable because the engine resolves a spawn height from the terrain rather
  -- than storing one, so most start positions genuinely have no y to record.
  y real,

  -- Whatever the kind carries and nothing else does: the amount and radius of a
  -- metal spot, the feature type of a geo. Nothing filters on it, which is the
  -- same test appearance on public.map passes.
  meta jsonb
);

-- One point per position per kind, so re-reading an archive replaces the set
-- rather than doubling it. Also the index every read uses, since map_id leads.
create unique index map_point_identity_idx
  on public.map_point (map_id, kind, ordinal);

-- Who made a map, as the archive credits them and as the hub files them.
--
-- raw is exactly what the archive said, kept because it is the evidence and
-- because a mapper's own spelling of their name is theirs. key is the
-- normalised form the hub groups on, computed by the route. Storing only the
-- key would throw away the credit, and storing only the raw string would make
-- "everything by this person" a text search that misses half of it.
create table public.map_author (
  id bigint generated always as identity primary key,

  map_id uuid not null references public.map (id) on delete cascade,

  -- The order the archive credited them in, which is not alphabetical and is
  -- not the hub's to reorder.
  credit_index integer not null check (credit_index >= 0),

  raw text not null check (length(btrim(raw)) between 1 and 256),
  key text not null check (length(btrim(key)) between 1 and 256)
);

-- One credit per position, so re-reading an archive rewrites the credits rather
-- than appending a second copy of them.
create unique index map_author_credit_idx
  on public.map_author (map_id, credit_index);

-- Every map by one author, which is the question this table exists to answer.
create index map_author_key_idx on public.map_author (key);

-- Two keys that are one person.
--
-- Normalising a credit gets most of the way there and no further. People
-- change handle, sign one map with a clan tag and the next without, and no
-- rule applied to a string can know that. So the remaining cases are a
-- maintainer's judgement, recorded here, and map_author.key is left exactly as
-- computed so the evidence for a merge stays visible after it.
--
-- One hop. from_key resolves to to_key and the reader stops there, because a
-- chain is a loop waiting to happen and a maintainer merging into an alias
-- rather than a person is the mistake to notice, not to follow.
create table public.author_alias (
  from_key text primary key check (length(btrim(from_key)) between 1 and 256),
  to_key text not null check (length(btrim(to_key)) between 1 and 256),

  -- Why the maintainer thinks these are one person, which is the part nobody
  -- remembers a year later.
  note text check (length(note) <= 2000),

  -- Set null rather than cascade, the same as map.submitted_by: a merge is a
  -- decision about the catalog and it outlives the account that made it.
  set_by uuid references auth.users (id) on delete set null,

  set_at timestamptz not null default now(),

  -- An alias to itself is a hop to nowhere that a reader has to spot at every
  -- lookup, and it arrives as a caller bug rather than a decision.
  constraint author_alias_not_self_check check (from_key <> to_key)
);

-- Where a person can download a map from.
--
-- The hub holds no archives. It holds facts about them, and a download link is
-- somebody else's server, so the hosts are a table rather than a constant: a
-- mirror that goes down is a row turned off, not a deploy.
create table public.map_mirror_host (
  id bigint generated always as identity primary key,

  name text not null check (length(btrim(name)) between 1 and 128),

  -- The URL with archive_filename substituted in. No shape is checked here,
  -- because the placeholder convention belongs to the code that renders the
  -- template and a check written now would be a second, quietly diverging copy
  -- of it.
  url_template text not null check (length(btrim(url_template)) between 1 and 512),

  -- Off rather than deleted, so a mirror that comes back is one column and its
  -- template is still the one that was known to work.
  enabled boolean not null default true,

  -- Which mirror to offer first. Ties are arbitrary and that is fine, since the
  -- ordering is a preference and not a fact.
  sort_order integer not null default 0,

  note text check (length(note) <= 2000)
);

-- One row per host, so turning a mirror off really turns it off rather than
-- leaving a second copy of it enabled.
create unique index map_mirror_host_name_idx on public.map_mirror_host (name);

-- Two clients disagreeing about what one archive contains, which is
-- public.asset_source_conflict with map_id in place of asset_id.
--
-- It is the same signal for the same reason. Extraction is deterministic, so
-- two installs of one archive produce the same facts and therefore the same
-- source_hash. A different hash from a different archive is a version rollover
-- and is ordinary. A different hash from the same archive means one of the two
-- clients is not doing what it says it is doing, either because somebody
-- modified it or because the install is corrupt.
--
-- And it keeps the same restraint. Recording a disagreement refuses one
-- submission, holds nothing else back, moves no row and changes no state. What
-- it does is mark the row so a reviewer knows where to look.
create table public.map_source_conflict (
  id bigint generated always as identity primary key,

  map_id uuid not null references public.map (id),

  -- The archive both sides named. Copied rather than joined, because the map
  -- row moves on: an accepted resubmission rewrites source_hash and
  -- source_archive, and this row has to go on saying what the disagreement was
  -- about afterwards.
  source_archive text not null check (length(btrim(source_archive)) between 1 and 256),

  -- What the row held at the time, and what the submission declared. Both,
  -- because either one alone leaves the reader unable to tell which set of
  -- facts is the odd one out once a third report arrives.
  held_source_hash text not null check (length(btrim(held_source_hash)) between 1 and 128),
  reported_source_hash text not null check (length(btrim(reported_source_hash)) between 1 and 128),

  -- A plain uuid with no foreign key, for the reason
  -- asset_source_conflict.reported_by gives: map.submitted_by empties itself
  -- when an account closes, and the account most likely to close in a hurry is
  -- the one behind an anomaly.
  reported_by uuid,

  at timestamptz not null default now(),

  -- Agreeing hashes are not a conflict, and a row saying they are would be a
  -- caller bug arriving as evidence.
  constraint map_source_conflict_differs_check
    check (held_source_hash <> reported_source_hash)
);

-- One row per distinct set of reported facts, so a client looping on the same
-- refused submission leaves one record rather than a table full of them. Also
-- the index a reviewer reads by map_id, since map_id leads.
create unique index map_source_conflict_report_idx
  on public.map_source_conflict (map_id, reported_source_hash);

-- ## Access
--
-- Row level security on every table, and no policy anywhere without a grant
-- behind it. A grant says what a role may attempt and a policy says which rows
-- it may touch, they are independent layers, and either one shut is enough.
-- That is the rule every table here has followed since #59 found production
-- holding grants these migrations never wrote.
--
-- Authoritative rather than additive, in the style of 20260810120000: whatever
-- these roles hold is taken away first, so a privilege some hosted default
-- handed out is gone rather than sitting underneath the grants below where
-- nothing would notice it.

revoke all on public.map from anon, authenticated, service_role;
revoke all on public.map_point from anon, authenticated, service_role;
revoke all on public.map_author from anon, authenticated, service_role;
revoke all on public.author_alias from anon, authenticated, service_role;
revoke all on public.map_mirror_host from anon, authenticated, service_role;
revoke all on public.map_source_conflict from anon, authenticated, service_role;

-- The five catalog tables are public in full. Unlike public.asset there is no
-- moderation state to filter on: a row exists because somebody submitted facts
-- about a map that is already published everywhere else, and nothing on it
-- names a person except submitted_by, which is the same disclosure
-- item.author_id already makes.
grant select on public.map to anon, authenticated;
grant select on public.map_point to anon, authenticated;
grant select on public.map_author to anon, authenticated;
grant select on public.author_alias to anon, authenticated;
grant select on public.map_mirror_host to anon, authenticated;

create policy map_read_all on public.map
  for select to anon, authenticated
  using (true);

create policy map_point_read_all on public.map_point
  for select to anon, authenticated
  using (true);

create policy map_author_read_all on public.map_author
  for select to anon, authenticated
  using (true);

create policy author_alias_read_all on public.author_alias
  for select to anon, authenticated
  using (true);

create policy map_mirror_host_read_all on public.map_mirror_host
  for select to anon, authenticated
  using (true);

-- Writes are service_role only, which means the API routes and nothing else.
-- The routes are what compute slug, facts_digest and the author keys, and a
-- direct PostgREST insert with the publishable key would skip all three: a row
-- with no slug is unreachable, a row with a made up digest reads as unchanged
-- facts forever, and an unkeyed credit belongs to nobody.
--
-- Select as well as insert and update, because a route reads before it writes.
-- Deciding whether a submission is the same facts is a lookup on facts_digest,
-- and an insert that returns the stored row needs select on it.
grant select, insert, update on public.map to service_role;
grant select, insert, update on public.author_alias to service_role;
grant select, insert, update on public.map_mirror_host to service_role;

-- Delete as well, on the two tables that hold a set rather than a row. A
-- resubmission does not edit a map's points and credits, it replaces them, and
-- a map that loses a metal spot or a co-author has to lose the row too. Without
-- delete the route can only ever add, and the stale point stays on the map
-- forever with nothing to show it is stale.
--
-- public.map itself gets no delete, for the reason public.asset gets none: a
-- superseded map is a fact about a map that existed, and nothing has a reason
-- to remove one yet, so nothing may.
grant select, insert, update, delete on public.map_point to service_role;
grant select, insert, update, delete on public.map_author to service_role;

-- The conflict table is server side on both sides and nowhere else. The
-- submission route records a disagreement and a reviewer reads which maps have
-- one. The reported hash names facts nobody has checked, and who reported them
-- is not the public's business, which is exactly the line
-- asset_source_conflict draws.
grant select, insert on public.map_source_conflict to service_role;

alter table public.map enable row level security;
alter table public.map_point enable row level security;
alter table public.map_author enable row level security;
alter table public.author_alias enable row level security;
alter table public.map_mirror_host enable row level security;

-- On with no policy at all, which refuses everyone holding the publishable key
-- however the grants above are read.
alter table public.map_source_conflict enable row level security;
