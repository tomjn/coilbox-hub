-- Taking a game, or one of its releases, off the site without deleting it
-- (issue #242).
--
-- Everything in the game catalog is world readable, and until now nothing
-- could leave once a backfill posted it. Two levels of hiding, worked by two
-- kinds of person:
--
-- A hidden game disappears whole: its row, its factions, its units, its
-- versions and their revisions, and its line in game_browse. A hidden version
-- disappears from every picker and its revisions stop rendering, while the
-- rest of the game carries on - including current facts that happen to have
-- been read from the hidden release.
--
-- ## The rules live in row level security, not in pages
--
-- The lesson the asset access model teaches twice: hiding must hold at the data
-- layer, because a page-level check only covers the paths somebody remembered.
-- So the read policies on all five catalog tables gain a visibility condition,
-- and a hidden game's children are filtered through their parent rather than by
-- each table knowing about hiding separately. What can be seen is exactly what
-- the policies return, for every role and every client.
--
-- Three parties see through a hide: the moderator who holds can_moderate (the
-- same question is_moderator() asks), the game's approved owner, and
-- service_role, which the visibility rules never bind because the submission
-- route has to keep writing while a page is hidden. A backfill re-reporting a
-- hidden release updates what it always updated and does not unhide anything:
-- the submit function never touches these columns.
--
-- ## Why two helper functions
--
-- Every child policy has to answer "is this game visible to me", and the
-- revision policy has to answer it about a version too. Four copies of that
-- logic across four policies would drift the first time an escape was added to
-- one and not the others, so both questions are functions: security definer,
-- because evaluating hidden_at on a row the caller cannot select is the whole
-- point, and revoking execute from public first, which is the discipline every
-- migration here has followed since #59.

alter table public.game add column hidden_at timestamptz;
alter table public.game add column hidden_by uuid references auth.users (id) on delete set null;

alter table public.game_version add column hidden_at timestamptz;
alter table public.game_version add column hidden_by uuid references auth.users (id) on delete set null;

-- Is this game on the site for whoever is asking?
--
-- True for a visible game, true for a game nobody holds (a missing parent is
-- not a secret, and callers join through it), and true past a hide for a
-- moderator or the owner. False otherwise, which is what makes hidden mean
-- hidden.
create function public.game_row_visible(g_id uuid)
returns boolean
language sql
stable
security definer
set search_path = ''
as $$
  select coalesce(
    (
      select g.hidden_at is null
      from public.game as g
      where g.id = g_id
    ),
    true
  )
  or public.is_moderator()
  or exists (
    select 1 from public.game as g
    where g.id = g_id and g.owner_user_id = auth.uid()
  );
$$;

-- Is this release readable for whoever is asking?
--
-- The same escapes as the game question, asked about the version row. A
-- version nobody has reported is trivially readable, which keeps new releases
-- visible from the moment they arrive.
create function public.game_version_visible(g_id uuid, v_version text)
returns boolean
language sql
stable
security definer
set search_path = ''
as $$
  select coalesce(
    (
      select gv.hidden_at is null
      from public.game_version as gv
      where gv.game_id = g_id and gv.version = v_version
    ),
    true
  )
  or public.is_moderator()
  or exists (
    select 1 from public.game as g
    where g.id = g_id and g.owner_user_id = auth.uid()
  );
$$;

-- Is this unit's history readable at this release, for whoever is asking?
--
-- One question rather than two helpers composed in the policy, and the reason
-- is where the arguments come from. A revision knows its unit, not its game,
-- so a policy written as "look up the unit's game" would run that lookup as
-- the caller, under the very policies being evaluated: hide the game, the
-- caller can no longer see the unit, the lookup returns null, and null reads
-- as visible. Resolving unit, game and release inside the definer sidesteps
-- the recursion entirely.
create function public.game_unit_revision_visible(r_unit_id bigint, r_version text)
returns boolean
language sql
stable
security definer
set search_path = ''
as $$
  with held as (
    select u.game_id
    from public.game_unit as u
    where u.id = r_unit_id
  )
  select (
    coalesce(
      (select g.hidden_at is null from public.game as g where g.id = (select game_id from held)),
      true
    )
    and coalesce(
      (
        select gv.hidden_at is null
        from public.game_version as gv
        where gv.game_id = (select game_id from held) and gv.version = r_version
      ),
      true
    )
  )
  or public.is_moderator()
  or exists (
    select 1 from public.game as g
    where g.id = (select game_id from held) and g.owner_user_id = auth.uid()
  );
$$;

revoke execute on function public.game_row_visible(uuid) from public;
revoke execute on function public.game_version_visible(uuid, text) from public;
revoke execute on function public.game_unit_revision_visible(bigint, text) from public;
grant execute on function public.game_row_visible(uuid) to anon, authenticated;
grant execute on function public.game_version_visible(uuid, text) to anon, authenticated;
grant execute on function public.game_unit_revision_visible(bigint, text) to anon, authenticated;

-- ## The read policies, replaced rather than added to
--
-- Permissive policies OR together, so bolting a second policy beside read_all
-- would hand every row back and hide nothing. Each read-all policy below is
-- dropped and replaced by one carrying the visibility condition; the grants are
-- untouched, because a grant says what may be attempted and the policy still
-- says which rows survive it.

drop policy game_read_all on public.game;
create policy game_read_visible on public.game
  for select to anon, authenticated
  using (
    hidden_at is null
    or public.is_moderator()
    or owner_user_id = auth.uid()
  );

drop policy game_faction_read_all on public.game_faction;
create policy game_faction_read_visible on public.game_faction
  for select to anon, authenticated
  using (public.game_row_visible(game_id));

drop policy game_unit_read_all on public.game_unit;
create policy game_unit_read_visible on public.game_unit
  for select to anon, authenticated
  using (public.game_row_visible(game_id));

drop policy game_version_read_all on public.game_version;
create policy game_version_read_visible on public.game_version
  for select to anon, authenticated
  using (
    public.game_row_visible(game_id)
    and public.game_version_visible(game_id, version)
  );

-- A revision is visible when its game is, and when the release it belongs to
-- is: hiding a release takes its history with it, which is the point of hiding
-- one. Both questions resolve inside the definer, for the reason the function
-- records.
drop policy game_unit_revision_read_all on public.game_unit_revision;
create policy game_unit_revision_read_visible on public.game_unit_revision
  for select to anon, authenticated
  using (public.game_unit_revision_visible(unit_id, version));
