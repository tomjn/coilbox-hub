import {
  ASSET_ORIGINS,
  type AssetOrigin,
  MAP_HEIGHT_OVERLAY_VARIANT,
  MAP_VARIANTS,
  UNIT_BUILDPIC_VARIANT,
  UNIT_RENDER_VARIANT_PREFIX,
  isMapVariant,
} from "@/lib/assets/asset";
import type { AssetUploadDeclaration } from "@/lib/assets/upload";

/**
 * The wire shape of an upload declaration (issue #104): the JSON part of the
 * multipart body posted to `POST /api/v1/assets/upload`, which since #133 is
 * the only way to upload.
 *
 * Carries `format` and `version` on the reply the way `/api/v1/assets/have`
 * does. A shipped desktop build sits on disk for months, so it reads those two
 * first and can say the service is newer than it understands rather than
 * guessing at a shape that changed under it.
 */
export const ASSET_UPLOAD_FORMAT = "coilbox-hub-asset-upload";
export const ASSET_UPLOAD_VERSION = 1;

/**
 * What the hub decided to do with the bytes.
 *
 * Deliberately does not say where they went. The staging tier is public, so a
 * pending upload's path is its URL, and the queue's whole authority is that the
 * hub does not hand that out before a reviewer has seen it (#131). Replying
 * with it would hand it to the one party the queue is holding the picture back
 * from, since the uploader is who a modified client is. The path is on the row
 * and reaches the caller when the row is approved and resolvable.
 */
export interface AssetUploadBody {
  format: typeof ASSET_UPLOAD_FORMAT;
  version: typeof ASSET_UPLOAD_VERSION;
  /** Always `pending` today. Nothing on this path can approve a row. */
  moderation: "pending";
}

export type ParsedAssetUpload =
  | { ok: true; declaration: AssetUploadDeclaration }
  | { ok: false; error: string };

/**
 * `width` and `height` are deliberately absent (#105). The hub reads the pixel
 * dimensions out of the image header, so a declared pair could only ever agree
 * with the bytes or be wrong, and the unknown field rule below turns a client
 * that still sends them into a 400 that names the field rather than a silently
 * ignored claim.
 *
 * `hash` is absent for the same reason and a sharper one (#154). It is over the
 * encoded bytes, which are in this request, so the hub computes it in
 * `lib/assets/hash.ts` and a declared value could only agree or be wrong. The
 * sharper reason is what a wrong one buys: `hash` is the leaf of the content
 * addressed path, so a client that could declare it could choose which picture
 * promotion overwrites in a permanent public history.
 *
 * Dropped rather than kept and refused on mismatch. Refusing would be a second
 * rule saying what the hub already knows on its own, and it would tie every
 * shipped desktop build to the hub's exact spelling of a digest for no gain,
 * since the hub's own value is the one that gets used either way. What a broken
 * client needs is to be told, and the unknown field rule below does that
 * already: it answers 400 naming `hash`, on the first upload, rather than
 * accepting a request whose declaration disagrees with its own bytes.
 *
 * `source_hash` stays, and the difference is not an inconsistency. It is over
 * the raw archive bytes, which never reach the hub, so there is nothing here to
 * compute it from and it remains the client's word. It also names no object and
 * decides no path.
 */
const COMMON_FIELDS = [
  "keyed_on",
  "variant",
  "source_hash",
  "encode_profile",
  "origin",
  "mime",
  "bytes",
  "source_archive",
] as const;

const UNIT_FIELDS = [...COMMON_FIELDS, "game", "unit_name"] as const;
const MAP_FIELDS = [
  ...COMMON_FIELDS,
  "map_name",
  "map_width",
  "map_height",
  "world_height_min",
  "world_height_max",
] as const;

/** The lengths `public.asset` accepts, so a declaration the table could never
 * hold is refused here rather than after an advanced operation has been spent
 * on it. */
const MAX_LENGTHS = {
  game: 64,
  unit_name: 128,
  map_name: 256,
  variant: 64,
  source_hash: 128,
  encode_profile: 64,
  mime: 128,
  source_archive: 256,
} as const;

type TextField = keyof typeof MAX_LENGTHS;

function unknownField(
  record: Record<string, unknown>,
  allowed: readonly string[],
): string | null {
  return Object.keys(record).find((field) => !allowed.includes(field)) ?? null;
}

/**
 * A required string field, held to the same length the table's check
 * constraints are. Measured after trimming, the way the constraints measure it,
 * and the untrimmed value is what gets stored and looked up, matching
 * `parseAssetHaveBody`.
 */
function readText(
  record: Record<string, unknown>,
  field: TextField,
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
 * A required positive integer. The table has `check (x > 0)` on every one of
 * these, so a float or a zero is a constraint violation after the write rather
 * than a 400 before it.
 */
function readCount(
  record: Record<string, unknown>,
  field: string,
): { ok: true; value: number } | { ok: false; error: string } {
  const value = record[field];
  if (typeof value !== "number" || !Number.isSafeInteger(value) || value < 1) {
    return { ok: false, error: `\`${field}\` is required and must be a positive integer.` };
  }
  return { ok: true, value };
}

/**
 * A required finite number, which is what a world height is and what a count is
 * not: terrain below sea level is negative, a flat map's range is zero wide, and
 * the archive stores both ends as floats.
 */
function readMeasure(
  record: Record<string, unknown>,
  field: string,
): { ok: true; value: number } | { ok: false; error: string } {
  const value = record[field];
  if (typeof value !== "number" || !Number.isFinite(value)) {
    return { ok: false, error: `\`${field}\` is required and must be a number.` };
  }
  return { ok: true, value };
}

function isOrigin(value: unknown): value is AssetOrigin {
  return typeof value === "string" && (ASSET_ORIGINS as readonly string[]).includes(value);
}

/**
 * The two ends of a height overlay's world range, in elmos.
 *
 * Required on `overlay:height` and refused on everything else, which is the
 * table's rule too. The layer is a grayscale ramp with a linear mapping, so
 * these two numbers are the whole of what turns a sample back into a height,
 * and only the archive has them: an overlay stored without them is a picture of
 * a heightmap rather than a heightmap.
 */
function readHeightRange(
  record: Record<string, unknown>,
  variant: string,
):
  | { ok: true; value: { worldHeightMin: number | null; worldHeightMax: number | null } }
  | { ok: false; error: string } {
  const declaresRange =
    record.world_height_min !== undefined || record.world_height_max !== undefined;

  if (variant !== MAP_HEIGHT_OVERLAY_VARIANT) {
    return declaresRange
      ? {
          ok: false,
          error: `\`world_height_min\` and \`world_height_max\` belong to "${MAP_HEIGHT_OVERLAY_VARIANT}" and to nothing else.`,
        }
      : { ok: true, value: { worldHeightMin: null, worldHeightMax: null } };
  }

  const min = readMeasure(record, "world_height_min");
  if (!min.ok) return min;

  const max = readMeasure(record, "world_height_max");
  if (!max.ok) return max;

  if (max.value < min.value) {
    return { ok: false, error: "`world_height_max` cannot be below `world_height_min`." };
  }

  return { ok: true, value: { worldHeightMin: min.value, worldHeightMax: max.value } };
}

/**
 * One declaration.
 *
 * Strict about unknown fields for the same reason `parseAssetHaveBody` is: a
 * client that sent `sourceHash` instead of `source_hash` and had it ignored
 * would get a row that dedupes against nothing and re-uploads on every run,
 * against an allowance of 2,000 a month.
 *
 * The two identity shapes are read separately, so a declaration naming a game
 * alongside a map is a 400 rather than a row that fails the table's identity
 * check after the bytes are already in the store.
 */
export function parseAssetUpload(value: unknown): ParsedAssetUpload {
  if (typeof value !== "object" || value === null || Array.isArray(value)) {
    return { ok: false, error: "The asset declaration must be a JSON object." };
  }
  const record = value as Record<string, unknown>;

  const keyedOn = record.keyed_on;
  if (keyedOn !== "unit" && keyedOn !== "map") {
    return { ok: false, error: '`keyed_on` must be "unit" or "map".' };
  }

  const extra = unknownField(record, keyedOn === "unit" ? UNIT_FIELDS : MAP_FIELDS);
  if (extra) {
    return { ok: false, error: `Unknown field: ${extra}` };
  }

  const variant = readText(record, "variant");
  if (!variant.ok) return variant;

  const sourceHash = readText(record, "source_hash");
  if (!sourceHash.ok) return sourceHash;

  const encodeProfile = readText(record, "encode_profile");
  if (!encodeProfile.ok) return encodeProfile;

  const mime = readText(record, "mime");
  if (!mime.ok) return mime;

  const sourceArchive = readText(record, "source_archive");
  if (!sourceArchive.ok) return sourceArchive;

  if (!isOrigin(record.origin)) {
    return { ok: false, error: `\`origin\` must be one of ${ASSET_ORIGINS.join(", ")}.` };
  }

  const bytes = readCount(record, "bytes");
  if (!bytes.ok) return bytes;

  const common = {
    sourceHash: sourceHash.value,
    encodeProfile: encodeProfile.value,
    origin: record.origin,
    mime: mime.value,
    bytes: bytes.value,
    sourceArchive: sourceArchive.value,
  };

  if (keyedOn === "map") {
    const mapName = readText(record, "map_name");
    if (!mapName.ok) return mapName;

    // The same rule as `asset_map_variant_check`. A closed list, unlike the
    // unit side, because none of the four is open ended the way a render angle
    // is, and a typo mints an identity nothing ever asks for.
    if (!isMapVariant(variant.value)) {
      return {
        ok: false,
        error: `\`variant\` on a map must be one of ${MAP_VARIANTS.join(", ")}.`,
      };
    }

    // Mandatory on a map row rather than merely available. Nothing downstream
    // of extraction can recover the world size, and without it every overlay
    // is subtly misaligned with a cause that is hard to isolate.
    const mapWidth = readCount(record, "map_width");
    if (!mapWidth.ok) return mapWidth;

    const mapHeight = readCount(record, "map_height");
    if (!mapHeight.ok) return mapHeight;

    const heightRange = readHeightRange(record, variant.value);
    if (!heightRange.ok) return heightRange;

    return {
      ok: true,
      declaration: {
        identity: { keyedOn: "map", mapName: mapName.value, variant: variant.value },
        ...common,
        mapWidth: mapWidth.value,
        mapHeight: mapHeight.value,
        ...heightRange.value,
      },
    };
  }

  const game = readText(record, "game");
  if (!game.ok) return game;

  const unitName = readText(record, "unit_name");
  if (!unitName.ok) return unitName;

  // The same rule as `asset_unit_variant_check`, and no stricter. An angle is
  // open ended, so `render:` with anything after it is the table's rule.
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
    declaration: {
      identity: {
        keyedOn: "unit",
        game: game.value,
        unitName: unitName.value,
        variant: variant.value,
      },
      ...common,
      mapWidth: null,
      mapHeight: null,
      worldHeightMin: null,
      worldHeightMax: null,
    },
  };
}

export function buildAssetUploadBody(): AssetUploadBody {
  return {
    format: ASSET_UPLOAD_FORMAT,
    version: ASSET_UPLOAD_VERSION,
    moderation: "pending",
  };
}
