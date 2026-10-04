import type { Metadata } from "next";
import Link from "next/link";
import { Suspense } from "react";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { setupPacks } from "@/components/art/drawings";
import { Skeleton } from "@/components/Skeleton";
import { SignInWithDiscord } from "@/components/SignInWithDiscord";
import { kindsSingular } from "@/lib/gallery/label";
import { currentUser } from "@/lib/supabase/user";
import { PublishForm } from "./PublishForm";

export const metadata: Metadata = {
  title: "Publish",
  description: `Share a ${kindsSingular()} you have made.`,
};

// The form is dense with fields, and this drawing's outer hexagon fills the
// whole canvas rather than leaving the margins `hub`'s does, so it sits well
// back from even the gallery's strength: at 0.07 its lit diamond read as a
// shape competing with the title field rather than atmosphere behind it.
const BACKDROP_STRENGTH = 0.045;

/**
 * The part that depends on who is looking: the form for somebody signed in, the
 * sign in panel for anybody else. The heading and introduction above it are the
 * same for everybody, so the page draws them without waiting for this.
 */
async function PublishPanel() {
  // Only decides which of the two panels is drawn. Publishing itself asks the
  // auth server who is signed in before it writes.
  const user = await currentUser();

  if (!user) {
    return (
      <div className="flex flex-col items-start gap-4 rounded-md border border-neutral-800 bg-card p-6">
        <p className="text-sm text-neutral-400">
          Signing in is only needed to publish, so your name is on it and
          you can change or withdraw it later.
        </p>
        <SignInWithDiscord />
      </div>
    );
  }

  return (
    <>
      <div className="flex items-center justify-between border-b border-neutral-900 pb-4 text-sm text-neutral-400">
        <span>
          Publishing as{" "}
          <span className="text-neutral-300">
            {(user.metadata.full_name as string) ??
              (user.metadata.name as string) ??
              "you"}
          </span>
        </span>
        <Link
          href="/account"
          className="mr-4 transition-colors hover:text-neutral-300 active:text-neutral-300"
        >
          Your account
        </Link>
        <form action="/auth/signout" method="post">
          <button
            type="submit"
            className="transition-colors hover:text-neutral-300 active:text-neutral-300"
          >
            Sign out
          </button>
        </form>
      </div>
      <PublishForm />
    </>
  );
}

export default function Publish() {
  return (
    <main id="main-content" tabIndex={-1} className="relative flex-1">
      <ArtBackdrop drawing={setupPacks} strength={BACKDROP_STRENGTH} />
      <div className="relative z-10 mx-auto flex w-full max-w-2xl flex-col gap-8 px-6 py-16">
        <div className="flex flex-col gap-2">
          <h1 className="text-3xl font-semibold tracking-tight">Publish</h1>
          <p className="text-neutral-400">
            Share something you have made in Coilbox. Anyone can browse and
            import it without an account.
          </p>
        </div>

        <Suspense fallback={<Skeleton className="h-64" />}>
          <PublishPanel />
        </Suspense>
      </div>
    </main>
  );
}
