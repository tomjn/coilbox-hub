-- A display name the hub owns, one per account (issue #429).
--
-- Until now every name was read out of auth.users.raw_user_meta_data, in the
-- order Discord happens to fill it: full_name, name, preferred_username,
-- user_name. That chain lived in three places, current_author_name(),
-- moderator_accounts() and lib/author.ts, and an account made with an email
-- address has none of those keys. This table is the one place a name lives
-- from here on. It is seeded from the provider when the account is made, and
-- is the owner's to change afterwards (#434).
--
-- The length check and the unique index are the issue's. The index is on
-- lower(display_name) because a chosen name colliding with somebody else's is
-- impersonation, and a gallery filtered by name only means anything if a name
-- identifies one person.
create table public.profile (
  user_id      uuid primary key references auth.users (id) on delete cascade,
  display_name text not null check (length(btrim(display_name)) between 2 and 40),
  created_at   timestamptz not null default now(),
  updated_at   timestamptz not null default now()
);

create unique index profile_display_name_unique on public.profile (lower(display_name));

create trigger profile_touch_updated_at
  before update on public.profile
  for each row execute function public.touch_updated_at();

-- ## Seeding
--
-- One function chooses the name and writes the row, so the sign up trigger
-- and the backfill below cannot drift apart. In order:
--
-- 1. The first of the four metadata keys that holds a name passing the length
--    check. That is the old chain, except a key too short or too long gives
--    way to the next key rather than straight to the email address. Any future
--    provider that fills one of these keys is covered with no change here.
-- 2. The local part of the email address.
-- 3. "Coilbox user" and the start of the account id, which always passes.
--
-- A name somebody already holds, in any case, gets " 2", " 3" and so on, with
-- the name shortened to keep it inside the check. Sign up must never fail
-- because somebody else had the name first. The insert says on conflict do
-- nothing and tries the next number, so two sign ups racing for one name both
-- succeed rather than one of them failing on the unique index.
--
-- An account that already has a profile keeps it. That is what makes the
-- backfill safe to run against an account the trigger has already seeded.
create function public.seed_profile(p_user_id uuid) returns text
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_meta jsonb;
  v_email text;
  v_key text;
  v_base text;
  v_suffix text;
  v_candidate text;
  v_number integer := 1;
begin
  select coalesce(u.raw_user_meta_data, '{}'::jsonb), u.email
    into v_meta, v_email
    from auth.users u
    where u.id = p_user_id;
  if not found then
    raise exception 'seed_profile: no account %', p_user_id using errcode = 'P0002';
  end if;

  foreach v_key in array array['full_name', 'name', 'preferred_username', 'user_name'] loop
    v_base := btrim(v_meta ->> v_key);
    exit when length(v_base) between 2 and 40;
    v_base := null;
  end loop;

  if v_base is null then
    v_base := btrim(split_part(v_email, '@', 1));
    if not coalesce(length(v_base) between 2 and 40, false) then
      v_base := null;
    end if;
  end if;

  if v_base is null then
    v_base := 'Coilbox user ' || left(replace(p_user_id::text, '-', ''), 8);
  end if;

  loop
    select p.display_name into v_candidate from public.profile p where p.user_id = p_user_id;
    if found then
      return v_candidate;
    end if;

    if v_number = 1 then
      v_candidate := v_base;
    else
      v_suffix := ' ' || v_number;
      v_candidate := rtrim(left(v_base, 40 - length(v_suffix))) || v_suffix;
    end if;

    insert into public.profile (user_id, display_name)
      values (p_user_id, v_candidate)
      on conflict do nothing;
    if found then
      return v_candidate;
    end if;

    v_number := v_number + 1;
  end loop;
end;
$$;

revoke execute on function public.seed_profile(uuid) from public;

-- Every new account, whichever provider made it. security definer because
-- the auth server inserts into auth.users as its own role, which holds no
-- grant on seed_profile and should not.
create function public.profile_on_sign_up() returns trigger
language plpgsql
security definer
set search_path = ''
as $$
begin
  perform public.seed_profile(new.id);
  return new;
end;
$$;

revoke execute on function public.profile_on_sign_up() from public;

create trigger profile_on_sign_up
  after insert on auth.users
  for each row execute function public.profile_on_sign_up();

-- ## Backfill
--
-- Every existing account, oldest first, so where two Discord names collide
-- the account that has held it longest keeps it unchanged. A loop rather than
-- one select, so the order the rows are seeded in is the order written here.
do $$
declare
  r record;
begin
  for r in select u.id from auth.users u order by u.created_at, u.id loop
    perform public.seed_profile(r.id);
  end loop;
end;
$$;

-- ## Access
--
-- Readable by everyone, because a display name already appears next to every
-- published item and on every moderator row. Only user_id and display_name
-- are granted: when an account was made is not the public's business.
--
-- The owner may change the name and nothing else. No role inserts or deletes:
-- the trigger above makes the row and the cascade from auth.users removes it.
-- The unique index refuses a name somebody else holds, which #434 turns into
-- a sentence a person can act on.
alter table public.profile enable row level security;

grant select (user_id, display_name) on public.profile to anon, authenticated;
grant update (display_name) on public.profile to authenticated;

create policy profile_read_all on public.profile
  for select
  using (true);

create policy profile_update_own on public.profile
  for update to authenticated
  using (auth.uid() = user_id)
  with check (auth.uid() = user_id);

-- ## Readers
--
-- The trust default on item.author_name keeps its guarantee: a client cannot
-- set the column, and the default reads the caller's own row by auth.uid().
-- Only where the name comes from changes. 'Unknown' stays as the fallback so
-- the column can never be null, though every account now has a row.
create or replace function public.current_author_name() returns text
language sql
stable
security definer
set search_path = ''
as $$
  select coalesce(
    (select p.display_name from public.profile p where p.user_id = auth.uid()),
    'Unknown'
  );
$$;

-- The moderator directory, unchanged except for the name. It still lists
-- every account in auth.users, so an account is never missing from it.
create or replace function public.moderator_accounts(p_search text, p_offset integer)
returns table (
  id uuid,
  display_name text,
  created_at timestamptz,
  capabilities text[]
)
language plpgsql
stable
security definer
set search_path = ''
as $$
begin
  if not public.is_moderator() then
    raise exception 'moderator_accounts is for moderators' using errcode = '42501';
  end if;

  return query
  with directory as (
    select
      u.id,
      coalesce(p.display_name, '') as display_name,
      u.created_at
    from auth.users u
    left join public.profile p on p.user_id = u.id
    where
      p_search is null
      or u.id::text = p_search
      or p.display_name ilike '%' || p_search || '%'
  )
  select
    d.id,
    d.display_name,
    d.created_at,
    coalesce(
      array_agg(uc.capability order by uc.capability) filter (where uc.user_id is not null),
      '{}'
    )
  from directory d
  left join public.user_capability uc on uc.user_id = d.id
  group by d.id, d.display_name, d.created_at
  order by d.created_at desc, d.id
  offset greatest(coalesce(p_offset, 0), 0)
  limit 50;
end;
$$;
