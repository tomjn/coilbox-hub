import { createClient } from "./client";
import { isDevSignInEnabled } from "./loopback";

/**
 * Starts sign in from the browser. Both places that offer sign in go through
 * here so the callback URL is written once.
 *
 * Discord cannot complete its OAuth callback against a local Supabase stack
 * (see issue #34), so in development this sends the browser to
 * app/dev/sign-in/route.ts instead, which the same isDevSignInEnabled() guard
 * decides whether to actually serve.
 */
export function signInWithDiscord(next: string) {
  if (isDevSignInEnabled()) {
    // A full navigation, not router.push: /dev/sign-in is a route handler that
    // sets a session cookie and redirects, and the client router does not run
    // one.
    // eslint-disable-next-line @next/next/no-location-assign-relative-destination
    window.location.href = `/dev/sign-in?next=${encodeURIComponent(next)}`;
    return Promise.resolve({ error: null });
  }

  const supabase = createClient();
  return supabase.auth.signInWithOAuth({
    provider: "discord",
    options: {
      redirectTo: `${window.location.origin}/auth/callback?next=${encodeURIComponent(next)}`,
    },
  });
}
