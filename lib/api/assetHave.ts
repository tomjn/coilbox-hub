import {
  type AssetIdentity,
  UNIT_BUILDPIC_VARIANT,
  UNIT_RENDER_VARIANT_PREFIX,
} from "@/lib/assets/asset";
import { identityKey } from "@/lib/assets/have";

/**
 * The wire shape of the batch have check (issue #103), the first of the four
 * asset routes. Coilbox sends identity keys with the `source_hash` it holds for
 * each, and gets back which of them the hub still wants.
 *
 * Carries its own `format` and `version`, the way `/api/v1/items` and
 * `/api/v1/auth` already do. A shipped desktop build sits on disk for months, so
 * it reads those two fields first and can say the service is newer than it
 * understands rather than guessing at a shape that changed under it.
 */
export const ASSET_HAVE_FORMAT = "coilbox-hub-asset-have";
export const ASSET_HAVE_VERSION = 1;

/**
 * How many keys one request may carry.
 *
 * A batch check with no ceiling is a way to make the hub's own database do
 * unbounded work on request, and it is reachable by anybody who can sign in.
 * Five hundred covers a whole game's buildpics in one round trip, which is the
 * shape of the real call, and a client with more than that pages through them.
 * Over the limit is refused whole rather than truncated: a truncated answer
 * reads as "the hub does not have the rest", and the caller uploads them again.
 */
export const ASSET_HAVE_MAX_KEYS = 500;

/**
 * What the hub wants the caller to do with each key.
 *
 * - `have`: a row exists for this identity carrying this `source_hash`. Do
 *   nothing. Encode nothing, upload nothing.
 * - `changed`: a row exists for this identity and its `source_hash` differs, so
 *   the archive these bytes came from is not the archive the hub was given.
 *   Encode and upload, and the row is replaced.
 * - `missing`: no row for this identity at all. Render or extract, encode, and
 *   upload.
 *
 * The issue asks for "missing or changed" and this splits the two, because the
 * work either one implies is different: a changed asset is already extracted
 * and only needs re-encoding, and a missing render has to be drawn first.
 */
export type AssetHaveStatus = "have" | "changed" | "missing";

/**
 * The key echoed back with its answer, in the shape it was sent. Results are in
 * request order as well, so a caller can zip by index and does not have to
 * reassemble a key to read the reply.
 */
export type AssetHaveResult =
  | {
      keyed_on: "unit";
      game: string;
      unit_name: string;
      variant: string;
      status: AssetHaveStatus;
    }
  | { keyed_on: "map"; map_name: string; variant: string; status: AssetHaveStatus };

export interface AssetHaveBody {
  format: typeof ASSET_HAVE_FORMAT;
  version: typeof ASSET_HAVE_VERSION;
  results: AssetHaveResult[];
}

/** One requested key: which asset, and the `source_hash` the caller holds. */
export interface AssetHaveKey {
  identity: AssetIdentity;
  sourceHash: string;
}

export type ParsedAssetHaveBody =
  | { ok: true; keys: AssetHaveKey[] }
  | { ok: false; error: string; status: number };

const BODY_FIELDS = ["keys"] as const;
const UNIT_FIELDS = ["keyed_on", "game", "unit_name", "variant", "source_hash"] as const;
const MAP_FIELDS = ["keyed_on", "map_name", "variant", "source_hash"] as const;

/** The lengths `public.asset` accepts, so a key the table could never hold is
 * refused here rather than looked up and reported as missing. */
const MAX_LENGTHS = {
  game: 64,
  unit_name: 128,
  map_name: 256,
  variant: 64,
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
 * A required string field, held to the same length the table's check
 * constraints are. Measured after trimming, the way the constraints measure it,
 * but the untrimmed value is what gets looked up: the unique indexes are on the
 * stored text, so trimming here would ask about a different row.
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
 * One key. The two identity shapes are read separately rather than through a
 * single lenient object, so a request that names a game alongside a map, or a
 * map alongside a unit, is a 400 and not a lookup for something that cannot
 * exist. `keyed_on` is the caller saying which key it means, matching
 * `AssetIdentity`.
 */
function parseKey(value: unknown): { ok: true; key: AssetHaveKey } | { ok: false; error: string } {
  if (typeof value !== "object" || value === null || Array.isArray(value)) {
    return { ok: false, error: "must be a JSON object." };
  }
  const record = value as Record<string, unknown>;

  const keyedOn = record.keyed_on;
  if (keyedOn !== "unit" && keyedOn !== "map") {
    return { ok: false, error: '`keyed_on` must be "unit" or "map".' };
  }

  const extra = unknownField(record, keyedOn === "unit" ? UNIT_FIELDS : MAP_FIELDS);
  if (extra) {
    return { ok: false, error: `unknown field: ${extra}` };
  }

  const variant = readField(record, "variant");
  if (!variant.ok) return variant;

  const sourceHash = readField(record, "source_hash");
  if (!sourceHash.ok) return sourceHash;

  if (keyedOn === "map") {
    const mapName = readField(record, "map_name");
    if (!mapName.ok) return mapName;

    return {
      ok: true,
      key: {
        identity: { keyedOn: "map", mapName: mapName.value, variant: variant.value },
        sourceHash: sourceHash.value,
      },
    };
  }

  const game = readField(record, "game");
  if (!game.ok) return game;

  const unitName = readField(record, "unit_name");
  if (!unitName.ok) return unitName;

  // The same rule as `asset_unit_variant_check`, and no stricter. An angle is
  // open ended, so `render:` with anything after it is the table's rule and
  // narrowing it here would refuse a key that names a real row.
  if (
    variant.value !== UNIT_BUILDPIC_VARIANT &&
    !variant.value.startsWith(UNIT_RENDER_VARIANT_PREFIX)
  ) {
    return {
      ok: false,
      error: `\`variant\` on a unit must be "${UNIT_BUILDPIC_VARIANT}" or "${UNIT_RENDER_VARIANT_PREFIX}<angle>".`,
    };
  }

  return {
    ok: true,
    key: {
      identity: {
        keyedOn: "unit",
        game: game.value,
        unitName: unitName.value,
        variant: variant.value,
      },
      sourceHash: sourceHash.value,
    },
  };
}

/**
 * Same strictness as `parseApiFilters` and `parsePublishBody`, and for the same
 * reason: a client that sent `sourceHash` instead of `source_hash` and had it
 * ignored would be told the hub is missing its whole corpus and would upload it
 * all again. A batch makes that worse rather than better, so unknown fields are
 * a 400 both on the body and on every key in it.
 *
 * The one failure that is not a 400 is a batch over the limit, which is a 413:
 * the request is well formed and the caller's fix is to split it, which is a
 * different thing to tell it than "that was malformed".
 */
export function parseAssetHaveBody(body: unknown): ParsedAssetHaveBody {
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
  if (raw.length > ASSET_HAVE_MAX_KEYS) {
    return {
      ok: false,
      error: `A batch may carry at most ${ASSET_HAVE_MAX_KEYS} keys. That request carried ${raw.length}. Split it.`,
      status: 413,
    };
  }

  const keys: AssetHaveKey[] = [];
  const seen = new Set<string>();

  for (const [index, value] of raw.entries()) {
    const parsed = parseKey(value);
    if (!parsed.ok) {
      return { ok: false, error: `keys[${index}] ${parsed.error}`, status: 400 };
    }

    // A repeated key with two different hashes has no single right answer, and
    // a repeated key with the same hash is a client bug spending batch room.
    const seenKey = identityKey(parsed.key.identity);
    if (seen.has(seenKey)) {
      return {
        ok: false,
        error: `keys[${index}] repeats a key already in the batch.`,
        status: 400,
      };
    }
    seen.add(seenKey);
    keys.push(parsed.key);
  }

  return { ok: true, keys };
}

/**
 * The answer for one key, given what the hub holds. Absent from the lookup means
 * no row at all, which is `missing`. Present and equal is `have`, and present
 * and different is `changed`.
 *
 * Moderation state is deliberately not part of this. A pending row and an
 * approved row both mean "the hub already has these bytes, do not send them
 * again", and a rejected one means it has seen them and said no, so all three
 * answer the caller's question the same way. Saying which would tell any signed
 * in account what is sitting in the moderation queue, for a distinction that
 * changes nothing the caller does.
 */
function resolveStatus(
  key: AssetHaveKey,
  sourceHashes: Map<string, string>,
): AssetHaveStatus {
  const stored = sourceHashes.get(identityKey(key.identity));
  if (stored === undefined) return "missing";
  return stored === key.sourceHash ? "have" : "changed";
}

export function buildAssetHaveBody(
  keys: AssetHaveKey[],
  sourceHashes: Map<string, string>,
): AssetHaveBody {
  return {
    format: ASSET_HAVE_FORMAT,
    version: ASSET_HAVE_VERSION,
    results: keys.map((key) => {
      const status = resolveStatus(key, sourceHashes);
      return key.identity.keyedOn === "unit"
        ? {
            keyed_on: "unit" as const,
            game: key.identity.game,
            unit_name: key.identity.unitName,
            variant: key.identity.variant,
            status,
          }
        : {
            keyed_on: "map" as const,
            map_name: key.identity.mapName,
            variant: key.identity.variant,
            status,
          };
    }),
  };
}
