-- Discord login is a deterrent, not moderation. There needs to be a way for
-- anyone to flag something and a place for somebody to act on it.

-- Who can act. A table rather than a claim on the user, so adding one is a row
-- and not a redeployment.
create table public.moderator (
  user_id uuid primary key references auth.users (id) on delete cascade,
  added_at timestamptz not null default now()
);

alter table public.moderator enable row level security;

-- Deliberately no policy granting select to anyone. The list of who moderates is
-- not public, and the function below reads it as its owner.
create function public.is_moderator() returns boolean
language sql
stable
security definer
set search_path = ''
as $$
  select exists (select 1 from public.moderator where user_id = auth.uid());
$$;

grant execute on function public.is_moderator() to anon, authenticated;

create table public.report (
  id uuid primary key default gen_random_uuid(),
  item_id uuid not null references public.item (id) on delete cascade,
  -- Nullable, because the person best placed to notice something wrong is often
  -- just browsing and has no account.
  reporter_id uuid references auth.users (id) on delete set null,
  reason text not null check (length(btrim(reason)) between 1 and 1000),
  created_at timestamptz not null default now(),
  handled_at timestamptz,
  handled_by uuid references auth.users (id) on delete set null
);

create index report_open_idx on public.report (created_at desc) where handled_at is null;
create index report_item_idx on public.report (item_id);

alter table public.report enable row level security;

-- Anyone may report, with or without an account, and nobody may read reports
-- except a moderator. A reporter cannot see their own report either: there is
-- nothing useful in it for them and it would leak what else has been flagged.
create policy report_insert_anyone on public.report
  for insert to anon, authenticated
  with check (true);

create policy report_read_moderators on public.report
  for select to authenticated
  using (public.is_moderator());

create policy report_handle_moderators on public.report
  for update to authenticated
  using (public.is_moderator())
  with check (public.is_moderator());

grant insert on public.report to anon, authenticated;
grant select, update on public.report to authenticated;

-- A moderator can see and withdraw anything, which is the whole of what
-- moderating means here. Everything else about an item stays with its author.
create policy item_read_moderators on public.item
  for select to authenticated
  using (public.is_moderator());

create policy item_withdraw_moderators on public.item
  for update to authenticated
  using (public.is_moderator())
  with check (public.is_moderator());
