import { cache } from "react";
import { fetchDisplayName } from "@/lib/author";
import { createClient } from "./server";

/** As much of a signed in visitor as a page needs to know who they are. What
 *  to call them is `currentDisplayName`, which reads the hub's own table. */
export interface SessionUser {
  id: string;
}

export function userFromClaims(claims: { sub: string }): SessionUser {
  return { id: claims.sub };
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

/**
 * What to call the signed in visitor, once per request.
 *
 * Read from public.profile rather than the token's metadata, so a rename shows
 * on the next page rather than at the next sign in. Null when nobody is signed
 * in or the read failed.
 */
export const currentDisplayName = cache(async (): Promise<string | null> => {
  const user = await currentUser();
  if (!user) return null;
  return fetchDisplayName(await createClient(), user.id);
});

/**
 * Whether the visitor is a moderator, once per request.
 *
 * For deciding what a page draws. It is not a gate: a server action or route
 * handler that writes asks `is_moderator` itself, as the session, because the
 * answer here is shared by everything rendered in the request.
 *
 * It reads the visitor's cookies, so it cannot run inside a `"use cache"`
 * function, and `cache` holds the answer for one request only. A signed out
 * visitor is not asked about, and an error from the database is a no.
 */
export const isModerator = cache(async (): Promise<boolean> => {
  if (!(await currentUser())) return false;
  const supabase = await createClient();
  const { data } = await supabase.rpc("is_moderator");
  return data === true;
});
