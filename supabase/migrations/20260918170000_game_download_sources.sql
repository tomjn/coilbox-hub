-- A game's download sources as an ordered list, and enough on each one for
-- coilbox to fetch the file without a person reading a page (#396).
--
-- ## Why the single pair had to go
--
-- 20260918120000_game_featured_download_card.sql gave public.game one
-- download_kind and one download_value, held together by a check so a game has
-- one source or none. Real games have several, and they are not
-- interchangeable. Balanced-Annihilation/Balanced-Annihilation publishes no
-- GitHub releases at all, so only its rapid tag works. SplinterFaction ships
-- only through GitHub releases, so only its repo works. Coilbox's own
-- catalog.json already pairs a repo and a rapid name key on every
-- githubGameRepos entry for exactly that reason, and the hub could record one
-- of the two and lost the other.
--
-- Coilbox's client agrees at the code level: downloadGame.ts tries a
-- GameSource of 'github', then 'springfiles', then 'rapid', one after another,
-- and stops at the first that yields the file. An ordered list of sources is
-- that fallback chain written down, which is why sort_order is a column and
-- not a tiebreak.
--
-- ## A table, the shape public.map_mirror_host already set
--
-- One row per source, with its own kind, its own detail and its own place in
-- the order, is how a map's mirrors have worked since
-- 20260818100000_map_catalog.sql. The reasoning the single pair was built on
-- survives intact: the set of kinds is closed at three and a client reads the
-- kind to decide what to do with the value, so the kind is a column with a
-- check constraint on it rather than another entry in the links blob.
--
-- No enabled column, unlike map_mirror_host. A mirror is the hub's row about
-- somebody else's server, shared by every map, so turning one off has to leave
-- the template that worked in place. A download source is the game's own fact,
-- written by the game's owner, and an owner who wants a source gone deletes
-- the row.
--
-- ## The two detail columns, and the ones deliberately absent
--
-- Both are what coilbox's own downloader takes as an argument today, so a
-- source stored here is one its existing code can execute unchanged.
--
-- asset, for a github source: part of the release archive's filename, matched
-- case-insensitively against the .sd7 and .sdz assets of the last 30 releases.
-- Null takes the newest. It is needed because newest is often the wrong file.
-- FluidPlay/TAP's most recent release holds 40 archives and the first GitHub
-- returns is TAPrime_v3.0.sdz, a different game from the TAP_v4.0.7.sdz a
-- reader came for. It is a fragment rather than a whole filename because the
-- whole filename carries the version and would need editing on every release.
--
-- filename, for a url source: what to save the download as. The client needs
-- one and cannot derive it, since a URL is not obliged to end in a filename,
-- which is why catalog.json's own url downloads carry it as a required field.
-- Without it a url source stored here is not fetchable.
--
-- No checksum column. Nothing in coilbox verifies a hash: the downloads crate
-- has no verification step, GitHub's asset JSON is read for name, URL and size
-- alone, and rapid's integrity is pr-downloader's business. A column nobody
-- checks is worse than one fewer column.
--
-- No version column, and the hub does not watch for a new release. The client
-- resolves 'newest' at fetch time by asking GitHub for the releases itself,
-- and compares nothing: there is no version ordering anywhere in it. A version
-- stored here would be stale the moment upstream cut a release, and would be
-- stale in a way a reader could not see.
--
-- No map kind. Coilbox keeps the two apart - MapSource is springfiles, hakora
-- or rapid, GameSource is github, springfiles or rapid, and downloadMap.ts
-- never touches the GitHub release path - and the hub already answers where a
-- map comes from through public.map_mirror_host.

create table public.game_download_source (
  id bigint generated always as identity primary key,

  game_id uuid not null references public.game (id) on delete cascade,

  -- The same closed set the single column held, for the same reason.
  kind text not null check (kind in ('rapid', 'url', 'github')),

  -- The rapid tag, the address, or the owner/repo. Per-kind format is checked
  -- in lib/games/download.ts rather than here, which is the argument
  -- 20260918120000 makes and this inherits: these are strings a person types
  -- into a form, and a constraint violation is not a sentence anybody can act
  -- on.
  value text not null check (length(btrim(value)) between 1 and 512),

  asset text check (length(btrim(asset)) between 1 and 256),
  filename text check (length(btrim(filename)) between 1 and 256),

  -- Which source to try first. Unlike map_mirror_host's, these ties are not
  -- arbitrary: the order is the fallback chain, so the form writes a distinct
  -- number per row and id breaks any tie a hand written row leaves.
  sort_order integer not null default 0,

  -- A detail on a kind that has no use for it is a value nothing will ever
  -- read, and the reader of the row cannot tell whether it was meant.
  constraint game_download_source_asset_kind_check
    check (asset is null or kind = 'github'),
  constraint game_download_source_filename_kind_check
    check (filename is null or kind = 'url')
);

comment on table public.game_download_source is
  'Where coilbox fetches a game, best source first (#396). One row per source, authored by the game''s owner or a moderator.';
comment on column public.game_download_source.asset is
  'github only: part of the release archive filename to pick, matched case-insensitively. Null takes the newest .sd7 or .sdz.';
comment on column public.game_download_source.filename is
  'url only: what to save the download as, which the client needs and a URL does not always carry.';

-- The read every caller makes: one game's sources in order.
create index game_download_source_game_idx
  on public.game_download_source (game_id, sort_order, id);

-- ## Access
--
-- Row level security on the table and a grant behind every policy, the rule
-- since #59. The list is public because a download link is the point of it,
-- the same sentence map_mirror_host's grant carries.
--
-- Writing is the owner's or a moderator's, which is where this differs from
-- map_mirror_host: a mirror is the hub's opinion and service_role writes it,
-- while a download source is the game's own fact, told by the people who ship
-- it. Both policies read the same predicates game_edit_owner and
-- game_edit_moderator use on public.game, reached through game_id rather than
-- copied onto the row, so there is one rule and one place it can drift from.
--
-- Insert and delete but no update. The form replaces the whole list on every
-- save, because the order is part of what is being edited and a reorder is not
-- expressible as a set of independent row updates.
alter table public.game_download_source enable row level security;

revoke all on public.game_download_source from anon, authenticated, service_role;
grant select on public.game_download_source to anon, authenticated;
grant insert, delete on public.game_download_source to authenticated;
grant select, insert, update, delete on public.game_download_source to service_role;

create policy game_download_source_read_all on public.game_download_source
  for select to anon, authenticated
  using (true);

create policy game_download_source_insert_owner on public.game_download_source
  for insert to authenticated
  with check (
    exists (
      select 1 from public.game as g
      where g.id = game_download_source.game_id and g.owner_user_id = auth.uid()
    )
  );

create policy game_download_source_delete_owner on public.game_download_source
  for delete to authenticated
  using (
    exists (
      select 1 from public.game as g
      where g.id = game_download_source.game_id and g.owner_user_id = auth.uid()
    )
  );

create policy game_download_source_insert_moderator on public.game_download_source
  for insert to authenticated
  with check (public.is_moderator());

create policy game_download_source_delete_moderator on public.game_download_source
  for delete to authenticated
  using (public.is_moderator());

-- ## Carry the single pair across
--
-- Written to move rows whatever production turns out to hold. Production held
-- one at the time this was written - EvoRTS, rapid, evo:stable - and 24 games
-- with nothing, which is one row this would have silently dropped had the
-- migration assumed the column was empty.
insert into public.game_download_source (game_id, kind, value, sort_order)
select g.id, g.download_kind, btrim(g.download_value), 0
from public.game as g
where g.download_kind is not null and g.download_value is not null;

-- ## The view loses two columns and gains one
--
-- Dropped and recreated rather than replaced, because create or replace view
-- cannot remove a column. That also frees the ordering, so `downloads` sits
-- where the pair it replaces sat instead of being appended. Everything above
-- and below it is the view as 20260918120000 left it.
--
-- One jsonb array rather than a join the caller has to make, so the listing
-- stays the single query lib/games/query.ts describes. security_invoker means
-- the subquery runs under whoever is asking, and anon holds select on the
-- table, so nothing here discloses more than the table already does.
drop view public.game_browse;

create view public.game_browse
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
  g.card_staged_tier
from public.game as g;

revoke all on public.game_browse from anon, authenticated, service_role;
grant select on public.game_browse to anon, authenticated, service_role;

-- ## The pair goes
--
-- Nothing reads it now, and leaving it would be a second answer to "where is
-- this game from" that quietly disagrees with the first.
alter table public.game
  drop constraint game_download_pair_check,
  drop column download_kind,
  drop column download_value;

-- Dropping a column drops the grant on it, so the owner's pen would read as a
-- diff against migrations before it. Recited whole, the way
-- 20260918160000_game_conquest_factions.sql recites it.
revoke update on public.game from authenticated;
grant update (display_name, description, links, conquest_factions)
  on public.game to authenticated;
