import type { NextConfig } from "next";
import { getSupabaseConfig, SUPABASE_CONFIG_ERROR } from "./lib/supabase/config";

// The cached listings build empty without Supabase settings, so CI can build
// with none. On Vercel the CDN would then serve those empty pages to everybody,
// since the proxy no longer runs on them, so a Vercel build fails instead.
if (process.env.VERCEL && !getSupabaseConfig().ok) {
  throw new Error(SUPABASE_CONFIG_ERROR);
}

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
};

export default nextConfig;
