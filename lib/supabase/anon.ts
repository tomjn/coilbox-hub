import { createClient as createSupabaseClient, type SupabaseClient } from "@supabase/supabase-js";
import { requireSupabaseConfig } from "@/lib/supabase/config";

/**
 * The client a cached read uses.
 *
 * No cookies and no session, because a `"use cache"` function may not read the
 * request and its answer is shared by everyone who asks. Row level security
 * therefore answers as `anon`, which is what a public page should show.
 * Anything a signed in reader may see and the public may not, such as their own
 * withdrawn item, is read again outside the cache by the page itself, with the
 * session client from `server.ts`.
 *
 * The fourth client kind, beside the session (`server.ts`), a bearer token
 * (`bearer.ts`) and the secret key (`admin.ts`).
 */
export function createAnonClient(): SupabaseClient {
  const { url, publishableKey } = requireSupabaseConfig();
  return createSupabaseClient(url, publishableKey, {
    auth: { autoRefreshToken: false, persistSession: false },
  });
}
