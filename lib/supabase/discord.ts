import { createClient } from "./client";

/**
 * Starts the Discord OAuth flow from the browser. Both places that offer sign in
 * go through here so the callback URL is written once.
 */
export function signInWithDiscord(next: string) {
  const supabase = createClient();
  return supabase.auth.signInWithOAuth({
    provider: "discord",
    options: {
      redirectTo: `${window.location.origin}/auth/callback?next=${encodeURIComponent(next)}`,
    },
  });
}
