-- One table for everything published to the gallery.
--
-- The service has no opinion about what is inside an item. Coilbox already
-- defines the format in src/container/container.ts, and that container is stored
-- verbatim in `container`. Everything else on the row is lifted out of it only so
-- a listing can filter and sort without opening the payload.

create table public.item (
  id uuid primary key default gen_random_uuid(),

  -- Four of the five kinds coilbox writes. Campaigns are excluded because they
  -- inline images and audio as base64 and run past the import ceiling below. A
  -- check rather than an enum, because lego is expected to join later and
  -- widening a check is a one line migration.
  kind text not null check (kind in ('preset', 'challenge', 'setup-pack', 'scenario')),
  kind_version integer not null check (kind_version > 0),

  title text not null check (length(btrim(title)) between 1 and 120),
  description text not null default '' check (length(description) <= 2000),

  -- Lifted out of the payload at publish time. Nullable because not every kind
  -- names a game or a map, and a setup pack names neither.
  game_name text,
  map_name text,
  tags text[] not null default '{}',

  -- The container exactly as coilbox wrote it. The size check is the same 512 KB
  -- ceiling the app applies when importing, from MAX_IMPORT_BYTES in
  -- src/deeplink/fetchImport.ts. Storing anything larger would mean handing out a
  -- link that cannot be opened, so it is refused here as well as in the app, and
  -- a direct PostgREST insert cannot get around it.
  container jsonb not null check (octet_length(container::text) <= 524288),

  -- Cascades so deleting an account really does take everything with it.
  author_id uuid not null references auth.users (id) on delete cascade,
  -- A snapshot, not a join. An item keeps the name its author had when they
  -- published it, and still reads sensibly once the account is gone.
  author_name text not null,

  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  -- Withdrawal is soft: the item stops being served, and a moderator can still
  -- see what was there.
  deleted_at timestamptz
);

-- Every read filters out withdrawn items, so the indexes that serve browsing are
-- partial on the same condition.
create index item_live_created_idx on public.item (created_at desc) where deleted_at is null;
create index item_live_kind_idx on public.item (kind, created_at desc) where deleted_at is null;
create index item_live_game_idx on public.item (game_name) where deleted_at is null;
create index item_live_map_idx on public.item (map_name) where deleted_at is null;
create index item_live_tags_idx on public.item using gin (tags) where deleted_at is null;
create index item_author_idx on public.item (author_id);

-- updated_at is maintained here rather than trusted from the client, since the
-- client that writes it is the one being authorised.
create function public.touch_updated_at() returns trigger
language plpgsql
set search_path = ''
as $$
begin
  new.updated_at = now();
  return new;
end;
$$;

create trigger item_touch_updated_at
  before update on public.item
  for each row execute function public.touch_updated_at();

alter table public.item enable row level security;

-- Grants and policies are two different layers and both are needed. A grant says
-- what a role may attempt, a policy says which rows it may touch. Without the
-- grant the read policy below never runs, because permission is refused first.
--
-- The update grant is per column on purpose. It is the whole of what "edit your
-- item" means: the words around it, and withdrawing it. The container, its kind,
-- the derived game and map names and the timestamps are all outside it, so
-- republishing a changed payload under an existing URL is not something an author
-- can do by accident, and created_at cannot be forged to climb the default sort.
grant select on public.item to anon, authenticated;
grant insert, delete on public.item to authenticated;
grant update (title, description, tags, deleted_at) on public.item to authenticated;

-- The whole authorisation model is these four policies, and it holds however the
-- data is reached, including straight through PostgREST. That is what makes an
-- anonymous read path safe to expose without a hand written API in front of it.

-- Anyone may read a live item, with no account and no session. An author can also
-- see their own withdrawn items, otherwise withdrawing one would be irreversible.
create policy item_read_live on public.item
  for select
  using (deleted_at is null or auth.uid() = author_id);

-- Publishing requires an account, and you can only publish as yourself.
create policy item_insert_own on public.item
  for insert to authenticated
  with check (auth.uid() = author_id);

-- An author may change their own item, and may not hand it to somebody else.
create policy item_update_own on public.item
  for update to authenticated
  using (auth.uid() = author_id)
  with check (auth.uid() = author_id);

create policy item_delete_own on public.item
  for delete to authenticated
  using (auth.uid() = author_id);
