import { isDevSignInEnabled } from "@/lib/supabase/loopback";

/**
 * Sign in is only ever needed to publish, edit or withdraw. Browsing and
 * importing stay anonymous, so this never appears in the way of reading.
 *
 * A form post to `app/auth/signin/route.ts` rather than a script, so it works
 * with scripting off and the page ships no supabase-js for it. `next` is where
 * to come back to, and the page that renders this knows better than the route's
 * referer would.
 */
export function SignInWithDiscord({ next = "/publish" }: { next?: string }) {
  return (
    <form action="/auth/signin" method="post">
      <input type="hidden" name="next" value={next} />
      <button
        type="submit"
        className="rounded-md bg-[#5865F2] px-5 py-2.5 text-sm font-medium text-white transition-colors hover:bg-[#4752c4] active:bg-[#3b45a3]"
      >
        {isDevSignInEnabled() ? "Sign in (dev)" : "Sign in with Discord"}
      </button>
    </form>
  );
}
