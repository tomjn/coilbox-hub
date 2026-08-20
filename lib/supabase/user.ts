import { cache } from "react";
import { createClient } from "./server";

/** As much of a signed in visitor as a page needs: who, and what to call them. */
export interface SessionUser {
  id: string;
  metadata: Record<string, unknown>;
}

export function userFromClaims(claims: {
  sub: string;
  user_metadata?: Record<string, unknown>;
}): SessionUser {
  return { id: claims.sub, metadata: claims.user_metadata ?? {} };
}

/**
 * Who is signed in, once per request.
 *
 * `getClaims` verifies the token against the project's signing keys, which the
 * client caches, so on a warm function this costs no round trip. `getUser` asks
 * the auth server every time, and the layout and the item page were each asking
 * it after the proxy already had. The proxy refreshes the session before any
 * page runs, so a page only needs to read it, and `cache` shares one reading
 * between the layout and the page.
 *
 * A project still on a symmetric signing key cannot verify locally, and there
 * `getClaims` falls back to asking the auth server, which is what `getUser` did.
 * Nothing is less checked than before.
 */
export const currentUser = cache(async (): Promise<SessionUser | null> => {
  const supabase = await createClient();
  const { data } = await supabase.auth.getClaims();
  return data ? userFromClaims(data.claims) : null;
});
