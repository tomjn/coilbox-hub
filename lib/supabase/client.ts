import { createBrowserClient } from "@supabase/ssr";
import { requireSupabaseConfig } from "@/lib/supabase/config";

/**
 * The browser client. Carries the publishable key, which is public by design:
 * every rule about who may read and write lives in the row level security
 * policies on the database, not in this bundle.
 */
export function createClient() {
  const { url, publishableKey } = requireSupabaseConfig();
  return createBrowserClient(url, publishableKey);
}
