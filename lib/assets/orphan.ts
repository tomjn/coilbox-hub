import type { SupabaseClient } from "@supabase/supabase-js";

/**
 * Clearing staging objects nothing claims (issues #113 and #335).
 *
 * ## The ways an object stops being claimed, and who owns each
 *
 * 1. A newer archive replaces a picture (#106), or an upload stored its bytes
 *    and its row was never written. Either way the bucket holds an object no
 *    row names, and {@link sweepStagedObjects} finds it by listing the bucket
 *    from Postgres.
 * 2. A promoted picture whose staging copy has not been deleted yet. Not this
 *    module. `lib/assets/promote.ts` already drains those from `asset.blob_path`
 *    at the top of every run, gated on the durable tier actually serving the
 *    bytes, and a second sweeper over the same objects would be a second thing
 *    that can delete them without that gate.
 *
 * ## Vercel Blob
 *
 * Blob was the staging store before the bucket (#332), and #338 removed it
 * along with its own orphan queue and the trigger that filled it. Pictures
 * that were only in Blob are marked `bytes_missing_at` (#336), so Coilbox
 * uploads them again into the bucket.
 */

/** How many objects one sweep handles. The bucket normally holds none nothing
 *  claims and a replacement adds one, so this bounds a pathological run rather
 *  than a normal one, the way `PROMOTION_BATCH` does. */
export const CLEANUP_BATCH = 200;

/** The one side effect a sweep has, injected for the same reason
 *  `PromotionPorts` is: a test must be able to watch what would be deleted
 *  without a store to delete it from. */
export interface CleanupPorts {
  /** Remove objects from the Supabase bucket. Safe to repeat. */
  discardStaged(paths: string[]): Promise<void>;
  say(message: string): void;
}

export interface CleanupResult {
  /** Objects deleted from the staging tier. */
  deleted: number;
  /** Objects left alone because a row claimed them after the listing, each of
   *  which was said out loud. */
  kept: number;
}

// ## The Supabase bucket (#335)
//
// Nothing is queued for the bucket. Postgres can list it (`storage.objects`),
// so the sweep asks `unclaimed_staged_objects` what nothing claims, and every
// bucket deletion, the sweep's and promotion's drain alike, goes through
// {@link deleteStagedObjects}. That reserves each path under a lock an upload
// also takes, so an upload cannot reuse an object between the claim check and
// the delete. `20260914170000_staged_pictures_promotion.sql` has the reasoning.

/** One bucket object nothing claims. */
export interface UnclaimedStagedObject {
  path: string;
  bytes: number;
  created_at: string;
}

/** Bucket objects nothing claims, oldest first. Wants the secret key. */
export async function fetchUnclaimedStagedObjects(
  supabase: SupabaseClient,
  limit: number = CLEANUP_BATCH,
): Promise<UnclaimedStagedObject[]> {
  const { data, error } = await supabase.rpc("unclaimed_staged_objects", { max_objects: limit });

  if (error) throw new Error(`Could not list what nothing claims in the bucket: ${error.message}`);

  return ((data ?? []) as { object_path: string; bytes: number; created_at: string }[]).map((row) => ({
    path: row.object_path,
    bytes: Number(row.bytes),
    created_at: row.created_at,
  }));
}

/**
 * Delete bucket objects nothing claims, and answer with the paths deleted.
 *
 * Reserve, delete, release, in that order. A path some row claims is not
 * reserved and not deleted, and the caller decides what to say about it. A run
 * that dies after reserving leaves the reservation, which refuses any upload
 * of those bytes until {@link sweepStagedObjects} finishes the delete.
 *
 * `queuedIsClaimed` is whether promotion's drain queue (`asset.blob_path`)
 * counts as a claim. True for the sweep, which must leave that queue to
 * promotion. False for the drain itself, which has already checked the durable
 * tier is serving the bytes every entry for that path names.
 */
export async function deleteStagedObjects(
  supabase: SupabaseClient,
  discardStaged: (paths: string[]) => Promise<void>,
  paths: string[],
  queuedIsClaimed: boolean,
): Promise<Set<string>> {
  if (paths.length === 0) return new Set();

  const { data, error } = await supabase.rpc("reserve_staged_deletions", {
    object_paths: paths,
    queued_is_claimed: queuedIsClaimed,
  });

  if (error) throw new Error(`Could not reserve bucket objects for deletion: ${error.message}`);

  const reserved = ((data ?? []) as { object_path: string }[]).map((row) => row.object_path);
  if (reserved.length === 0) return new Set();

  await discardStaged(reserved);
  await releaseStagedDeletions(supabase, reserved);

  return new Set(reserved);
}

async function releaseStagedDeletions(supabase: SupabaseClient, paths: string[]): Promise<number> {
  const { data, error } = await supabase.rpc("release_staged_deletions", { object_paths: paths });

  if (error) throw new Error(`Could not release the deleted bucket objects: ${error.message}`);

  return typeof data === "number" ? data : 0;
}

/**
 * How old a reservation has to be before the sweep treats its run as dead.
 *
 * The daily job is the only scheduled deleter, and `promote.yml` in the assets
 * repo stops it at `timeout-minutes: 60`. A reservation older than that
 * belongs to no run still going, so finishing it cannot delete an object from
 * under a run that is about to release it.
 */
export const ABANDONED_RESERVATION_MINUTES = 60;

/** Paths reserved by a run that died before releasing them. */
export async function fetchAbandonedStagedDeletions(
  supabase: SupabaseClient,
  now: Date = new Date(),
): Promise<string[]> {
  const before = new Date(now.getTime() - ABANDONED_RESERVATION_MINUTES * 60 * 1000).toISOString();

  const { data, error } = await supabase
    .from("staged_object_deletion")
    .select("path")
    .lt("reserved_at", before)
    .order("reserved_at", { ascending: true });

  if (error) throw new Error(`Could not read the reserved bucket deletions: ${error.message}`);

  return ((data ?? []) as { path: string }[]).map((row) => row.path);
}

/**
 * One sweep of the bucket.
 *
 * First the deletions a dead run reserved and never released. A reservation
 * is only ever made for a path nothing claims, and no claim can be written
 * while it stands, so finishing it needs no second check. Then everything
 * nothing claims, through {@link deleteStagedObjects}.
 */
export async function sweepStagedObjects(
  supabase: SupabaseClient,
  ports: CleanupPorts,
  limit: number = CLEANUP_BATCH,
  now: Date = new Date(),
): Promise<CleanupResult> {
  const abandoned = await fetchAbandonedStagedDeletions(supabase, now);
  if (abandoned.length > 0) {
    await ports.discardStaged(abandoned);
    await releaseStagedDeletions(supabase, abandoned);
    ports.say(`Finished deleting ${abandoned.length} bucket object(s) an earlier run reserved.`);
  }

  const unclaimed = await fetchUnclaimedStagedObjects(supabase, limit);
  const deleted = await deleteStagedObjects(
    supabase,
    (paths) => ports.discardStaged(paths),
    unclaimed.map((object) => object.path),
    true,
  );

  for (const object of unclaimed) {
    if (!deleted.has(object.path)) {
      ports.say(`keep ${object.path}: a row claimed it after the bucket was listed.`);
    }
  }

  return {
    deleted: abandoned.length + deleted.size,
    kept: unclaimed.length - deleted.size,
  };
}
