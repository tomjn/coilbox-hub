"use client";

import { type ComponentProps, useEffect, useState } from "react";

/**
 * A plain form that marks itself busy once submitted.
 *
 * The gallery search and the maps filters are GET forms so they work with
 * scripting off, and they stay that way: this adds `aria-busy` on submit and
 * lets the browser carry on with the navigation it was always going to make. A
 * submit button inside reads the flag with `group-aria-busy:` to dim itself.
 * Without a script the handler is never attached and the form is exactly what
 * it was.
 *
 * `pageshow` clears the flag when the page comes back out of the back-forward
 * cache, which restores React state as it was, busy included.
 */
export function BusyForm({ children, className, ...props }: ComponentProps<"form">) {
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    const restore = (event: PageTransitionEvent) => {
      if (event.persisted) setBusy(false);
    };
    window.addEventListener("pageshow", restore);
    return () => window.removeEventListener("pageshow", restore);
  }, []);

  return (
    <form
      {...props}
      aria-busy={busy || undefined}
      onSubmit={() => setBusy(true)}
      className={className ? `group ${className}` : "group"}
    >
      {children}
    </form>
  );
}
