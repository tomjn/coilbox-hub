import { createClient as createSupabaseClient, type SupabaseClient } from "@supabase/supabase-js";
import { requireSupabaseConfig, requireSupabaseServiceRoleKey } from "@/lib/supabase/config";

/**
 * A client that runs as `service_role`, for the routes that have to see rows
 * the read policy hides. The third client kind alongside `server.ts` (the
 * session cookie) and `bearer.ts` (an access token), and the one with no user
 * behind it at all.
 *
 * The asset pipeline needs it because `asset_read_approved` in
 * `20260814180000_asset_access.sql` shows `anon` and `authenticated` only rows
 * where `moderation = 'approved'`. A route that asked the question through
 * either of those would read a pending upload as absent, and the caller would
 * upload it again against an allowance of 2,000 a month.
 *
 * `service_role` carries `bypassrls`, so every call made through this is
 * unfiltered and the route is the only thing deciding what a caller may learn.
 * Reach for `bearer.ts` first, and use this only where the answer genuinely
 * depends on rows the policy hides.
 *
 * `persistSession: false` because a route handler starts fresh on every
 * request, so there is nothing to persist to.
 */
export function createAdminClient(): SupabaseClient {
  const { url } = requireSupabaseConfig();
  return createSupabaseClient(url, requireSupabaseServiceRoleKey(), {
    auth: { autoRefreshToken: false, persistSession: false },
  });
}
