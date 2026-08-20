import { cacheLife, cacheTag } from "next/cache";
import { TAGS } from "@/lib/cache/tags";
import type { ResolvedAsset } from "@/lib/assets/resolve";
import { fetchPage } from "@/lib/gallery/query";
import { createAdminClient } from "@/lib/supabase/admin";
import { createAnonClient } from "@/lib/supabase/anon";
import { loadMapPage, type MapPage } from "./page";
import {
  applyFilters,
  applySort,
  type Filters,
  MAP_PAGE_SIZE,
  MAP_SUMMARY_COLUMNS,
  type MapSummary,
  mapPictures,
  resolveAuthorKey,
} from "./query";

/**
 * The catalog's reads, held between requests.
 *
 * `lib/gallery/cached.ts` sets out why these build their own anonymous client
 * and take their filters as arguments. The map half has one wrinkle that half
 * does not: a `Map` cannot cross a cache boundary, so a lookup goes out as its
 * entries and the page builds the `Map` back. {@link picturesFromEntries} is
 * that half, and it is a function rather than a line in the page so both sides
 * of the round trip are proved by a test.
 *
 * The catalog is the heaviest thing the hub reads. `public.map_browse`
 * re-aggregates every credit in `public.map_author` on each request and no index
 * covers any sort it offers, so holding the answer is worth more here than
 * anywhere else on the site.
 */

const LISTING_LIFE = "hours";

/** A picture lookup as it crosses a cache boundary. */
export type PictureEntries = [string, ResolvedAsset][];

export function picturesFromEntries(
  entries: PictureEntries,
): ReadonlyMap<string, ResolvedAsset> {
  return new Map(entries);
}

export interface MapsPage {
  maps: MapSummary[];
  count: number;
  error: string | null;
  /** Keyed by canonical map name, as entries. See the note above. */
  pictures: PictureEntries;
}

/** One page of the catalog, with a minimap for every card on it. */
export async function mapsPage(filters: Filters): Promise<MapsPage> {
  "use cache";
  cacheLife(LISTING_LIFE);
  cacheTag(TAGS.maps, TAGS.assets);

  const supabase = createAnonClient();

  // The one thing that cannot be worked out here. A reader arrives with a
  // spelling and the catalog files a key, and the rules turning one into the
  // other live in the database so there is only ever one copy of them.
  // `lib/maps/query.ts` sets out what a second copy would cost.
  const authorKey = await resolveAuthorKey(supabase, filters.author);

  const listing = () =>
    applySort(
      applyFilters(
        supabase.from("map_browse").select(MAP_SUMMARY_COLUMNS, { count: "exact" }),
        filters,
        authorKey,
      ),
      filters.sort,
    );

  const { data, count, error } = await fetchPage(
    () => listing().range((filters.page - 1) * MAP_PAGE_SIZE, filters.page * MAP_PAGE_SIZE - 1),
    async () => {
      const { count, error } = await listing().range(0, 0);
      return { count, error };
    },
  );

  const maps = data as unknown as MapSummary[];
  // One batched lookup for the whole page rather than one per card.
  const pictures = await mapPictures(supabase, maps);

  return { maps, count, error, pictures: [...pictures] };
}

/**
 * Everything one map's page shows.
 *
 * Tagged with the catalog as well as the map itself, because a merge recorded
 * against an author changes the credits on every map they made, and with the
 * items because the page lists what was played on it.
 */
export async function mapPageCached(slug: string): Promise<MapPage | null> {
  "use cache";
  cacheLife(LISTING_LIFE);
  cacheTag(TAGS.maps, TAGS.map(slug), TAGS.assets, TAGS.items);

  // The secret key for the facts and the gate they come through, and an
  // anonymous client for everything else. `lib/maps/page.ts` sets out why a
  // public page reads anything as `service_role` at all.
  return loadMapPage(createAnonClient(), createAdminClient(), slug);
}
