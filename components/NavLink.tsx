"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

/**
 * A header link that knows when the visitor is in its section.
 *
 * The section is the link's path and everything under it, so a game's unit
 * page still lights Games. `aria-current` says the same thing to a screen
 * reader, as `ModerationNav` does for its bar.
 *
 * The one part of the header that reads the route. Next suspends a route read
 * while it builds the shell for any page with dynamic params, so the layout
 * wraps this in `<Suspense>` with the plain link as the fallback, and the rest
 * of the header stays in the held shell.
 */
export function NavLink({
  href,
  className,
  currentClassName,
  children,
}: {
  href: string;
  className: string;
  currentClassName: string;
  children: React.ReactNode;
}) {
  const pathname = usePathname();
  const current = pathname === href || pathname.startsWith(`${href}/`);
  return (
    <Link
      href={href}
      aria-current={current ? "page" : undefined}
      className={current ? `${className} ${currentClassName}` : className}
    >
      {children}
    </Link>
  );
}
