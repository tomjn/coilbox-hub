-- Whether the hub may redistribute a game's or a map's pictures, and what that
-- answer rests on (issue #97).
--
-- The durable tier is a public git repository, so its history is permanent.
-- Publishing something the licence did not allow is not a delete from a bucket,
-- it is a rewrite of a published history, and that is the asymmetry this table
-- exists for. So nothing here is ever allowed by default, and an absent row
-- reads exactly the same as one that says it does not know.
--
-- The point of a table rather than a constant is that the answer has to keep
-- the evidence, not just the verdict. A bare `permissive` throws away the only
-- thing that makes the decision defensible a year later: which licence, where
-- that claim came from, when somebody looked, and who looked. Those four are
-- the columns, and they are what a takedown request would be answered with.
--
-- Two subjects, shaped the same way `public.asset` is keyed, so a lookup is the
-- asset's own identity with the variant dropped.
--
--   game      the modinfo shortname, the value asset.game and item.game_key
--             hold. Never a version. A game relicences forwards, not per
--             release, and a per release row would go stale on every update.
--   map_name  the full canonical name including the version string, the value
--             asset.map_name holds, and never split. A remade map is a
--             different map by a possibly different author, so it is a
--             different row.
--
-- There is no games table and no maps table in this repo, and this is not one.
-- It is a set of decisions keyed by the strings the asset table already uses,
-- and it holds a row only for a subject somebody has actually looked at.
--
-- Nothing joins to it with a foreign key, deliberately. An asset row and a
-- licence row are written by different people at different times, and a
-- reference would either refuse the first asset for a game nobody has ruled on
-- yet or force a placeholder row that says nothing.

create table public.asset_licence (
  id uuid primary key default gen_random_uuid(),

  -- Exactly one of these. See asset_licence_subject_check below.
  game text check (length(btrim(game)) between 1 and 64),
  map_name text check (length(btrim(map_name)) between 1 and 256),

  -- The evidence. All three are nullable because "nobody could find this out"
  -- is a real and useful finding, and recording it stops the next person
  -- repeating the search. A null licence is not a permissive one.
  --
  -- `licence` is an SPDX identifier where the project publishes a clean one,
  -- and a plain description where it does not. Free text rather than a list:
  -- the honest answer for several Recoil games is a sentence about a mixed
  -- tree, and a list of SPDX identifiers would force that into a lie.
  licence text check (length(btrim(licence)) between 1 and 128),

  -- Where the claim came from. A licence file, a repository, a forum post, or
  -- an archived mail from an author. This is the column that survives the
  -- reasoning being forgotten.
  licence_url text check (length(btrim(licence_url)) between 1 and 512),

  -- Anything the other columns flatten. The mixed tree, the one directory that
  -- is different, the author who answered by mail.
  notes text check (length(btrim(notes)) between 1 and 4096),

  -- When somebody last looked, and who or what looked. A licence claim ages:
  -- a project can relicence, and a check from two years ago is a weaker answer
  -- than one from last week even when it says the same thing. `checked_by` is
  -- free text and takes a person, a handle or the name of whatever automated
  -- the search, because the useful distinction is a person reading a licence
  -- file against a script pattern matching one.
  checked_at timestamptz not null default now(),
  checked_by text not null check (length(btrim(checked_by)) between 1 and 128),

  -- The two permissions, and they are two rather than one on purpose.
  --
  -- Redistributing an image the archive already contains and publishing a
  -- render drawn from a model are different acts. A render is a derivative
  -- work, and a licence that plainly allows passing the shipped buildpic on
  -- does not always cover generating and publishing a new image from the
  -- model. Collapsing them into one flag would make the narrower answer
  -- unsayable, and the failure would be silent: renders published under a
  -- permission that was only ever given for extraction.
  --
  --   unknown  nobody has decided, or nobody could establish it
  --   allowed  the maintainer has decided the hub may publish this class
  --   denied   somebody looked and the answer is no
  --
  -- `unknown` and `denied` both block, so the split buys nothing at read time
  -- and everything at review time: it is the difference between a gap in the
  -- research and a settled no, and only one of those is worth looking at
  -- again. Default `unknown`, so a row inserted to record a licence grants
  -- nothing by the act of existing.
  redistribute_extracted text not null default 'unknown'
    check (redistribute_extracted in ('unknown', 'allowed', 'denied')),
  redistribute_rendered text not null default 'unknown'
    check (redistribute_rendered in ('unknown', 'allowed', 'denied')),

  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),

  -- A row is about a game or about a map. The two unique indexes below are
  -- partial, so a row filling in both would sit in both keyspaces while
  -- colliding with nothing in either, the same trap public.asset has.
  constraint asset_licence_subject_check check (num_nonnulls(game, map_name) = 1),

  -- Saying yes has to say what the yes rests on. This is the whole point of
  -- the table, and without it the first busy afternoon produces a row that
  -- allows everything and cites nothing.
  --
  -- `licence` rather than `licence_url`, because permission is not always a
  -- published document. An author answering by mail is recorded as a licence
  -- of its own words with the detail in `notes`, and that is a real answer.
  -- Saying no needs no evidence, since refusing to publish harms nobody.
  constraint asset_licence_evidence_check check (
    licence is not null
    or (redistribute_extracted <> 'allowed' and redistribute_rendered <> 'allowed')
  )
);

-- One decision per subject. Two rows for one game would let a lookup return
-- either answer, and the wrong half of that is a permanent publication.
create unique index asset_licence_game_idx
  on public.asset_licence (game) where game is not null;

create unique index asset_licence_map_idx
  on public.asset_licence (map_name) where map_name is not null;

create trigger asset_licence_touch_updated_at
  before update on public.asset_licence
  for each row execute function public.touch_updated_at();

-- Row level security on with no policy and no grant, which refuses everyone
-- holding the publishable key. That is the same position public.asset was left
-- in by 20260814090000, for the same reason: the table's safety must not rest
-- on the absence of a grant, which is the drift #59 found in production.
--
-- Read access is a decision rather than an oversight. An attribution page would
-- want this readable and probably should have it, but who may read a licence
-- record and who may change one are both #102's to settle alongside the asset
-- table's, and guessing at half of it here would be the third time the
-- migrations and the live grants disagreed.
alter table public.asset_licence enable row level security;
