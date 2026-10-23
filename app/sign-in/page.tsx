import type { Metadata } from "next";
import { redirect } from "next/navigation";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { games } from "@/components/art/drawings";
import { SignInButtons } from "@/components/SignInButtons";
import { signInNext } from "@/lib/supabase/signInNext";
import { currentUser } from "@/lib/supabase/user";

export const metadata: Metadata = {
  title: "Sign in",
  robots: { index: false },
};

const BACKDROP_STRENGTH = 0.09;

/**
 * Where the header's sign in button lands, since one button in the header
 * cannot say which provider. `app/auth/signin/route.ts` sends it here with the
 * page it was posted from as `next`.
 */
export default async function SignIn({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const raw = (await searchParams).next;
  const next = signInNext(typeof raw === "string" ? raw : null, null, "");

  if (await currentUser()) redirect(next);

  return (
    <main id="main-content" tabIndex={-1} className="relative flex-1">
      <ArtBackdrop drawing={games} strength={BACKDROP_STRENGTH} />
      <div className="relative z-10 mx-auto flex w-full max-w-md flex-col gap-6 px-6 py-12">
        <div className="flex flex-col gap-2">
          <h1 className="text-3xl font-semibold tracking-tight">Sign in</h1>
          <p className="text-neutral-400">
            Signing in is only needed to publish, so your name is on it and
            you can change or withdraw it later.
          </p>
        </div>
        <SignInButtons next={next} />
      </div>
    </main>
  );
}
