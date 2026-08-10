import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  images: {
    // BAR's own image proxy, the source of every map minimap the hub shows.
    // Narrowed to the proxy path rather than the whole host, since that is the
    // only thing `lib/bar/previewUrl.ts` ever builds a URL for.
    remotePatterns: [
      new URL("https://maps-metadata.beyondallreason.dev/i/**"),
    ],
  },
};

export default nextConfig;
