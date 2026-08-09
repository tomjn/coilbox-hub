import type { Metadata } from "next";
import { SignInWithDiscord } from "@/components/SignInWithDiscord";
import { createClient } from "@/lib/supabase/server";
import { PublishForm } from "./PublishForm";

export const metadata: Metadata = {
  title: "Publish - Coilbox Hub",
  description: "Share a preset, challenge, setup pack or scenario you have made.",
};

export default async function Publish() {
  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();

  return (
    <main className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-8 px-6 py-16">
      <div className="flex flex-col gap-2">
        <h1 className="text-3xl font-semibold tracking-tight">Publish</h1>
        <p className="text-neutral-400">
          Share something you have made in Coilbox. Anyone can browse and import
          it without an account.
        </p>
      </div>

      {user ? (
        <>
          <div className="flex items-center justify-between border-b border-neutral-900 pb-4 text-sm text-neutral-500">
            <span>
              Publishing as{" "}
              <span className="text-neutral-300">
                {(user.user_metadata?.full_name as string) ??
                  (user.user_metadata?.name as string) ??
                  "you"}
              </span>
            </span>
            <form action="/auth/signout" method="post">
              <button
                type="submit"
                className="transition-colors hover:text-neutral-300"
              >
                Sign out
              </button>
            </form>
          </div>
          <PublishForm />
        </>
      ) : (
        <div className="flex flex-col items-start gap-4 rounded-md border border-neutral-800 bg-neutral-950 p-6">
          <p className="text-sm text-neutral-400">
            Signing in is only needed to publish, so your name is on it and you
            can change or withdraw it later.
          </p>
          <SignInWithDiscord />
        </div>
      )}
    </main>
  );
}
