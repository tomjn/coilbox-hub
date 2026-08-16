import {
  type AssetIdentity,
  type AssetTier,
  isMapVariant,
  MAP_VARIANTS,
  UNIT_BUILDPIC_VARIANT,
  UNIT_RENDER_VARIANT_PREFIX,
} from "@/lib/assets/asset";
import { identityKey } from "@/lib/assets/have";
import { assetTierUrl, type HeldAssets, resolveAsset } from "@/lib/assets/resolve";

/**
 * The wire shape of the batch picture lookup (issue #171), the question
 * `/api/v1/assets/have` is not: given an identity, what does the hub hold a
 * picture of, and where is it.
 *
 * Coilbox draws minimaps out of local archives, so a map the reader has not
 * installed leaves it with the map's name and nothing else. The hub has a
 * picture of that map, and `asset.path` comes off the sha256 of the encoded
 * bytes (`lib/assets/path.ts`) with a random suffix on top for anything still in
 * the staging tier (`lib/assets/blob.ts`). A caller that does not hold the bytes
 * cannot work either out, so it has to be told.
 *
 * Carries its own `format` and `version`, the way `/api/v1/items`,
 * `/api/v1/auth` and `/api/v1/assets/have` already do. A shipped desktop build
 * sits on disk for months, so it reads those two fields first and can say the
 * service is newer than it understands rather than guessing at a shape that
 * changed under it.
 */
export const ASSET_PICTURES_FORMAT = "coilbox-hub-asset-pictures";
export const ASSET_PICTURES_VERSION = 1;

/**
 * How many keys one request may carry, the same ceiling `/api/v1/assets/have`
 * sets and for the same reason: a batch with no limit is a way to make the
 * hub's own database do unbounded work on request, and this one is reachable
 * without an account at all.
 *
 * Its own constant rather than the have check's, because the two routes answer
 * different questions and either could move without the other. The number is
 * the same today, which is what lets coilbox's existing splitting code speak to
 * both.
 *
 * Over the limit is refused whole rather than truncated. A truncated answer
 * reads as "the hub has no picture of the rest", and the caller draws a
 * placeholder over pictures that exist.
 */
export const ASSET_PICTURES_MAX_KEYS = 500;

/**
 * Where one picture is, and how big it is.
 *
 * `tier` and `path` are the answer. `path` is tier relative, exactly as the row
 * stores it, so a client joins it to whichever base it is configured with.
 * `url` is the hub joining it to its own, for a caller that has not got a copy
 * of the two tier bases.
 *
 * `served_variant` and `substituted` are here because `resolveAsset` stands a
 * unit's buildpic in for a missing `render:<angle>`. A caller that assumed it
 * got the angle it asked for would caption a head-on icon as a view from
 * behind. `substituted` is derivable from the two variants and is sent anyway,
 * for the reason `ServedAsset` sends it: a caller that has to remember to
 * compare will not.
 */
export interface AssetPicture {
  tier: AssetTier;
  path: string;
  url: string;
  /** The encoded image in pixels, off the row, so an `<img>` can carry its own
   *  dimensions and not shift the page when it loads. */
  width: number;
  height: number;
  served_variant: string;
  substituted: boolean;
}

/**
 * The key echoed back with its answer, in the shape it was sent, and in request
 * order, so a caller can zip by index rather than reassembling a key to read
 * the reply. The same shape `/api/v1/assets/have` answers in, minus the
 * `source_hash` this route never asked for.
 *
 * `picture` is null for an identity the hub holds nothing approved for. That is
 * a 200 and the ordinary answer for most identities: the caller has its own
 * fallback, and an error would make the normal case look like a fault.
 */
export type AssetPictureResult =
  | {
      keyed_on: "unit";
      game: string;
      unit_name: string;
      variant: string;
      picture: AssetPicture | null;
    }
  | { keyed_on: "map"; map_name: string; variant: string; picture: AssetPicture | null };

export interface AssetPicturesBody {
  format: typeof ASSET_PICTURES_FORMAT;
  version: typeof ASSET_PICTURES_VERSION;
  results: AssetPictureResult[];
}

export type ParsedAssetPicturesBody =
  | { ok: true; identities: AssetIdentity[] }
  | { ok: false; error: string; status: number };

const BODY_FIELDS = ["keys"] as const;
const UNIT_FIELDS = ["keyed_on", "game", "unit_name", "variant"] as const;
const MAP_FIELDS = ["keyed_on", "map_name", "variant"] as const;

/** The lengths `public.asset` accepts, so a key the table could never hold is
 * refused here rather than looked up and reported as no picture. */
const MAX_LENGTHS = {
  game: 64,
  unit_name: 128,
  map_name: 256,
  variant: 64,
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
 * One key.
 *
 * The same vocabulary `parseAssetHaveBody` reads, deliberately, so a client
 * that can build a key for one route can build one for the other: the two
 * identity shapes are read separately rather than through a single lenient
 * object, `keyed_on` is the caller saying which one it means, and a variant
 * outside the table's own list is refused rather than looked up. The one
 * difference is `source_hash`, which is absent here and is the point of the
 * route: a caller asking about a map it has not installed does not hold the
 * bytes and cannot hash them. `assetPictures.test.ts` holds the two parsers
 * against each other so they cannot drift apart.
 */
function parseKey(
  value: unknown,
): { ok: true; identity: AssetIdentity } | { ok: false; error: string } {
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

  if (keyedOn === "map") {
    const mapName = readField(record, "map_name");
    if (!mapName.ok) return mapName;

    // The same closed list as `asset_map_variant_check`, off `lib/assets/asset.ts`.
    if (!isMapVariant(variant.value)) {
      return {
        ok: false,
        error: `\`variant\` on a map must be one of ${MAP_VARIANTS.join(", ")}.`,
      };
    }

    return {
      ok: true,
      identity: { keyedOn: "map", mapName: mapName.value, variant: variant.value },
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
    identity: {
      keyedOn: "unit",
      game: game.value,
      unitName: unitName.value,
      variant: variant.value,
    },
  };
}

/**
 * Same strictness as `parseAssetHaveBody`, `parseApiFilters` and
 * `parsePublishBody`. A client that sent `mapName` instead of `map_name` and
 * had it ignored would be told the hub has no picture of anything and would
 * draw placeholders over a corpus that exists, so an unknown field is a 400 on
 * the body and on every key in it.
 *
 * The one failure that is not a 400 is a batch over the limit, which is a 413:
 * the request is well formed and the caller's fix is to split it, which is a
 * different thing to tell it than "that was malformed".
 */
export function parseAssetPicturesBody(body: unknown): ParsedAssetPicturesBody {
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
  if (raw.length > ASSET_PICTURES_MAX_KEYS) {
    return {
      ok: false,
      error: `A batch may carry at most ${ASSET_PICTURES_MAX_KEYS} keys. That request carried ${raw.length}. Split it.`,
      status: 413,
    };
  }

  const identities: AssetIdentity[] = [];
  const seen = new Set<string>();

  for (const [index, value] of raw.entries()) {
    const parsed = parseKey(value);
    if (!parsed.ok) {
      return { ok: false, error: `keys[${index}] ${parsed.error}`, status: 400 };
    }

    // A repeated key spends batch room on an answer the caller already has, and
    // the same refusal the have check makes keeps one vocabulary across both.
    const seenKey = identityKey(parsed.identity);
    if (seen.has(seenKey)) {
      return {
        ok: false,
        error: `keys[${index}] repeats a key already in the batch.`,
        status: 400,
      };
    }
    seen.add(seenKey);
    identities.push(parsed.identity);
  }

  return { ok: true, identities };
}

/**
 * The picture for one identity, or null when the hub holds none it may show.
 *
 * Which rung answers is `resolveAsset`'s decision, not this file's, so the
 * buildpic substitution and the moderation check are the website's and this
 * route's one behaviour rather than two.
 *
 * The row is read back for `path`, which `ServedAsset` does not carry: it
 * hands out a URL, and this route's caller joins its own base. It cannot miss,
 * because `resolveAsset` only ever serves an identity it found a row for, and a
 * null if it somehow did is the same "no picture" the caller already handles.
 */
function pictureFor(identity: AssetIdentity, held: HeldAssets): AssetPicture | null {
  const resolved = resolveAsset(identity, held);
  if (resolved.from === "placeholder") return null;

  const row = held.get(identityKey(resolved.served));
  if (!row) return null;

  return {
    tier: row.tier,
    path: row.path,
    url: assetTierUrl(row.tier, row.path),
    width: resolved.width,
    height: resolved.height,
    served_variant: resolved.served.variant,
    substituted: resolved.substituted,
  };
}

export function buildAssetPicturesBody(
  identities: AssetIdentity[],
  held: HeldAssets,
): AssetPicturesBody {
  return {
    format: ASSET_PICTURES_FORMAT,
    version: ASSET_PICTURES_VERSION,
    results: identities.map((identity) => {
      const picture = pictureFor(identity, held);
      return identity.keyedOn === "unit"
        ? {
            keyed_on: "unit" as const,
            game: identity.game,
            unit_name: identity.unitName,
            variant: identity.variant,
            picture,
          }
        : {
            keyed_on: "map" as const,
            map_name: identity.mapName,
            variant: identity.variant,
            picture,
          };
    }),
  };
}
