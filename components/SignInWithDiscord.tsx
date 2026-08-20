"use client";

import { useState } from "react";
import { signInWithDiscord } from "@/lib/supabase/discord";
import { isDevSignInEnabled } from "@/lib/supabase/loopback";

/**
 * Sign in is only ever needed to publish, edit or withdraw. Browsing and
 * importing stay anonymous, so this never appears in the way of reading.
 */
export function SignInWithDiscord({ next = "/publish" }: { next?: string }) {
  const [busy, setBusy] = useState(false);
  const dev = isDevSignInEnabled();

  async function signIn() {
    setBusy(true);
    const { error } = await signInWithDiscord(next);
    // A failure leaves the page as it was, so the button has to come back.
    if (error) setBusy(false);
  }

  return (
    <button
      type="button"
      onClick={signIn}
      disabled={busy}
      className="rounded-md bg-[#5865F2] px-5 py-2.5 text-sm font-medium text-white transition-colors hover:bg-[#4752c4] active:bg-[#3b45a3] disabled:opacity-60"
    >
      {dev
        ? busy
          ? "Signing you in…"
          : "Sign in (dev)"
        : busy
          ? "Taking you to Discord…"
          : "Sign in with Discord"}
    </button>
  );
}
