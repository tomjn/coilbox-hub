import { MAP_CATALOG_CAPS } from "@/lib/maps/catalog";
import type { MapPoints } from "@/lib/maps/facts";

/**
 * The wire shape of the map lookup (#188), which is the question the rest of
 * the map catalog was built to answer: given a canonical map name, what does
 * the hub know about it.
 *
 * Coilbox draws a battle lobby and a download screen for maps the player has
 * not installed, so it holds a name and nothing else. `/api/v1/assets/pictures`
 * turns that name into a minimap and this turns it into the facts.
 *
 * No picture comes back from here. Resolving a tier is `resolveAsset`'s job and
 * doing it in two routes would be two places to change when a tier moves, so a
 * client that wants both makes two calls, each with one job.
 *
 * Carries its own `format` and `version`, the way `/api/v1/items`,
 * `/api/v1/auth` and the other map routes already do. A shipped desktop build
 * sits on disk for months, so it reads those two fields first and can say the
 * service is newer than it understands rather than guessing at a shape that
 * changed under it.
 */
export const MAP_LOOKUP_FORMAT = "coilbox-hub-map-lookup";
export const MAP_LOOKUP_VERSION = 1;

/**
 * How many names one request may carry.
 *
 * Read from the vendored catalog rather than written out here, because the
 * number a client splits on and the number the hub enforces have to be the same
 * number or the client is told to make requests the hub refuses.
 * `lib/maps/catalog.ts` says more about why the file is the single copy of it.
 *
 * Over the limit is refused whole rather than truncated, the same as every
 * other batch route: a truncated answer reads as "the hub knows nothing about
 * the rest", and the caller draws its own fallback over maps the hub could have
 * described.
 */
export const MAP_LOOKUP_MAX_NAMES = MAP_CATALOG_CAPS.lookupNames;

/**
 * One person who made the map, as the hub files them rather than as this
 * archive spelled them.
 *
 * `key` is what an author page is addressed by and what "everything by this
 * person" is a lookup on. It comes back through `public.resolved_author_key`,
 * so two spellings a maintainer has merged answer with one key, and a caller
 * grouping maps by author gets the person rather than the spelling.
 *
 * `name` is the most common raw spelling among that author's maps, which is the
 * rule #183 set for the author's own page. It is not this archive's spelling.
 * An archive that credited `[BAR]Beherith` names the same person as one that
 * credited `Beherith`, and showing whichever spelling this particular archive
 * happened to carry would give one mapper a different name on every map.
 */
export interface MapLookupAuthor {
  key: string;
  name: string;
}

/**
 * What the hub knows about one map.
 *
 * The measurements are `public.map`'s own columns, so a client reads the same
 * numbers it or another client submitted. `tags` are `public.map_listing`'s,
 * merged and deduplicated, so a caller never has to know which were derived
 * from a measurement and which a maintainer wrote by hand.
 *
 * The points are the shape `lib/maps/facts.ts` defines for a submission, reused
 * rather than declared again. They are the same points, read back, and two
 * declarations of one shape would let the two part company the first time a
 * field was added to either.
 *
 * `appearance` passes through as it was stored. It is the water, sky, sun and
 * fog colours only a 3D view reads, and nothing here has an opinion about its
 * contents.
 */
export interface MapFacts {
  slug: string;
  display_name: string | null;
  description: string | null;
  authors: MapLookupAuthor[];
  width_elmos: number;
  height_elmos: number;
  world_height_min: number;
  world_height_max: number;
  min_wind: number | null;
  max_wind: number | null;
  tidal_strength: number | null;
  void_water: boolean | null;
  water_coverage: number | null;
  tags: string[];
  points: MapPoints;
  appearance: Record<string, unknown>;
}

/**
 * The name echoed back with its answer, in the shape it was sent and in request
 * order, so a caller zips by index rather than matching names back up.
 *
 * `map` is null for a name the hub knows nothing about. That is a 200 and it is
 * the ordinary answer for most names for a while: the catalog fills up as
 * clients submit, the caller's next move is its own fallback, and an error
 * would make the ordinary case look like a fault. The same answer, for the same
 * reason, that `/api/v1/assets/pictures` gives for a picture it does not hold.
 *
 * A map the hub holds and may not publish answers null as well, and the route
 * says why.
 */
export interface MapLookupResult {
  map_name: string;
  map: MapFacts | null;
}

export interface MapLookupBody {
  format: typeof MAP_LOOKUP_FORMAT;
  version: typeof MAP_LOOKUP_VERSION;
  results: MapLookupResult[];
}

export type ParsedMapLookupBody =
  | { ok: true; names: string[] }
  | { ok: false; error: string; status: number };

const BODY_FIELDS = ["names"] as const;

/** The length `public.map.map_name` accepts, so a name the table could never
 * hold is refused here rather than looked up and reported as unknown. */
const MAX_NAME_LENGTH = 256;

function unknownField(
  record: Record<string, unknown>,
  allowed: readonly string[],
): string | null {
  return Object.keys(record).find((field) => !allowed.includes(field)) ?? null;
}

/**
 * Same strictness as `parseMapHaveBody` and `parseAssetPicturesBody`. A client
 * that sent `map_names` instead of `names` and had it ignored would be told the
 * hub has never heard of anything, and would fall back to drawing a name on a
 * blank panel for a catalog that exists.
 *
 * The one failure that is not a 400 is a batch over the limit, which is a 413:
 * the request is well formed and the caller's fix is to split it, which is a
 * different thing to tell it than "that was malformed".
 *
 * ## A repeated name is not refused here, unlike on the have check
 *
 * The have check refuses one, because a name repeated there carries two sets of
 * facts and has no single right answer. Nothing is carried here but the name,
 * so a repeat asks one question twice and the answer is the same both times.
 *
 * The caller's list is a screen rather than an inventory. A lobby list names
 * the map each game is playing and several games play the same map, so
 * refusing the repeat would make every caller deduplicate its own list and put
 * the answers back against the rows it drew. The hub asks the database once and
 * answers every position, which is the same work for the hub and less for the
 * client.
 */
export function parseMapLookupBody(body: unknown): ParsedMapLookupBody {
  if (typeof body !== "object" || body === null || Array.isArray(body)) {
    return { ok: false, error: "The request body must be a JSON object.", status: 400 };
  }
  const record = body as Record<string, unknown>;

  const extra = unknownField(record, BODY_FIELDS);
  if (extra) {
    return { ok: false, error: `Unknown field: ${extra}`, status: 400 };
  }

  const raw = record.names;
  if (!Array.isArray(raw)) {
    return { ok: false, error: "`names` is required and must be an array.", status: 400 };
  }
  if (raw.length === 0) {
    return { ok: false, error: "`names` must not be empty.", status: 400 };
  }
  if (raw.length > MAP_LOOKUP_MAX_NAMES) {
    return {
      ok: false,
      error: `A batch may carry at most ${MAP_LOOKUP_MAX_NAMES} names. That request carried ${raw.length}. Split it.`,
      status: 413,
    };
  }

  const names: string[] = [];

  for (const [index, value] of raw.entries()) {
    // Held to the same length the column's check constraint is, measured after
    // trimming the way the constraint measures it. The untrimmed value is what
    // gets looked up, because `map_identity_idx` is on the stored text and
    // trimming here would ask about a different row.
    const length = typeof value === "string" ? value.trim().length : 0;
    if (typeof value !== "string" || length < 1 || length > MAX_NAME_LENGTH) {
      return {
        ok: false,
        error: `names[${index}] must be a string of 1 to ${MAX_NAME_LENGTH} characters.`,
        status: 400,
      };
    }
    names.push(value);
  }

  return { ok: true, names };
}

/**
 * The answer, one result per name the request listed and in that order,
 * including a name it listed twice.
 *
 * `found` is keyed on the name exactly as stored, which is the name that was
 * asked for: `map_identity_idx` is unique on the stored text, so a name that
 * matched a row matched it exactly.
 */
export function buildMapLookupBody(
  names: string[],
  found: Map<string, MapFacts>,
): MapLookupBody {
  return {
    format: MAP_LOOKUP_FORMAT,
    version: MAP_LOOKUP_VERSION,
    results: names.map((mapName) => ({
      map_name: mapName,
      map: found.get(mapName) ?? null,
    })),
  };
}
