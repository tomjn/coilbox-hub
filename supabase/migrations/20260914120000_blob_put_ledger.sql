-- One row per put() into the staging store, so the hub counts advanced
-- operations the way Vercel does.
--
-- ## Why the old count was wrong
--
-- The upload budget and the operations meter both counted asset rows whose
-- seen_at fell in the current calendar month. Vercel counts a rolling 30 days
-- on Hobby and has no billing cycle, so on 1 September the hub's count went back
-- to zero while every put() from late August still counted against the store.
-- By 13 September the store was suspended, and the hub was reporting 675 of
-- 2,000.
--
-- The rows were also the wrong thing to count. A replacement moves seen_at and
-- so erases the put() before it, a game's logo and banner have no asset row at
-- all, and an upload that reuses an object it did not write counted as one.
--
-- ## Why a reservation rather than a record
--
-- The row is written before the put(), by the same call that checks the
-- budget, under a lock. Writing it after would miss a put() whose request died
-- before the record, and checking in one statement and writing in another lets
-- two uploads both see room for one. A reservation for a put() that then fails
-- stays counted, because a put() that timed out may still have landed, and
-- over-counting is the direction that does not end in a suspension. The one
-- exception is a store that answered "suspended", which accepted nothing, and
-- public.release_blob_put is for that.

create table public.blob_put (
  id bigint generated always as identity primary key,

  -- What the operation was for, so the allowances page can say which kind of
  -- upload is spending the allowance.
  kind text not null check (kind in ('asset', 'game_image')),

  at timestamptz not null default now()
);

-- Every read of this table is "since 30 days ago".
create index blob_put_at_idx on public.blob_put (at);

revoke all on public.blob_put from anon, authenticated, service_role;

-- Read for the meters, which run on the secret key. Nothing writes it directly:
-- the two functions below are the only writers, so a count cannot be edited
-- down by hand to make room.
grant select on public.blob_put to service_role;

alter table public.blob_put enable row level security;

-- Reserve one put(), or answer null when the last 30 days already hold
-- `budget` of them.
--
-- The budget is a parameter because the number belongs next to the code that
-- spends it (lib/assets/blob.ts) rather than in a migration.
--
-- A transaction scoped advisory lock serialises reservations, which is the whole
-- of what makes the count and the insert one decision. It is held for the
-- length of one count over an indexed range.
create function public.reserve_blob_put(put_kind text, budget integer)
returns bigint
language plpgsql
security definer
set search_path = ''
as $$
declare
  used integer;
  reserved bigint;
begin
  perform pg_advisory_xact_lock(hashtext('public.blob_put'));

  select count(*) into used
  from public.blob_put
  where at > now() - interval '30 days';

  if used >= reserve_blob_put.budget then
    return null;
  end if;

  insert into public.blob_put (kind)
  values (reserve_blob_put.put_kind)
  returning id into reserved;

  return reserved;
end;
$$;

-- Give back a reservation whose put() the store refused outright. Answers
-- whether there was one to give back.
create function public.release_blob_put(put_id bigint)
returns boolean
language plpgsql
security definer
set search_path = ''
as $$
declare
  removed integer;
begin
  delete from public.blob_put where id = release_blob_put.put_id;
  get diagnostics removed = row_count;
  return removed > 0;
end;
$$;

revoke execute on function public.reserve_blob_put(text, integer) from public;
revoke execute on function public.release_blob_put(bigint) from public;

grant execute on function public.reserve_blob_put(text, integer) to service_role;
grant execute on function public.release_blob_put(bigint) to service_role;

-- What the table can recover of the last 30 days. It is less than happened,
-- because a replaced row kept only its latest put() and game pictures were never
-- recorded anywhere, so the store's own usage page stays the better figure until
-- these rows have aged out.
insert into public.blob_put (kind, at)
select 'asset', a.seen_at
from public.asset as a
where a.uploaded_by is not null
  and a.seen_at > now() - interval '30 days';

insert into public.blob_put (kind, at)
select 'asset', o.at
from public.asset_orphan as o
where o.reason = 'unclaimed'
  and o.at > now() - interval '30 days';
