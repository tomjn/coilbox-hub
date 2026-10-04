"use client"; // Error boundaries must be Client Components

/**
 * The fallback for an error thrown by the root layout itself, for example
 * `NavAccount` failing while it reads the visitor's session (#363).
 * `error.tsx` cannot catch this: it wraps `layout.tsx`'s children, not
 * `layout.tsx`. This file replaces the whole document when it renders, so
 * the header is lost here regardless. It defines its own `<html>` and
 * `<body>`, and imports the site's stylesheet directly because the layout
 * that would normally carry it is what failed.
 */
import Link from "next/link";
import { buttonClass } from "@/components/Button";
import { ErrorReference } from "@/components/ErrorReference";
import "./globals.css";

export default function GlobalError({
  error,
  retry,
}: {
  error: Error & { digest?: string };
  retry: () => void;
}) {
  return (
    <html lang="en" className="h-full antialiased">
      <body className="flex min-h-full flex-col items-center justify-center gap-4 px-6 py-16 text-center">
        <h1 className="text-3xl font-semibold tracking-tight">
          Something went wrong
        </h1>
        <p role="alert" className="max-w-md text-neutral-400">
          Nothing you entered was necessarily saved. Try again, or go back to
          the homepage.
        </p>
        <div className="flex flex-wrap justify-center gap-3">
          <button
            type="button"
            onClick={() => retry()}
            className="rounded-md bg-neutral-100 px-5 py-2.5 text-sm font-medium text-neutral-900 transition-colors hover:bg-white active:bg-neutral-300"
          >
            Try again
          </button>
          <Link
            href="/"
            className={buttonClass("ghost", "font-medium", "lg")}
          >
            Go home
          </Link>
        </div>
        {error.digest ? <ErrorReference digest={error.digest} /> : null}
      </body>
    </html>
  );
}
