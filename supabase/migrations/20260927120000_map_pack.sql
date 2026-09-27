-- Named bundles of maps a moderator puts together, for coilbox's Map packs menu
-- (tomjn/coilbox#3206).
--
-- ## A pack names maps rather than pointing at rows
--
-- A pack entry holds the map's name, not public.map.id. Two reasons.
--
-- public.clear_map_facts deletes the public.map row, and the next client to
-- report the archive creates a new one with a new id. A foreign key would either
-- block the clear or cascade the map out of every pack it was in, and neither is
-- what a moderator settling a disagreement means to do.
--
-- The name is also what coilbox downloads by. A pack entry goes out as
-- `{ kind: "map", springName }`, so a name is the whole of what the client
-- needs, and the catalog row only adds a nicer title and an archive filename.
-- public.map_pack_entry joins those in when the row is there and leaves them
-- null when it is not.
--
-- The name is still checked against the catalog, once, when a moderator adds it
-- (public.add_maps_to_pack below), so a typo never gets into a pack.
--
-- ## Who writes
--
-- The same split as 20260918150000_map_featured.sql. The moderation page's
-- server actions ask is_moderator() with the moderator's own session, then write
-- with the secret key. anon and authenticated read everything and write nothing.

create table public.map_pack (
  id uuid primary key default gen_random_uuid(),
  title text not null check (length(btrim(title)) between 1 and 128),
  blurb text check (length(blurb) <= 2000),
  -- Featured is what coilbox shows. An unfeatured pack is one a moderator is
  -- still putting together, or one that has been taken down.
  featured_at timestamptz,
  featured_by uuid references auth.users (id) on delete set null,
  created_at timestamptz not null default now()
);

comment on table public.map_pack is
  'A named bundle of maps for coilbox''s Map packs menu. Only service_role writes it.';
comment on column public.map_pack.featured_at is
  'When a moderator put this pack in coilbox''s menu, or null.';
comment on column public.map_pack.featured_by is
  'The moderator who featured it. Set null rather than cascading, so closing an account does not take the pack with it.';

create table public.map_pack_map (
  pack_id uuid not null references public.map_pack (id) on delete cascade,
  -- public.map.map_name as it was when the moderator added it. Not a foreign
  -- key, for the reason at the top of this file.
  map_name text not null check (length(btrim(map_name)) between 1 and 256),
  -- The order the moderator added them in. Gaps are fine, only the order counts.
  position integer not null check (position >= 0),
  primary key (pack_id, map_name)
);

comment on table public.map_pack_map is
  'The maps in a pack, by name. Only service_role writes it.';

create index map_pack_map_order_idx on public.map_pack_map (pack_id, position);

-- ## The read the route and the moderation page share
--
-- Security invoker, so it answers exactly what the two tables and public.map
-- already let the caller read, and adds no access of its own.
create view public.map_pack_entry
with (security_invoker = true)
as
select
  entry.pack_id,
  entry.map_name,
  entry.position,
  -- Null when the catalog no longer holds the map, for example straight after a
  -- moderator cleared its facts. The entry still goes out, under its name.
  map.slug,
  map.display_name,
  map.archive_filename
from public.map_pack_map as entry
left join public.map as map on map.map_name = entry.map_name;

-- ## Adding maps by the hundred
--
-- A pack like the BAR one is every map on a mirror, and nobody should type those
-- by hand. The moderator pastes a list, one per line, of either map names or
-- archive filenames. A mirror lists filenames, so both have to work.
--
-- A function rather than a lookup in the route, because the list travels in the
-- request body here. Hundreds of names in a PostgREST `in` filter would travel in
-- the URL instead.
--
-- Every line comes back, with the map it matched or null, so the page can say
-- which lines matched nothing. Matching ignores case. A map name match wins over
-- a filename match. Lines already in the pack are left where they are.
--
-- Security invoker. service_role already holds everything this reads and
-- writes, so there is nothing for a definer to add.
create function public.add_maps_to_pack(p_pack_id uuid, p_names text[])
returns table (wanted text, map_name text)
language sql
set search_path = ''
as $$
  with pasted as (
    select btrim(line.name) as wanted, min(line.ordinality) as ord
    from unnest(p_names) with ordinality as line (name, ordinality)
    where btrim(line.name) <> ''
    group by btrim(line.name)
  ),
  resolved as (
    select
      pasted.wanted,
      pasted.ord,
      (
        select candidate.map_name
        from public.map as candidate
        where lower(candidate.map_name) = lower(pasted.wanted)
          or lower(candidate.archive_filename) = lower(pasted.wanted)
        order by (lower(candidate.map_name) = lower(pasted.wanted)) desc, candidate.map_name
        limit 1
      ) as map_name
    from pasted
  ),
  next_slot as (
    select coalesce(max(existing.position) + 1, 0) as next_position
    from public.map_pack_map as existing
    where existing.pack_id = p_pack_id
  ),
  added as (
    insert into public.map_pack_map (pack_id, map_name, position)
    select
      p_pack_id,
      resolved.map_name,
      (next_slot.next_position + row_number() over (order by min(resolved.ord)) - 1)::integer
    from resolved
    cross join next_slot
    where resolved.map_name is not null
    group by resolved.map_name, next_slot.next_position
    on conflict (pack_id, map_name) do nothing
    returning 1
  )
  select resolved.wanted, resolved.map_name
  from resolved
  order by resolved.ord;
$$;

-- ## Access
--
-- Whatever these roles hold is taken away first, the discipline every table,
-- view and function has followed since #59.
revoke all on public.map_pack from anon, authenticated, service_role;
revoke all on public.map_pack_map from anon, authenticated, service_role;
revoke all on public.map_pack_entry from anon, authenticated, service_role;

alter table public.map_pack enable row level security;
alter table public.map_pack_map enable row level security;

-- Read for everybody. GET /api/v1/map-packs is anonymous, and nothing in a pack
-- is private: its title, its blurb and the names of public maps.
grant select on public.map_pack to anon, authenticated;
grant select on public.map_pack_map to anon, authenticated;
grant select on public.map_pack_entry to anon, authenticated, service_role;

create policy map_pack_read_all on public.map_pack
  for select to anon, authenticated
  using (true);

create policy map_pack_map_read_all on public.map_pack_map
  for select to anon, authenticated
  using (true);

-- The moderation page's server actions, after their own is_moderator() check.
-- Delete on both, because a pack can be thrown away and a map can be taken out
-- of one. No update on the entries: an entry is a name and a position, and
-- neither is edited in place.
grant select, insert, update, delete on public.map_pack to service_role;
grant select, insert, delete on public.map_pack_map to service_role;

-- Execute is granted to PUBLIC on every new function, so this revoke is the
-- access control rather than a tidy-up.
revoke execute on function public.add_maps_to_pack(uuid, text[]) from public;
grant execute on function public.add_maps_to_pack(uuid, text[]) to service_role;
