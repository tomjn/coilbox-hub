import type { Metadata } from "next";
import Link from "next/link";
import { buttonClass } from "@/components/Button";

export const metadata: Metadata = {
  title: "Page not found",
};

/**
 * Shown for any URL that matches no route, and when a page calls
 * `notFound()`. Rendered inside `layout.tsx`, so the header stays on screen.
 */
export default function NotFound() {
  return (
    <main className="mx-auto flex w-full max-w-5xl flex-1 flex-col items-start gap-4 px-6 py-16">
      <h1 className="text-3xl font-semibold tracking-tight">Page not found</h1>
      <p className="max-w-md text-neutral-400">
        This page does not exist, or it has been removed.
      </p>
      <div className="flex flex-wrap gap-3">
        <Link
          href="/"
          className={buttonClass("primary", "font-medium", "lg")}
        >
          Go home
        </Link>
        <Link
          href="/gallery"
          className={buttonClass("ghost", "font-medium", "lg")}
        >
          Browse the gallery
        </Link>
      </div>
    </main>
  );
}
