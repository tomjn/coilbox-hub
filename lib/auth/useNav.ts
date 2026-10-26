"use client";

import { usePathname } from "next/navigation";
import { useEffect, useMemo, useSyncExternalStore } from "react";
import { navFromCookies } from "./navCookie";

/** Asks every `useNav` to read the cookies again. */
const RECHECK = "hub-nav-recheck";

function recheck() {
  window.dispatchEvent(new Event(RECHECK));
}

/**
 * When to read the cookies again. A server action that changes `hub_nav`, such
 * as entering a code or renaming the account, sets it without a page load, and
 * the header sits in the layout, which a navigation does not redraw. So: after
 * each navigation (`NavRecheck`), when the cookie store says something changed,
 * where the browser has one, and when the tab comes back into focus.
 */
function subscribe(onChange: () => void) {
  const store = (window as { cookieStore?: EventTarget }).cookieStore;
  window.addEventListener(RECHECK, onChange);
  window.addEventListener("focus", onChange);
  store?.addEventListener("change", onChange);
  return () => {
    window.removeEventListener(RECHECK, onChange);
    window.removeEventListener("focus", onChange);
    store?.removeEventListener("change", onChange);
  };
}

const readCookies = () => document.cookie;
// Nothing is known while the page is built or hydrated, so both draw the same
// thing and `SESSION_MARK_SCRIPT` decides what is visible.
const readNothing = () => null;

let repairing: Promise<void> | null = null;

/** Once per page load at most: a session with no `hub_nav` asks for one. */
function repair() {
  repairing ??= fetch("/auth/nav", { method: "POST" }).then(recheck, () => {});
}

/**
 * Who the cookies say is signed in, or null until the page has hydrated.
 */
export function useNav() {
  const cookies = useSyncExternalStore(subscribe, readCookies, readNothing);
  const seen = useMemo(() => (cookies === null ? null : navFromCookies(cookies)), [cookies]);
  const state = seen?.state;
  useEffect(() => {
    if (state === "missing") repair();
  }, [state]);
  return seen;
}

/**
 * Reads the cookies again after every navigation. Its own component because
 * reading the route waits behind a boundary, and the header should not.
 */
export function NavRecheck() {
  const pathname = usePathname();
  useEffect(recheck, [pathname]);
  return null;
}
