-- Pictures stuck in the suspended Vercel Blob store (issue #336).
--
-- In September 2026 Vercel suspended the Blob store for 30 days, and it answers
-- 403 for every object. The hub still has rows for the pictures in it, so
-- `/api/v1/assets/have` tells Coilbox the hub holds them and Coilbox never
-- offers them again, while the site links to URLs that serve nothing.
--
-- `bytes_missing_at` marks a row whose bytes the store would not return when
-- `scripts/find-unreadable-blob-assets.ts` asked. The row stays, because its
-- moderation history and `asset_event` trail hang off it. While it is marked:
--
-- - `have` answers missing for it, so Coilbox uploads it again
-- - the upload replaces it even with the same `source_hash`, lands in the
--   bucket and clears the mark
-- - pages never read it, so they show the buildpic or the placeholder
-- - promotion never selects it, so one dead row cannot fill a batch
--
-- The tier and path are left alone. If the store answers again, the script
-- clears the mark and promotion drains the row like any other. If a re-upload
-- replaces it, the Blob path is queued in `asset_orphan` the way every
-- superseded Blob path is.
--
-- Only a Blob row may be marked. An upload that moved a row to the bucket and
-- forgot the mark would hide a picture that is there, so the table refuses it.

alter table public.asset add column bytes_missing_at timestamptz;

alter table public.asset add constraint asset_bytes_missing_needs_blob
  check (bytes_missing_at is null or tier = 'blob');

comment on column public.asset.bytes_missing_at is
  'When the Blob store last refused to return this row''s bytes, or null when nobody has found them missing. Set and cleared by scripts/find-unreadable-blob-assets.ts, and cleared by an upload that replaces the row. A marked row is not held for the have check, not served and not promoted.';

-- Replaces the function in 20260914170000. The only change is that a marked row
-- does not move: its bytes could not be read, so there is nothing a durable
-- path could hold, and without this a row marked mid run would trip the check
-- constraint above and roll back the whole batch.
create or replace function public.promote_assets(ids uuid[], paths text[])
returns table (id uuid, blob_path text, blob_path_tier text)
language plpgsql
set search_path = ''
as $$
begin
  if coalesce(array_length(promote_assets.ids, 1), 0)
    <> coalesce(array_length(promote_assets.paths, 1), 0) then
    raise exception 'promote_assets wants one path per id'
      using errcode = 'invalid_parameter_value';
  end if;

  return query
  update public.asset as a
  set path = wanted.path,
      tier = 'static',
      promoted_at = now(),
      blob_path = a.path,
      blob_path_tier = a.tier
  from unnest(promote_assets.ids, promote_assets.paths) as wanted(id, path)
  where a.id = wanted.id
    and a.tier <> 'static'
    and a.moderation = 'approved'
    and a.blob_path is null
    and a.bytes_missing_at is null
  returning a.id, a.blob_path, a.blob_path_tier;
end;
$$;

revoke execute on function public.promote_assets(uuid[], text[]) from public;
grant execute on function public.promote_assets(uuid[], text[]) to service_role;
