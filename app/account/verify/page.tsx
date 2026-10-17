import type { Metadata } from "next";
import { redirect } from "next/navigation";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { games } from "@/components/art/drawings";
import { createClient } from "@/lib/supabase/server";
import { signInNext } from "@/lib/supabase/signInNext";
import { VerifyForm } from "./VerifyForm";

export const metadata: Metadata = {
  title: "Enter your code",
};

const BACKDROP_STRENGTH = 0.09;

/**
 * The step up (issue #145). A moderation page sends somebody here when their
 * account has a second factor and this session has not entered a code yet,
 * which is the only way the database will let them use what they hold.
 */
export default async function Verify({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const raw = (await searchParams).next;
  const next = signInNext(typeof raw === "string" ? raw : null, null, "");

  const supabase = await createClient();
  const { data: session } = await supabase.auth.getClaims();
  if (!session) redirect("/publish");
  if (session.claims.aal === "aal2") redirect(next);

  const { data: factors } = await supabase.auth.mfa.listFactors();
  const verified = (factors?.totp ?? []).map((factor) => ({
    id: factor.id,
    createdAt: factor.created_at,
  }));
  // Nothing to verify against, so nothing here is asking for a code.
  if (verified.length === 0) redirect("/account");

  return (
    <main id="main-content" tabIndex={-1} className="relative flex-1">
      <ArtBackdrop drawing={games} strength={BACKDROP_STRENGTH} />
      <div className="relative z-10 mx-auto flex w-full max-w-md flex-col gap-6 px-6 py-12">
        <div className="flex flex-col gap-2">
          <h1 className="text-3xl font-semibold tracking-tight">Enter your code</h1>
          <p className="text-neutral-400">
            Your account has an authenticator, so moderation and the other
            things you have been trusted with need a code from it as well as
            your sign in. You only need to do this once per sign in.
          </p>
        </div>
        <VerifyForm factors={verified} next={next} />
      </div>
    </main>
  );
}
