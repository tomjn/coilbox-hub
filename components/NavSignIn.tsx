import { SignInIcon } from "@/components/icons";
import { isDevSignInEnabled } from "@/lib/supabase/loopback";

/**
 * Sign in from the header.
 *
 * A form post to `app/auth/signin/route.ts`, the same shape as the sign out form
 * beside it, so it works with scripting off and no page ships supabase-js for
 * one button. The route sends you back to the page you were reading, which it
 * learns from the post's referer, because signing in from the nav is rarely the
 * start of publishing something.
 */
export function NavSignIn({ className }: { className?: string }) {
  return (
    <form action="/auth/signin" method="post">
      <button type="submit" className={className}>
        <SignInIcon className="w-4" />
        <span className="sr-only sm:not-sr-only">
          {isDevSignInEnabled() ? "Sign in (dev)" : "Sign in"}
        </span>
      </button>
    </form>
  );
}
