import type { MapPack } from "@/lib/maps/packs";

/**
 * The wire shape of `GET /api/v1/map-packs` (tomjn/coilbox#3206).
 *
 * Written to the contract coilbox already reads: `HubMapPack` in coilbox's
 * `src/hub/api.ts`, whose `maps` are its `SuggestedMap` from
 * `src/content/branding.ts`, the same shape a branding catalog pack uses. So a
 * hub pack drops into coilbox's Map packs menu with no conversion.
 *
 * ## Each map is a name to search for
 *
 * `{ kind: "map", springName }` with no `searchUrl`. Coilbox then tries its own
 * source order for the name (evolutionrts first), which is where every map on
 * the BAR mirror already lives. The hub holds no archives and no download
 * addresses, so a name is the honest answer, the same reasoning `/games` gives
 * for handing out a rapid tag rather than a URL.
 *
 * `filename` is the archive filename where the catalog holds one. Coilbox uses
 * it to tell a map is already installed, so a pack of maps a player mostly has
 * reads as mostly done rather than all to fetch.
 *
 * ## What is left out
 *
 * A pack with no maps. Coilbox would draw it as a pack with nothing to download,
 * which is a moderator's half finished pack rather than something to offer.
 *
 * A map that has been taken down for licence reasons, so a pack whose maps were
 * all taken down is left out the same way.
 *
 * Unfeatured packs are listed, with `featured: false`, the way `/games` lists
 * unfeatured games. Coilbox only shows the featured ones.
 */

export const MAP_PACK_LIST_FORMAT = "coilbox-hub-map-packs";
export const MAP_PACK_LIST_VERSION = 1;

/** coilbox's `SuggestedMap`, narrowed to the one download kind the hub hands out. */
export interface MapPackListMap {
  id: string;
  title: string;
  download: { kind: "map"; springName: string };
  filename?: string;
}

export interface MapPackListEntry {
  id: string;
  title: string;
  blurb?: string;
  featured: boolean;
  maps: MapPackListMap[];
}

export interface MapPackListResponseBody {
  format: typeof MAP_PACK_LIST_FORMAT;
  version: typeof MAP_PACK_LIST_VERSION;
  packs: MapPackListEntry[];
}

/** The listing as JSON, in the order it was given: featured first. */
export function buildMapPackListBody(packs: MapPack[]): MapPackListResponseBody {
  return {
    format: MAP_PACK_LIST_FORMAT,
    version: MAP_PACK_LIST_VERSION,
    packs: packs
      .map((pack) => ({ ...pack, maps: pack.maps.filter((map) => !map.takenDown) }))
      .filter((pack) => pack.maps.length > 0)
      .map((pack) => ({
        id: pack.id,
        title: pack.title,
        ...(pack.blurb ? { blurb: pack.blurb } : {}),
        featured: pack.featuredAt !== null,
        maps: pack.maps.map((map) => ({
          // The map name rather than the slug: it is unique in the catalog and
          // still there when the catalog row is not.
          id: map.mapName,
          title: map.displayName ?? map.mapName,
          download: { kind: "map" as const, springName: map.mapName },
          ...(map.archiveFilename ? { filename: map.archiveFilename } : {}),
        })),
      })),
  };
}
