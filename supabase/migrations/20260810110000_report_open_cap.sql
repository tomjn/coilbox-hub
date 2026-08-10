-- Reports have no rate limit, and reporter_id is null for exactly the people
-- this needs to bound: an anonymous caller with no account. A per-IP cap is
-- not available to a trigger, and a cap over time still lets the queue grow
-- without limit as long as the caller is patient.
--
-- The shape that actually bounds it: at most one open report per item. The
-- queue can then never hold more rows than there are items, and publishing
-- is already capped at 20 an hour, so the flood is bounded by something
-- already controlled. A duplicate report on an item that is already flagged
-- adds nothing a moderator will read.
--
-- "Open" means handled_at is null. Once a report is handled, the item can be
-- reported again: this is not one shot per item forever, it is one open
-- report per item at a time.
--
-- The awkward part is what an anonymous caller is told when their report is
-- the duplicate. Rejecting the insert with an error would reveal that this
-- item already has an open report, which is exactly the fact
-- report_read_moderators exists to hide from everyone but a moderator.
-- Returning null from a before-insert trigger skips the insert without
-- raising, so PostgREST answers the same way whether the report was stored
-- or silently dropped: success either way, and nothing to tell them apart
-- from outside.
create function public.dedupe_open_report() returns trigger
language plpgsql
security definer
set search_path = ''
as $$
begin
  if exists (
    select 1 from public.report
    where item_id = new.item_id
      and handled_at is null
  ) then
    return null;
  end if;

  return new;
end;
$$;

create trigger report_dedupe_open
  before insert on public.report
  for each row execute function public.dedupe_open_report();
