import { ProviderLogo } from "@/components/ProviderLogo";
import { isDevSignInEnabled } from "@/lib/supabase/loopback";
import { SIGN_IN_PROVIDERS, type SignInProviderId } from "@/lib/supabase/signInProvider";

const BUTTON =
  "inline-flex items-center gap-2.5 rounded-md px-5 py-2.5 text-sm font-medium text-white transition-colors";

// Each provider's own brand colour. White text on each passes AA.
const COLOURS: Record<SignInProviderId, string> = {
  discord: "bg-[#5865F2] hover:bg-[#4752c4] active:bg-[#3b45a3]",
  github: "border border-neutral-600 bg-[#24292f] hover:bg-[#32383f] active:bg-[#1b1f24]",
  twitch: "bg-[#9146FF] hover:bg-[#772ce8] active:bg-[#5c16c5]",
};

/**
 * Sign in is only ever needed to publish, edit or withdraw. Browsing and
 * importing stay anonymous, so this never appears in the way of reading.
 *
 * One form post per provider to `app/auth/signin/route.ts` rather than a script,
 * so it works with scripting off and the page ships no supabase-js for it.
 * `next` is where to come back to, and the page that renders this knows better
 * than the route's referer would.
 *
 * The dev bypass ignores the provider, so in development there is one button.
 */
export function SignInButtons({ next = "/publish" }: { next?: string }) {
  if (isDevSignInEnabled()) {
    return (
      <form action="/auth/signin" method="post">
        <input type="hidden" name="next" value={next} />
        <button type="submit" className={`${BUTTON} ${COLOURS.discord}`}>
          Sign in (dev)
        </button>
      </form>
    );
  }

  return (
    <div className="flex flex-wrap gap-3">
      {SIGN_IN_PROVIDERS.map((provider) => (
        <form key={provider.id} action="/auth/signin" method="post">
          <input type="hidden" name="next" value={next} />
          <input type="hidden" name="provider" value={provider.id} />
          <button type="submit" className={`${BUTTON} ${COLOURS[provider.id]}`}>
            <ProviderLogo provider={provider.id} />
            Sign in with {provider.label}
          </button>
        </form>
      ))}
    </div>
  );
}
