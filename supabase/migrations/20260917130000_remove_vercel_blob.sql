-- Remove Vercel Blob (issue #338).
--
-- Uploads have gone to the staged-pictures bucket since #332, promotion has
-- drained what it could, and the Blob store is being deleted. What is left in
-- the database is the tier value, the put ledger, the orphan queue and the
-- functions that served them.
--
-- ## The rows that were only in Blob
--
-- 47 rows were still on `tier = 'blob'` in production, every one of them
-- marked `bytes_missing_at` (#336), because the suspended store would not
-- return them. They are not deleted. Each has an `asset_event` row, and that
-- table is append only on purpose (20260814220100), so deleting an asset means
-- deleting its audit trail first.
--
-- So they move to `bucket` and keep the mark. Everything that reads a row
-- already skips a marked one: `have` answers missing so Coilbox uploads it
-- again, pages never show it, promotion never selects it, and the upload that
-- replaces it writes a real bucket path and clears the mark. The path they
-- keep names nothing in the bucket, which is what the mark says.
--
-- The check that a marked row is on `blob` becomes a check that it is on
-- `bucket`, because a durable row's bytes are in a git history and cannot go
-- missing that way.
--
-- A row on `blob` without the mark would be a picture this migration hides
-- by moving it, so it refuses instead.

do $$
begin
  if exists (select 1 from public.asset where tier = 'blob' and bytes_missing_at is null) then
    raise exception 'an asset row is still served from Vercel Blob, so it cannot be removed yet';
  end if;

  if exists (select 1 from public.asset where blob_path_tier = 'blob')
    or exists (select 1 from public.game where logo_staged_tier = 'blob' or banner_staged_tier = 'blob') then
    raise exception 'a staged copy is still queued in Vercel Blob, so it cannot be removed yet';
  end if;
end;
$$;

-- The trigger first, so moving the rows below queues nothing.
drop trigger asset_record_superseded_object on public.asset;
drop function public.record_superseded_object();

alter table public.asset drop constraint asset_bytes_missing_needs_blob;

update public.asset set tier = 'bucket' where tier = 'blob';

alter table public.asset
  add constraint asset_bytes_missing_needs_bucket
  check (bytes_missing_at is null or tier = 'bucket');

comment on column public.asset.bytes_missing_at is
  'When the hub found this row''s bytes missing, or null. Set on the rows whose only copy was in Vercel Blob when it was removed (#338), and cleared by an upload that replaces the row. A marked row is not held for the have check, not served and not promoted.';

-- Every writer in the app sets the tier itself. The default is where an upload
-- lands, so a row inserted by hand says the same.
alter table public.asset alter column tier set default 'bucket';
alter table public.asset drop constraint asset_tier_check;
alter table public.asset add constraint asset_tier_check check (tier in ('bucket', 'static'));

alter table public.asset drop constraint asset_blob_path_tier_check;
alter table public.asset add constraint asset_blob_path_tier_check check (blob_path_tier = 'bucket');

alter table public.game drop constraint game_logo_staged_tier_check;
alter table public.game add constraint game_logo_staged_tier_check check (logo_staged_tier = 'bucket');
alter table public.game drop constraint game_banner_staged_tier_check;
alter table public.game add constraint game_banner_staged_tier_check check (banner_staged_tier = 'bucket');

comment on column public.game.logo_staged_tier is
  'bucket when the staged-pictures bucket holds a copy of logo_path waiting for promotion, or null when the row records no staged copy.';
comment on column public.game.banner_staged_tier is
  'bucket when the staged-pictures bucket holds a copy of banner_path waiting for promotion, or null when the row records no staged copy.';

-- The orphan queue. The bucket is listed from Postgres by
-- public.unclaimed_staged_objects, so nothing needs queueing any more.
drop function public.record_unclaimed_object(text, integer);
drop function public.clear_asset_orphans(bigint[]);
drop function public.reusable_staging_object(text);

-- Replaces the function in 20260814250000 without the orphan half. Same
-- signature and grants, because lib/assets/meters.ts reads it.
create or replace function public.asset_storage_usage()
returns table (tier text, variant text, objects bigint, bytes bigint)
language sql
stable
set search_path = ''
as $$
  select a.tier, a.variant, count(*)::bigint, coalesce(sum(a.bytes), 0)::bigint
  from public.asset as a
  group by a.tier, a.variant;
$$;

drop table public.asset_orphan;

-- The put ledger (20260914120000, 20260914130000).
drop function public.blob_put_days();
drop function public.reserve_blob_put(text, integer);
drop function public.release_blob_put(bigint);
drop table public.blob_put;
