-- Lets the hub's secret key read the archive tables, so `php artisan
-- mirrors:copy` can copy them into MariaDB (#600).
--
-- 20261117120000_hub_mirrors.sql and 20261117130000_hub_mirror_moderation.sql
-- revoked every privilege on these tables, so only their functions could
-- reach them. The service role already reads mirror and catalogue_state, and
-- bypasses row level security. No other role gains anything, and nothing can
-- write through these grants.

grant select on public.archive, public.archive_mirror, public.archive_mismatch to service_role;
