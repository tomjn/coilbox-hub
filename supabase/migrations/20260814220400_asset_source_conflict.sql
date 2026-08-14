-- Two clients disagreeing about what one archive contains (issue #116).
--
-- The same archive is the same file. Extraction is deterministic, so two
-- installs of `bar_1.2.sdz` that both hold a buildpic for `armsolar` produce
-- the same raw bytes and therefore the same source_hash. A different
-- source_hash from a different archive is a version rollover and is ordinary.
-- A different source_hash from the same archive is not: one of the two clients
-- is not doing what it says it is doing, either because somebody modified it or
-- because the install is corrupt.
--
-- That is a cheap signal and nothing else. It refuses no upload, moves no row
-- out of the queue and holds nothing back. What it does is mark the tile so a
-- reviewer working a contact sheet of a few hundred pictures knows which one to
-- look at properly. lib/assets/sourceConflict.ts carries the reasoning for why
-- there is nothing automated behind it, and it is worth reading before adding
-- anything that is.
--
-- ## Why a table of its own
--
-- Not public.asset_event. That table records what the public can see changing,
-- every row is a moderation decision with an actor behind it, and its check
-- constraint ties the kind to the action. A disagreement is not a decision and
-- usually accompanies a request that changed nothing at all, so it would have
-- to arrive as an action meaning "nothing happened", which is the sort of value
-- that makes an audit trail stop being one.
--
-- Not a column on public.asset either, and this is the substantive choice. The
-- case the issue is actually about is a second account reporting different
-- bytes, and under the rule in lib/assets/upload.ts that account cannot replace
-- the row: it is refused outright and, before this migration, nothing anywhere
-- recorded that it had ever asked. A boolean on the row would say "somebody
-- disagreed" and throw away who, what and when, which is the whole of the
-- signal. Writing to the row is also the one thing #115 asks nothing to do to a
-- safety rejected asset, and a side table never touches it.
--
-- So: one row per distinct set of bytes reported against one asset, keeping the
-- pair of hashes that disagreed and the account that reported the new one.
create table public.asset_source_conflict (
  id bigint generated always as identity primary key,

  asset_id uuid not null references public.asset (id),

  -- The archive both sides named. Copied rather than joined, because the asset
  -- row moves on: an accepted replacement rewrites source_hash and
  -- source_archive, and this row has to go on saying what the disagreement was
  -- about afterwards.
  source_archive text not null check (length(btrim(source_archive)) between 1 and 256),

  -- What the row held at the time, and what the upload declared. Both, because
  -- either one alone leaves the reader unable to tell which bytes are the odd
  -- ones out once a third report arrives.
  held_source_hash text not null check (length(btrim(held_source_hash)) between 1 and 128),
  reported_source_hash text not null check (length(btrim(reported_source_hash)) between 1 and 128),

  -- Who reported the new bytes. A plain uuid with no foreign key, for the
  -- reason public.asset_event.uploader gives: asset.uploaded_by empties itself
  -- when an account closes, and the account most likely to close in a hurry is
  -- the one behind an anomaly.
  reported_by uuid,

  at timestamptz not null default now(),

  -- Agreeing hashes are not a conflict, and a row saying they are would be a
  -- caller bug arriving as evidence.
  constraint asset_source_conflict_differs_check
    check (held_source_hash <> reported_source_hash)
);

-- One row per distinct set of reported bytes, so a client looping on the same
-- refused upload leaves one record rather than a table full of them. Also the
-- index the queue reads by asset_id, since asset_id is the leading column.
create unique index asset_source_conflict_report_idx
  on public.asset_source_conflict (asset_id, reported_source_hash);

revoke all on public.asset_source_conflict from anon, authenticated, service_role;

-- Read and write, both server side. The upload route records a disagreement and
-- the moderation grid reads which assets have one, and neither anon nor
-- authenticated has any business with either: the reported hash names bytes
-- nobody has reviewed, and who reported them is not the queue's to publish.
grant select, insert on public.asset_source_conflict to service_role;

-- On with no policy, which refuses everyone holding the publishable key. The
-- same rule as every other table here: safety must not rest on the absence of a
-- grant, which is the drift #59 found in production.
alter table public.asset_source_conflict enable row level security;
