-- Count only what an account still has in the staging bucket.
--
-- The quota exists to keep one account from filling the bucket. Promotion moves
-- approved pictures to the static tier and deletes their staging copies, but
-- the rows stay, so counting every row made the quota a lifetime cap. The most
-- prolific uploader hit it with about 1 MB in the bucket and 66 MB already
-- promoted.
create or replace function public.account_asset_bytes(account uuid) returns bigint
language sql
stable
set search_path = ''
as $$
  select coalesce(sum(bytes), 0)::bigint
  from public.asset
  where uploaded_by = account
    and tier = 'bucket';
$$;
