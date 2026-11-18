-- Anonymous download counters for maps, games and shared items. A caller
-- sends a hashed key (the PHP route derives it from the requester, so no
-- address is stored) and each key counts once per target per day. Repeats the
-- same day still report success, because the caller cannot tell them apart
-- from a first download and should not retry.
--
-- download_day holds the counts. download_seen holds the keys that already
-- counted today, and is pruned on every call, keeping yesterday as well so a
-- request just past midnight in another timezone does not count twice.
--
-- Neither table is granted to anon or authenticated. The write path is a
-- security definer function, the same shape as record_item_imported in
-- 20260810130000, and the read path is the views below. Functions in public
-- start with no grant since 20260827120000, so the grant is explicit.
--
-- Items keep import_count, which the function bumps beside download_day.
-- record_item_imported stays until the PHP that calls it is replaced.

create table public.download_day (
  kind text not null check (kind in ('map', 'game', 'item')),
  target_id uuid not null,
  day date not null,
  count integer not null default 0,
  primary key (kind, target_id, day)
);

create table public.download_seen (
  day date not null,
  seen_key text not null check (seen_key ~ '^[0-9a-f]{64}$'),
  primary key (day, seen_key)
);

alter table public.download_day enable row level security;
alter table public.download_seen enable row level security;

-- The key is per target as well as per requester, so one requester
-- downloading two maps counts both. The caller mixes the target into the
-- key it sends, and this function stores it as given.
create function public.record_download(p_kind text, p_target text, p_seen_key text)
returns boolean
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_target uuid;
  v_inserted integer;
begin
  if p_kind = 'map' then
    select id into v_target from public.map where map_name = p_target;
  elsif p_kind = 'game' then
    select id into v_target from public.game where shortname = p_target;
  elsif p_kind = 'item' then
    select id into v_target from public.item
    where id::text = p_target and deleted_at is null;
  end if;

  if v_target is null then
    return false;
  end if;

  delete from public.download_seen where day < current_date - 1;

  insert into public.download_seen (day, seen_key)
  values (current_date, p_seen_key)
  on conflict do nothing;
  get diagnostics v_inserted = row_count;

  if v_inserted = 1 then
    insert into public.download_day (kind, target_id, day, count)
    values (p_kind, v_target, current_date, 1)
    on conflict (kind, target_id, day)
    do update set count = public.download_day.count + 1;

    if p_kind = 'item' then
      update public.item set import_count = import_count + 1 where id = v_target;
    end if;
  end if;

  return true;
end;
$$;

grant execute on function public.record_download(text, text, text) to anon, authenticated;

-- Owner-run views (no security_invoker), because the tables underneath have
-- no grant to the roles that read them.
create view public.map_download_count as
select
  target_id,
  sum(count)::bigint as downloads_total,
  coalesce(sum(count) filter (where day > current_date - 30), 0)::bigint as downloads_30d,
  coalesce(sum(count) filter (where day > current_date - 182), 0)::bigint as downloads_182d
from public.download_day
where kind = 'map'
group by target_id;

create view public.game_download_count as
select
  target_id,
  sum(count)::bigint as downloads_total,
  coalesce(sum(count) filter (where day > current_date - 30), 0)::bigint as downloads_30d,
  coalesce(sum(count) filter (where day > current_date - 182), 0)::bigint as downloads_182d
from public.download_day
where kind = 'game'
group by target_id;

revoke all on public.map_download_count from anon, authenticated, service_role;
grant select on public.map_download_count to anon, authenticated;
revoke all on public.game_download_count from anon, authenticated, service_role;
grant select on public.game_download_count to anon, authenticated;

-- Appended after map_version, because create or replace view refuses a column
-- added between existing ones. The SQL is 20261118130000's, unchanged apart
-- from the three appended columns and the join that feeds them.
create or replace view public.map_browse
with (security_invoker = true)
as
with credit as (
  select
    author.map_id,
    public.resolved_author_key(author.key) as key,
    min(author.credit_index) as credit_index
  from public.map_author as author
  group by author.map_id, public.resolved_author_key(author.key)
),

credited as (
  select
    credit.map_id,
    array_agg(credit.key order by credit.credit_index) as keys,
    array_agg(shown.name order by credit.credit_index) as names
  from credit
  join public.author_display_name as shown on shown.key = credit.key
  group by credit.map_id
),

start_point as (
  select
    point.map_id,
    count(*) as positions
  from public.map_point as point
  where point.kind = 'start'
  group by point.map_id
)

select
  listing.*,
  map.created_at,
  greatest(listing.width_elmos, listing.height_elmos) as longer_edge_elmos,
  coalesce(start_point.positions, 0)::integer as start_positions,
  coalesce(credited.keys, '{}') as author_keys,
  coalesce(credited.names, '{}') as author_names,
  setweight(to_tsvector('english', coalesce(listing.map_name, '')), 'A') ||
  setweight(to_tsvector('english', coalesce(listing.display_name, '')), 'A') ||
  setweight(to_tsvector('english', coalesce(map.description, '')), 'B') ||
  setweight(
    to_tsvector('english', array_to_string(coalesce(credited.names, '{}'), ' ')),
    'C'
  ) as search,
  map.featured_at,
  public.map_uploaded_at(listing.map_name) as uploaded_at,
  array_remove(array[
    case when coalesce(start_point.positions, 0) = 2
      or exists (select 1 from unnest(listing.tags) as t(tag) where lower(t.tag) = '1v1')
      then '1v1' end,
    case when coalesce(start_point.positions, 0) >= 4 then 'team' end
  ], null) as play_types,

  -- Never null. An empty array is a map no healthy mirror holds.
  array(
    select source ->> 'value'
    from jsonb_array_elements(public.map_mirror_sources(listing.map_name)) as source
  ) as mirror_urls,

  map.map_version,
  coalesce(downloads.downloads_total, 0) as downloads_total,
  coalesce(downloads.downloads_30d, 0) as downloads_30d,
  coalesce(downloads.downloads_182d, 0) as downloads_182d

from public.map_listing as listing
join public.map as map on map.id = listing.id
left join credited on credited.map_id = listing.id
left join start_point on start_point.map_id = listing.id
left join public.map_download_count as downloads on downloads.target_id = listing.id;

-- Repeated, because create or replace does not carry grants forward.
revoke all on public.map_browse from anon, authenticated, service_role;
grant select on public.map_browse to anon, authenticated, service_role;
