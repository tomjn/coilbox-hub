-- New uploads are staged in the private Supabase bucket from #330 instead of
-- Vercel Blob (issue #332). Rows need to say which store holds their bytes,
-- because pictures already in Blob stay there until promotion drains them
-- (#335) or they are uploaded again (#336).
--
-- ## Asset rows
--
-- A third tier value, `bucket`, rather than reusing `blob`. A row on `blob`
-- names a suffixed path in a public store that the promotion job reads over
-- HTTP. A row on `bucket` names the content addressed path the hub computed,
-- in a private store that only the secret key can read. Nothing that reads a
-- `blob` row can read a `bucket` row the same way, so they must not share a
-- value.
--
-- Everything already written against `tier = 'blob'` leaves a `bucket` row
-- alone, which is what keeps the readers safe until #333, #334 and #335 give
-- them real handling:
--
-- - public.promote_assets moves only `blob` rows, so a `bucket` row is never
--   promoted or given a `blob_path`.
-- - public.record_superseded_object queues only `blob` paths, so the Blob
--   sweep is never handed a bucket path.
-- - public.reusable_staging_object answers only with `blob` paths. The upload
--   route no longer asks it, because a content addressed path in the bucket
--   already reuses identical bytes: the second put finds the object there.
--
-- The column default stays `blob`. Every writer sets the tier itself, and
-- #338 removes the value along with the default.
alter table public.asset drop constraint asset_tier_check;
alter table public.asset add constraint asset_tier_check check (tier in ('blob', 'bucket', 'static'));

-- ## Game rows
--
-- A game's logo or banner has no tier column. Pages read `logo_path` and
-- `banner_path` from the durable tier only, and the staged copy sits at the
-- same path until promotion copies it across. So what a game row needs is not
-- which tier serves the picture but where its staged copy is, if anywhere.
--
-- Null means the row records no staged copy: nothing was uploaded, or the
-- import script wrote the picture straight to the durable tier.
--
-- Rows that already name a picture are backfilled as `blob`. That is what the
-- game picture promotion pass assumed about every row until now, and it is
-- still only a maybe: a picture already promoted, or imported, is not in Blob,
-- and the pass reads that as a 404 and moves on, as it always has.
alter table public.game
  add column logo_staged_tier text check (logo_staged_tier in ('blob', 'bucket')),
  add column banner_staged_tier text check (banner_staged_tier in ('blob', 'bucket'));

comment on column public.game.logo_staged_tier is
  'Which staging store holds the copy of logo_path waiting for promotion: blob (Vercel Blob) or bucket (the staged-pictures Supabase bucket). Null when the row records no staged copy.';
comment on column public.game.banner_staged_tier is
  'Which staging store holds the copy of banner_path waiting for promotion: blob (Vercel Blob) or bucket (the staged-pictures Supabase bucket). Null when the row records no staged copy.';

update public.game
set logo_staged_tier = 'blob'
where logo_path is not null and logo_hash is not null;

update public.game
set banner_staged_tier = 'blob'
where banner_path is not null and banner_hash is not null;
