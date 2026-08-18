import { MAP_CATALOG_CAPS } from "@/lib/maps/catalog";
import type { MapCatalogState } from "@/lib/maps/have";

/**
 * The wire shape of the map have check (#186). A client sends the facts it holds
 * for each map it has, and gets back which of them the hub still wants.
 *
 * The picture side of this is `lib/api/assetHave.ts` and this is deliberately its
 * twin: same envelope, same three statuses, same cap, results in request order so
 * a caller zips by index. What is not the same is the rule that picks a status,
 * and that difference is the substance of the issue. See {@link resolveStatus}.
 *
 * Carries its own `format` and `version`, the way `/api/v1/items` and
 * `/api/v1/auth` already do. A shipped desktop build sits on disk for months, so
 * it reads those two fields first and can say the service is newer than it
 * understands rather than guessing at a shape that changed under it.
 */
export const MAP_HAVE_FORMAT = "coilbox-hub-map-have";
export const MAP_HAVE_VERSION = 1;

/**
 * How many keys one request may carry.
 *
 * Read from the vendored catalog rather than written out here, because the number
 * a client splits on and the number the hub enforces have to be the same number
 * or the client is told to make requests the hub refuses. `lib/maps/catalog.ts`
 * says more about why the file is the single copy of it.
 *
 * An install with three thousand maps therefore pages through six requests. Over
 * the limit is refused whole rather than truncated, the same as the asset check:
 * a truncated answer reads as "the hub does not have the rest", and the caller
 * submits them again.
 */
export const MAP_HAVE_MAX_KEYS = MAP_CATALOG_CAPS.haveKeys;

/**
 * What the hub wants the caller to do with each key.
 *
 * - `have`: the hub holds these facts, from this archive, extracted by this
 *   catalog version or a later one. Send nothing.
 * - `changed`: the hub holds something older or different. Send it, and let the
 *   submission route decide what that means.
 * - `missing`: the hub holds nothing under that name at all. Send it.
 *
 * The same three words the asset check uses, so a client has one vocabulary
 * rather than two. `changed` and `missing` both mean "send it" here, unlike on
 * the asset side where the work each implies is different, and they stay split
 * because the caller's own reporting is the better for knowing which: a hub full
 * of `changed` after a coilbox release is a catalog upgrade landing, and a hub
 * full of `missing` is a corpus nobody has submitted yet.
 */
export type MapHaveStatus = "have" | "changed" | "missing";

/**
 * The answer for one key: the name it was asked under, and what to do.
 *
 * Nothing about what the hub holds. Not the stored `source_hash`, not the stored
 * `catalog_version`, not whether the row was seeded or submitted. Two reasons,
 * and neither is confidentiality, because `public.map` grants select to `anon`
 * and anybody can read the whole row from `/api/v1/maps/lookup`.
 *
 * The first is that the caller has no decision left that the number would change.
 * It sends or it does not, and `status` is that answer whole.
 *
 * The second is that the stored `catalog_version` is a number a client could aim
 * at. Handing it back invites a client to declare the version the hub is holding
 * out for rather than the version its own extraction actually ran, and then the
 * hub's own state decides what clients report about archives. The version has to
 * mean "this is the code that read the archive" for the comparison below to mean
 * anything at all, so the hub does not put a target on it.
 */
export interface MapHaveResult {
  map_name: string;
  status: MapHaveStatus;
}

export interface MapHaveBody {
  format: typeof MAP_HAVE_FORMAT;
  version: typeof MAP_HAVE_VERSION;
  results: MapHaveResult[];
}

/** One requested key: which map, which archive it came from, and which
 * extraction read it. */
export interface MapHaveKey {
  mapName: string;
  sourceHash: string;
  catalogVersion: number;
}

export type ParsedMapHaveBody =
  | { ok: true; keys: MapHaveKey[] }
  | { ok: false; error: string; status: number };

const BODY_FIELDS = ["keys"] as const;
const KEY_FIELDS = ["map_name", "source_hash", "catalog_version"] as const;

/** The lengths `public.map` accepts, so a key the table could never hold is
 * refused here rather than looked up and reported as missing. */
const MAX_LENGTHS = {
  map_name: 256,
  source_hash: 128,
} as const;

type Field = keyof typeof MAX_LENGTHS;

function unknownField(
  record: Record<string, unknown>,
  allowed: readonly string[],
): string | null {
  return Object.keys(record).find((field) => !allowed.includes(field)) ?? null;
}

/**
 * A required string field, held to the same length the table's check constraints
 * are. Measured after trimming, the way the constraints measure it, but the
 * untrimmed value is what gets looked up: `map_identity_idx` is on the stored
 * text, so trimming here would ask about a different row.
 */
function readField(
  record: Record<string, unknown>,
  field: Field,
): { ok: true; value: string } | { ok: false; error: string } {
  const value = record[field];
  const length = typeof value === "string" ? value.trim().length : 0;
  if (typeof value !== "string" || length < 1 || length > MAX_LENGTHS[field]) {
    return {
      ok: false,
      error: `\`${field}\` is required and must be a string of 1 to ${MAX_LENGTHS[field]} characters.`,
    };
  }
  return { ok: true, value };
}

/**
 * One key.
 *
 * `catalog_version` is required and is held to the same rule the column's own
 * check is, a positive integer. It is not optional and it does not default. A key
 * without one cannot be answered: every status below turns on comparing it, and a
 * missing value would have to be read as either "as old as possible", which
 * reports the hub's whole corpus as `have` and loses every improvement, or "as
 * new as possible", which reports it all as `changed` and asks for every map
 * back. Both
 * are wrong in a way nothing downstream can see, so it is a refusal.
 *
 * A float is refused rather than rounded for the same reason a version string
 * would be. `catalog_version` names a release of the extraction code, and 3.5
 * names none of them, so a client sending one does not mean what it is saying and
 * the answer would be built on a guess about which side of 3 it meant.
 */
function parseKey(value: unknown): { ok: true; key: MapHaveKey } | { ok: false; error: string } {
  if (typeof value !== "object" || value === null || Array.isArray(value)) {
    return { ok: false, error: "must be a JSON object." };
  }
  const record = value as Record<string, unknown>;

  const extra = unknownField(record, KEY_FIELDS);
  if (extra) {
    return { ok: false, error: `unknown field: ${extra}` };
  }

  const mapName = readField(record, "map_name");
  if (!mapName.ok) return mapName;

  const sourceHash = readField(record, "source_hash");
  if (!sourceHash.ok) return sourceHash;

  const catalogVersion = record.catalog_version;
  if (!Number.isInteger(catalogVersion) || (catalogVersion as number) < 1) {
    return {
      ok: false,
      error: "`catalog_version` is required and must be an integer of 1 or more.",
    };
  }

  return {
    ok: true,
    key: {
      mapName: mapName.value,
      sourceHash: sourceHash.value,
      catalogVersion: catalogVersion as number,
    },
  };
}

/**
 * Same strictness as `parseAssetHaveBody`, and for the same reason: a client that
 * sent `sourceHash` instead of `source_hash` and had it ignored would be told the
 * hub is missing its whole corpus and would submit it all again. A batch makes
 * that worse rather than better, so unknown fields are a 400 both on the body and
 * on every key in it.
 *
 * The one failure that is not a 400 is a batch over the limit, which is a 413: the
 * request is well formed and the caller's fix is to split it, which is a different
 * thing to tell it than "that was malformed".
 */
export function parseMapHaveBody(body: unknown): ParsedMapHaveBody {
  if (typeof body !== "object" || body === null || Array.isArray(body)) {
    return { ok: false, error: "The request body must be a JSON object.", status: 400 };
  }
  const record = body as Record<string, unknown>;

  const extra = unknownField(record, BODY_FIELDS);
  if (extra) {
    return { ok: false, error: `Unknown field: ${extra}`, status: 400 };
  }

  const raw = record.keys;
  if (!Array.isArray(raw)) {
    return { ok: false, error: "`keys` is required and must be an array.", status: 400 };
  }
  if (raw.length === 0) {
    return { ok: false, error: "`keys` must not be empty.", status: 400 };
  }
  if (raw.length > MAP_HAVE_MAX_KEYS) {
    return {
      ok: false,
      error: `A batch may carry at most ${MAP_HAVE_MAX_KEYS} keys. That request carried ${raw.length}. Split it.`,
      status: 413,
    };
  }

  const keys: MapHaveKey[] = [];
  const seen = new Set<string>();

  for (const [index, value] of raw.entries()) {
    const parsed = parseKey(value);
    if (!parsed.ok) {
      return { ok: false, error: `keys[${index}] ${parsed.error}`, status: 400 };
    }

    // A repeated name with two different sets of facts has no single right
    // answer, and a repeated name with the same facts is a client bug spending
    // batch room. One canonical name is one archive, permanently, which is the
    // identity rule the migration sets out, so a batch naming one twice is a
    // client that has read its own map list wrong.
    if (seen.has(parsed.key.mapName)) {
      return {
        ok: false,
        error: `keys[${index}] repeats a key already in the batch.`,
        status: 400,
      };
    }
    seen.add(parsed.key.mapName);
    keys.push(parsed.key);
  }

  return { ok: true, keys };
}

/**
 * The answer for one key, given what the hub holds.
 *
 * Absent from the lookup means no row under that name, which is `missing`.
 *
 * A different `source_hash` is `changed` whatever the versions say. The hash is
 * over the raw archive bytes, so a different hash is a different archive, and no
 * comparison of extraction versions applies across two archives. A newer
 * extraction of an older archive is not an improvement on the map the hub is
 * holding, it is facts about a different map that happens to share a name.
 *
 * The same `source_hash` is where this parts company with the asset check, which
 * stops at the hash and calls it `have`. Facts have a second axis. The same
 * archive read by a newer coilbox produces a better entry - more metal spots, a
 * field the old extractor ignored - and the hub should take it, so a client above
 * the stored version hears `changed`.
 *
 * Below the stored version it hears `have`. The row already came from a better
 * read of the same bytes, and an old build that heard `changed` would submit its
 * poorer entry and talk the catalog backwards, which is the one failure this rule
 * exists to prevent. An old build is being honest and enthusiastic, and the hub
 * declines politely.
 *
 * Equal versions are `have`, which is the ordinary case and the reason the whole
 * route exists: three thousand maps and almost all of them already known.
 */
function resolveStatus(key: MapHaveKey, held: Map<string, MapCatalogState>): MapHaveStatus {
  const stored = held.get(key.mapName);
  if (stored === undefined) return "missing";
  if (stored.sourceHash !== key.sourceHash) return "changed";
  return key.catalogVersion > stored.catalogVersion ? "changed" : "have";
}

export function buildMapHaveBody(
  keys: MapHaveKey[],
  held: Map<string, MapCatalogState>,
): MapHaveBody {
  return {
    format: MAP_HAVE_FORMAT,
    version: MAP_HAVE_VERSION,
    results: keys.map((key) => ({
      map_name: key.mapName,
      status: resolveStatus(key, held),
    })),
  };
}
