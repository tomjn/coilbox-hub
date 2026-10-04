import Link from "next/link";
import { AccountIcon, ModerationIcon, SignOutIcon } from "@/components/icons";
import { LinkPending } from "@/components/LinkPending";
import { NavSignIn } from "@/components/NavSignIn";
import { displayName } from "@/lib/author";
import { currentUser, isModerator } from "@/lib/supabase/user";

/**
 * The part of the header that differs per visitor: whether they are signed in,
 * and what to call them. Whether they moderate is `NavModeration`.
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

  return author ? (
    <>
      <Link href="/account" className={className}>
        <LinkPending className="flex flex-col items-center gap-0.5 sm:flex-row sm:gap-2">
          <AccountIcon className="w-4" />
          <span className="block max-w-20 truncate sm:max-w-32">{author}</span>
        </LinkPending>
      </Link>
      <form action="/auth/signout" method="post">
        <button type="submit" className={className}>
          <SignOutIcon className="w-4" />
          <span>Sign out</span>
        </button>
      </form>
    </>
  ) : (
    <NavSignIn className={className} />
  );
}

/**
 * The moderation link, which only a moderator gets.
 *
 * Split from `NavAccount` because it sits with the section links, and on a phone
 * the account controls sit on the logo row instead. `data-moderator` lets the
 * nav see that it holds six links rather than five.
 */
export async function NavModeration({ className }: { className: string }) {
  return (await isModerator()) ? (
    <Link href="/moderation" className={className} data-moderator>
      <LinkPending className="flex flex-col items-center gap-0.5 sm:flex-row sm:gap-2">
        <ModerationIcon className="w-4" />
        <span>Moderation</span>
      </LinkPending>
    </Link>
  ) : null;
}

/**
 * The space the account controls take while they are being read.
 *
 * Sized to the sign in button, which is what most visitors get. It holds the
 * row's height so the header does not jump, and says nothing, because "signed
 * in as nobody" would be a claim about a visitor the page has not read yet.
 */
export function NavAccountFallback() {
  return <span aria-hidden className="h-11 w-11 sm:h-8 sm:w-20" />;
}
