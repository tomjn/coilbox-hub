import type { SupabaseClient } from "@supabase/supabase-js";
import { MAP_MINIMAP_VARIANT } from "@/lib/assets/asset";
import { fetchHeldAssets, type ResolvedAsset, resolveAsset } from "@/lib/assets/resolve";
import { ITEM_SUMMARY_COLUMNS, type ItemSummary, PAGE_SIZE } from "@/lib/gallery/query";
import { type MapAuthor, mapAuthors } from "./authors";
import { mapSquares } from "./labels";
import { publishableMaps } from "./lookup";

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
 * ## Two clients, and only one of them is the secret one
 *
 * The catalog itself is public. `anon` holds select on `public.map`,
 * `public.map_listing`, `public.map_point` and `public.map_author`, so the facts
 * are read with whatever client the visitor already has, and row level security
 * stays underneath the answer.
 *
 * `public.asset_licence` is the exception, readable by `service_role` alone, and
 * it decides whether the page exists at all. So the admin client is passed in
 * for that one read and is used for nothing else. Reading the whole page through
 * it would put every other query outside the policies for no gain.
 *
 * ## Why not `public.map_facts`
 *
 * It is the same facts and it would be one call. It is granted to `service_role`
 * alone, deliberately, because the lookup route holds the secret key for the
 * licence gate anyway and the function must not be reachable by a browser. A
 * page that called it would have to read everything as `service_role` to save
 * three requests against tables the visitor may read for themselves.
 */

/** The columns a map's page shows, and no more. The provenance columns say how
 *  the hub decided to store the row and mean nothing to a reader. */
const MAP_COLUMNS =
  "id, map_name, slug, display_name, description, width_elmos, height_elmos, min_wind, max_wind, tidal_strength, void_water";

export interface MapRow {
  id: string;
  map_name: string;
  slug: string;
  display_name: string | null;
  description: string | null;
  width_elmos: number;
  height_elmos: number;
  min_wind: number | null;
  max_wind: number | null;
  tidal_strength: number | null;
  void_water: boolean | null;
}

/** One point in the map's own coordinates: x across, z along, exactly as
 *  `public.map_point` stores them. */
export interface MapSpot {
  x: number;
  z: number;
}

/** The three kinds `map_point.kind` accepts, each in its own list and each in
 *  stored order. A kind with no points is an empty list rather than a missing
 *  key, so the page never asks whether the map has any. */
export interface MapSpots {
  start: MapSpot[];
  metal: MapSpot[];
  geo: MapSpot[];
}

export interface MapPage {
  map: MapRow;
  /** Merged, sorted and deduplicated by `public.map_listing`, so the page never
   *  has to know which tags were derived and which a maintainer wrote. */
  tags: string[];
  spots: MapSpots;
  authors: MapAuthor[];
  /** The hub's own minimap, or the drawing standing in for it. */
  picture: ResolvedAsset;
  /** Gallery items played on this map, newest first. Empty is ordinary and the
   *  page shows no section for it. */
  played: ItemSummary[];
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
  spot: MapSpot,
  map: Pick<MapRow, "width_elmos" | "height_elmos">,
): { left: number; top: number } {
  return {
    left: (spot.x / map.width_elmos) * 100,
    top: (spot.z / map.height_elmos) * 100,
  };
}

/** The map behind a slug, or null when nothing has that slug. `map_slug_idx` is
 *  unique, so a slug names one map or none. */
async function mapBySlug(supabase: SupabaseClient, slug: string): Promise<MapRow | null> {
  const { data } = await supabase
    .from("map")
    .select(MAP_COLUMNS)
    .eq("slug", slug)
    .maybeSingle();

  return (data as MapRow | null) ?? null;
}

/** The tags off the view, never recomputed. `20260818120000_map_listing.sql` is
 *  where a measurement becomes a word a reader browses by, and a second copy of
 *  those thresholds here would disagree with the listing the first time one
 *  moved. */
async function mapTags(supabase: SupabaseClient, id: string): Promise<string[]> {
  const { data } = await supabase.from("map_listing").select("tags").eq("id", id).maybeSingle();
  return (data as { tags: string[] } | null)?.tags ?? [];
}

/**
 * The points, split by kind and left in stored order.
 *
 * The order is `map_point.ordinal`, which is the team index on a start position
 * and carries meaning, so it is never sorted on anything else.
 *
 * A large map runs to a few hundred metal spots, which is well inside
 * PostgREST's row ceiling. A map that somehow exceeded it would draw fewer metal
 * spots than it has, which is a thinner picture rather than a wrong page.
 */
async function mapPoints(supabase: SupabaseClient, id: string): Promise<MapSpots> {
  const { data } = await supabase
    .from("map_point")
    .select("kind, x, z")
    .eq("map_id", id)
    .order("ordinal", { ascending: true });

  const spots: MapSpots = { start: [], metal: [], geo: [] };
  for (const row of (data ?? []) as { kind: keyof MapSpots; x: number; z: number }[]) {
    spots[row.kind]?.push({ x: row.x, z: row.z });
  }

  return spots;
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

/** The hub's own minimap of this map, or the drawing that stands in for it. The
 *  footprint is the catalog's own size, so a 12 x 20 map with no picture is
 *  drawn as a 12 x 20 rather than as a square. */
async function mapPicture(supabase: SupabaseClient, map: MapRow): Promise<ResolvedAsset> {
  const identity = {
    keyedOn: "map",
    mapName: map.map_name,
    variant: MAP_MINIMAP_VARIANT,
  } as const;

  const held = await fetchHeldAssets(supabase, [identity]);
  return resolveAsset(identity, held, mapSquares(map.width_elmos, map.height_elmos));
}

/**
 * Everything the page shows, or null when there is no page to show.
 *
 * Null covers two cases and tells them apart nowhere, which is the point. A slug
 * nothing is stored under and a map the hub may not publish both answer not
 * found, so a takedown looks exactly like a map the hub has never heard of.
 * `lib/maps/lookup.ts` gives the same answer to the same question for the API.
 *
 * A licence read that fails is also null. It fails closed on purpose: the hub
 * publishes a map because a row says it may, never because a read did not
 * happen.
 *
 * The row is read first because everything else needs its id, and the rest go
 * together. That includes the gate, so a takedown costs a few requests whose
 * answers are thrown away, which is the right way round: almost every map is
 * publishable and paying a round trip on every one of them to save four requests
 * on the rare one is a bad trade.
 */
export async function loadMapPage(
  supabase: SupabaseClient,
  admin: SupabaseClient,
  slug: string,
): Promise<MapPage | null> {
  const map = await mapBySlug(supabase, slug);
  if (!map) return null;

  const [publishable, tags, spots, authors, picture, played] = await Promise.all([
    publishableMaps(admin, [map.map_name]),
    mapTags(supabase, map.id),
    mapPoints(supabase, map.id),
    mapAuthors(supabase, map.id),
    mapPicture(supabase, map),
    playedHere(supabase, map.map_name),
  ]);

  if (!publishable.ok || !publishable.names.has(map.map_name)) return null;

  return { map, tags, spots, authors, picture, played };
}
