import type { MetadataRoute } from "next";
import { rfc3339 } from "@/lib/feed/atom";

/** The most URLs the sitemap protocol allows in one file. */
export const SITEMAP_LIMIT = 50_000;

export interface SitemapSource {
  items: { id: string; updated_at: string }[];
  maps: { slug: string }[];
  games: { shortname: string; units: string[]; releases: number }[];
}

/**
 * Every public page the sitemap lists.
 *
 * Throws past {@link SITEMAP_LIMIT}. A search engine ignores a sitemap over the
 * limit, so cutting the list short would drop pages without anybody being told.
 * Failing the build, or the next regeneration, is how the day the hub outgrows
 * one file gets noticed. The answer then is `generateSitemaps`.
 */
export function sitemapEntries(origin: string, source: SitemapSource): MetadataRoute.Sitemap {
  const part = encodeURIComponent;
  const entries: MetadataRoute.Sitemap = [
    { url: `${origin}/` },
    { url: `${origin}/gallery` },
    { url: `${origin}/maps` },
    { url: `${origin}/games` },
    ...source.items.map((item) => ({
      url: `${origin}/item/${part(item.id)}`,
      lastModified: rfc3339(item.updated_at),
    })),
    ...source.maps.map((map) => ({ url: `${origin}/map/${part(map.slug)}` })),
    ...source.games.flatMap((game) => [
      { url: `${origin}/games/${part(game.shortname)}` },
      { url: `${origin}/games/${part(game.shortname)}/units` },
      // The page compares two releases, so a game with one has nothing on it.
      ...(game.releases >= 2 ? [{ url: `${origin}/games/${part(game.shortname)}/changes` }] : []),
      ...game.units.map((unit) => ({ url: `${origin}/games/${part(game.shortname)}/units/${part(unit)}` })),
    ]),
  ];

  if (entries.length > SITEMAP_LIMIT) {
    throw new Error(
      `The sitemap has ${entries.length} URLs and one file may hold ${SITEMAP_LIMIT}. Split it with generateSitemaps.`,
    );
  }
  return entries;
}
