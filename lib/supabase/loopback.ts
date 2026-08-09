/**
 * Whether a Supabase URL points at the machine running this process, rather
 * than a hosted project. One of the two independent guards on the dev only
 * sign in route: even in a development build, a Supabase URL that is not
 * loopback means this is not really pointed at a disposable local stack.
 */
export function isLoopbackUrl(value: string): boolean {
  let url: URL;
  try {
    url = new URL(value);
  } catch {
    return false;
  }
  return (
    url.hostname === "127.0.0.1" ||
    // The WHATWG URL parser keeps the brackets on an IPv6 host.
    url.hostname === "[::1]" ||
    url.hostname === "localhost"
  );
}

/**
 * Whether the dev only sign in route (app/dev/sign-in/route.ts) exists in
 * this build. This is the one place that decides what "development" means
 * for that route - anything that links to it, such as the sign in button in
 * the header, reads this rather than re-deriving the same two conditions.
 */
export function isDevSignInEnabled(): boolean {
  return (
    process.env.NODE_ENV === "development" &&
    isLoopbackUrl(process.env.NEXT_PUBLIC_SUPABASE_URL ?? "")
  );
}
