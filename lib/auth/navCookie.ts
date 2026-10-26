import type { CapabilityAccess } from "@/lib/access/secondFactor";

/**
 * What the header needs to know about a signed in visitor, kept in a cookie the
 * browser can read.
 *
 * Public pages are served from the CDN without running a function, so nothing
 * on the server can say who is looking. The header reads this instead. It is
 * written by the requests that change it, which run a function anyway: signing
 * in, entering or changing a second factor, and renaming the account. It is for
 * drawing the header and nothing else. Every write still asks the database as
 * the session, so a stale or forged cookie can only mislabel a button.
 */
export const NAV_COOKIE = "hub_nav";

export interface NavState {
  /** The account's id, so a page can tell its owner's controls from a stranger's. */
  id: string;
  /** What to call them. Null when the profile could not be read. */
  name: string | null;
  /** Their access to moderation, which decides whether the header links to it. */
  moderation: CapabilityAccess;
}

const ACCESS: readonly CapabilityAccess[] = ["granted", "verify", "enrol", "none"];

export function encodeNav(state: NavState): string {
  return encodeURIComponent(JSON.stringify(state));
}

/** Null for a missing cookie or one that does not hold a `NavState`. */
export function parseNav(raw: string | undefined | null): NavState | null {
  if (!raw) return null;
  let value: unknown;
  try {
    value = JSON.parse(decodeURIComponent(raw));
  } catch {
    return null;
  }
  if (typeof value !== "object" || value === null) return null;
  const { id, name, moderation } = value as Record<string, unknown>;
  if (typeof id !== "string" || id === "") return null;
  if (name !== null && typeof name !== "string") return null;
  const access = ACCESS.find((answer) => answer === moderation);
  if (!access) return null;
  return { id, name, moderation: access };
}

/** Whether the cookies hold a Supabase session, as `sb-<ref>-auth-token` or
 *  split into `.0`, `.1` chunks when it is too long for one cookie. */
export function hasAuthCookie(cookies: { name: string }[]): boolean {
  return cookies.some(({ name }) => /^sb-.+-auth-token(\.\d+)?$/.test(name));
}

/** The cookies in `document.cookie`, by name. */
export function parseCookieHeader(header: string): Map<string, string> {
  const found = new Map<string, string>();
  for (const part of header.split(";")) {
    const at = part.indexOf("=");
    if (at === -1) continue;
    found.set(part.slice(0, at).trim(), part.slice(at + 1).trim());
  }
  return found;
}

/**
 * Who the browser's cookies say is signed in.
 *
 * Signed in needs both cookies. Supabase clears its own when a session ends or
 * cannot be refreshed, so a `hub_nav` left behind by a session that has gone
 * does not keep anybody signed in. `missing` is a session with no `hub_nav`,
 * such as one from before the cookie existed, which `/auth/nav` repairs.
 */
export function navFromCookies(
  header: string,
): { state: "signed-out" } | { state: "missing" } | { state: "signed-in"; nav: NavState } {
  const cookies = parseCookieHeader(header);
  if (!hasAuthCookie([...cookies.keys()].map((name) => ({ name })))) return { state: "signed-out" };
  const nav = parseNav(cookies.get(NAV_COOKIE));
  return nav ? { state: "signed-in", nav } : { state: "missing" };
}
