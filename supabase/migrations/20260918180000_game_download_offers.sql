-- A download source coilbox offers, held until a person accepts it (#408).
--
-- ## Why an offer is not a fact
--
-- Coilbox knows where a game comes from. It carries a curated catalog with a
-- download for each game and it is running on the machine the game is installed
-- on, so it can answer a question the hub has to ask a person to type in by
-- hand. Every other thing a facts submission carries is descriptive: a unit's
-- armour value, a faction's name. A download source is an instruction to fetch
-- and run code, offered by anybody who can submit facts for a game they have
-- installed.
--
-- So the answer settled on #408 is that a submitted source is held for
-- approval. It never overwrites what is already on the hub, and it never takes
-- effect on its own. Anybody running coilbox may offer one, because offering
-- costs nothing while nothing they offer reaches a reader until a person agrees
-- to it.
--
-- ## Why a second table rather than a state column
--
-- public.asset holds a pending picture in the same table it holds an approved
-- one, narrowed by asset_read_approved, and that is the shape this follows for
-- everything except where the held row lives. Two reasons it lives apart:
--
-- public.game_download_source is published whole. game_browse's `downloads`
-- array is a subquery over it and GET /api/v1/games hands that array to every
-- reader. A held row in that table would be invisible only for as long as two
-- predicates - a policy and a view subquery - both keep filtering it, and
-- getting either wrong once publishes an address a stranger chose. A held row
-- in a table the view does not name cannot leak through the view at all.
--
-- The edit form replaces the whole list on every save (#396): it reads the
-- game's row ids, inserts the new list and deletes the ids it read. Held rows
-- sitting in that table would be deleted by an owner saving something else
-- entirely, which is a queue that empties itself.
--
-- ## The columns are game_download_source's, plus who and when
--
-- Same four fields and the same constraints, because an offer is a source
-- waiting to be one and accepting it is a copy rather than a translation. The
-- per-kind format - what a rapid tag looks like, that a url source carries the
-- filename to save it as - is checked in lib/games/download.ts before a
-- submission ever reaches here, the same function the edit form runs on an
-- owner's own typing. These constraints are the backstop underneath it.
--
-- No sort_order. The order is the fallback chain the game's own people wrote,
-- and a stranger's offer does not get to say where in it they belong: accepting
-- appends.

create table public.game_download_offer (
  id bigint generated always as identity primary key,

  game_id uuid not null references public.game (id) on delete cascade,

  kind text not null check (kind in ('rapid', 'url', 'github')),
  value text not null check (length(btrim(value)) between 1 and 512),
  asset text check (length(btrim(asset)) between 1 and 256),
  filename text check (length(btrim(filename)) between 1 and 256),

  -- Cascades, the way game_ownership_request.requested_by does and for the same
  -- reason: an offer is somebody saying something, and an account that closes
  -- takes its saying with it. An offer already accepted survives, because what
  -- survives it is the row in public.game_download_source.
  offered_by uuid not null references auth.users (id) on delete cascade,

  state text not null default 'held' check (state in ('held', 'accepted', 'declined')),

  decided_by uuid references auth.users (id) on delete set null,
  decided_at timestamptz,

  created_at timestamptz not null default now(),

  constraint game_download_offer_asset_kind_check
    check (asset is null or kind = 'github'),
  constraint game_download_offer_filename_kind_check
    check (filename is null or kind = 'url'),

  -- Held and undecided are one state, not two that can disagree. Without this a
  -- row could read 'accepted' with nobody and no time against it, and the queue
  -- would have no way to tell an accepted offer from one whose update half
  -- landed.
  constraint game_download_offer_decided_check
    check ((state = 'held') = (decided_at is null))
);

comment on table public.game_download_offer is
  'A download source coilbox offered for a game, held until the owner or a moderator accepts it (#408). Never read by a public path.';

-- One held offer per source per game. A client sweeping every installed game on
-- a timer sends the same source over and over, and without this the queue grows
-- a row a run. A second submission of a source already held is a unique
-- violation the offer function swallows, so it changes nothing at all: it
-- cannot promote the held row and it cannot add a second.
create unique index game_download_offer_held_idx
  on public.game_download_offer (game_id, kind, value)
  where state = 'held';

-- The queue reads oldest first, the way the ownership queue does.
create index game_download_offer_queue_idx
  on public.game_download_offer (created_at)
  where state = 'held';

-- ## Access: nobody holding a publishable key touches this table
--
-- Row level security on, no policy, and no grant to anon or authenticated. The
-- position public.user_capability and public.asset were left in, and the
-- strongest available answer to "can a reader see a held source": there is no
-- grant to reach it through and no policy to let a row past even if there were.
--
-- That covers a moderator's own session too. The queue is read with the secret
-- key after the page has asked who is looking, exactly as fetchPictureQueue
-- reads pending pictures, and the deciding is the one function below.
alter table public.game_download_offer enable row level security;

revoke all on public.game_download_offer from anon, authenticated, service_role;
grant select, insert, update on public.game_download_offer to service_role;

-- ## Offering
--
-- Called by the facts route with the secret key, after the submission it rode
-- in with has been written. Separate from submit_game_facts on purpose: that
-- function replaces what it covers, which is the right rule for a unit list read
-- off an archive and the wrong one for anything a person authors. #393 kept a
-- game's conquest factions out of it for that reason and this stays out for the
-- same one. Nothing in here writes to public.game_download_source.
--
-- Returns how many offers are newly held, which is what the route logs and what
-- a test asserts against. A source the game already holds is not an offer, so it
-- is skipped rather than queued: there is nothing for a person to decide about a
-- source already on the page.
--
-- Each source lands in its own subtransaction, the way a unit does in
-- submit_game_facts. A source the constraints refuse costs itself and not the
-- rest, and never the facts that arrived with it.
create function public.offer_game_download_sources(
  p_shortname text,
  p_sources jsonb,
  p_offered_by uuid
)
returns integer
language plpgsql
set search_path = ''
as $$
declare
  v_game_id uuid;
  v_entry jsonb;
  v_held integer := 0;
  v_written integer;
begin
  if jsonb_typeof(p_sources) <> 'array' then
    return 0;
  end if;

  select g.id into v_game_id
  from public.game as g
  where g.shortname = p_shortname;

  if v_game_id is null then
    return 0;
  end if;

  for v_entry in select element.value from jsonb_array_elements(p_sources) as element(value)
  loop
    begin
      -- Already on the page, so there is nothing to decide. Compared on the
      -- pair that identifies a source rather than on the details, because a
      -- github source for the same repo with a different asset fragment is the
      -- same place to look and the owner has already picked which file.
      if not exists (
        select 1 from public.game_download_source as s
        where s.game_id = v_game_id
          and s.kind = v_entry ->> 'kind'
          and s.value = v_entry ->> 'value'
      ) then
        insert into public.game_download_offer (game_id, kind, value, asset, filename, offered_by)
        values (
          v_game_id,
          v_entry ->> 'kind',
          v_entry ->> 'value',
          v_entry ->> 'asset',
          v_entry ->> 'filename',
          p_offered_by
        )
        on conflict do nothing;

        get diagnostics v_written = row_count;
        v_held := v_held + v_written;
      end if;
    exception
      when others then
        -- A source the constraints refuse is dropped. The route checked the
        -- format before this was called, so anything arriving here that the
        -- table will not take is a bug on the way in rather than something a
        -- person could act on, and it must not cost the submission.
        null;
    end;
  end loop;

  return v_held;
end;
$$;

revoke execute on function public.offer_game_download_sources(text, jsonb, uuid) from public;
grant execute on function public.offer_game_download_sources(text, jsonb, uuid) to service_role;

-- ## Accepting, or not
--
-- The explicit act, and the only way a held source ever becomes a real one.
-- Security definer because the table grants a browser session nothing, so the
-- right has to be asked here rather than expressed as a policy over a grant
-- nobody holds. The three moderation functions in
-- 20260814220200_asset_moderation_functions.sql are built the same way and
-- raise insufficient_privilege the same way.
--
-- Who may: the game's owner, or a moderator on any game. The same pair that
-- edits the list by hand, reached through game_id rather than copied onto the
-- offer, so there is one rule and one place it can drift from.
--
-- Accepting appends. It reads the end of the list and writes past it, so an
-- offer can never displace, reorder or replace a source a person put there. An
-- owner who wants it first reorders the list afterwards on the edit form, which
-- is a decision about their own game rather than something a submission gets to
-- make.
--
-- False rather than an exception for an offer that is not there or is already
-- decided. Two moderators reaching the same queue is a race the second one
-- loses harmlessly, and a page that showed a stale row should say the work is
-- gone rather than fail.
create function public.decide_game_download_offer(p_offer_id bigint, p_accept boolean)
returns boolean
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_uid uuid := auth.uid();
  v_offer public.game_download_offer%rowtype;
  v_allowed boolean;
begin
  if v_uid is null then
    raise exception 'Sign in to decide a download offer.'
      using errcode = 'insufficient_privilege';
  end if;

  -- Locked before it is read, so two decisions on one offer queue up and the
  -- second sees the state the first wrote.
  select o.* into v_offer
  from public.game_download_offer as o
  where o.id = p_offer_id and o.state = 'held'
  for update;

  if v_offer.id is null then
    return false;
  end if;

  select exists (
    select 1 from public.game as g
    where g.id = v_offer.game_id and g.owner_user_id = v_uid
  ) or public.is_moderator()
  into v_allowed;

  if not v_allowed then
    raise exception 'Only the game''s owner or a moderator decides a download offer.'
      using errcode = 'insufficient_privilege';
  end if;

  if p_accept then
    -- Not already there. The owner may have typed the same source in while the
    -- offer sat in the queue, and a list holding one place to look twice is a
    -- fallback chain that tries the same thing again.
    if not exists (
      select 1 from public.game_download_source as s
      where s.game_id = v_offer.game_id
        and s.kind = v_offer.kind
        and s.value = v_offer.value
    ) then
      insert into public.game_download_source (game_id, kind, value, asset, filename, sort_order)
      values (
        v_offer.game_id,
        v_offer.kind,
        v_offer.value,
        v_offer.asset,
        v_offer.filename,
        coalesce(
          (select max(s.sort_order) + 1 from public.game_download_source as s
            where s.game_id = v_offer.game_id),
          0
        )
      );
    end if;
  end if;

  update public.game_download_offer as o
  set
    state = case when p_accept then 'accepted' else 'declined' end,
    decided_by = v_uid,
    decided_at = now()
  where o.id = v_offer.id;

  return true;
end;
$$;

revoke execute on function public.decide_game_download_offer(bigint, boolean) from public;
grant execute on function public.decide_game_download_offer(bigint, boolean) to authenticated;
