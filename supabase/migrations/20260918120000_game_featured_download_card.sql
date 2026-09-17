-- Three things a game row could not say yet: that the hub wants it seen first,
-- where a lobby fetches it from, and what it looks like on a card.
--
-- ## Featuring is not hiding upside down
--
-- Hiding is a decision an owner may make about their own page, so
-- 20260821140000_game_visibility.sql lets an owner hide their own game.
-- Featuring is a decision about the hub's front door, so it stays behind
-- service_role and the server action checks is_moderator() before spending it.
-- The column pair and the on delete set null are copied from hidden_at and
-- hidden_by for the reason those exist: who and when are both part of the
-- fact, and closing an account must not take the catalog row with it.
--
-- The listing used to order by unit count. That answered a question nobody was
-- asking and left the order at the mercy of whichever extraction ran last, so
-- it is alphabetical now, and this column is how the hub says what goes above
-- that.
--
-- ## Why the download source is typed columns
--
-- public.game.links is a jsonb blob, and its comment gives the test it passed:
-- the set of places worth linking is open ended and nothing filters on it. A
-- download source fails that test. There are three kinds, the set is closed,
-- and a client reads the kind to decide what to do with the value. So the kind
-- is a column with a check constraint on it.
--
-- The per-kind format, what a rapid tag looks like and what a github value
-- looks like, is validated in lib/games/download.ts rather than here. A
-- constraint violation is not a sentence anybody can act on, and these are
-- strings a person types into a form. The constraint still holds the kind and
-- the length, so a write that skipped that file cannot store nonsense.
--
-- ## Card art is a third picture, not a new mechanism
--
-- The three card_ columns match logo_ and banner_ exactly, so every reader,
-- the upload action, the promotion run and the hub's own art route treat it as
-- the same kind of thing. card_staged_tier is checked against 'bucket' alone,
-- which is what 20260917130000_remove_vercel_blob.sql narrowed the other two
-- to. A column created today never had a Vercel Blob era to carry.

alter table public.game
  add column featured_at timestamptz,
  add column featured_by uuid references auth.users (id) on delete set null,

  add column download_kind text
    check (download_kind in ('rapid', 'url', 'github')),
  add column download_value text
    check (length(btrim(download_value)) between 1 and 512),

  add column card_path text check (length(btrim(card_path)) between 1 and 512),
  add column card_hash text check (length(btrim(card_hash)) between 1 and 128),
  add column card_staged_tier text check (card_staged_tier = 'bucket');

-- A kind naming no value would draw a download control pointing nowhere, and a
-- value with no kind is a string nothing knows how to use. Neither half means
-- anything alone.
alter table public.game
  add constraint game_download_pair_check
  check ((download_kind is null) = (download_value is null));

comment on column public.game.featured_at is
  'When a moderator put this game at the top of the listing, or null. Only service_role writes it.';
comment on column public.game.download_kind is
  'How a lobby fetches this game: a rapid tag, a URL, or a GitHub repo with releases. Null when nobody has said.';
comment on column public.game.download_value is
  'The tag, address or owner/repo the kind names. Format is checked in lib/games/download.ts.';
comment on column public.game.card_staged_tier is
  'Set to bucket while an uploaded card picture waits for promotion to the durable tier, and cleared once it is there.';

-- ## The owner's pen widens by two columns
--
-- 20260821130000_game_ownership.sql grants an owner update on the display
-- name, the description and the links, and says everything else on the row is
-- a decision about the catalog rather than words about the game. Where a game
-- is downloaded from is the game's own fact, told by the people who ship it,
-- so it joins the three. featured_at deliberately does not: that is the hub
-- talking about the game, not the game talking about itself.
revoke update on public.game from authenticated;
grant update (display_name, description, links, download_kind, download_value)
  on public.game to authenticated;

-- ## The view
--
-- Six columns appended rather than inserted among the existing ones, for the
-- reason 20260914210000_game_browse_logo_staging.sql records: create or
-- replace view refuses a column added between existing ones. Nothing here
-- discloses anything new, since anon can already select every column of
-- public.game and security_invoker keeps the game's read policy in force.
--
-- The body above the new columns is the view as
-- 20260914220000_game_browse_random_faction.sql left it, which means
-- faction_count still leaves Random out. Every one of these replacements has
-- to carry the whole body forward, and dropping that filter here would have
-- put BAR's count back to 4 above a page showing 3.
create or replace view public.game_browse
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

  -- Where a lobby fetches the game from.
  g.download_kind,
  g.download_value,

  -- The card picture, resolved by the same three columns every other game
  -- picture uses.
  g.card_path,
  g.card_hash,
  g.card_staged_tier
from public.game as g;

-- Repeated from the view's first migration, because create or replace does not
-- carry grants forward.
revoke all on public.game_browse from anon, authenticated, service_role;
grant select on public.game_browse to anon, authenticated, service_role;
