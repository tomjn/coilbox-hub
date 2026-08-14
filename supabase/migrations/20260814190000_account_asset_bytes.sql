-- How much of the store one account is already using (issue #104).
--
-- The upload route has to answer this before it writes anything, because a
-- cumulative storage quota checked after the fact is not a quota. It cannot ask
-- Vercel Blob, since list() is an advanced operation out of 2,000 a month, and
-- public.asset already knows: every object in the store has a row and every row
-- carries its byte count.
--
-- A function rather than a query in the route because PostgREST refuses
-- aggregates by default, and the alternative is fetching one row per asset and
-- adding them up in JavaScript. That reads at most a thousand rows before
-- PostgREST truncates the page, so an account over the quota would come back
-- under it, which is the one wrong answer this must not give.
--
-- Security invoker, deliberately. service_role already holds select on
-- public.asset and carries bypassrls, so a definer function would add a
-- privilege nothing needs and would answer for rows the caller cannot see.
--
-- #107 owns the number this is compared against, and may replace the live sum
-- with a running total it maintains. The signature is what the route depends
-- on, so that swap is this file and nothing else.
create function public.account_asset_bytes(account uuid) returns bigint
language sql
stable
set search_path = ''
as $$
  select coalesce(sum(bytes), 0)::bigint
  from public.asset
  where uploaded_by = account;
$$;

-- Execute is granted to PUBLIC on every new function, so the revoke is the
-- whole of the access control here and not a tidy-up. Without it anyone holding
-- the publishable key could total another account's uploads one call at a time.
revoke execute on function public.account_asset_bytes(uuid) from public;
grant execute on function public.account_asset_bytes(uuid) to service_role;
