-- A private Supabase Storage bucket for staged pictures (issue #330).
--
-- Pictures wait here between upload and moderator approval, replacing Vercel
-- Blob as the staging tier. Blob was public and a random suffix on each path
-- was the only thing keeping an unreviewed upload out of sight (#131). A
-- private bucket removes the need for that: `public = false` and no storage
-- policy on `storage.objects` for `anon` or `authenticated` below means
-- neither role can list, read or write here at all, whatever path it names.
-- Only a client holding the secret key, which bypasses RLS, reads or writes
-- an object. #331 is where that client goes.
--
-- `allowed_mime_types` is `ASSET_MIME_EXTENSIONS` from `lib/assets/path.ts`,
-- the whole of what the hub can name a stored file after. Game logos and
-- banners go in the same bucket and are also either of these two: their write
-- path measures a PNG or WebP header and refuses anything else
-- (`readImageHeader`, used by both `app/games/actions.ts` and
-- `lib/assets/caps.ts`).
--
-- `file_size_limit` is the larger of the two ceilings a staged picture can
-- arrive under:
--
-- - `ASSET_MAX_OBJECT_BYTES` in `lib/assets/upload.ts`, 2097152 bytes. It is
--   the backstop for a class with no `maxEdge` of its own, and every class in
--   `lib/assets/caps.ts` that does have one derives a `maxBytes` under it
--   (the largest, `minimap` and `overlay:height`, is 1048576).
-- - `MAX_IMAGE_BYTES` in `app/games/actions.ts`, 524288 bytes, for a game
--   logo or banner.
--
-- 2097152 is the larger of the two, so it is the bucket's limit. Both checks
-- still run in application code before a byte reaches the bucket; this is a
-- second, database enforced ceiling behind them.
insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values (
  'staged-pictures',
  'staged-pictures',
  false,
  2097152,
  array['image/webp', 'image/png']
);
