"use client";

import type { NavState } from "@/lib/auth/navCookie";
import { useNav } from "@/lib/auth/useNav";

/**
 * Draws `children` only for the visitor `hub_nav` says may use them: the
 * account `owner` names, a moderator when `moderators` is set, or anybody
 * signed in when `signedIn` is set.
 *
 * For controls on a page the CDN serves to everybody, which therefore cannot
 * know who is looking. It decides what is drawn and nothing more. The action
 * behind every control asks the database as the session before it writes, so a
 * forged cookie can show a button but not use it. A moderator must have entered
 * their code (`granted`), as the database will insist when they press it.
 */
export function ShownTo({
  children,
  ...who
}: Audience & { children: React.ReactNode }) {
  const seen = useNav();
  return seen?.state === "signed-in" && isShownTo(seen.nav, who) ? children : null;
}

interface Audience {
  owner?: string | null;
  moderators?: boolean;
  signedIn?: boolean;
}

export function isShownTo(
  { id, moderation }: NavState,
  { owner, moderators = false, signedIn = false }: Audience,
): boolean {
  return signedIn || (owner != null && owner === id) || (moderators && moderation === "granted");
}
