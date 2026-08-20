import { cacheLife, cacheTag } from "next/cache";
import { TAGS } from "@/lib/cache/tags";
import { createAdminClient } from "@/lib/supabase/admin";
import { createAnonClient } from "@/lib/supabase/anon";
import { type ItemPictures, itemPictures } from "./itemPictures";

/**
 * What an item page shows to anybody, held between requests.
 *
 * ## Held means the public reading, and nothing else
 *
 * `createAnonClient` has no session, so the read policy answers as `anon`: a
 * live item comes back and a withdrawn one comes back as nothing found. That is
 * the whole reason this is safe to hold. An author looking at their own
 * withdrawn item, and a moderator, see something the public does not, and the
 * page reads that for itself with the visitor's own client rather than out of
 * here.
 *
 * So a null answer is not "no such item". It is "nothing here for the public",
 * and the page falls back to its own read before it decides that.
 *
 * ## The pictures come with it
 *
 * `itemPictures` is a batch of its own, keyed on the same row, so holding the
 * row without it would leave the page waiting on a round trip anyway. It hands
 * back `Map`s, which cannot cross a cache boundary, so they travel as entries
 * and {@link itemPicturesFromEntries} puts them back.
 */

const ITEM_LIFE = "hours";

/** The columns an item page reads. Wider than a listing's: this is the page
 *  that draws the container. */
export const DETAIL_COLUMNS =
  "id,kind,mode,container,title,description,game_name,game_key,map_name,tags,author_name,created_at,updated_at,import_count";

export interface ItemDetail {
  id: string;
  kind: string;
  mode: string | null;
  container: unknown;
  title: string;
  description: string;
  game_name: string | null;
  /** The grouping key `/gallery?game=` filters on (issue #50). Absent when
   * `game_name` is only the exact archive name, since that is not shared by
   * anything else to filter to. */
  game_key: string | null;
  map_name: string | null;
  tags: string[];
  author_name: string;
  created_at: string;
  updated_at: string;
  /** Imports coilbox has pinged back for (issue #51): only ones that started
   * from this page's link, and only since the coilbox release that sends the
   * ping. Nothing before that, and nothing outside a hub link, was ever
   * countable. */
  import_count: number;
}

/** The picture lookups as they cross a cache boundary. */
export interface PictureEntries {
  map: ItemPictures["map"];
  units: [string, ItemPictures["units"] extends ReadonlyMap<string, infer V> ? V : never][];
  packMaps: [string, ItemPictures["packMaps"] extends ReadonlyMap<string, infer V> ? V : never][];
  catalog: [string, ItemPictures["catalog"] extends ReadonlyMap<string, infer V> ? V : never][];
}

export interface PublicItem {
  item: ItemDetail;
  pictures: PictureEntries;
}

export function itemPicturesFromEntries(entries: PictureEntries): ItemPictures {
  return {
    map: entries.map,
    units: new Map(entries.units),
    packMaps: new Map(entries.packMaps),
    catalog: new Map(entries.catalog),
  };
}

/**
 * The item as the public sees it, or null when the public sees nothing.
 *
 * Tagged with the item itself and with the listings, because a change to a row
 * changes both, and with the pictures and the catalog, because an approved
 * minimap or a corrected map size changes what this page draws without the row
 * moving at all.
 */
export async function itemPublic(id: string): Promise<PublicItem | null> {
  "use cache";
  cacheLife(ITEM_LIFE);
  cacheTag(TAGS.items, TAGS.item(id), TAGS.assets, TAGS.maps);

  const supabase = createAnonClient();
  const { data } = await supabase
    .from("item")
    .select(DETAIL_COLUMNS)
    .eq("id", id)
    .maybeSingle();

  const item = (data as ItemDetail | null) ?? null;
  if (!item) return null;

  // The secret key is the second client because the map facts come through the
  // licence gate over `public.asset_licence`, which nothing else may read
  // (issue #191).
  const pictures = await itemPictures(supabase, createAdminClient(), item);

  return {
    item,
    pictures: {
      map: pictures.map,
      units: [...pictures.units],
      packMaps: [...pictures.packMaps],
      catalog: [...pictures.catalog],
    },
  };
}
