/**
 * The hub's own origin, with no trailing slash.
 *
 * Vercel sets `VERCEL_PROJECT_PRODUCTION_URL` on every deployment, so production
 * resolves against the real domain and local development against localhost.
 * A preview deployment resolves against production too. That is what an
 * unfurler wants, and it means a preview's pictures come through production's
 * routes rather than its own.
 *
 * Server only. The variable is not `NEXT_PUBLIC_`, so a client component
 * reading this would get localhost.
 */
export function siteUrl(): string {
  const host = process.env.VERCEL_PROJECT_PRODUCTION_URL;
  return host ? `https://${host}` : "http://localhost:3000";
}

/**
 * The origin an import link carries: the address of the deployment serving the
 * page.
 *
 * Read from the environment rather than the request, so a page can build its
 * import links without waiting on request headers and stay in the prerendered
 * shell. `VERCEL_ENV` and `VERCEL_URL` are set at build and at run time, and a
 * deployment's output is only ever served by that deployment, so the value
 * baked in at build is the right one.
 *
 * Production resolves to the production domain, a preview to its own deployment
 * URL (not its branch alias) and local development to localhost on port 3000.
 *
 * Server only, for the same reason as `siteUrl()`.
 */
export function importOrigin(): string {
  const preview = process.env.VERCEL_ENV === "preview" ? process.env.VERCEL_URL : undefined;
  return preview ? `https://${preview}` : siteUrl();
}
