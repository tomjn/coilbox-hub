import type { SupabaseClient } from "@supabase/supabase-js";
import type { AssetIdentity, AssetOrigin } from "./asset";
import { BLOB_ADVANCED_OPERATIONS_PER_MONTH } from "./blob";
import { identityFilter } from "./have";
import { type AssetLicenceRow, licenceForMap, mayRedistribute } from "./licence";
import { ASSET_MIME_EXTENSIONS, assetObjectPath, isAssetMime } from "./path";

/**
 * Everything an upload is refused for, in one place (issue #104).
 *
 * ## Why this is a module and not a route
 *
 * It was written for two upload paths, so that a check could not exist on one
 * and not the other. There is one now: everything posts the bytes to
 * `POST /api/v1/assets/upload` and the route calls `put()`. The client direct
 * path went in #133, because the browser SDK hands the uploader the finished
 * URL of its own unreviewed picture.
 *
 * It stays a module anyway. The route reads as a sequence of refusals with the
 * write at the end, and {@link checkAssetUpload} is where the reason for each
 * refusal and its cost live. #107 owns its numbers.
 *
 * ## Why every check is before the write
 *
 * `put()` is an advanced operation. A Hobby Blob store gets 2,000 a month,
 * exceeding that removes Blob access for 30 days, and there is no overage
 * billing, so it cannot be paid through. A rejected upload therefore has to
 * cost nothing at all, which means nothing here may run after the write and the
 * checks are ordered cheapest first: everything answerable from the request
 * alone before anything that asks the database.
 *
 * The database checks are issued together and read in a fixed order afterwards.
 * That keeps one round trip's latency while keeping the answer deterministic,
 * so a request that trips two limits always hears about the same one.
 *
 * ## What is not here
 *
 * The per class caps (#105) are in `./caps`, and the route applies them between
 * the last pure check and the first database one. They need the bytes and this
 * module never sees them, and they need no round trip, so putting them here
 * would only move a check the request can answer on its own behind one that
 * costs a query.
 */

/**
 * The largest object the hub will take, well under the 4.5 MB the platform
 * refuses a function body at.
 *
 * The platform limit is free enforcement that runs before any code here does,
 * so this number is not about protecting the function. It is about what a game
 * asset plausibly is: buildpics are 5 to 10 KB and minimaps and renders 40 to
 * 150 KB, so 2 MB is more than an order of magnitude of headroom and anything
 * over it is not the thing it claims to be.
 *
 * #107 owns this number and may tighten it per class.
 */
export const ASSET_MAX_OBJECT_BYTES = 2 * 1024 * 1024;

/**
 * How much of the store one account may hold.
 *
 * The whole store is 1 GB, so this is a sixteenth of it. It is a ceiling on one
 * account taking the store away from everybody else rather than a budget
 * anybody is expected to reach: the entire buildpic corpus is about 20 MB.
 */
export const ACCOUNT_STORAGE_QUOTA_BYTES = 64 * 1024 * 1024;

/**
 * How many stored variants any one `(game, unit_name)` may have.
 *
 * The cap #107 calls the one that matters most, and it is here rather than in
 * #107 because renders are the only asset class with no natural bound: units
 * times angles, with nothing in the data stopping either from growing. A
 * buildpic plus seven angles is already more than any use case has asked for,
 * and the point is that nothing can bulk render a roster.
 */
export const UNIT_VARIANT_CEILING = 8;

/**
 * How many assets one account may upload for one subject in an hour, where the
 * subject is a game for a unit asset and maps as a whole for a map asset.
 *
 * Backfill is meant to be lazy: the units a viewed blueprint actually
 * references, not the roster. A hundred an hour is far past that and is
 * insurance against a client looping rather than a pace anybody meets, in the
 * spirit of `enforce_publish_rate_limit` on `public.item`.
 *
 * One rule with a subject rather than two rules, because a map asset is not
 * scoped to a game and inventing a game for it would either exempt maps or
 * force a second limit that drifts from this one.
 */
export const SUBJECT_UPLOADS_PER_HOUR = 100;

/**
 * How many uploads the hub will accept in a calendar month.
 *
 * Every accepted upload is one `put()` and therefore one advanced operation,
 * and going over the store's allowance is a 30 day outage that cannot be paid
 * through. The margin below the allowance is deliberate: this counts rows the
 * hub wrote, so anything that spends an operation outside this route, or a row
 * written and then rolled back, eats into the margin rather than into the
 * outage.
 */
export const MONTHLY_UPLOAD_BUDGET = BLOB_ADVANCED_OPERATIONS_PER_MONTH - 100;

/**
 * What a client says it is uploading. Every field here ends up on the row
 * except the ones the hub decides for itself: `path`, `tier`, `moderation`,
 * `approval_source` and `uploaded_by`.
 *
 * `width` and `height` are not here, and their absence is #105's answer. The
 * hub measures the image header, so a declared pair could only agree with the
 * bytes or be wrong, and there is no third thing a client could usefully mean
 * by it. {@link insertPendingAsset} takes the measured pair separately.
 */
export interface AssetUploadDeclaration {
  identity: AssetIdentity;
  /** Over the raw archive bytes. Identity, and what the have check compares. */
  sourceHash: string;
  /** Over the encoded bytes. The path component. */
  hash: string;
  encodeProfile: string;
  origin: AssetOrigin;
  mime: string;
  bytes: number;
  /** The map in world units, set on a map row and null on a unit row. */
  mapWidth: number | null;
  mapHeight: number | null;
  /** The elmo range a height overlay's ramp spans, and null on everything else.
   * Only the archive has these and nothing downstream can recover them. */
  worldHeightMin: number | null;
  worldHeightMax: number | null;
  sourceArchive: string;
}

/** What the bytes turned out to be, from `./caps`. Never what a client said. */
export interface AssetImageDimensions {
  width: number;
  height: number;
}

export type AssetUploadCheck =
  | { ok: true; path: string }
  | { ok: false; error: string; status: number };

/** Beginning of the current calendar month, in UTC, as PostgREST wants it. */
function monthStart(now: Date): string {
  return new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), 1)).toISOString();
}

/**
 * A count, or null when the query failed. Null is not zero: reading a failed
 * quota query as an empty one would let every limit through at exactly the
 * moment the database is unwell.
 */
async function countRows(
  query: PromiseLike<{ count: number | null; error: unknown }>,
): Promise<number | null> {
  const { count, error } = await query;
  return error ? null : (count ?? 0);
}

/**
 * The licence row that decides for this subject, or null when there is none.
 *
 * A game is looked up directly and a map falls back to the blanket row, which
 * is the difference {@link licenceForMap} exists for: almost no map has a row
 * of its own, so a direct lookup alone would refuse nearly every map.
 */
async function fetchLicence(
  supabase: SupabaseClient,
  identity: AssetIdentity,
): Promise<{ ok: true; licence: AssetLicenceRow | null } | { ok: false }> {
  if (identity.keyedOn === "unit") {
    const { data, error } = await supabase
      .from("asset_licence")
      .select("*")
      .eq("game", identity.game)
      .maybeSingle();
    return error ? { ok: false } : { ok: true, licence: data as AssetLicenceRow | null };
  }

  const [perMap, allMaps] = await Promise.all([
    supabase.from("asset_licence").select("*").eq("map_name", identity.mapName).maybeSingle(),
    supabase.from("asset_licence").select("*").eq("all_maps", true).maybeSingle(),
  ]);
  if (perMap.error || allMaps.error) return { ok: false };

  return {
    ok: true,
    licence: licenceForMap(
      perMap.data as AssetLicenceRow | null,
      allMaps.data as AssetLicenceRow | null,
    ),
  };
}

/**
 * Whether the hub may publish this class of picture for this subject.
 *
 * `uploaded` is not licence gated, and that is not an oversight. Nobody can
 * tell from the bytes what a supplied image is a picture of or who made it, so
 * no per game or per map decision can answer for one, and `mayRedistribute()`
 * does not accept the origin at all. The moderation queue is what stands in
 * front of that class.
 *
 * Everything else is gated, and the gate is here rather than at approval time
 * because the staging tier is public: `put()` puts the bytes at a reachable URL
 * before any person has looked at them. Accepting an upload the hub may never
 * redistribute would therefore publish it, spend an advanced operation on it,
 * and leave a deletion as the only remedy.
 */
function licencePermits(licence: AssetLicenceRow | null, origin: AssetOrigin): boolean {
  return origin === "uploaded" ? true : mayRedistribute(licence, origin);
}

const QUOTA_UNAVAILABLE = {
  ok: false,
  error: "The upload quotas could not be read just now. Try again shortly.",
  status: 503,
} as const;

/**
 * Everything that has to be true before a byte is written, and the path the
 * bytes go to when it all is.
 *
 * `supabase` must be the secret key client. Every question here is about rows
 * `asset_read_approved` hides, and asking through the publishable key would
 * read a pending upload as absent and a full store as empty.
 */
export async function checkAssetUpload(
  supabase: SupabaseClient,
  userId: string,
  declaration: AssetUploadDeclaration,
): Promise<AssetUploadCheck> {
  const { identity, origin, mime, bytes } = declaration;

  // Pure checks first. None of these costs a round trip, so the request that
  // was never going to be accepted is refused before the hub does any work.
  if (!isAssetMime(mime)) {
    return {
      ok: false,
      error: `\`mime\` must be one of ${Object.keys(ASSET_MIME_EXTENSIONS).join(", ")}.`,
      status: 415,
    };
  }

  if (bytes > ASSET_MAX_OBJECT_BYTES) {
    return {
      ok: false,
      error: `An asset may be at most ${ASSET_MAX_OBJECT_BYTES} bytes. That one declares ${bytes}.`,
      status: 413,
    };
  }

  const path = assetObjectPath(identity, declaration.hash, mime);
  if (!path) {
    return {
      ok: false,
      error:
        "`game`, `variant` and `hash` have to be storable as path segments: letters, digits, dots, dashes and underscores, and a variant may separate segments with a colon.",
      status: 400,
    };
  }

  // One round trip for the rest. Read in a fixed order below, so a request that
  // trips two limits always hears about the same one.
  const since = new Date(Date.now() - 60 * 60 * 1000).toISOString();

  const recentForSubject =
    identity.keyedOn === "unit"
      ? supabase
          .from("asset")
          .select("id", { count: "exact", head: true })
          .eq("uploaded_by", userId)
          .eq("game", identity.game)
          .gte("created_at", since)
      : supabase
          .from("asset")
          .select("id", { count: "exact", head: true })
          .eq("uploaded_by", userId)
          .not("map_name", "is", null)
          .gte("created_at", since);

  const [licence, existing, unitVariants, accountBytes, recent, thisMonth] = await Promise.all([
    fetchLicence(supabase, identity),
    countRows(
      supabase
        .from("asset")
        .select("id", { count: "exact", head: true })
        .or(identityFilter(identity)),
    ),
    identity.keyedOn === "unit"
      ? countRows(
          supabase
            .from("asset")
            .select("id", { count: "exact", head: true })
            .eq("game", identity.game)
            .eq("unit_name", identity.unitName),
        )
      : Promise.resolve(0),
    supabase.rpc("account_asset_bytes", { account: userId }),
    countRows(recentForSubject),
    countRows(
      supabase
        .from("asset")
        .select("id", { count: "exact", head: true })
        .not("uploaded_by", "is", null)
        .gte("created_at", monthStart(new Date())),
    ),
  ]);

  if (!licence.ok) return QUOTA_UNAVAILABLE;
  if (!licencePermits(licence.licence, origin)) {
    return {
      ok: false,
      error:
        identity.keyedOn === "unit"
          ? `The hub has no recorded permission to redistribute ${origin} pictures for "${identity.game}".`
          : `The hub has no recorded permission to redistribute ${origin} pictures for "${identity.mapName}".`,
      status: 403,
    };
  }

  if (existing === null) return QUOTA_UNAVAILABLE;
  if (existing > 0) {
    return {
      ok: false,
      error: "The hub already holds an asset with that identity. Nothing was uploaded.",
      status: 409,
    };
  }

  if (unitVariants === null) return QUOTA_UNAVAILABLE;
  if (unitVariants >= UNIT_VARIANT_CEILING) {
    return {
      ok: false,
      error: `That unit already has ${unitVariants} stored variants, which is the ceiling of ${UNIT_VARIANT_CEILING}.`,
      status: 409,
    };
  }

  if (accountBytes.error || typeof accountBytes.data !== "number") return QUOTA_UNAVAILABLE;
  if (accountBytes.data + bytes > ACCOUNT_STORAGE_QUOTA_BYTES) {
    return {
      ok: false,
      error: `That upload would put this account over its ${ACCOUNT_STORAGE_QUOTA_BYTES} byte storage quota.`,
      status: 413,
    };
  }

  if (recent === null) return QUOTA_UNAVAILABLE;
  if (recent >= SUBJECT_UPLOADS_PER_HOUR) {
    return {
      ok: false,
      error: `Too many uploads for that subject in the last hour, which is capped at ${SUBJECT_UPLOADS_PER_HOUR}. Try again later.`,
      status: 429,
    };
  }

  if (thisMonth === null) return QUOTA_UNAVAILABLE;
  if (thisMonth >= MONTHLY_UPLOAD_BUDGET) {
    return {
      ok: false,
      error: "The hub has reached its upload allowance for this month. Try again next month.",
      status: 503,
    };
  }

  return { ok: true, path };
}

/**
 * Write the pending row for an upload the hub has just accepted.
 *
 * `moderation` and `approval_source` are left at their defaults rather than
 * set, so nothing on this path can put a row in front of the public. Bypass on
 * a capability is #114's alongside the queue that would otherwise hold it.
 *
 * #106 is where this learns to replace a row whose `source_hash` has changed.
 * It is no longer a route of its own: with one upload path the row is always
 * written by the request that holds the bytes, so what is left of #106 is this
 * function taking an update as well as an insert. Until then a row is only ever
 * inserted, and an identity that already exists is refused above.
 */
export async function insertPendingAsset(
  supabase: SupabaseClient,
  userId: string,
  declaration: AssetUploadDeclaration,
  path: string,
  measured: AssetImageDimensions,
): Promise<boolean> {
  const { identity } = declaration;

  const { error } = await supabase.from("asset").insert({
    game: identity.keyedOn === "unit" ? identity.game : null,
    unit_name: identity.keyedOn === "unit" ? identity.unitName : null,
    map_name: identity.keyedOn === "map" ? identity.mapName : null,
    variant: identity.variant,
    source_hash: declaration.sourceHash,
    hash: declaration.hash,
    encode_profile: declaration.encodeProfile,
    path,
    origin: declaration.origin,
    tier: "blob",
    mime: declaration.mime,
    bytes: declaration.bytes,
    width: measured.width,
    height: measured.height,
    map_width: declaration.mapWidth,
    map_height: declaration.mapHeight,
    world_height_min: declaration.worldHeightMin,
    world_height_max: declaration.worldHeightMax,
    source_archive: declaration.sourceArchive,
    uploaded_by: userId,
  });

  return !error;
}
