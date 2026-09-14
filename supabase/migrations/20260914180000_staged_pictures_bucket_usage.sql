-- How full the staged-pictures bucket is (issue #337).
--
-- The allowances page measures Vercel Blob in detail and knows nothing about
-- Supabase Storage, so once uploads moved into the private `staged-pictures`
-- bucket (#332) the page kept reading "empty" while the bucket grew. This is
-- the count it was missing.
--
-- `storage.get_size_by_bucket()` already sums `storage.objects.metadata ->>
-- 'size'` per bucket, proven against the local stack, so this wraps it rather
-- than repeating the sum by hand. Every object counts, whatever its
-- moderation state: a rejected picture (#348) is still bytes sitting in the
-- allowance until somebody decides what happens to it.
--
-- Security definer for the same reason public.unclaimed_staged_objects is
-- (20260914170000): it reads storage.objects, and the grants on that schema
-- are Supabase's to change rather than these migrations'.
create function public.staged_pictures_bucket_bytes() returns bigint
language sql
stable
security definer
set search_path = ''
as $$
  select coalesce(sum(b.size), 0)::bigint
  from storage.get_size_by_bucket() as b
  where b.bucket_id = 'staged-pictures';
$$;

-- Execute is granted to PUBLIC on every new function, so this revoke is the
-- access control. Only the ops page and the daily sweep read it, both on the
-- secret key, the same as every other meter in lib/assets/meters.ts.
revoke execute on function public.staged_pictures_bucket_bytes() from public;
grant execute on function public.staged_pictures_bucket_bytes() to service_role;
