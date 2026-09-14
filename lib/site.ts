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
