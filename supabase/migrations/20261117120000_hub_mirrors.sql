-- Hub mirrors: the tables behind signed uploads, mirror reports and the
-- signed catalogue.
--
-- The hub never stores a map or game file. A mirror holds the bytes, and the
-- hub records which archives exist, which mirror holds them, and the signed
-- catalogue every mirror syncs from. Spec:
-- docs/superpowers/specs/2026-11-16-hub-mirrors-design.md
--
-- Nothing here is readable or writable from a browser. The hub reads mirror
-- and catalogue_state with the secret key, and every write goes through the
-- functions added in the migrations that follow.

-- ## Mirrors

create table public.mirror (
  -- Part of the signed catalogue and of every upload token.
  id text primary key check (id ~ '^[a-z0-9][a-z0-9-]{0,63}$'),
  name text not null check (char_length(name) between 1 and 128),
  base_url text not null check (
    base_url ~ '^https?://[^/?#\s]+(/[^?#\s]*)?$' and base_url !~ '/$'
  ),
  -- An Ed25519 public key, base64url with no padding.
  public_key text not null check (public_key ~ '^[A-Za-z0-9_-]{43}$'),
  enabled boolean not null default true,
  -- A new mirror starts unhealthy until a report passes the hub's check.
  healthy boolean not null default false,
  last_report_at timestamptz,
  last_report_sent_at bigint,
  last_catalogue_seq bigint,
  last_catalogue_generated_at bigint,
  last_check_at timestamptz,
  created_at timestamptz not null default now()
);

comment on table public.mirror is
  'A server that holds map and game archives and syncs from the hub catalogue.';

-- ## Archives

create table public.archive (
  -- Also the upload ID in the upload token.
  id uuid primary key default gen_random_uuid(),
  sha256 text not null check (sha256 ~ '^[0-9a-f]{64}$'),
  filename text not null check (octet_length(filename) between 1 and 255),
  filename_lower text generated always as (lower(filename)) stored,
  kind text not null check (kind in ('map', 'game')),
  internal_name text not null check (char_length(internal_name) between 1 and 256),
  -- Empty for a map whose name carries no separate version.
  version text not null check (char_length(version) <= 256),
  shortname text check (char_length(shortname) between 1 and 64),
  author text check (char_length(author) <= 256),
  description text check (char_length(description) <= 4000),
  size bigint not null check (size > 0),
  uploader uuid references auth.users (id) on delete set null,
  state text not null default 'uploading'
    check (state in ('uploading', 'listed', 'failed', 'removed')),
  game_id uuid references public.game (id) on delete set null,
  -- The mirror an upload token was issued for. A mirror's report links to the
  -- upload by hash and mirror.
  upload_mirror_id text references public.mirror (id),
  reserved_until timestamptz,
  listed_at timestamptz,
  -- Removal controls come later. These columns let the catalogue's removed
  -- list be real now.
  removed_at timestamptz,
  removal_reason text check (char_length(removal_reason) between 1 and 200),
  removed_by uuid references auth.users (id) on delete set null,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),

  constraint archive_game_shortname_check
    check (kind <> 'game' or shortname is not null)
);

comment on table public.archive is
  'A map or game archive uploaded through the hub. The file lives on mirrors.';

-- A failed upload frees its hash, name and map name for another attempt.
create unique index archive_sha256_idx on public.archive (sha256) where state <> 'failed';
create unique index archive_filename_idx on public.archive (filename_lower) where state <> 'failed';
create unique index archive_name_version_idx on public.archive (internal_name, version) where state <> 'failed';

create index archive_game_listed_idx on public.archive (game_id, listed_at) where state = 'listed';
create index archive_kind_name_listed_idx on public.archive (kind, internal_name) where state = 'listed';

create table public.archive_mirror (
  archive_id uuid not null references public.archive (id) on delete cascade,
  mirror_id text not null references public.mirror (id) on delete cascade,
  first_seen_at timestamptz not null default now(),
  last_seen_at timestamptz not null default now(),
  primary key (archive_id, mirror_id)
);

comment on table public.archive_mirror is
  'Which mirror reported holding which listed archive, from its latest report.';

-- ## Catalogue state
--
-- One row. changes counts every edit that alters the catalogue, built_changes
-- is the count the stored catalogue was built from, and the two differ when
-- the stored copy is out of date.

create table public.catalogue_state (
  id boolean primary key default true check (id),
  next_seq bigint not null default 1,
  changes bigint not null default 0,
  built_changes bigint not null default -1,
  seq bigint,
  generated_at bigint,
  body text,
  sig text,
  next_key_public text,
  next_key_since_seq bigint
);

insert into public.catalogue_state (id) values (true);

-- ## Triggers

create function public.bump_catalogue_changes() returns trigger
language plpgsql
security definer
set search_path = ''
as $$
begin
  update public.catalogue_state set changes = changes + 1;
  return null;
end;
$$;

revoke execute on function public.bump_catalogue_changes() from public;

create trigger mirror_catalogue_insert_delete
  after insert or delete on public.mirror
  for each statement execute function public.bump_catalogue_changes();

create trigger mirror_catalogue_update
  after update of enabled, base_url, public_key on public.mirror
  for each row
  when (
    old.enabled is distinct from new.enabled
    or old.base_url is distinct from new.base_url
    or old.public_key is distinct from new.public_key
  )
  execute function public.bump_catalogue_changes();

create trigger archive_catalogue_update
  after update of state on public.archive
  for each row
  when (
    old.state in ('listed', 'removed') or new.state in ('listed', 'removed')
  )
  execute function public.bump_catalogue_changes();

create function public.touch_archive_updated_at() returns trigger
language plpgsql
set search_path = ''
as $$
begin
  new.updated_at = now();
  return new;
end;
$$;

create trigger archive_touch_updated_at
  before update on public.archive
  for each row execute function public.touch_archive_updated_at();

-- ## jj-darkstars
--
-- Registered by migration, as 20260819100000_map_mirror_seed.sql registered
-- the map hosts. The public key is public. It starts unhealthy and turns
-- healthy when its first report passes the hub's check.

insert into public.mirror (id, name, base_url, public_key)
values (
  'jj-darkstars',
  'jj.darkstars.co.uk',
  'https://jj.darkstars.co.uk',
  'B17CkPdtRVPSfwdwz9CenRCdM6QSUBFMarO-10NppNY'
);

-- ## Access
--
-- Row level security on and every grant revoked. The hub reads mirror and
-- catalogue_state as service_role. Writes happen only through functions.

alter table public.mirror enable row level security;
alter table public.archive enable row level security;
alter table public.archive_mirror enable row level security;
alter table public.catalogue_state enable row level security;

revoke all on public.mirror from anon, authenticated, service_role;
revoke all on public.archive from anon, authenticated, service_role;
revoke all on public.archive_mirror from anon, authenticated, service_role;
revoke all on public.catalogue_state from anon, authenticated, service_role;

grant select on public.mirror, public.catalogue_state to service_role;
