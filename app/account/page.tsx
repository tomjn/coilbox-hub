import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { games } from "@/components/art/drawings";
import { USER_CAPABILITIES } from "@/lib/access/capability";
import { capabilityAccess, stepUpPath } from "@/lib/access/secondFactor";
import {
  linkableProviders,
  linkFailureMessage,
  signInMethods,
} from "@/lib/account/signInMethods";
import { contributionsCached } from "@/lib/profile/cached";
import { createClient } from "@/lib/supabase/server";
import { signInProvider } from "@/lib/supabase/signInProvider";
import { currentDisplayName, currentUser } from "@/lib/supabase/user";
import { DeleteAccountForm } from "./DeleteAccountForm";
import { RenameAccountForm } from "./RenameAccountForm";
import { SecondFactor } from "./SecondFactor";
import { SignInMethods } from "./SignInMethods";

export const metadata: Metadata = {
  title: "Your account",
};

// Content is sparse here, closer to the landing page than to the gallery.
const BACKDROP_STRENGTH = 0.09;

export default async function Account({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const user = await currentUser();
  if (!user) redirect("/publish");

  // Counted as the session, so row level security decides which rows it sees.
  const supabase = await createClient();
  const { count } = await supabase
    .from("item")
    .select("id", { count: "exact", head: true })
    .eq("author_id", user.id);

  const published = count ?? 0;
  const contributions = await contributionsCached(user.id);
  // The delete form asks for the name, so a page without it cannot be drawn.
  const name = await currentDisplayName();
  if (!name) throw new Error("Could not read your display name. Reload the page to try again.");

  // Second factors (issue #145). Whether this session has entered a code, the
  // authenticators the account has, and what it holds, so the page can say
  // why an authenticator matters to this account in particular.
  const [{ data: session }, { data: factors }, access, { data: linked }] = await Promise.all([
    supabase.auth.getClaims(),
    supabase.auth.mfa.listFactors(),
    Promise.all(USER_CAPABILITIES.map((capability) => capabilityAccess(capability))),
    supabase.auth.getUserIdentities(),
  ]);
  const verified = session?.claims.aal === "aal2";
  const authenticators = (factors?.totp ?? []).map((factor) => ({
    id: factor.id,
    createdAt: factor.created_at,
  }));
  const holdsCapability = access.some((answer) => answer !== "none");
  // Only the capabilities need the code, so for anybody else an authenticator
  // would protect nothing and the page does not offer one.
  const showAuthenticators = holdsCapability || authenticators.length > 0;

  // Ways to sign in (issue #435). A link the provider or Supabase refused
  // comes back here from the callback, named on the query string.
  const identities = linked?.identities ?? [];
  if (identities.length === 0) {
    throw new Error("Could not read how you sign in. Reload the page to try again.");
  }
  const query = await searchParams;
  const failedProvider = signInProvider(
    typeof query.link_failed === "string" ? query.link_failed : null,
  );
  const linkFailure = failedProvider
    ? linkFailureMessage(
        failedProvider,
        typeof query.link_reason === "string" ? query.link_reason : null,
      )
    : null;

  return (
    <main id="main-content" tabIndex={-1} className="relative flex-1">
      <ArtBackdrop drawing={games} strength={BACKDROP_STRENGTH} />
      <div className="relative z-10 mx-auto flex w-full max-w-2xl flex-col gap-8 px-6 py-12">
        <div className="flex flex-col gap-2">
          <h1 className="text-3xl font-semibold tracking-tight">
            Your account
          </h1>
          <p className="text-neutral-400">
            Signed in as {name}, with {published}{" "}
            {published === 1 ? "thing" : "things"} published.
          </p>
        </div>

        <div className="flex flex-col gap-3">
          <Link
            href={`/profile/${user.id}`}
            className="text-sm text-neutral-300 underline-offset-4 hover:underline active:underline"
          >
            Your public profile
          </Link>
          {contributions ? (
            <p className="text-sm text-neutral-400">
              {contributions.maps.count} {contributions.maps.count === 1 ? "map" : "maps"} and{" "}
              {contributions.games.count} {contributions.games.count === 1 ? "game" : "games"} you
              sent the facts for, and {contributions.pictures.count}{" "}
              {contributions.pictures.count === 1 ? "picture" : "pictures"} approved.
            </p>
          ) : null}
          <Link
            href={`/gallery?account=${user.id}`}
            className="text-sm text-neutral-300 underline-offset-4 hover:underline active:underline"
          >
            Everything you have published
          </Link>
          <form action="/auth/signout" method="post">
            <button
              type="submit"
              className="text-sm text-neutral-400 transition-colors hover:text-white active:text-white"
            >
              Sign out
            </button>
          </form>
        </div>

        <div className="flex flex-col gap-3 rounded-md border border-neutral-800 bg-card p-5">
          <h2 className="text-sm font-medium">Change your display name</h2>
          <RenameAccountForm name={name} />
        </div>

        <div
          id="sign-in-methods"
          className="flex scroll-mt-24 flex-col gap-3 rounded-md border border-neutral-800 bg-card p-5"
        >
          <h2 className="text-sm font-medium">How you sign in</h2>
          <p className="text-sm text-neutral-400">
            Any of these signs you in to this same account. With a second one
            linked, losing access to the first does not lose you your account
            or what you published.
          </p>
          {linkFailure ? (
            <p role="alert" className="text-sm text-red-400">
              {linkFailure}
            </p>
          ) : null}
          <SignInMethods
            methods={signInMethods(identities)}
            linkable={linkableProviders(identities)}
            mayChange={authenticators.length === 0 || verified}
            stepUpHref={stepUpPath("/account#sign-in-methods")}
          />
        </div>

        {showAuthenticators ? (
          <div
            id="second-factor"
            className="flex scroll-mt-24 flex-col gap-3 rounded-md border border-neutral-800 bg-card p-5"
          >
            <h2 className="text-sm font-medium">Authenticator app</h2>
            {access.includes("verify") ? (
              <p role="status" className="text-sm text-amber-300">
                What you have been trusted with here, such as moderation, needs a
                code from your authenticator in this session.{" "}
                <Link href={stepUpPath("/account")} className="underline underline-offset-4">
                  Enter your code
                </Link>
                .
              </p>
            ) : access.includes("enrol") ? (
              <p role="status" className="text-sm text-amber-300">
                What you have been trusted with here, such as moderation, now
                needs an authenticator. Set one up below to use it again.
              </p>
            ) : holdsCapability && authenticators.length === 0 ? (
              <p className="text-sm text-amber-300">
                You have been trusted with things here, such as moderation. Set
                up an authenticator so that somebody who gets hold of your sign
                in cannot use them.
              </p>
            ) : null}
            <p className="text-sm text-neutral-400">
              An authenticator app gives you a code to type after signing in.
              Once you have one, moderation and anything else you have been
              trusted with needs that code as well as your sign in. If you lose
              your only authenticator, the hub&apos;s maintainer has to remove it
              before you can use those again, so adding a spare is worth it.
            </p>
            <SecondFactor
              factors={authenticators}
              verified={verified}
              stepUpHref={stepUpPath("/account#second-factor")}
            />
          </div>
        ) : null}

        <div className="flex flex-col gap-3 rounded-md border border-red-950 bg-card p-5">
          <h2 className="text-sm font-medium">Delete your account</h2>
          <p className="text-sm text-neutral-400">
            This removes your account and everything you have published,
            including anything you had already withdrawn. Import links for
            those items stop working. It cannot be undone, and it is not the
            same as withdrawing something, which is reversible.
          </p>
          <p className="text-sm text-neutral-400">
            If a moderator rejected a picture you uploaded for safety reasons,
            your display name and Discord username are kept with that record
            after your account is deleted, so it still says who uploaded it.
          </p>
          {published > 0 ? (
            <p className="text-sm text-neutral-400">
              {published} {published === 1 ? "item" : "items"} will go with
              it.
            </p>
          ) : null}
          <DeleteAccountForm name={name} />
        </div>
      </div>
    </main>
  );
}
