-- Lets the hub's secret key read published items, reports, profiles and
-- capabilities, so `php artisan items:copy` can copy them into MariaDB (#601).
--
-- 20260810100000_revoke_item_service_role_grants.sql took the service role's
-- privileges on item, and report, profile and user_capability never had any.
-- The service role bypasses row level security, so it reads every row. No
-- other role gains anything, and nothing can write through these grants.

grant select on public.item, public.report, public.profile, public.user_capability to service_role;
