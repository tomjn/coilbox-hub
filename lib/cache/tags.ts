/**
 * What a cached page reads, named so that a write can say what it changed.
 *
 * Every `"use cache"` loader tags itself with the names below, and every write
 * path names the tags it touched: `updateTag` in a server action, so the page
 * the action redirects to is already fresh, and `revalidateTag` in a route
 * handler, where nobody is waiting on a page.
 *
 * Listings share a tag with the rows they list. A gallery card and the item
 * page behind it are both stale the moment the item changes, and telling them
 * apart would mean every write remembering two names instead of one.
 *
 * The pictures are a tag of their own because they reach every kind of page:
 * an approved minimap changes a map's page, the catalog, and every item played
 * on that map, so a picture write names `assets` and every loader that draws
 * one reads it.
 */
export const TAGS = {
  /** The gallery, the home page's newest items, and every item page. */
  items: "items",
  /** One item's page, for a write that touches one row. */
  item: (id: string) => `item:${id}`,
  /** The map catalog, every map page, and the items played on a map. */
  maps: "maps",
  /** One map's page, for a write that touches one map. */
  map: (slug: string) => `map:${slug}`,
  /** The game catalog, every game page, and everything they list. */
  games: "games",
  /** Every picture the hub holds, on whatever page it is drawn. */
  assets: "assets",
} as const;
