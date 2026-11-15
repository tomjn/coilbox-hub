-- Where a map can be downloaded, and who may say so.
--
-- Until now a map page only offered public.map_mirror_host's guesses, built
-- from the archive filename and never checked. This adds a map's own ordered
-- download sources, the shape public.game_download_source has, written by the
-- map's owner, the owner of one of its authors, or a moderator.
--
-- Ownership here grants editing the download sources and nothing else. The
-- facts stay unowned, for the reason 20260818130000_map_submission.sql gives.
--
-- A person owns one map, or one author key and with it every map credited to
-- that author, now and later. Both are granted by a moderator approving a
-- request, never by a browser write.

-- ## Owners

alter table public.map add column owner_user_id uuid references auth.users (id) on delete set null;

create table public.author_owner (
  -- The key as it resolved when the request was approved. can_edit_map
  -- resolves both sides, so a merge either way keeps the owner's maps.
  key text primary key check (length(btrim(key)) between 1 and 256),
  user_id uuid not null references auth.users (id) on delete cascade,
  created_at timestamptz not null default now()
);

create index author_owner_user_idx on public.author_owner (user_id);

-- ## Requests

create table public.map_ownership_request (
  id bigint generated always as identity primary key,

  -- Exactly one of the two: this map, or every map by this author.
  map_id uuid references public.map (id) on delete cascade,
  author_key text check (length(btrim(author_key)) between 1 and 256),

  requested_by uuid not null references auth.users (id) on delete cascade,
  requested_by_name text not null default public.current_author_name()
    check (length(btrim(requested_by_name)) between 1 and 256),

  note text check (length(note) <= 2000),

  state text not null default 'open' check (state in ('open', 'approved', 'declined')),

  decided_by uuid references auth.users (id) on delete set null,
  decided_at timestamptz,

  created_at timestamptz not null default now(),

  constraint map_ownership_request_target_check
    check ((map_id is null) <> (author_key is null))
);

create unique index map_ownership_request_open_map_idx
  on public.map_ownership_request (map_id, requested_by)
  where state = 'open' and map_id is not null;

create unique index map_ownership_request_open_author_idx
  on public.map_ownership_request (author_key, requested_by)
  where state = 'open' and author_key is not null;

create index map_ownership_request_queue_idx
  on public.map_ownership_request (created_at)
  where state = 'open';

-- ## Sources

create table public.map_download_source (
  id bigint generated always as identity primary key,

  map_id uuid not null references public.map (id) on delete cascade,

  -- No rapid. Coilbox's map "rapid" step is pr-downloader searching by map
  -- name, not a tag anybody could type here.
  kind text not null check (kind in ('url', 'github')),

  value text not null check (length(btrim(value)) between 1 and 512),
  asset text check (length(btrim(asset)) between 1 and 256),
  filename text check (length(btrim(filename)) between 1 and 256),

  sort_order integer not null default 0,

  constraint map_download_source_asset_kind_check
    check (asset is null or kind = 'github'),
  constraint map_download_source_filename_kind_check
    check (filename is null or kind = 'url')
);

comment on table public.map_download_source is
  'Where to download a map, best source first. Written by the map''s owner, an owner of one of its authors, or a moderator.';

create index map_download_source_map_idx
  on public.map_download_source (map_id, sort_order, id);

-- ## Who may edit a map's sources
--
-- Security definer because author_owner is not readable by a browser. It
-- answers only for the caller, so it discloses nothing beyond yes or no.
create function public.can_edit_map(p_map_id uuid) returns boolean
language sql
stable
security definer
set search_path = ''
as $$
  select auth.uid() is not null and (
    public.is_moderator()
    or exists (
      select 1 from public.map as m
      where m.id = p_map_id and m.owner_user_id = auth.uid()
    )
    or exists (
      select 1
      from public.map_author as a
      join public.author_owner as o
        on public.resolved_author_key(o.key) = public.resolved_author_key(a.key)
      where a.map_id = p_map_id and o.user_id = auth.uid()
    )
  );
$$;

revoke execute on function public.can_edit_map(uuid) from public;
grant execute on function public.can_edit_map(uuid) to authenticated, service_role;

-- ## Access

revoke all on public.author_owner from anon, authenticated, service_role;
revoke all on public.map_ownership_request from anon, authenticated, service_role;
revoke all on public.map_download_source from anon, authenticated, service_role;

alter table public.author_owner enable row level security;
alter table public.map_ownership_request enable row level security;
alter table public.map_download_source enable row level security;

-- author_owner: the hub reads it with the secret key to draw the edit link,
-- and a moderator's approval writes it. Nothing for a browser.
grant select, insert, delete on public.author_owner to service_role;

-- Requests: the same rules as game_ownership_request.
grant select, insert on public.map_ownership_request to authenticated;
grant update (state, decided_by, decided_at) on public.map_ownership_request to authenticated;
grant select, update on public.map_ownership_request to service_role;

create policy map_ownership_request_read_own_or_moderator
  on public.map_ownership_request
  for select to authenticated
  using (requested_by = auth.uid() or public.is_moderator());

create policy map_ownership_request_insert_own
  on public.map_ownership_request
  for insert to authenticated
  with check (requested_by = auth.uid() and state = 'open');

create policy map_ownership_request_decide
  on public.map_ownership_request
  for update to authenticated
  using (public.is_moderator())
  with check (public.is_moderator());

-- Sources: public to read, insert and delete for whoever can_edit_map allows.
-- No update, the form replaces the whole list.
grant select on public.map_download_source to anon, authenticated;
grant insert, delete on public.map_download_source to authenticated;
grant select, insert, update, delete on public.map_download_source to service_role;

create policy map_download_source_read_all on public.map_download_source
  for select to anon, authenticated
  using (true);

create policy map_download_source_insert_editor on public.map_download_source
  for insert to authenticated
  with check (public.can_edit_map(map_id));

create policy map_download_source_delete_editor on public.map_download_source
  for delete to authenticated
  using (public.can_edit_map(map_id));

-- ## map_facts carries the sources
--
-- The map page and the lookup route both read this, so both get the sources.
-- Owners are left out: this is published to every Coilbox.
create or replace function public.map_facts(p_names text[]) returns jsonb
language sql
stable
set search_path = ''
as $$
  with wanted as (
    select map.*
    from public.map as map
    where map.map_name = any(p_names)
  ),

  -- Every credit on the maps asked about, under the key it counts as today
  -- rather than the key it was filed under when it was submitted.
  credit as (
    select
      wanted.id as map_id,
      author.credit_index,
      public.resolved_author_key(author.key) as key
    from wanted
    join public.map_author as author on author.map_id = wanted.id
  ),

  -- One entry per person per map. Two credits on one map that resolve to one
  -- key are one author, which is what an archive crediting `Beherith` and
  -- `[BAR]Beherith` in the same string comes to, and listing them twice would
  -- put the same mapper on the page twice.
  --
  -- The earliest credit_index carries the order, because credit_index is the
  -- order the archive credited them in and is not the hub's to reorder.
  merged as (
    select
      credit.map_id,
      credit.key,
      min(credit.credit_index) as credit_index
    from credit
    group by credit.map_id, credit.key
  ),

  -- A plain join rather than a left join, because every key here has at least
  -- one spelling: the credit row being answered is itself one of the rows the
  -- view counted.
  authors as (
    select
      merged.map_id,
      jsonb_agg(
        jsonb_build_object('key', merged.key, 'name', shown.name)
        order by merged.credit_index
      ) as entries
    from merged
    join public.author_display_name as shown on shown.key = merged.key
    group by merged.map_id
  ),

  -- The points, grouped by kind and ordered within each kind. The order is the
  -- stored ordinal, which is the team index on a start position and carries
  -- meaning, so it is never sorted on anything else.
  --
  -- meta comes through whole. It is where a metal spot's amount and radius and
  -- a geo vent's feature type live, and a caller drawing metal spots needs
  -- them.
  points as (
    select
      grouped.map_id,
      jsonb_object_agg(grouped.kind, grouped.positions) as kinds
    from (
      select
        point.map_id,
        point.kind,
        jsonb_agg(
          jsonb_build_object('x', point.x, 'z', point.z, 'y', point.y, 'meta', point.meta)
          order by point.ordinal
        ) as positions
      from public.map_point as point
      join wanted on wanted.id = point.map_id
      group by point.map_id, point.kind
    ) as grouped
    group by grouped.map_id
  ),

  -- The map's own download sources, in the order to try them. The keys a kind
  -- has no use for are absent, the contract game downloads follow.
  downloads as (
    select
      source.map_id,
      jsonb_agg(
        jsonb_strip_nulls(jsonb_build_object(
          'kind', source.kind,
          'value', source.value,
          'asset', source.asset,
          'filename', source.filename
        ))
        order by source.sort_order, source.id
      ) as entries
    from public.map_download_source as source
    join wanted on wanted.id = source.map_id
    group by source.map_id
  )

  -- One element per map the hub holds, each carrying the name it was found
  -- under and the facts beneath it. A name with no row is simply absent, and
  -- the route turns that into the null the caller reads as "the hub has never
  -- heard of this map".
  select coalesce(
    jsonb_agg(
      jsonb_build_object(
        'map_name', wanted.map_name,
        'facts', jsonb_build_object(
          'slug', wanted.slug,
          'display_name', wanted.display_name,
          'description', wanted.description,
          'authors', coalesce(authors.entries, '[]'::jsonb),
          'width_elmos', wanted.width_elmos,
          'height_elmos', wanted.height_elmos,
          'world_height_min', wanted.world_height_min,
          'world_height_max', wanted.world_height_max,
          'min_wind', wanted.min_wind,
          'max_wind', wanted.max_wind,
          'tidal_strength', wanted.tidal_strength,
          'void_water', wanted.void_water,
          'water_coverage', wanted.water_coverage,

          -- Off the view, never recomputed. public.map_listing is where a
          -- measurement becomes a word a reader browses by, and it merges the
          -- curated tags in, so a caller never has to know which half a tag
          -- came from.
          'tags', to_jsonb(listing.tags),

          -- The three kinds are always present, so a caller reads
          -- points.metal without asking whether the key exists. The empty
          -- object is the base rather than the answer, so a kind added to
          -- map_point_kind_check later appears here on its own.
          'points', '{"start": [], "metal": [], "geo": []}'::jsonb
            || coalesce(points.kinds, '{}'::jsonb),

          'appearance', wanted.appearance,
          'downloads', coalesce(downloads.entries, '[]'::jsonb)
        )
      )
    ),
    '[]'::jsonb
  )
  from wanted
  join public.map_listing as listing on listing.id = wanted.id
  left join authors on authors.map_id = wanted.id
  left join points on points.map_id = wanted.id
  left join downloads on downloads.map_id = wanted.id;
$$;

revoke execute on function public.map_facts(text[]) from public;
grant execute on function public.map_facts(text[]) to service_role;
