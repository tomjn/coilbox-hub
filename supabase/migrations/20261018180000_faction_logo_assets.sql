-- Faction logos as a third kind of asset (issue #238).
--
-- A game's side toggles and faction strips draw `game_faction.logo_path`, which
-- until now a maintainer seeded by hand. Coilbox can send the picture once the
-- shared vocabulary names it (tomjn/coilbox#4378), so it goes through the same
-- upload, moderation queue and promotion job as unit and map pictures, and
-- promotion writes the durable path into `game_faction` itself.
--
-- ## Identity
--
-- A faction logo is keyed on (game, faction_key, variant), the game being its
-- shortname and the faction key being `game_faction.key`. It has the shape of a
-- unit key, because sides repeat across games the way unit names do. There is no
-- foreign key to `game_faction`, for the reason the catalog gives about
-- pictures: a logo can arrive before the facts that name its side, and a
-- resubmission replaces the whole faction list, which a reference would refuse.
-- The upload route checks the side exists when the picture is sent.
--
-- `game` now belongs to a unit row and a faction row and not to a map row, so
-- the scope check says that directly instead of tying it to `unit_name`.
--
-- Nothing is rewritten. The column is nullable with no default, and the three
-- replaced constraints are checked against the existing rows, none of which sets
-- `faction_key`.

alter table public.asset add column faction_key text
  check (length(btrim(faction_key)) between 1 and 128);

comment on column public.asset.faction_key is
  'game_faction.key for a faction logo, which is keyed on game, faction_key and variant. Null on a unit or a map row.';

alter table public.asset drop constraint asset_identity_check;
alter table public.asset add constraint asset_identity_check
  check (num_nonnulls(unit_name, map_name, faction_key) = 1);

alter table public.asset drop constraint asset_game_scope_check;
alter table public.asset add constraint asset_game_scope_check
  check ((game is null) = (map_name is not null));

-- The faction side of the variant vocabulary: one picture, its logo. A closed
-- list of one, like the map side, because a typo would mint an identity nothing
-- ever asks for.
alter table public.asset add constraint asset_faction_variant_check
  check (faction_key is null or variant = 'logo');

create unique index asset_faction_identity_idx
  on public.asset (game, faction_key, variant) where faction_key is not null;

-- ## Promotion writes the logo onto the faction
--
-- A trigger on the asset row rather than a second statement in the promotion
-- job, so the logo appears in the same transaction as the move to the durable
-- tier and the job's order (`lib/assets/promote.ts`) stays exactly as it is.
-- `promote_assets` is unchanged: its single UPDATE fires this, and a batch that
-- rolls back takes the faction rows with it.
--
-- A row is live when it is approved and on the durable tier, which is the only
-- state the site serves a faction logo from. Three transitions matter:
--
-- - Becoming live. Promotion, a seed insert, and an editorial rejection that a
--   moderator returned and approved again (that row is already on the durable
--   tier, so promotion never visits it).
-- - Becoming rejected. The logo comes off the faction if it is the one being
--   shown. A safety rejection of a promoted picture also lands in
--   `asset_withdrawal` for the takedown, and this makes sure the site stops
--   drawing it in the meantime.
-- - A replacement. The row goes back to pending on the staging tier and the old
--   logo keeps showing until the new one is approved and promoted, because the
--   bytes on display were approved and the replacement's were not.
--
-- A side that does not exist yet is not an error, the faction trigger below
-- picks the logo up when it does.
create function public.sync_faction_logo() returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  was_live boolean := false;
  was_rejected boolean := false;
begin
  if new.faction_key is null then
    return null;
  end if;

  if tg_op = 'UPDATE' then
    was_live := old.moderation = 'approved' and old.tier = 'static';
    was_rejected := old.moderation = 'rejected';
  end if;

  if new.moderation = 'approved' and new.tier = 'static' and not was_live then
    update public.game_faction as gf
    set logo_path = new.path, logo_hash = new.hash
    from public.game as g
    where g.shortname = new.game
      and gf.game_id = g.id
      and gf.key = new.faction_key;
  elsif new.moderation = 'rejected' and not was_rejected then
    update public.game_faction as gf
    set logo_path = null, logo_hash = null
    from public.game as g
    where g.shortname = new.game
      and gf.game_id = g.id
      and gf.key = new.faction_key
      and gf.logo_path = new.path;
  end if;

  return null;
end;
$$;

create trigger asset_sync_faction_logo
  after insert or update on public.asset
  for each row execute function public.sync_faction_logo();

-- A resubmission of a game's facts deletes its factions and inserts them again
-- (`submit_game_facts`), which would drop every logo promotion had written. A
-- side inserted without a logo takes the live one for its key, if the hub holds
-- one.
create function public.adopt_faction_logo() returns trigger
language plpgsql
security definer
set search_path = ''
as $$
begin
  if new.logo_path is not null then
    return new;
  end if;

  select a.path, a.hash
  into new.logo_path, new.logo_hash
  from public.asset as a
  join public.game as g on g.shortname = a.game
  where g.id = new.game_id
    and a.faction_key = new.key
    and a.variant = 'logo'
    and a.moderation = 'approved'
    and a.tier = 'static';

  return new;
end;
$$;

create trigger game_faction_adopt_logo
  before insert on public.game_faction
  for each row execute function public.adopt_faction_logo();

-- Execute is granted to PUBLIC on every new function, so these revokes are the
-- access control. Trigger functions are run by the trigger and need no grant.
revoke execute on function public.sync_faction_logo() from public;
revoke execute on function public.adopt_faction_logo() from public;
