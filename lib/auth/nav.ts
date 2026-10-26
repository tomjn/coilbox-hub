import { DEFAULT_COOKIE_OPTIONS } from "@supabase/ssr";
import type { SupabaseClient } from "@supabase/supabase-js";
import { cookies } from "next/headers";
import { toCapabilityAccess } from "@/lib/access/secondFactor";
import { fetchDisplayName } from "@/lib/author";
import { encodeNav, NAV_COOKIE, type NavState } from "./navCookie";

/**
 * The header's view of whoever `supabase` is signed in as, or null.
 *
 * Takes the client rather than making one, so a route that has just exchanged
 * a code reads the session it now holds rather than the cookies the request
 * arrived with.
 */
export async function readNav(supabase: SupabaseClient): Promise<NavState | null> {
  const { data } = await supabase.auth.getClaims();
  if (!data) return null;
  const id = data.claims.sub;
  const [name, access] = await Promise.all([
    fetchDisplayName(supabase, id),
    supabase.rpc("capability_access", { capability: "can_moderate" }),
  ]);
  return { id, name, moderation: access.error ? "none" : toCapabilityAccess(access.data) };
}

/**
 * Writes `hub_nav` for the session `supabase` holds, or clears it when there is
 * none. Only from a route handler or a server action, which can set cookies.
 *
 * With the options Supabase gives its own session cookie: readable by the
 * browser, and as long lived, so the two end together when nothing clears them
 * sooner.
 */
export async function writeNav(supabase: SupabaseClient): Promise<NavState | null> {
  const nav = await readNav(supabase);
  const store = await cookies();
  if (nav) {
    store.set(NAV_COOKIE, encodeNav(nav), DEFAULT_COOKIE_OPTIONS);
  } else {
    store.delete(NAV_COOKIE);
  }
  return nav;
}

export async function clearNav(): Promise<void> {
  (await cookies()).delete(NAV_COOKIE);
}
