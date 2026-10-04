import type { MetadataRoute } from "next";

/** Pages a crawler gains nothing from, or that are not for it.
 *
 *  `/dev` holds the local sign in route. It answers 404 outside development
 *  (`isDevSignInEnabled`), and it is listed anyway so the rule does not depend on
 *  that guard. `/i/` is the raw container an import link fetches, `/export` is
 *  the whole gallery as one file, and the edit pages need a sign in. */
const PRIVATE_PATHS = [
  "/moderation",
  "/ops",
  "/account",
  "/publish",
  "/auth",
  "/api",
  "/dev",
  "/i/",
  "/export",
  "/item/*/edit",
  "/games/*/edit",
];

/**
 * What `robots.txt` says.
 *
 * A preview deployment disallows everything and names no sitemap. It reads the
 * same database as production, so its pages are copies of production's, and
 * indexing them would compete with the real ones. `siteUrl()` points a preview's
 * sitemap at production anyway, which would also be a sitemap on the wrong host.
 *
 * `vercelEnv` is `VERCEL_ENV`: unset locally, `production` or `preview` on Vercel.
 */
export function robotsRules(origin: string, vercelEnv: string | undefined): MetadataRoute.Robots {
  if (vercelEnv === "preview") {
    return { rules: { userAgent: "*", disallow: "/" } };
  }
  return {
    rules: { userAgent: "*", allow: "/", disallow: PRIVATE_PATHS },
    sitemap: `${origin}/sitemap.xml`,
  };
}
