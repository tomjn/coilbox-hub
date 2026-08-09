-- The insert grant on public.item was table wide (line 84 of the previous
-- migration), unlike the update grant beside it, which is per column. RLS
-- checks author_id and nothing else, so id, created_at, updated_at,
-- deleted_at and author_name were all whatever an insert said they were.
-- created_at could not climb the sort by editing, as the comment there says,
-- but it could by inserting, and author_name could name anybody at all. None
-- of that is reachable through the website, since the publish form fills
-- those fields itself, but it becomes reachable the moment a second client
-- exists, which is what this milestone adds.
--
-- The fix is the same shape already used for update: grant insert per
-- column, and let the column's own default cover the rest. id, created_at
-- and updated_at already default sensibly, and deleted_at is nullable, so
-- leaving them out of the grant is the whole fix for those four. author_name
-- has no row-derived default to fall back on, since it comes from the
-- author's Discord profile rather than from the item, so it gets one here:
-- the same lookup lib/author.ts's displayName does, in the same fallback
-- order, read from auth.users instead of a client-supplied value. A column
-- whose default a client cannot override cannot be forged, whatever the
-- insert asks for.
create function public.current_author_name() returns text
language sql
stable
security definer
set search_path = ''
as $$
  select coalesce(
    nullif(btrim(u.raw_user_meta_data ->> 'full_name'), ''),
    nullif(btrim(u.raw_user_meta_data ->> 'name'), ''),
    nullif(btrim(u.raw_user_meta_data ->> 'preferred_username'), ''),
    nullif(btrim(u.raw_user_meta_data ->> 'user_name'), ''),
    'Unknown'
  )
  from auth.users u
  where u.id = auth.uid();
$$;

grant execute on function public.current_author_name() to authenticated;

alter table public.item alter column author_name set default public.current_author_name();

revoke insert on public.item from authenticated;
grant insert (
  kind, kind_version, title, description, game_name, map_name, tags, container, author_id
) on public.item to authenticated;

-- Separately, nothing checked that `container` held a coilbox container at
-- all: the existing checks cover size, kind and that kind_version is
-- positive, and a literal '{}' passes every one of them. Full payload
-- validation stays in accept(), the only place able to run the TypeScript
-- validator against a specific kind, but the envelope frame itself -
-- format, container, kind, kindVersion and payload, from asContainer() in
-- lib/container/container.ts - is cheap to check here and closes the gap
-- for every write path, including one straight through PostgREST.
alter table public.item add constraint item_container_is_frame check (
  jsonb_typeof(container) = 'object'
  and container ->> 'format' = 'coilbox'
  and jsonb_typeof(container -> 'container') = 'number'
  and jsonb_typeof(container -> 'kind') = 'string'
  and jsonb_typeof(container -> 'kindVersion') = 'number'
  and container ? 'payload'
);
