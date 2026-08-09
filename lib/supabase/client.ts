import { createBrowserClient } from "@supabase/ssr";

/**
 * The browser client. Carries the publishable key, which is public by design:
 * every rule about who may read and write lives in the row level security
 * policies on the database, not in this bundle.
 */
export function createClient() {
  return createBrowserClient(
    process.env.NEXT_PUBLIC_SUPABASE_URL!,
    process.env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY!,
  );
}
