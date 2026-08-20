/**
 * Where to send somebody back to once they have signed in.
 *
 * The header's sign in button is on every page and does not know which one, so
 * the route reads the `Referer` the browser sends with the form post. A page
 * that does know, such as the publish page, says so in a `next` field, which
 * wins. Either way only a path on this site is accepted: a referer from
 * elsewhere is somebody arriving from a link, and `//host` would be an open
 * redirect dressed as a path.
 */
export function signInNext(
  field: string | null,
  referer: string | null,
  origin: string,
): string {
  if (field && isPath(field)) return field;

  if (referer) {
    try {
      const url = new URL(referer);
      if (url.origin === origin) return `${url.pathname}${url.search}`;
    } catch {
      // Not a URL at all, so it cannot name a page here.
    }
  }

  return "/";
}

function isPath(value: string): boolean {
  return value.startsWith("/") && !value.startsWith("//");
}
