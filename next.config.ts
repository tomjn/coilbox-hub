import type { NextConfig } from "next";
import {
  CHANGES_FILTER_KEYS,
  GALLERY_FILTER_KEYS,
  MAP_FILTER_KEYS,
  TREE_FILTER_KEYS,
  UNIT_GRID_FILTER_KEYS,
} from "./lib/filterKeys";
import { getSupabaseConfig, SUPABASE_CONFIG_ERROR } from "./lib/supabase/config";

// The cached listings build empty without Supabase settings, so CI can build
// with none. On Vercel the CDN would then serve those empty pages to everybody,
// since the proxy no longer runs on them, so a Vercel build fails instead.
if (process.env.VERCEL && !getSupabaseConfig().ok) {
  throw new Error(SUPABASE_CONFIG_ERROR);
}

// The hub now runs from `hub-php/` at this address. On Vercel this app only
// sends people there. Without `VERCEL` set it still serves its old pages, which
// nothing is checked against.
const PHP_HUB = "https://hub.coilbox.org";
const ON_VERCEL = Boolean(process.env.VERCEL);

// The hub serves its own pictures at the size it shows them, so nothing here
// goes through next/image and no remote host has to be allowed.
const nextConfig: NextConfig = {
  // What a page reads through a "use cache" function is kept between requests.
  // A public page reads nothing else, and the header draws who is signed in in
  // the browser (`lib/auth/navCookie.ts`), so the CDN can serve the page without
  // running a function. `lib/cache/tags.ts` names what a write has to
  // invalidate.
  cacheComponents: true,
  // An item, map, game or unit page is not listed at build time. With this on,
  // its first visit gets the shell and Next renders the full page in the
  // background, so later visits are served whole from the cache rather than
  // streaming the rest from a function each time. That needs a
  // `generateStaticParams` on the segment, even a placeholder.
  partialPrefetching: true,
  async redirects() {
    if (ON_VERCEL) {
      return [
        // Every page, with its query string. The API and the container links
        // are left to the rewrites below.
        {
          source: "/:path((?!api/v1/|i/).*)",
          destination: `${PHP_HUB}/:path`,
          permanent: true,
        },
      ];
    }
    return [
      // A unit at a past release moved from `?v=` to `<unit>@<release>`, so the
      // unit page reads nothing from the request and the CDN can serve it
      // (`lib/games/unitRelease.ts`).
      {
        source: "/games/:shortname/units/:unit",
        has: [{ type: "query", key: "v", value: "(?<v>.+)" }],
        destination: "/games/:shortname/units/:unit@:v",
        permanent: true,
      },
    ];
  },
  async rewrites() {
    if (ON_VERCEL) {
      return {
        // Proxied, not redirected. Installed copies of Coilbox call this host,
        // and their HTTP client drops the Authorization header when a redirect
        // changes host, which would sign every upload out.
        beforeFiles: [
          { source: "/api/v1/:path*", destination: `${PHP_HUB}/api/v1/:path*` },
          { source: "/i/:path*", destination: `${PHP_HUB}/i/:path*` },
        ],
      };
    }
    return {
      // Before the pages are matched, or the listing's own page would answer.
      beforeFiles: [
        ...whenFiltered("/maps", "/maps/filtered", MAP_FILTER_KEYS),
        ...whenFiltered("/gallery", "/gallery/filtered", GALLERY_FILTER_KEYS),
        // Beside the units rather than under them, where a unit could be named
        // `filtered`.
        ...whenFiltered("/games/:shortname/units", "/games/:shortname/units-filtered", UNIT_GRID_FILTER_KEYS),
        ...whenFiltered("/games/:shortname/tree", "/games/:shortname/tree/filtered", TREE_FILTER_KEYS),
        ...whenFiltered("/games/:shortname/changes", "/games/:shortname/changes/filtered", CHANGES_FILTER_KEYS),
      ],
    };
  },
};

/** One rewrite per key, because a rewrite's `has` items must all match and a
 *  filtered listing holds any one of them (`lib/filterKeys.ts`). */
function whenFiltered(source: string, destination: string, keys: readonly string[]) {
  return keys.map((key) => ({ source, destination, has: [{ type: "query" as const, key }] }));
}

export default nextConfig;
