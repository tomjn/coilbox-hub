-- A way to report a map as broken (issue #294). The report table that has held
-- gallery item reports since 20260809183738 grows a second target, so a map
-- report lands in the same queue, with the same handled and handled_by columns
-- and the same policies.
--
-- No existing row changes: every one has an item_id, a null map_id and a null
-- kind, which is what the checks below require of an item report.

alter table public.report alter column item_id drop not null;

alter table public.report
  add column map_id uuid references public.map (id) on delete cascade,
  add column kind text;

-- A short fixed list, like asset.rejection_kind, so a moderator reading
-- several reports of one map can see whether they say the same thing. Free
-- text stays in `reason` beside it.
alter table public.report add constraint report_kind_check
  check (kind in ('crashes_engine', 'files_missing', 'download_wrong', 'wrong_map', 'other'));

-- A report names exactly one thing, and a map report always says what kind of
-- problem it is. An item report has no kind.
alter table public.report add constraint report_one_target_check
  check ((item_id is null) <> (map_id is null));

alter table public.report add constraint report_kind_matches_target_check
  check ((kind is not null) = (map_id is not null));

create index report_map_idx on public.report (map_id) where map_id is not null;

-- The open-report cap from 20260810110000, now per target. It still drops the
-- duplicate silently instead of raising, for the same reason: an error would
-- tell an anonymous caller that this map already has an open report.
create or replace function public.dedupe_open_report() returns trigger
language plpgsql
security definer
set search_path = ''
as $$
begin
  if exists (
    select 1 from public.report
    where handled_at is null
      and (
        (new.item_id is not null and item_id = new.item_id)
        or (new.map_id is not null and map_id = new.map_id)
      )
  ) then
    return null;
  end if;

  return new;
end;
$$;
