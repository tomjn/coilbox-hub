/**
 * Where an asset's bytes sit inside a tier, and which MIME types the hub will
 * take at all (issue #104).
 *
 * The path is derived here and never taken from a caller. `public.asset.path`
 * is tier relative and both tiers serve it verbatim, so a client that chose its
 * own path would choose where the durable tier's git repository puts a file,
 * and `../` in a variant would put it somewhere else again.
 *
 * ## What is in a path, and what is deliberately not
 *
 * The leaf is `hash`, over the encoded bytes, so the path is content addressed:
 * the same bytes always name the same key, which is what lets the durable tier
 * hold one object per picture and what #112 packs an atlas out of.
 *
 * On the staging tier this is the path the hub asks for rather than the path
 * the object ends up at. Blob appends a suffix, the row stores what came back,
 * and promotion (#111) recomputes this path from the row when it writes the
 * object into the durable tier. The reason is #131: the uploader holds the
 * bytes, so the uploader can derive anything derived from them, and a public
 * store makes a derivable path a public URL before anybody has reviewed it.
 *
 * `unit_name` and `map_name` are not in the path, for two different reasons.
 * A map name is the full canonical name the engine reports, which is free text
 * with spaces, brackets, quotes and full stops in it, and none of that belongs
 * in an object key or in a git tree. A unit name is safe enough, but including
 * it would store one object per unit where two units in a game legitimately
 * share a picture, and each of those copies is an advanced operation out of
 * 2,000 a month.
 *
 * The consequence is worth naming: two rows can point at one object. Deleting
 * the object for one row therefore has to consider the other, which is #113's
 * problem and not this file's. On the staging tier they no longer do, since
 * each upload gets its own suffix, so they converge only once promotion has
 * written both to the same durable path. #132 wants to spot the second upload
 * before it spends an operation, and the column that answers that is now `hash`
 * rather than `path`.
 *
 * That is only safe now the hub computes the hash (#154). Two rows sharing a
 * path used to mean either the same bytes or a client that had declared
 * somebody else's hash, and a #132 style check would have reused a stranger's
 * object on the strength of the second. With the hash taken from the bytes, a
 * shared path means shared bytes and nothing else, which is the honest case
 * #132 is about and the only one left.
 */

import type { AssetIdentity } from "./asset";

/**
 * The MIME types an upload may declare, and the extension each is stored under.
 *
 * An allowlist rather than a denylist, and short. Everything the pipeline
 * produces is one of these two, so anything else is either a client bug or
 * somebody trying the hub as a file host, and both are refused before any byte
 * is written.
 *
 * PNG is here for `overlay:height`, which #105 settles: coilbox extracts height
 * as 16 bit grayscale, WebP's lossless mode is 8 bit ARGB, and encoding one as
 * the other halves the precision without anything looking broken. Which variant
 * may use which of the two is #105's to enforce, and this list is only which
 * types exist at all.
 */
export const ASSET_MIME_EXTENSIONS = {
  "image/webp": "webp",
  "image/png": "png",
} as const;

export type AssetMime = keyof typeof ASSET_MIME_EXTENSIONS;

export function isAssetMime(value: string): value is AssetMime {
  return Object.hasOwn(ASSET_MIME_EXTENSIONS, value);
}

/**
 * What may be one segment of a path. Deliberately narrow: alphanumerics, dots,
 * dashes and underscores, and never a leading dot, so `.`, `..` and a hidden
 * file are all unsayable and traversal cannot be spelled at all.
 */
const SAFE_SEGMENT = /^[A-Za-z0-9][A-Za-z0-9._-]*$/;

/**
 * A variant as path segments. `render:270` becomes `render/270` and
 * `overlay:metal` becomes `overlay/metal`, so the colon that reads well in the
 * database never reaches an object key or a filename.
 */
function variantSegments(variant: string): string[] | null {
  const parts = variant.split(":");
  return parts.every((part) => SAFE_SEGMENT.test(part)) ? parts : null;
}

/**
 * The tier relative path for one asset, or null when some part of the identity
 * cannot be a path segment.
 *
 * Null rather than a throw or a sanitised fallback. Sanitising would map two
 * different identities onto one path and quietly overwrite one picture with
 * another, and a throw would make every caller wrap this. The callers turn null
 * into a 400, which is the honest answer: the hub cannot store something it
 * cannot name.
 */
export function assetObjectPath(
  identity: AssetIdentity,
  hash: string,
  mime: string,
): string | null {
  if (!isAssetMime(mime)) return null;
  if (!SAFE_SEGMENT.test(hash)) return null;

  const variant = variantSegments(identity.variant);
  if (!variant) return null;

  const leaf = `${hash}.${ASSET_MIME_EXTENSIONS[mime]}`;

  if (identity.keyedOn === "map") {
    return ["maps", ...variant, leaf].join("/");
  }

  if (!SAFE_SEGMENT.test(identity.game)) return null;
  return ["units", identity.game, ...variant, leaf].join("/");
}
