import {
  ASSET_ORIGINS,
  type AssetOrigin,
  UNIT_BUILDPIC_VARIANT,
  UNIT_RENDER_VARIANT_PREFIX,
} from "@/lib/assets/asset";
import type { AssetUploadDeclaration } from "@/lib/assets/upload";

/**
 * The wire shape of an upload declaration (issue #104), shared by both upload
 * paths: the JSON part of the multipart body Coilbox posts, and the
 * `clientPayload` the website sends when it asks for a client token.
 *
 * One shape rather than two, because the two paths share their checks and a
 * second shape would be a second thing to keep in step with the table.
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

const COMMON_FIELDS = [
  "keyed_on",
  "variant",
  "source_hash",
  "hash",
  "encode_profile",
  "origin",
  "mime",
  "bytes",
  "width",
  "height",
  "source_archive",
] as const;

const UNIT_FIELDS = [...COMMON_FIELDS, "game", "unit_name"] as const;
const MAP_FIELDS = [...COMMON_FIELDS, "map_name", "map_width", "map_height"] as const;

/** The lengths `public.asset` accepts, so a declaration the table could never
 * hold is refused here rather than after an advanced operation has been spent
 * on it. */
const MAX_LENGTHS = {
  game: 64,
  unit_name: 128,
  map_name: 256,
  variant: 64,
  source_hash: 128,
  hash: 128,
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

function isOrigin(value: unknown): value is AssetOrigin {
  return typeof value === "string" && (ASSET_ORIGINS as readonly string[]).includes(value);
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

  const hash = readText(record, "hash");
  if (!hash.ok) return hash;

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

  const width = readCount(record, "width");
  if (!width.ok) return width;

  const height = readCount(record, "height");
  if (!height.ok) return height;

  const common = {
    sourceHash: sourceHash.value,
    hash: hash.value,
    encodeProfile: encodeProfile.value,
    origin: record.origin,
    mime: mime.value,
    bytes: bytes.value,
    width: width.value,
    height: height.value,
    sourceArchive: sourceArchive.value,
  };

  if (keyedOn === "map") {
    const mapName = readText(record, "map_name");
    if (!mapName.ok) return mapName;

    // Mandatory on a map row rather than merely available. Nothing downstream
    // of extraction can recover the world size, and without it every overlay
    // is subtly misaligned with a cause that is hard to isolate.
    const mapWidth = readCount(record, "map_width");
    if (!mapWidth.ok) return mapWidth;

    const mapHeight = readCount(record, "map_height");
    if (!mapHeight.ok) return mapHeight;

    return {
      ok: true,
      declaration: {
        identity: { keyedOn: "map", mapName: mapName.value, variant: variant.value },
        ...common,
        mapWidth: mapWidth.value,
        mapHeight: mapHeight.value,
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
