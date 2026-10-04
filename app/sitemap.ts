import type { MetadataRoute } from "next";
import { sitemapEntries } from "@/lib/sitemap/entries";
import { sitemapGames, sitemapItems, sitemapMaps } from "@/lib/sitemap/cached";
import { siteUrl } from "@/lib/site";

/**
 * Every public page, as one file.
 *
 * Prerendered, and regenerated after the shortest `cacheLife` among the three
 * loaders (an hour) or when a write clears the `items`, `maps` or `games` tag.
 * `sitemapEntries` throws past the protocol's 50,000 URLs, which is the signal to
 * split this with `generateSitemaps`.
 */
export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const [items, maps, games] = await Promise.all([sitemapItems(), sitemapMaps(), sitemapGames()]);
  return sitemapEntries(siteUrl(), { items, maps, games });
}
