import type { SupabaseClient } from "@supabase/supabase-js";

/**
 * Clearing staging objects nothing claims (issue #113).
 *
 * ## The three ways an object stops being claimed, and who owns each
 *
 * 1. A newer archive replaces a picture (#106). The row is updated in place, the
 *    old pathname is overwritten, and the object it named is left behind. This
 *    module, via the `asset_record_superseded_object` trigger in
 *    `20260814250000_asset_orphan.sql`, which copies the name out in the same
 *    statement that loses it.
 * 2. An upload stored its bytes and its row was never written. The upload route
 *    deletes the object itself, which is free, and calls
 *    {@link recordUnclaimedObject} when that delete fails too. Partly this
 *    module: see the honest limit below.
 * 3. A promoted picture whose staging copy has not been deleted yet. Not this
 *    module. `lib/assets/promote.ts` already drains those from `asset.blob_path`
 *    at the top of every run, gated on the durable tier actually serving the
 *    bytes, and a second sweeper over the same objects would be a second thing
 *    that can delete them without that gate.
 *
 * ## What cannot be caught, and why saying so matters
 *
 * `list()` is an advanced operation out of 2,000 a month and `./blob` does not
 * export it, so what to delete is enumerated from Postgres. That makes one case
 * structurally unreachable: an upload that dies between `put()` returning and
 * anything at all being written down leaves an object the database has never
 * heard of. No query can find it, this module will never delete it, and it sits
 * in the store until somebody spends an advanced operation to look.
 *
 * That is the whole residue, and it is small. The route writes the row in the
 * same request as the `put()`, and when that write fails it deletes the object
 * before answering. Case 2 above is only the narrower failure where the delete
 * fails as well, which is what leaves something worth recording.
 *
 * ## The check before every deletion
 *
 * Nothing is deleted on the strength of the queue alone. {@link sweepOrphans}
 * asks Postgres whether any row names each path first, and skips the ones that
 * do. A pathname carries Blob's random suffix so a recycled one should be
 * impossible, and that is exactly the kind of assumption that is worth one query
 * rather than a comment: the cost of being wrong is deleting bytes a live row
 * points at, and deletion is the one step nothing here can undo.
 */

/** How many objects one sweep handles. The queue is normally empty and a
 *  replacement adds one entry, so this bounds a pathological run rather than a
 *  normal one, the way `PROMOTION_BATCH` does. */
export const CLEANUP_BATCH = 200;

export type OrphanReason = "superseded" | "unclaimed";

/** One staging object nothing points at. */
export interface Orphan {
  id: number;
  /** The staging pathname, suffix and all. */
  path: string;
  bytes: number;
  reason: OrphanReason;
  at: string;
}

/**
 * Everything still in the store and unclaimed, oldest first.
 *
 * Wants the secret key. Nothing else holds select on `public.asset_orphan`,
 * because a row here names a reachable object in a public store holding bytes
 * nobody has reviewed.
 */
export async function fetchOrphans(
  supabase: SupabaseClient,
  limit: number = CLEANUP_BATCH,
): Promise<Orphan[]> {
  const { data, error } = await supabase
    .from("asset_orphan")
    .select("id, path, bytes, reason, at")
    .is("deleted_at", null)
    .order("at", { ascending: true })
    .limit(limit);

  if (error) throw new Error(`Could not read what nothing claims: ${error.message}`);

  return (data ?? []) as unknown as Orphan[];
}

/**
 * Which of these pathnames some row still names, either as the object it serves
 * from or as one it has queued for deletion.
 *
 * Both columns, because both are a claim. `path` on a staging row is the live
 * object, and `blob_path` is promotion's own drain queue, and deleting either
 * out from under it would be this module reaching into a job that is already
 * doing the work carefully.
 */
export async function claimedPaths(
  supabase: SupabaseClient,
  paths: string[],
): Promise<Set<string>> {
  if (paths.length === 0) return new Set();

  const [live, queued] = await Promise.all([
    supabase.from("asset").select("path").eq("tier", "blob").in("path", paths),
    supabase.from("asset").select("blob_path").in("blob_path", paths),
  ]);

  if (live.error || queued.error) {
    throw new Error(
      `Could not check what still claims these objects: ${(live.error ?? queued.error)?.message}`,
    );
  }

  return new Set([
    ...((live.data ?? []) as { path: string }[]).map((row) => row.path),
    ...((queued.data ?? []) as { blob_path: string }[]).map((row) => row.blob_path),
  ]);
}

/** Say the objects are gone. Answers with how many entries it settled, which is
 *  not always how many were asked about. */
export async function forgetOrphans(
  supabase: SupabaseClient,
  ids: number[],
): Promise<number> {
  if (ids.length === 0) return 0;

  const { data, error } = await supabase.rpc("clear_asset_orphans", { ids });

  if (error) throw new Error(`Could not settle the swept objects: ${error.message}`);

  return typeof data === "number" ? data : 0;
}

/**
 * Record an object the store took and no row ever claimed.
 *
 * Best effort from the caller's point of view: the upload has already failed by
 * the time this runs, and a leaked object is worth less than a second error in
 * the reply. Answers whether the queue gained an entry.
 */
export async function recordUnclaimedObject(
  supabase: SupabaseClient,
  path: string,
  bytes: number,
): Promise<boolean> {
  const { data, error } = await supabase.rpc("record_unclaimed_object", {
    object_path: path,
    object_bytes: bytes,
  });

  return !error && data === true;
}

/** The one side effect a sweep has, injected for the same reason
 *  `PromotionPorts` is: a test must be able to watch what would be deleted
 *  without a store to delete it from. */
export interface CleanupPorts {
  /** Remove staging objects. Free, and safe to repeat. */
  discard(paths: string[]): Promise<void>;
  say(message: string): void;
}

export interface CleanupResult {
  /** Objects deleted from the staging tier. */
  deleted: number;
  /** Objects left alone because a row names them after all, each of which was
   *  said out loud. */
  kept: number;
}

/**
 * One sweep.
 *
 * Delete, then forget, in that order and never the other way round. Dying
 * between the two leaves an entry naming an object that is already gone, and the
 * next sweep deletes it again, which Blob accepts without complaint. Dying the
 * other way round would leave an object nothing names, which is the state this
 * whole file exists to prevent.
 */
export async function sweepOrphans(
  supabase: SupabaseClient,
  ports: CleanupPorts,
  limit: number = CLEANUP_BATCH,
): Promise<CleanupResult> {
  const orphans = await fetchOrphans(supabase, limit);
  if (orphans.length === 0) return { deleted: 0, kept: 0 };

  const claimed = await claimedPaths(
    supabase,
    orphans.map((orphan) => orphan.path),
  );

  const going: Orphan[] = [];
  for (const orphan of orphans) {
    if (claimed.has(orphan.path)) {
      ports.say(`keep ${orphan.path}: a row names it, so it is not an orphan.`);
    } else {
      going.push(orphan);
    }
  }

  if (going.length === 0) return { deleted: 0, kept: orphans.length };

  await ports.discard(going.map((orphan) => orphan.path));
  await forgetOrphans(
    supabase,
    going.map((orphan) => orphan.id),
  );

  return { deleted: going.length, kept: orphans.length - going.length };
}
