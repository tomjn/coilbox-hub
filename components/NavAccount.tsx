import Link from "next/link";
import { AccountIcon, ModerationIcon, SignOutIcon } from "@/components/icons";
import { LinkPending } from "@/components/LinkPending";
import { NavSignIn } from "@/components/NavSignIn";
import { displayName } from "@/lib/author";
import { createClient } from "@/lib/supabase/server";
import { currentUser } from "@/lib/supabase/user";

/**
 * The part of the header that differs per visitor: whether they are signed in,
 * what to call them, and whether they moderate.
 *
 * Its own component so that the layout around it reads nothing about the
 * request. Everything else on the page is the same for everybody and can be
 * built once and held, and this is rendered per request inside a `Suspense` the
 * layout puts around it. Before that split, one session read in the layout made
 * every route on the site dynamic.
 *
 * `NavAccount.fallback` is what stands in its place until it arrives, and it is
 * exported beside it so the two cannot drift into different widths and shift
 * the header when the real one lands.
 */
export async function NavAccount({ className }: { className: string }) {
  const user = await currentUser();
  const author = user ? displayName(user.metadata) : null;
  // Only signed in visitors can be moderators, so nobody else pays for the call.
  const { data: moderator } = user
    ? await (await createClient()).rpc("is_moderator")
    : { data: false };

  return (
    <>
      {moderator ? (
        <Link href="/moderation" className={className}>
          <LinkPending className="flex items-center gap-2">
            <ModerationIcon className="w-4" />
            <span className="sr-only sm:not-sr-only">Moderation</span>
          </LinkPending>
        </Link>
      ) : null}
      {author ? (
        <>
          <Link href="/account" className={className}>
            <LinkPending className="flex items-center gap-2">
              <AccountIcon className="w-4" />
              <span className="sr-only sm:not-sr-only">
                <span className="block max-w-32 truncate">{author}</span>
              </span>
            </LinkPending>
          </Link>
          <form action="/auth/signout" method="post">
            <button type="submit" className={className}>
              <SignOutIcon className="w-4" />
              <span className="sr-only sm:not-sr-only">Sign out</span>
            </button>
          </form>
        </>
      ) : (
        <NavSignIn className={className} />
      )}
    </>
  );
}

/**
 * The space the account controls take while they are being read.
 *
 * Sized to the sign in button, which is what most visitors get. It holds the
 * row's height so the header does not jump, and says nothing, because "signed
 * in as nobody" would be a claim about a visitor the page has not read yet.
 */
export function NavAccountFallback() {
  return <span aria-hidden className="h-8 w-8 sm:w-20" />;
}
