"use client";

import { useLinkStatus } from "next/link";

/**
 * The body of a link, dimmed while the navigation it started is still on its
 * way.
 *
 * Rendered as a child of `<Link>`, which is where `useLinkStatus` reads from. A
 * route with a loading file swaps in its skeleton almost at once, so this is for
 * the moment between the tap and that swap, and for the pagers, where the reader
 * stays on the same route and no skeleton is shown.
 */
export function LinkPending({
  className,
  children,
}: {
  className?: string;
  children: React.ReactNode;
}) {
  const { pending } = useLinkStatus();
  return (
    <span
      aria-busy={pending || undefined}
      className={`${className ?? ""} transition-opacity${pending ? " opacity-60" : ""}`.trim()}
    >
      {children}
    </span>
  );
}
