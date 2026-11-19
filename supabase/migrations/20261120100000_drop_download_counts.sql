-- The download counters moved to the hub's MariaDB (#596), and the hub no
-- longer calls record_download or reads the count views. This takes them out
-- of Supabase. They held no map or game counts. Item imports were also
-- counted in item.import_count, which stays.
--
-- map_browse loses the three download columns. create or replace view cannot
-- remove a column, so the view is replaced whole, with the SQL from
-- 20261118130000 unchanged. Nothing else depends on it.

drop view public.map_browse;

create view public.map_browse
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

  map.map_version

from public.map_listing as listing
join public.map as map on map.id = listing.id
left join credited on credited.map_id = listing.id
left join start_point on start_point.map_id = listing.id;

-- Repeated, because create or replace does not carry grants forward.
revoke all on public.map_browse from anon, authenticated, service_role;
grant select on public.map_browse to anon, authenticated, service_role;

drop view public.map_download_count;
drop view public.game_download_count;
drop function public.record_download(text, text, text);
drop table public.download_day;
drop table public.download_seen;
