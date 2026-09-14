-- The put() ledger by day, for the chart on the allowances page.
--
-- One total says how close the store is to suspension. It does not say whether
-- that came from one release on one day or a steady trickle, and it does not say
-- when the room comes back, which is when the busiest day ages out of the 30
-- day window. Both need the days.
--
-- A function because PostgREST refuses aggregates by default, the reason
-- public.asset_storage_usage is one too. Days are UTC, matching every other
-- timestamp the page prints.
--
-- Security invoker. service_role already holds select on public.blob_put, so a
-- definer would add a privilege nothing needs.
create function public.blob_put_days()
returns table (day date, kind text, puts integer)
language sql
stable
set search_path = ''
as $$
  select (p.at at time zone 'utc')::date, p.kind, count(*)::integer
  from public.blob_put as p
  where p.at > now() - interval '30 days'
  group by 1, 2
  order by 1, 2;
$$;

revoke execute on function public.blob_put_days() from public;
grant execute on function public.blob_put_days() to service_role;
