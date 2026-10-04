"use client"; // Error boundaries must be Client Components

import Link from "next/link";
import { Button, buttonClass } from "@/components/Button";
import { ErrorReference } from "@/components/ErrorReference";
import { useEffect } from "react";

/**
 * The fallback for any error thrown while rendering a page, or by a server
 * action a page's form submitted (#363). It wraps every route below this one
 * but not `layout.tsx` itself, so the header and navigation stay on screen
 * instead of being replaced by Next's own "This page couldn't load" screen.
 *
 * A layout failure (for example `NavAccount` throwing) is not caught here.
 * That is what `global-error.tsx` is for.
 */
export default function ErrorPage({
  error,
  retry,
}: {
  error: Error & { digest?: string };
  retry: () => void;
}) {
  useEffect(() => {
    // Next only forwards a generic message here for a Server Component
    // error. `digest` is what ties this back to the server-side log line.
    console.error(error);
  }, [error]);

  return (
    <main className="mx-auto flex w-full max-w-5xl flex-1 flex-col items-start gap-4 px-6 py-16">
      <h1 className="text-3xl font-semibold tracking-tight">
        Something went wrong
      </h1>
      <p role="alert" className="max-w-md text-neutral-400">
        Nothing you entered was necessarily saved. Try again, or go back to
        the homepage.
      </p>
      <div className="flex flex-wrap gap-3">
        <Button
          variant="primary"
          size="lg"
          onClick={() => retry()}
        >
          Try again
        </Button>
        <Link
          href="/"
          className={buttonClass("ghost", { className: "font-medium", size: "lg" })}
        >
          Go home
        </Link>
      </div>
      {error.digest ? <ErrorReference digest={error.digest} /> : null}
    </main>
  );
}
