"use client";

import Link from "next/link";
import { useParams, usePathname } from "next/navigation";
import { ShownTo } from "@/components/ShownTo";

/**
 * Where a withdrawn item is, for a signed in visitor who reached its public
 * address. The public page is the same for everybody, so it cannot tell its
 * author or a moderator from anybody else, and it says nothing found. Not shown
 * on `/withdrawn` itself, where it would point at the page that just said no.
 */
export function WithdrawnHint() {
  const { id } = useParams<{ id: string }>();
  const pathname = usePathname();
  if (pathname.endsWith("/withdrawn")) return null;

  return (
    <ShownTo signedIn>
      <p className="mx-auto w-full max-w-5xl px-6 pb-16 text-sm text-neutral-400">
        If you withdrew this, or you moderate the hub, it is still{" "}
        <Link
          href={`/item/${id}/withdrawn`}
          className="text-neutral-300 underline underline-offset-4 hover:text-white active:text-white"
        >
          here
        </Link>
        .
      </p>
    </ShownTo>
  );
}
