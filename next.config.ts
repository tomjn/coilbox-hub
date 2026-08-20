import type { NextConfig } from "next";

// The hub serves its own pictures at the size it shows them, so nothing here
// goes through next/image and no remote host has to be allowed.
const nextConfig: NextConfig = {
  // Pages read the session for the header, which makes every route dynamic.
  // With this on, what a page reads through a "use cache" function is kept
  // between requests and the header alone is rendered per visitor, so a
  // logged out visit costs no round trip to the database. `lib/cache/tags.ts`
  // names what a write has to invalidate.
  cacheComponents: true,
};

export default nextConfig;
