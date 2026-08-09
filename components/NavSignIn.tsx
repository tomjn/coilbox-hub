"use client";

import { usePathname } from "next/navigation";
import { useState } from "react";
import { SignInIcon } from "@/components/icons";
import { signInWithDiscord } from "@/lib/supabase/discord";
import { isDevSignInEnabled } from "@/lib/supabase/loopback";

/**
 * Sign in from the header. It sends you back to the page you were reading,
 * because signing in from the nav is rarely the start of publishing something.
 */
export function NavSignIn({ className }: { className?: string }) {
  const pathname = usePathname();
  const [busy, setBusy] = useState(false);
  const dev = isDevSignInEnabled();

  async function signIn() {
    setBusy(true);
    const { error } = await signInWithDiscord(pathname);
    // A failure leaves the page as it was, so the button has to come back.
    if (error) setBusy(false);
  }

  return (
    <button
      type="button"
      onClick={signIn}
      disabled={busy}
      className={className}
    >
      <SignInIcon className="w-4" />
      <span className="sr-only sm:not-sr-only">
        {dev ? "Sign in (dev)" : "Sign in"}
      </span>
    </button>
  );
}
