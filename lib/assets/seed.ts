/**
 * Reading a coilbox seed export into rows and objects (issue #110).
 *
 * The maintainer's own maps and games are already on disk beside coilbox, which
 * extracted and encoded them. This is the other end: turn what it wrote into
 * `public.asset` rows and into files for the durable tier, and refuse anything
 * the hub would not have taken through the upload route.
 *
 * ## Nothing here trusts the manifest
 *
 * Every value that can be measured is measured. {@link checkSeedBytes} reads the
 * header out of the file and holds it to the same {@link checkAssetImage} the
 * upload route uses, compares the length against the declared `bytes` and the
 * hash against the declared `hash`, and refuses a disagreement rather than
 * preferring one side.
 *
 * That is stricter than the upload route, which quietly writes what it measured
 * over what the client declared. The difference is that an upload is one picture
 * a person is watching and a seed is thousands nobody is, so a manifest that
 * disagrees with its own files is a broken export rather than a stale client,
 * and publishing it would put the disagreement in a git history that cannot be
 * rewritten.
 *
 * ## Why a refusal is not a skip
 *
 * {@link planSeed} answers with three lists and the caller reports all three. A
 * held back variant was asked for and is not being published. A refusal is the
 * export saying something the hub cannot store, and every one of them would
 * otherwise be found by a check constraint after the files were committed,
 * which is the one order that cannot be undone.
 *
 * ## What the rows say, and what they deliberately do not
 *
 * `tier` is `static` and `moderation` is `approved` with `approval_source` of
 * `seed`, which is the whole reason this route exists: these files are the
 * maintainer's own collection going straight to the durable tier, so no object
 * is written to Blob and no moderator sees a queue of three thousand pictures.
 *
 * `promoted_at` stays null. It records a row being moved out of the staging
 * tier by #111, and a seeded row was never in it. Setting it would claim a
 * promotion that never happened.
 *
 * `uploaded_by` stays null for the same kind of reason. Nobody uploaded these.
 */

import {
  type AssetIdentity,
  type AssetOrigin,
  MAP_HEIGHT_OVERLAY_VARIANT,
} from "./asset";
import { type AssetImageCheck, capForVariant, checkAssetImage } from "./caps";
import { ASSET_MIME_EXTENSIONS, assetObjectPath, isAssetMime } from "./path";
import type { SeedObject } from "./seedBatch";

/**
 * The manifest shape this understands. A different number is a different export
 * and is refused rather than read hopefully: the fields below are the ones the
 * rows are built out of, so guessing at a shape that moved would write rows
 * whose provenance is wrong in ways no constraint can catch.
 */
export const SEED_MANIFEST_VERSION = 1;

/** Origins a seed may claim. `uploaded` is a person putting a file through the
 *  upload route, which is not what this is, and the column is free text so
 *  nothing downstream would refuse it. */
const SEED_ORIGINS: readonly string[] = ["extracted", "rendered"];

/** One asset in the export, in the export's own names. */
export interface SeedManifestAsset {
  kind: string;
  game?: string;
  unitName?: string;
  mapName?: string;
  variant: string;
  origin: string;
  /** Which batch of the export holds the bytes. */
  batch: number;
  /** The file, relative to the export directory. */
  file: string;
  /** Over the encoded bytes, and the leaf of the durable path. */
  hash: string;
  /** Over the archive bytes. Identity, and what the have check compares. */
  sourceHash: string;
  encodeProfile: string;
  mime: string;
  width: number;
  height: number;
  bytes: number;
  /** The map in world units, on a map row and never on a unit row. */
  mapWidth?: number;
  mapHeight?: number;
  /** The bounds an `overlay:height` sample set maps onto. */
  minHeight?: number;
  maxHeight?: number;
  sourceArchive: string;
}

export interface SeedManifest {
  manifestVersion: number;
  assets: SeedManifestAsset[];
}

/** A row in the table's own column names, ready to insert. */
export interface SeedRow {
  game: string | null;
  unit_name: string | null;
  map_name: string | null;
  variant: string;
  source_hash: string;
  hash: string;
  encode_profile: string;
  path: string;
  origin: AssetOrigin;
  tier: "static";
  mime: string;
  bytes: number;
  width: number;
  height: number;
  map_width: number | null;
  map_height: number | null;
  world_height_min: number | null;
  world_height_max: number | null;
  source_archive: string;
  moderation: "approved";
  approval_source: "seed";
}

/** One asset that will be published: where its bytes go, and what the row says. */
export interface SeedEntry {
  asset: SeedManifestAsset;
  /** The identity key, so a caller can compare against what the hub holds. */
  key: string;
  object: SeedObject;
  row: SeedRow;
}

export interface SeedRefusal {
  asset: SeedManifestAsset;
  reason: string;
}

export interface SeedPlan {
  entries: SeedEntry[];
  /** Assets the hub cannot store as they are. */
  refused: SeedRefusal[];
  /** Assets left out by the caller's own choice, which is not a fault. */
  heldBack: SeedManifestAsset[];
}

export interface SeedPlanOptions {
  /** Variants to leave out of this run entirely, in full including the colon. */
  skipVariants?: readonly string[];
}

/**
 * Parse a manifest, refusing a version this does not know.
 *
 * Throws rather than answering null, because there is nothing a caller could do
 * with half an export and every caller is a command line run by a person.
 */
export function readSeedManifest(text: string): SeedManifest {
  const parsed = JSON.parse(text) as Partial<SeedManifest>;

  if (parsed.manifestVersion !== SEED_MANIFEST_VERSION) {
    throw new Error(
      `That export says manifest version ${parsed.manifestVersion}, and this reads version ${SEED_MANIFEST_VERSION}.`,
    );
  }

  if (!Array.isArray(parsed.assets)) {
    throw new Error("That export has no `assets` array, so there is nothing to seed.");
  }

  return parsed as SeedManifest;
}

/** The identity the asset is keyed on, or null when the export did not give a
 *  whole one. */
export function seedIdentity(asset: SeedManifestAsset): AssetIdentity | null {
  if (asset.kind === "unit") {
    if (!asset.game?.trim() || !asset.unitName?.trim()) return null;
    return {
      keyedOn: "unit",
      game: asset.game,
      unitName: asset.unitName,
      variant: asset.variant,
    };
  }

  if (asset.kind === "map") {
    if (!asset.mapName?.trim()) return null;
    return { keyedOn: "map", mapName: asset.mapName, variant: asset.variant };
  }

  return null;
}

/**
 * The key the two unique indexes address a row by, as one string.
 *
 * Only for comparing an export against what the hub already holds, in memory,
 * in one run. Nothing is stored under it, and it is not the path.
 */
export function seedIdentityKey(identity: AssetIdentity): string {
  const fields =
    identity.keyedOn === "unit"
      ? ["unit", identity.game, identity.unitName, identity.variant]
      : ["map", identity.mapName, identity.variant];

  // Joined on something that cannot appear in a field rather than on anything
  // readable. A map name is free text with spaces and punctuation in it, so a
  // readable separator is one that can also appear inside a field, and two
  // different identities sharing a key would have the run refuse the second as
  // a duplicate of the first.
  return fields.join("\0");
}

/** What the hub cannot store about this asset, or null when it can. */
function planRefusal(asset: SeedManifestAsset, identity: AssetIdentity): string | null {
  if (!SEED_ORIGINS.includes(asset.origin)) {
    return `origin is "${asset.origin}", and a seed may only carry ${SEED_ORIGINS.join(" or ")}.`;
  }

  if (!capForVariant(asset.variant)) {
    return `"${asset.variant}" is not a variant the hub stores pictures for.`;
  }

  // Enforced by asset_map_size_check, which would find it after the files were
  // committed. Without them every overlay is misaligned and nothing downstream
  // of extraction can recover them.
  if (identity.keyedOn === "map" && !(asset.mapWidth && asset.mapHeight)) {
    return "a map row carries the map size in world units, and this one has none.";
  }

  // Not a constraint, because the columns are nullable for the classes that
  // have no bounds. A height overlay without them is samples nobody can turn
  // back into elmos, so it is a picture the hub would hold and never use.
  if (
    asset.variant === MAP_HEIGHT_OVERLAY_VARIANT &&
    (asset.minHeight === undefined || asset.maxHeight === undefined)
  ) {
    return "an `overlay:height` carries the height bounds, and this one has none.";
  }

  return null;
}

function seedRow(
  asset: SeedManifestAsset,
  identity: AssetIdentity,
  path: string,
): SeedRow {
  const map = identity.keyedOn === "map";

  return {
    game: identity.keyedOn === "unit" ? identity.game : null,
    unit_name: identity.keyedOn === "unit" ? identity.unitName : null,
    map_name: map ? identity.mapName : null,
    variant: asset.variant,
    source_hash: asset.sourceHash,
    hash: asset.hash,
    encode_profile: asset.encodeProfile,
    path,
    origin: asset.origin as AssetOrigin,
    tier: "static",
    mime: asset.mime,
    bytes: asset.bytes,
    width: asset.width,
    height: asset.height,
    // Read off the identity rather than off the asset, so a unit row carrying a
    // map size in the export cannot put one on the row.
    map_width: map ? (asset.mapWidth ?? null) : null,
    map_height: map ? (asset.mapHeight ?? null) : null,
    world_height_min: map ? (asset.minHeight ?? null) : null,
    world_height_max: map ? (asset.maxHeight ?? null) : null,
    source_archive: asset.sourceArchive,
    moderation: "approved",
    approval_source: "seed",
  };
}

/**
 * What a run would publish, what it will not, and what it cannot.
 *
 * A second asset on an identity an earlier one already took is refused rather
 * than replacing it. Both unique indexes would refuse it too, but only after
 * the files were committed, and there is no honest way to choose between two
 * rows the export offered for one key.
 */
export function planSeed(manifest: SeedManifest, options: SeedPlanOptions = {}): SeedPlan {
  const skip = new Set(options.skipVariants ?? []);
  const plan: SeedPlan = { entries: [], refused: [], heldBack: [] };
  const taken = new Map<string, SeedManifestAsset>();

  for (const asset of manifest.assets) {
    if (skip.has(asset.variant)) {
      plan.heldBack.push(asset);
      continue;
    }

    const identity = seedIdentity(asset);
    if (!identity) {
      plan.refused.push({
        asset,
        reason: `a "${asset.kind}" with no whole identity, so there is no row to key.`,
      });
      continue;
    }

    const reason = planRefusal(asset, identity);
    if (reason) {
      plan.refused.push({ asset, reason });
      continue;
    }

    const path = assetObjectPath(identity, asset.hash, asset.mime);
    if (!path) {
      plan.refused.push({
        asset,
        reason: "its identity, hash and type do not make a path the hub can store.",
      });
      continue;
    }

    const key = seedIdentityKey(identity);
    const already = taken.get(key);
    if (already) {
      plan.refused.push({
        asset,
        reason: `the export already offered ${already.hash} for that identity.`,
      });
      continue;
    }
    taken.set(key, asset);

    plan.entries.push({
      asset,
      key,
      object: { batch: asset.batch, from: asset.file, to: path },
      row: seedRow(asset, identity, path),
    });
  }

  return plan;
}

export type SeedBytesCheck = { ok: true } | { ok: false; error: string };

/**
 * Hold one file to everything the manifest says about it and everything its
 * class allows.
 *
 * `hash` is the caller's own digest of these bytes rather than something read
 * out of them, because hashing is asynchronous and this is not, and because a
 * hash the file carried would be checking the file against itself.
 */
export function checkSeedBytes(
  asset: SeedManifestAsset,
  bytes: Uint8Array,
  hash: string,
): SeedBytesCheck {
  if (bytes.byteLength !== asset.bytes) {
    return {
      ok: false,
      error: `the manifest says ${asset.bytes} bytes and the file is ${bytes.byteLength}.`,
    };
  }

  if (hash !== asset.hash) {
    return { ok: false, error: `the manifest says ${asset.hash} and the file hashes to ${hash}.` };
  }

  // Content addressed, so a leaf that is not the hash means the bytes at that
  // path are somebody else's and the durable tier would serve the wrong picture.
  const leaf = asset.file.slice(asset.file.lastIndexOf("/") + 1);
  const named = isAssetMime(asset.mime) ? `${hash}.${ASSET_MIME_EXTENSIONS[asset.mime]}` : null;
  if (leaf !== named) {
    return {
      ok: false,
      error: `the file should be called ${named ?? `something the hub has an extension for, not ${asset.mime}`} and is called ${leaf}.`,
    };
  }

  const measured: AssetImageCheck = checkAssetImage(asset.variant, asset.mime, bytes);
  if (!measured.ok) return { ok: false, error: measured.error };

  if (measured.width !== asset.width || measured.height !== asset.height) {
    return {
      ok: false,
      error: `the manifest says ${asset.width}x${asset.height} and the bytes measure ${measured.width}x${measured.height}.`,
    };
  }

  return { ok: true };
}
