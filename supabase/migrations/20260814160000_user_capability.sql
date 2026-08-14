-- What an account may do beyond publishing its own items, granted one
-- capability at a time (issue #101).
--
-- Two of them look alike and answer different questions.
--
--   can_seed_unit_assets    upload buildpics, minimaps, overlays and renders
--                           that go live immediately, which is how the corpus
--                           gets bootstrapped at all
--   can_publish_unreviewed  skip the moderation queue for whatever this person
--                           uploads, which is a content safety control being
--                           waived
--
-- The first is about getting content in, the second is about what stands in
-- front of the public. One `is_trusted` flag collapses them, and the failure is
-- silent rather than loud: the first time somebody is trusted to help seed a
-- game roster, that same grant lets them publish unreviewed binary content, and
-- nobody decided that. So a grant names one capability, and holding one says
-- nothing about the other.
--
-- Granting can_seed_unit_assets to anyone other than the maintainer is the
-- trigger condition for reconsidering automated screening of what lands in the
-- durable tier. Screening is deferred until then, deliberately, because until
-- then the entire seeded corpus came from one person.
--
-- Named in the singular, like item, report, asset and asset_licence. The issue
-- asks for `user_capabilities`; a plural here would be the only plural table in
-- the schema, and the convention is worth more than the issue's spelling.

create table public.user_capability (
  user_id uuid not null references auth.users (id) on delete cascade,

  -- A literal list rather than an enum, the same as item.kind and
  -- asset.approval_source, so widening it later is a one line migration. The
  -- point of the list is that it says no to everything else: a typo in a grant
  -- is a capability nobody holds and no code ever checks, which is a silent
  -- failure in the one place that must not have any.
  --
  -- can_moderate is here rather than in a table of its own. See the note on
  -- is_moderator() below.
  capability text not null check (capability in (
    'can_seed_unit_assets',
    'can_publish_unreviewed',
    'can_moderate'
  )),

  -- Who granted it and when. A capability is not a property of the account, it
  -- is a decision somebody made, and the decision is the thing worth keeping:
  -- a year from now the useful question about a bypass is who handed it out.
  --
  -- granted_by is nullable because the first grants have no granter with a
  -- session. The maintainer's own capabilities are inserted straight into the
  -- table, and the can_moderate rows carried over from public.moderator below
  -- predate the column entirely. `on delete set null` rather than cascade: a
  -- granter closing their account must not silently revoke what they granted.
  granted_at timestamptz not null default now(),
  granted_by uuid references auth.users (id) on delete set null,

  -- One row per capability per person, so granting twice is not two grants and
  -- revoking is one delete.
  primary key (user_id, capability)
);

-- The only way anything outside the database asks. Security definer, because
-- the table itself is readable by nobody: who holds a bypass is not public, and
-- neither is the fact that somebody does.
--
-- Answers for the caller and nobody else. There is no "does this other person
-- hold X" here, since nothing needs it yet and the moderation grid asking
-- would be a way to enumerate the capability table one question at a time.
create function public.has_capability(capability text) returns boolean
language sql
stable
security definer
set search_path = ''
as $$
  select exists (
    select 1 from public.user_capability uc
    where uc.user_id = auth.uid()
      and uc.capability = has_capability.capability
  );
$$;

grant execute on function public.has_capability(text) to anon, authenticated;

-- public.moderator moves in, rather than sitting beside this table.
--
-- It was already a capability table with one capability in it: a row per
-- person, granting the right to do something the account does not otherwise
-- carry. Leaving it where it was would mean either omitting can_moderate here,
-- so the answer to "what may this person do" lives in two places and only one
-- of them records who decided, or holding can_moderate in both, which is the
-- drift this issue argues against wearing a different hat.
--
-- Nothing that calls is_moderator() changes. It keeps its name and its
-- signature, five policies across public.item and public.report keep calling
-- it, app/layout.tsx and app/moderation/page.tsx keep calling it over RPC, and
-- only its body moves. That is the whole reason the moderation check was a
-- function rather than an inlined exists() in the first place.
insert into public.user_capability (user_id, capability, granted_at)
select user_id, 'can_moderate', added_at from public.moderator;

create or replace function public.is_moderator() returns boolean
language sql
stable
security definer
set search_path = ''
as $$
  select public.has_capability('can_moderate');
$$;

drop table public.moderator;

-- Row level security on with no policy and no grant, which refuses everyone
-- holding the publishable key, the same position public.asset and
-- public.asset_licence were left in. The table's safety must not rest on the
-- absence of a grant, which is the drift #59 found in production.
--
-- Whether a person may read back their own capabilities is #102's to settle
-- with the rest of the asset access model. Until it does, has_capability() is
-- the only way to learn anything derived from this table, exactly as
-- is_moderator() was the only way to learn anything from public.moderator.
alter table public.user_capability enable row level security;

-- Nothing is granted to anybody here. Who holds what is runtime data and the
-- maintainer's decision, and a migration that seeded a bypass would be a grant
-- nobody made.
