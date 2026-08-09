"use client";

import { useState } from "react";
import { createClient } from "@/lib/supabase/client";

/**
 * Sign in is only ever needed to publish, edit or withdraw. Browsing and
 * importing stay anonymous, so this never appears in the way of reading.
 */
export function SignInWithDiscord({ next = "/publish" }: { next?: string }) {
  const [busy, setBusy] = useState(false);

  async function signIn() {
    setBusy(true);
    const supabase = createClient();
    const { error } = await supabase.auth.signInWithOAuth({
      provider: "discord",
      options: {
        redirectTo: `${window.location.origin}/auth/callback?next=${encodeURIComponent(next)}`,
      },
    });
    // A failure leaves the page as it was, so the button has to come back.
    if (error) setBusy(false);
  }

  return (
    <button
      type="button"
      onClick={signIn}
      disabled={busy}
      className="rounded-md bg-[#5865F2] px-5 py-2.5 text-sm font-medium text-white transition-colors hover:bg-[#4752c4] disabled:opacity-60"
    >
      {busy ? "Taking you to Discord…" : "Sign in with Discord"}
    </button>
  );
}
