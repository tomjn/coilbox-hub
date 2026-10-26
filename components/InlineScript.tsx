/**
 * A script the browser runs as it parses the page, before the first paint.
 *
 * From the Next guide on preventing a flash before hydration: `text/plain` on
 * the client so React does not warn about rendering a script, and
 * `suppressHydrationWarning` for the type it changed.
 */
export function InlineScript({ html }: { html: string }) {
  return (
    <script
      type={typeof window === "undefined" ? "text/javascript" : "text/plain"}
      suppressHydrationWarning
      dangerouslySetInnerHTML={{ __html: html }}
    />
  );
}
