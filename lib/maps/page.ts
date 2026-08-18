import type { SupabaseClient } from "@supabase/supabase-js";
import type { MapFacts } from "@/lib/api/mapLookup";
import { MAP_HEIGHT_OVERLAY_VARIANT, MAP_MINIMAP_VARIANT } from "@/lib/assets/asset";
import { fetchHeldAssets, type ResolvedAsset, resolveAsset } from "@/lib/assets/resolve";
import { ITEM_SUMMARY_COLUMNS, type ItemSummary, PAGE_SIZE } from "@/lib/gallery/query";
import type { MapPoint } from "./facts";
import { mapSquares } from "./labels";
import { fetchMapFacts } from "./lookup";
import { fetchMirrorHosts, type MapMirrorLink, mirrorLinks } from "./mirrors";
import { type MapPreview, mapPreview } from "./preview";

/**
 * Everything one map's page shows, in one place a test can reach (#190).
 *
 * The page itself renders. This reads, and it sits between the page and the
 * catalog the way `lib/gallery/itemPictures.ts` sits between an item page and
 * the picture resolver. What a page did wrong is hard to see through a render
 * and easy to see through a returned object, and the four things #190 asks to be
 * sure of - the placeholder's shape, the markers' positions, an empty section
 * and a takedown - are three parts data and one part markup.
 *
 * ## The facts come from `public.map_facts`, the same as the lookup route
 *
 * A page could read the four catalog tables itself. `anon` holds select on all
 * of them, so the measurements, the tags and the points would all come back. Two
 * things would not.
 *
 * The first is the alias hop. `public.map_author.key` is resolved when a
 * submission is written, so a merge a maintainer records afterwards is not in
 * the column, and a page trusting it would split one mapper across two links
 * until every map they made was submitted again.
 *
 * The second is the name a merged author is shown under, which is the most
 * common spelling of that name across the whole catalog. Both are argued at
 * length in `20260818140000_map_lookup.sql`, which says outright that neither
 * can be done outside the database and that a copy of the hop in a caller is the
 * drift it exists to prevent. Rebuilding either here would be that copy, and the
 * way it would show is a mapper named one thing in a client and another on the
 * hub.
 *
 * ## Which is why a public page reads as `service_role`
 *
 * The licence gate settled that before the facts did. `public.asset_licence` is
 * readable by `service_role` alone and it decides whether this page exists at
 * all, so the page holds the secret key whatever else it does. Once it does,
 * `public.map_facts` costs nothing extra: it is one call in place of four
 * requests, it applies the gate on the way, and it answers the two questions
 * above with the schema's own rules rather than a second copy of them.
 *
 * Everything outside those facts stays on the visitor's own client. The minimap
 * and the gallery items are read with the session or anonymous client, so row
 * level security is underneath both, and nothing here widens what a visitor may
 * learn beyond what `/api/v1/maps/lookup` already answers for the same map.
 */

export interface MapPage {
  /** The canonical name, which is identity and is what the facts were found
   *  under. Beside the facts rather than in them, the same way
   *  `MapLookupResult` carries it. */
  mapName: string;
  facts: MapFacts;
  /** The hub's own minimap, or the drawing standing in for it. */
  picture: ResolvedAsset;
  /** What the 3D view needs, or null when the hub holds no height overlay for
   *  this map and there is no terrain to draw. `./preview.ts` says why that is
   *  no preview at all rather than an empty one. */
  preview: MapPreview | null;
  /** Gallery items played on this map, newest first. Empty is ordinary and the
   *  page shows no section for it. */
  played: ItemSummary[];
  /** Where to look for the archive. Empty when no host is enabled or none of
   *  their templates can be filled in for this map, and the page shows no
   *  section for it. */
  mirrors: MapMirrorLink[];
}

/**
 * Where one point sits in the figure, as percentages of its width and height.
 *
 * A division and nothing else, because the picture is the whole map and the
 * coordinates are in the map's own space. It is a function rather than two
 * expressions in the markup because getting it wrong is invisible: swapping the
 * two divisors draws every marker in a plausible looking place on a square map
 * and nowhere near the right one on a 12 x 20.
 *
 * Percentages rather than pixels, so the same numbers hold at every width the
 * page is read at.
 */
export function markerPosition(
  point: Pick<MapPoint, "x" | "z">,
  map: Pick<MapFacts, "width_elmos" | "height_elmos">,
): { left: number; top: number } {
  return {
    left: (point.x / map.width_elmos) * 100,
    top: (point.z / map.height_elmos) * 100,
  };
}

/** What only `public.map` has: the canonical name everything else is keyed on,
 *  and the filename a mirror template needs. */
interface MapRow {
  map_name: string;
  archive_filename: string | null;
}

/**
 * The row behind a slug, or null when nothing has that slug.
 *
 * Two columns the wire shape does not carry. `map_slug_idx` is unique, so a slug
 * names one map or none.
 *
 * `archive_filename` is here rather than on `MapFacts` because it is not a fact
 * about the map, it is a fact about a file one mirror serves, which is the
 * distinction `20260818100000_map_catalog.sql` draws against `source_archive`.
 * It comes off this read rather than a second one, since this read already has
 * the row open.
 *
 * Read with the visitor's own client. The slug is in the URL they typed and both
 * columns come straight back out of `public.map`, which `anon` may read, so
 * there is nothing here the secret key would answer differently.
 */
async function mapBySlug(supabase: SupabaseClient, slug: string): Promise<MapRow | null> {
  const { data } = await supabase
    .from("map")
    .select("map_name, archive_filename")
    .eq("slug", slug)
    .maybeSingle();

  return (data as MapRow | null) ?? null;
}

/**
 * What the gallery holds that was made for this map, newest first.
 *
 * A plain equality on `item.map_name`, which `item_live_map_idx` already covers,
 * and the same columns and the same order the gallery itself reads. One page of
 * them at most: a map with more than that has a gallery listing of its own to
 * link to, and a map page is about the map.
 */
async function playedHere(supabase: SupabaseClient, mapName: string): Promise<ItemSummary[]> {
  const { data } = await supabase
    .from("item")
    .select(ITEM_SUMMARY_COLUMNS)
    .eq("map_name", mapName)
    .order("created_at", { ascending: false })
    .limit(PAGE_SIZE);

  return (data ?? []) as unknown as ItemSummary[];
}

/**
 * Everything the page shows, or null when there is no page to show.
 *
 * Null covers four cases and tells them apart nowhere, which is the point. A
 * slug nothing is stored under, a slug whose map the hub holds no facts for, a
 * map the hub may not publish, and a licence table that could not be read all
 * answer not found. So a takedown looks exactly like a map the hub has never
 * heard of, which is what `/api/v1/maps/lookup` tells a client about the same
 * map, and an unreadable gate withholds the page rather than publishing on the
 * strength of a read that did not happen.
 *
 * The name is read first because everything else is keyed on it. The rest go
 * together, including the picture: `fetchHeldAssets` asks what the hub holds and
 * `resolveAsset` decides what to draw, and only the second half needs the size
 * off the facts, which is the whole reason those are two functions.
 */
export async function loadMapPage(
  supabase: SupabaseClient,
  admin: SupabaseClient,
  slug: string,
): Promise<MapPage | null> {
  const row = await mapBySlug(supabase, slug);
  if (!row) return null;

  const mapName = row.map_name;
  const identity = { keyedOn: "map", mapName, variant: MAP_MINIMAP_VARIANT } as const;
  // Asked for in the same lookup as the minimap rather than a second one. Most
  // maps hold neither, and a page that only asked when it already had a minimap
  // would make the common answer cost two round trips instead of one.
  const overlay = { keyedOn: "map", mapName, variant: MAP_HEIGHT_OVERLAY_VARIANT } as const;

  const [lookup, held, played, hosts] = await Promise.all([
    fetchMapFacts(admin, [mapName]),
    fetchHeldAssets(supabase, [identity, overlay]),
    playedHere(supabase, mapName),
    fetchMirrorHosts(supabase),
  ]);

  const facts = lookup.ok ? lookup.facts.get(mapName) : undefined;
  if (!facts) return null;

  // The footprint is the catalog's own size, so a 12 x 20 map with no picture
  // is drawn as a 12 x 20 rather than as a square.
  const picture = resolveAsset(
    identity,
    held,
    mapSquares(facts.width_elmos, facts.height_elmos),
  );

  return {
    mapName,
    facts,
    picture,
    preview: mapPreview(mapName, facts, held, picture),
    played,
    mirrors: mirrorLinks(hosts, {
      mapName,
      archiveFilename: row.archive_filename ?? null,
    }),
  };
}
