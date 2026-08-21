-- Who owns a game's page, and what an owner may change (#229).
--
-- The catalog's facts come from archives, but a game's author can say things an
-- archive cannot: what the game is trying to be, where its community lives, a
-- sentence about why a unit matters. Nothing in the schema so far could hold
-- those words or say who is allowed to write them, so this migration adds both:
-- an owner on each game, and a request queue a maintainer works through to make
-- somebody one.
--
-- ## The request state machine
--
-- A request is open, approved or declined, and only a maintainer moves it out
-- of open. One open request per person per game is a partial unique index,
-- which turns "already asking" into a constraint violation rather than a check
-- the route has to remember: two tabs submitting the same form lose nothing but
-- a duplicate row.
--
-- Approving sets public.game.owner_user_id, which no policy hands to a browser:
-- ownership changes inside the decision action, which checks is_moderator()
-- before it writes, and never through a grant a compromised publishable key
-- could reach.
--
-- requested_by_name carries the Discord display name at request time, filled by
-- the same default and in the same fallback order as item.author_name
-- (20260809190500). The queue reads it rather than joining auth.users, which
-- PostgREST does not serve; a name frozen when somebody asked is also the honest
-- one for a decision made later.

alter table public.game add column owner_user_id uuid references auth.users (id) on delete set null;
alter table public.game add column logo_path text check (length(btrim(logo_path)) between 1 and 512);
alter table public.game add column logo_hash text check (length(btrim(logo_hash)) between 1 and 128);
alter table public.game add column banner_path text check (length(btrim(banner_path)) between 1 and 512);
alter table public.game add column banner_hash text check (length(btrim(banner_hash)) between 1 and 128);

-- The author's own words about one unit, shown under the stats and never
-- instead of them. Bounded because it renders as a paragraph, not as a page.
alter table public.game_unit add column snippet text check (length(snippet) <= 2000);

create table public.game_ownership_request (
  id bigint generated always as identity primary key,

  game_id uuid not null references public.game (id) on delete cascade,

  -- Cascades, unlike every reference that names a decision: a request is
  -- somebody asking, and an account that closes takes its asking with it. An
  -- approval already granted survives, because owner_user_id is set null
  -- rather than cascaded.
  requested_by uuid not null references auth.users (id) on delete cascade,

  requested_by_name text not null default public.current_author_name()
    check (length(btrim(requested_by_name)) between 1 and 256),

  note text check (length(note) <= 2000),

  state text not null default 'open' check (state in ('open', 'approved', 'declined')),

  decided_by uuid references auth.users (id) on delete set null,
  decided_at timestamptz,

  created_at timestamptz not null default now()
);

-- One open ask per person per game. Declined requests do not block asking
-- again with a better note, and an approved one is history.
create unique index game_ownership_request_open_idx
  on public.game_ownership_request (game_id, requested_by)
  where state = 'open';

-- The queue reads oldest first, so this is the index it walks.
create index game_ownership_request_queue_idx
  on public.game_ownership_request (created_at)
  where state = 'open';

-- ## Access
--
-- Authoritative revokes first, the discipline since #59. The request table is
-- the one new surface a browser writes, so its policies are where the care goes;
-- everything else here narrows existing tables by column grants and owner
-- scoped policies, the shape 20260809190500 set for item.

revoke all on public.game_ownership_request from anon, authenticated, service_role;

grant select, insert on public.game_ownership_request to authenticated;
grant update (state, decided_by, decided_at) on public.game_ownership_request to authenticated;

alter table public.game_ownership_request enable row level security;

-- A requester sees their own asks, a moderator sees the queue, and nobody else
-- sees anything: who wants to own a game is a conversation, not a listing.
create policy game_ownership_request_read_own_or_moderator
  on public.game_ownership_request
  for select to authenticated
  using (requested_by = auth.uid() or public.is_moderator());

-- You can ask for yourself, and only in the state that means asking. The
-- column grant leaves requested_by_name out, whose default is the point.
create policy game_ownership_request_insert_own
  on public.game_ownership_request
  for insert to authenticated
  with check (requested_by = auth.uid() and state = 'open');

-- Only a moderator decides, and the column grant above is what stops a decided
-- row's other fields moving while they do it.
create policy game_ownership_request_decide
  on public.game_ownership_request
  for update to authenticated
  using (public.is_moderator())
  with check (public.is_moderator());

-- An owner edits what the issue says an owner edits: the display name, the
-- description, the links. Everything else on the row - identity, images,
-- ownership itself - stays behind service_role, because those are decisions
-- about the catalog rather than words about the game.
revoke update on public.game from authenticated;
grant update (display_name, description, links) on public.game to authenticated;

create policy game_edit_owner
  on public.game
  for update to authenticated
  using (owner_user_id = auth.uid())
  with check (owner_user_id = auth.uid());

-- A snippet belongs to the unit's game's owner, reached through the game rather
-- than duplicated onto the unit. One rule, one place it can drift from.
revoke update on public.game_unit from authenticated;
grant update (snippet) on public.game_unit to authenticated;

create policy game_unit_snippet_owner
  on public.game_unit
  for update to authenticated
  using (
    exists (
      select 1 from public.game as g
      where g.id = game_unit.game_id and g.owner_user_id = auth.uid()
    )
  )
  with check (
    exists (
      select 1 from public.game as g
      where g.id = game_unit.game_id and g.owner_user_id = auth.uid()
    )
  );
