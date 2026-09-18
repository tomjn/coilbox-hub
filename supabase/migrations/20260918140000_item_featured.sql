-- A moderator can say a published gallery item is worth seeing first (#395).
--
-- ## Why this is two more columns and not a featured table
--
-- 20260918120000 put featured_at and featured_by on public.game, #394 is about
-- to put them on public.map, and this is the third table to want them. The
-- alternative considered was one table keyed by a kind and an id, so that
-- "what is featured right now" is one read rather than three.
--
-- The columns win, for two reasons that are about this schema rather than about
-- taste. A keyed table cannot carry a foreign key to the thing it points at, so
-- a deleted map would leave a row claiming something is featured that no longer
-- exists, and the trigger that swept up after it would have to name each kind
-- of subject, which is the maintenance the keyed table was meant to avoid. And
-- the gallery's listing is ordered, filtered, counted and paged in one
-- PostgREST request over public.item: ordering it by a column on another table
-- means a view in front of it, which is a body every later migration has to
-- carry forward whole (20260914210000 says what that costs).
--
-- What that decision costs is the question the issue asks: nothing can answer
-- "what is featured" across the hub in one read. Each listing's own moderation
-- page answers it for its own kind of thing, which is where a moderator is
-- standing when they ask.
--
-- ## Nothing here names a kind of item
--
-- An item's kind is preset, challenge, setup-pack, scenario or blueprint today
-- and a mod project is coming. Featuring is a judgement about the item, not
-- about what is inside it, so neither the columns nor the function below look
-- at public.item.kind at all and neither has to change when the list grows.

alter table public.item
  add column featured_at timestamptz,
  add column featured_by uuid references auth.users (id) on delete set null;

comment on column public.item.featured_at is
  'When a moderator put this item at the top of the gallery, or null. Only public.set_item_featured writes it.';
comment on column public.item.featured_by is
  'The moderator who featured it. Set null rather than cascading, so closing an account does not take the item with it.';

-- Partial, and on the condition the moderation page reads: the handful of
-- featured items out of every row in the table. The gallery's own ordering does
-- not use it - that sorts a filtered page of two dozen and would not reach for
-- an index either way - and a full index on a column that is null on almost
-- every row would be paid for on every publish for nothing.
create index item_live_featured_idx on public.item (featured_at desc)
  where featured_at is not null and deleted_at is null;

-- ## Who may write it
--
-- Nobody, by grant. 20260810120000 is authoritative for this table and hands
-- `authenticated` update on four named columns, so these two are outside it
-- without anything being revoked, and item_withdraw_moderators does not widen
-- that: a policy says which rows, a grant says which columns. service_role
-- holds nothing on public.item at all, which 20260810100000 shut deliberately
-- and this does not reopen.
--
-- So the write is a definer function, the way the asset queue's three are
-- (20260814220200). The moderator's own session calls it, which is what makes
-- auth.uid() the moderator rather than null: service_role has no session behind
-- it, and an actor taken from the payload is a value the caller typed.
--
-- A withdrawn item is refused. It is invisible to the gallery's read policy, so
-- featuring one would be a button that reported success and changed nothing a
-- reader could see. Unfeaturing is allowed whatever state the item is in, so an
-- item withdrawn after it was featured can still be taken back down.
create function public.set_item_featured(item_id uuid, featured boolean)
returns boolean
language plpgsql
security definer
set search_path = ''
as $$
declare
  written uuid;
begin
  if not public.is_moderator() then
    raise exception 'featuring a gallery item needs can_moderate'
      using errcode = 'insufficient_privilege';
  end if;

  update public.item as i
  set
    featured_at = case when featured then now() end,
    featured_by = case when featured then auth.uid() end
  where i.id = set_item_featured.item_id
    and (not featured or i.deleted_at is null)
  returning i.id into written;

  return written is not null;
end;
$$;

-- Execute is granted to PUBLIC on every new function, so this revoke is the
-- access control rather than a tidy-up. A signed out visitor can never satisfy
-- is_moderator(), and there is no reason for the call to be reachable without a
-- session.
revoke execute on function public.set_item_featured(uuid, boolean) from public;
grant execute on function public.set_item_featured(uuid, boolean) to authenticated;
