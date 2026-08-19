import type { MapFacts } from "@/lib/api/mapLookup";
import { MAP_HEIGHT_OVERLAY_VARIANT } from "@/lib/assets/asset";
import { assetTierUrl, type HeldAssets, type ResolvedAsset, servable } from "@/lib/assets/resolve";
import type { HeightRange } from "./heights";

/**
 * What the map page hands the 3D preview, worked out on the server (#194).
 *
 * The browser gets two URLs, six numbers and some colours. Everything that
 * decides whether there is a preview at all happens here, where the row level
 * security and the resolver's own approved test are, rather than in a component
 * that has already been sent to somebody.
 *
 * ## Resolved through the resolver, not fetched from the pictures route
 *
 * `/api/v1/assets/pictures` answers the same question and is for coilbox
 * clients. The hub's own page has `lib/assets/resolve.ts` in process, so asking
 * itself over HTTP would be a round trip to learn what it already knows.
 *
 * `servable` is what both URLs come through, so the approved only filter and
 * `assetTierUrl` are the single door here as everywhere else. A pending upload's
 * Blob path is a working public URL and the only thing keeping unreviewed bytes
 * out of sight is that nobody knows it, so this must not become a second way to
 * reach one.
 *
 * ## No overlay means no preview
 *
 * Not an empty canvas and not a disabled button. The page shows the flat minimap
 * and says nothing about a view that does not exist, which is what it did before
 * this issue. Most maps will be in that state for a long time, so it is the
 * ordinary case rather than a degraded one.
 */

/**
 * The surroundings, off `public.map.appearance`, with everything this cannot use
 * dropped.
 *
 * Colours are RGB in 0 to 1, which is how mapinfo.lua writes them and how the
 * archive is read. Null means the map said nothing and the preview picks its
 * own, which is not the same as the map asking for black.
 */
export interface PreviewAppearance {
  /** The sea's own colour, so a red or a green ocean is not drawn generic
   *  blue. */
  water: [number, number, number] | null;
  waterAlpha: number | null;
  sky: [number, number, number] | null;
  fog: [number, number, number] | null;
  /** Where the light comes from. Not a colour, so it is not clamped. */
  sunDirection: [number, number, number] | null;
  sunColour: [number, number, number] | null;
}

export interface MapPreview {
  /** The 8 bit grey overlay, which is the relief. */
  heightUrl: string;
  /** What that picture's darkest and brightest samples mean. Off the asset row.
   *  Read `./heights` before reaching for the pair on {@link MapFacts}. */
  range: HeightRange;
  /** The minimap, draped over the relief. */
  textureUrl: string;
  /** The map's extent in elmos, which is what makes the vertical scale honest:
   *  relief and ground are then in the same units. */
  widthElmos: number;
  heightElmos: number;
  /** No sea plane at all, and terrain below it left open to the sky. The
   *  archive's own declaration, off the catalog column rather than guessed from
   *  the appearance blob. */
  voidWater: boolean;
  appearance: PreviewAppearance;
}

/** Everything in `appearance` is somebody else's jsonb. It can be `{}`, any key
 *  can be missing, and any key can be the wrong type, so nothing below trusts a
 *  value it has not just checked. */
function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

/** A finite number or nothing. `NaN` and the infinities all pass `typeof`, and
 *  any of them reaching a colour or a light direction is a scene that renders
 *  black or not at all. */
function finite(value: unknown): number | null {
  return typeof value === "number" && Number.isFinite(value) ? value : null;
}

function triple(value: unknown): [number, number, number] | null {
  if (!Array.isArray(value) || value.length !== 3) return null;

  const [x, y, z] = value.map(finite);
  if (x === null || y === null || z === null) return null;

  return [x, y, z];
}

/** A colour triple, clamped. A component outside 0 to 1 is a map declaring
 *  something the renderer has no meaning for, and clamping keeps the hue it
 *  asked for where discarding the whole triple would lose it. */
function rgb(value: unknown): [number, number, number] | null {
  const parsed = triple(value);
  if (!parsed) return null;

  return parsed.map((component) => Math.min(1, Math.max(0, component))) as [
    number,
    number,
    number,
  ];
}

/**
 * The appearance keys this preview knows, from a blob it has no schema for.
 *
 * The names are coilbox's `MapAppearance`, which is what reads `mapinfo.lua` and
 * is the only thing that submits a catalog entry. `public.map.appearance` is
 * declared as pass through jsonb and neither the table nor the vendored
 * vocabulary says what is in it, so this reads what it recognises and ignores
 * the rest. A key that is renamed upstream stops being read and the preview
 * falls back to its own colours, which is a duller picture rather than a broken
 * page.
 */
export function readAppearance(blob: unknown): PreviewAppearance {
  const record = isRecord(blob) ? blob : {};
  const alpha = finite(record.waterAlpha);

  return {
    water: rgb(record.waterColor),
    waterAlpha: alpha === null ? null : Math.min(1, Math.max(0, alpha)),
    sky: rgb(record.skyColor),
    fog: rgb(record.fogColor),
    sunDirection: triple(record.sunDir),
    sunColour: rgb(record.sunColor),
  };
}

/**
 * The preview for one map, or null when there is nothing to draw.
 *
 * Null covers a map with no height overlay, a map whose overlay is stored
 * without the range that decodes it, and a map with no minimap to drape over the
 * relief. The last one is not a partial preview: the page is already showing a
 * placeholder rather than a picture in that state, and offering a 3D view under
 * it would claim the hub holds something it does not.
 *
 * `picture` is the minimap the page already resolved, passed in rather than
 * resolved again. A second resolve could disagree with the first, and the flat
 * figure and the terrain's surface have to be the same bytes.
 */
export function mapPreview(
  mapName: string,
  facts: MapFacts,
  held: HeldAssets,
  picture: ResolvedAsset,
): MapPreview | null {
  if (picture.from === "placeholder") return null;

  const overlay = servable(held, {
    keyedOn: "map",
    mapName,
    variant: MAP_HEIGHT_OVERLAY_VARIANT,
  });
  if (!overlay) return null;

  // Both are mandatory on this variant under `asset_height_range_check`, so a
  // row missing either is one the constraint could not have accepted. Checked
  // anyway, because the alternative to checking is decoding against null and
  // drawing a map of NaN.
  const { world_height_min: min, world_height_max: max } = overlay;
  if (min === null || max === null) return null;

  return {
    heightUrl: assetTierUrl(overlay.tier, overlay.path),
    range: { min, max },
    textureUrl: picture.url,
    widthElmos: facts.width_elmos,
    heightElmos: facts.height_elmos,
    voidWater: facts.void_water === true,
    appearance: readAppearance(facts.appearance),
  };
}
