import type { SupabaseClient } from "@supabase/supabase-js";
import { blobTierUrl } from "./blob";
import { rowIdentity } from "./have";
import { assetObjectPath } from "./path";

/**
 * Moving approved pictures out of the staging tier and into the durable one
 * (issue #111). The only writer to the durable tier after the seed, and the
 * only reason the Blob store stays near empty.
 *
 * Everything approved and older than seven days, in the order it was approved.
 * Not the popular ones, not the large ones: the point is that staging drains,
 * and a Blob footprint that keeps growing is then a plain signal that this job
 * has stalled rather than a number somebody has to interpret.
 *
 * ## Nothing here asks Blob what it holds
 *
 * `list()` is an advanced operation out of 2,000 a month and `lib/assets/blob`
 * does not export it. What to promote comes from Postgres, which knows,
 * because every object in the store has a row. Reading the bytes back is a
 * plain `fetch` of the public URL, which is data transfer and not an
 * operation, and deleting is free. So a run of any size spends nothing out of
 * the monthly allowance.
 *
 * ## The order, and what an interrupted run leaves behind
 *
 * A run must never leave a picture absent from both tiers. It may leave one in
 * both, which costs a little storage until the next run, and that is the
 * direction every step below fails in.
 *
 * 1. Drain. Delete any staging object a previous run promoted and did not get
 *    round to deleting. Dies here: nothing has changed, the next run drains it.
 * 2. Select. Approved, on the staging tier, untouched for seven days. Dies
 *    here: nothing has changed.
 * 3. Read the bytes out of Blob and write them into the assets checkout under
 *    the content addressed path, which is recomputed from the row because the
 *    staging path carries a suffix that is not part of it. Dies here: the
 *    working tree of a throwaway checkout is thrown away with it.
 * 4. Re-check that every row is still approved and still on the staging tier,
 *    then commit and push. Dies before the push: nothing durable happened.
 *    Dies after it: the object is in both tiers and every row still says
 *    `blob`, so the next run redoes the whole batch and the commit is a no-op
 *    because the bytes are already at that path.
 * 5. Wait until the durable tier actually serves the batch. Dies here: same as
 *    the line above, and the next run waits again.
 * 6. Move the rows, in one statement, which is one transaction. Dies part way
 *    through: Postgres rolls it back and no row moved. Dies after it commits:
 *    the rows say `static`, the objects are in both tiers, and each moved row
 *    holds the staging path it used to have in `blob_path`, so step 1 of the
 *    next run finishes the job.
 * 7. Delete the staging objects and clear `blob_path`. Dies between the two:
 *    the next drain deletes an object that is already gone, which Blob accepts
 *    without complaint.
 *
 * There is no step at which the bytes are gone from Blob and not yet in the
 * durable tier, and no step at which a staging object stops being named by a
 * row. Both of those are properties of the order and of `blob_path` rather
 * than of the run going well, which is what `promote.test.ts` kills the run at
 * each step to demonstrate.
 *
 * ## What the atlas (#112) has to hook into
 *
 * The atlas is rebuilt from the durable tier and committed alongside the
 * assets, so a rebuild belongs between step 3 and step 4, working from
 * {@link Promotable.identity} for the rows written in step 3. Everything a
 * rebuild needs is in scope at that point: which games gained a `buildpic`,
 * and a checkout holding the new objects. Putting it there means the atlas and
 * the pictures it packs land in one commit and one push, so the tier is never
 * published with an atlas that disagrees with it, and #119's whole-site
 * republish is paid once rather than twice.
 *
 * It cannot go after step 6. The rows would already say `static` while the
 * atlas the resolver reaches for first still lacks them.
 *
 * ## How the batch interacts with #119
 *
 * Every push to the assets repo tars, uploads and redeploys the entire
 * published site, so the cost of publishing is set by the size of the corpus
 * and not by the size of the batch. One commit and one push per run is
 * therefore the whole of the batching rule, and {@link PROMOTION_BATCH} exists
 * to bound how long a single run takes rather than to spare the deploy.
 */

/**
 * How long an approved picture stays in staging before it moves.
 *
 * Seven days is the issue's, and it is a moderation window rather than a
 * storage one: an approval that turns out to be wrong is reversible while the
 * bytes are only in Blob, and stops being reversible once they are in a public
 * git history that cannot be rewritten. Nothing about the staging tier needs
 * the delay.
 */
export const PROMOTION_AGE_DAYS = 7;

/**
 * How many rows one run moves.
 *
 * Not a #119 batching rule. One run is one push whatever this is, so the
 * deploy costs the same for 1 row as for 200. What this bounds is the run
 * itself: the bytes it reads out of Blob, and how much work is in flight when
 * something goes wrong. At seven days behind a moderation queue that shows 240
 * at a time, a day's approvals fit comfortably inside it, and anything that
 * does not simply moves on the next run.
 */
export const PROMOTION_BATCH = 200;

/** Everything promotion needs off a row: the identity and hash the durable
 *  path is recomputed from, the staging path the bytes are read from, and the
 *  length they have to be. */
const PROMOTABLE_COLUMNS =
  "id, game, unit_name, map_name, variant, hash, mime, path, bytes, updated_at";

export interface PromotableRow {
  id: string;
  game: string | null;
  unit_name: string | null;
  map_name: string | null;
  variant: string;
  hash: string;
  mime: string;
  /** The staging pathname, suffix and all. Not the durable one. */
  path: string;
  bytes: number;
  updated_at: string;
}

/** A row with the durable path worked out, which is the only shape the rest of
 *  the run deals in. */
export interface Promotable {
  row: PromotableRow;
  /** Where it goes in the assets repo, recomputed from the identity and the
   *  hash because the staging path is not it. */
  durable: string;
}

/**
 * The instant a row has to be older than to be promoted.
 *
 * Measured on `updated_at` rather than on `created_at` or on the approval
 * event. `updated_at` is when the row last changed, which for an approved row
 * is when it was approved, and it is the value that does the right thing when
 * a newer archive replaces the bytes: the replacement puts the row back to
 * pending and restarts the seven days, so nothing is promoted on the strength
 * of a review somebody gave to different bytes.
 */
export function promotionCutoff(now: Date = new Date()): string {
  return new Date(now.getTime() - PROMOTION_AGE_DAYS * 24 * 60 * 60 * 1000).toISOString();
}

/**
 * Where a row's bytes go in the durable tier, or null when the identity cannot
 * be spelled as a path.
 *
 * Null should be unreachable: the upload route derives the same path from the
 * same columns and refuses the upload when it comes back null. It is handled
 * anyway, because a row written before a rule changed would otherwise be
 * committed under a path built out of `null`.
 */
export function durablePath(row: PromotableRow): string | null {
  return assetObjectPath(rowIdentity(row), row.hash, row.mime);
}

/**
 * The rows due to move, oldest approval first.
 *
 * Wants the secret key. The read is narrowed to approved rows anyway, so row
 * level security would not hide anything this needs, but `path` on a staging
 * row is a working public URL and the rule in `./queue` is that it stays on the
 * server.
 */
export async function fetchPromotable(
  supabase: SupabaseClient,
  limit: number = PROMOTION_BATCH,
  now: Date = new Date(),
): Promise<PromotableRow[]> {
  const { data, error } = await supabase
    .from("asset")
    .select(PROMOTABLE_COLUMNS)
    .eq("tier", "blob")
    .eq("moderation", "approved")
    .is("blob_path", null)
    .lte("updated_at", promotionCutoff(now))
    .order("updated_at", { ascending: true })
    .limit(limit);

  if (error) throw new Error(`Could not read what is due for promotion: ${error.message}`);

  return (data ?? []) as unknown as PromotableRow[];
}

/**
 * Which of these rows are still approved and still on the staging tier.
 *
 * Asked immediately before the push, because the push is permanent. Reading
 * the bytes for a batch takes as long as it takes, and a moderator rejecting
 * something in the middle of it should not have their decision overtaken by a
 * commit to a public history that cannot be rewritten.
 *
 * It narrows the window rather than closing it. A rejection landing between
 * this read and the push still commits the object, and nothing short of doing
 * the two in one transaction with git would change that. What is closed
 * afterwards is the rest of it: `promote_assets` asks the same question again
 * inside the statement that moves the rows, so a rejected row is never moved
 * and its staging copy is never deleted.
 */
export async function stillPromotable(
  supabase: SupabaseClient,
  ids: string[],
): Promise<Set<string>> {
  if (ids.length === 0) return new Set();

  const { data, error } = await supabase
    .from("asset")
    .select("id")
    .in("id", ids)
    .eq("tier", "blob")
    .eq("moderation", "approved");

  if (error) throw new Error(`Could not re-check the batch before pushing: ${error.message}`);

  return new Set((data ?? []).map((row) => (row as { id: string }).id));
}

/** A staging object whose row has already moved, and where that row points
 *  now. */
export interface PendingDeletion {
  id: string;
  /** On a `static` row this is the durable path, which has to be serving
   *  before the staging copy goes. On a `blob` row a newer archive has
   *  replaced the bytes since, and this is the replacement's staging path. */
  path: string;
  tier: string;
  /** The staging pathname to delete. */
  blob_path: string;
}

/**
 * Staging objects left over from a previous run, whether it was interrupted
 * between moving the rows and deleting them or simply could not reach Blob.
 *
 * This is the whole reason `blob_path` is a column. `path` has been overwritten
 * with the durable path by then, and the staging pathname carries a suffix
 * nobody can derive, so without this the object would be one Postgres cannot
 * name and #113 finds orphans by enumerating from Postgres.
 */
export async function fetchPendingDeletions(
  supabase: SupabaseClient,
): Promise<PendingDeletion[]> {
  const { data, error } = await supabase
    .from("asset")
    .select("id, path, tier, blob_path")
    .not("blob_path", "is", null);

  if (error) throw new Error(`Could not read the pending deletions: ${error.message}`);

  return (data ?? []) as unknown as PendingDeletion[];
}

/** Move the rows and answer with the ones that actually moved, each with the
 *  staging path it is now the only record of. */
export async function promoteRows(
  supabase: SupabaseClient,
  batch: Promotable[],
): Promise<PendingDeletion[]> {
  if (batch.length === 0) return [];

  const { data, error } = await supabase.rpc("promote_assets", {
    ids: batch.map((item) => item.row.id),
    paths: batch.map((item) => item.durable),
  });

  if (error) throw new Error(`Could not move the rows to the durable tier: ${error.message}`);

  const moved = (data ?? []) as { id: string; blob_path: string }[];
  const durable = new Map(batch.map((item) => [item.row.id, item.durable]));

  return moved.map((row) => ({
    id: row.id,
    path: durable.get(row.id) ?? "",
    tier: "static",
    blob_path: row.blob_path,
  }));
}

/** Forget staging objects that have been deleted. */
export async function clearPendingDeletions(
  supabase: SupabaseClient,
  ids: string[],
): Promise<number> {
  if (ids.length === 0) return 0;

  const { data, error } = await supabase.rpc("clear_promoted_blob_paths", { ids });

  if (error) throw new Error(`Could not clear the pending deletions: ${error.message}`);

  return typeof data === "number" ? data : 0;
}

/**
 * Everything the run does outside Postgres, as one set of injected calls.
 *
 * A port per side effect rather than the real thing, because the guarantee
 * this module is for is about the order these happen in and about what a
 * half-finished run leaves behind. Faking them is the only way to kill a run
 * between two of them and look at the state, and the alternative is spending
 * real Blob operations and writing test pictures into a git history that is
 * permanent.
 */
export interface PromotionPorts {
  /** The bytes at a URL. A plain fetch of a public object: data transfer, not
   *  an operation. */
  read(url: string): Promise<Uint8Array>;
  /** Whether the durable checkout already holds this path. Content addressed,
   *  so if it does, it holds the same bytes and there is nothing to write. */
  held(path: string): Promise<boolean>;
  /** Put the bytes in the durable checkout. */
  write(path: string, bytes: Uint8Array): Promise<void>;
  /** Commit and push everything written. Returning means it is on the default
   *  branch of the assets repo. */
  publish(paths: string[]): Promise<void>;
  /** Which of these the durable tier is actually serving, having waited a
   *  while for the ones it is not. The gate on every deletion, and the reason
   *  no deletion rests on a push having been followed by a deploy that worked. */
  serving(paths: string[]): Promise<string[]>;
  /** Remove staging objects. Free, and safe to repeat. */
  discard(paths: string[]): Promise<void>;
  say(message: string): void;
}

export interface PromotionResult {
  /** Staging objects a previous run left behind and this one deleted. */
  drained: number;
  /** Rows moved to the durable tier. */
  promoted: number;
  /** Rows the run read but did not move, each of which was said out loud. */
  skipped: number;
  /** Staging objects this run deleted for rows it promoted itself. */
  deleted: number;
}

/**
 * Delete the staging objects for rows that have already moved, once the
 * durable tier is confirmed to be serving them.
 *
 * The confirmation is the point, and it is the only place in this file that
 * deletion happens. Deletion is the one irreversible step, so it asks the
 * durable tier itself rather than trusting that a push earlier in this run, or
 * in a run days ago, was followed by a deploy that worked.
 *
 * A row that is back on the staging tier is not gated, because there is
 * nothing to gate on: a newer archive has replaced the bytes, `path` names the
 * replacement, and the object in `blob_path` is the superseded one that
 * nothing will ever point at again.
 *
 * Anything the durable tier is not serving yet is left alone and said out
 * loud. It stays in both tiers, which is the safe direction, and the next run
 * asks again.
 */
async function deletePromoted(
  supabase: SupabaseClient,
  ports: PromotionPorts,
  pending: PendingDeletion[],
): Promise<number> {
  if (pending.length === 0) return 0;

  const live = new Set(
    await ports.serving(pending.filter((row) => row.tier === "static").map((row) => row.path)),
  );

  const going: PendingDeletion[] = [];
  for (const row of pending) {
    if (row.tier !== "static" || live.has(row.path)) {
      going.push(row);
    } else {
      ports.say(`keep ${row.blob_path}: the durable tier is not serving ${row.path} yet.`);
    }
  }

  if (going.length === 0) return 0;

  await ports.discard(going.map((row) => row.blob_path));
  await clearPendingDeletions(
    supabase,
    going.map((row) => row.id),
  );

  return going.length;
}

/**
 * One run.
 *
 * Reads the numbered order at the top of this file. Nothing is reordered for
 * convenience, and every `await` between two steps is load bearing.
 */
export async function runPromotion(
  supabase: SupabaseClient,
  ports: PromotionPorts,
  options: { limit?: number; now?: Date } = {},
): Promise<PromotionResult> {
  // 1. Whatever a previous run left in both tiers.
  const drained = await deletePromoted(supabase, ports, await fetchPendingDeletions(supabase));
  if (drained > 0) ports.say(`Deleted ${drained} staging object(s) left by an earlier run.`);

  // 2. What is due.
  const rows = await fetchPromotable(supabase, options.limit ?? PROMOTION_BATCH, options.now);
  if (rows.length === 0) {
    ports.say("Nothing is due for promotion.");
    return { drained, promoted: 0, skipped: 0, deleted: 0 };
  }

  // 3. The bytes, into the checkout, at the path the identity and hash name.
  const batch: Promotable[] = [];
  let skipped = 0;

  for (const row of rows) {
    const durable = durablePath(row);
    if (!durable) {
      ports.say(`skip ${row.id}: its identity and hash do not make a storable path.`);
      skipped++;
      continue;
    }

    if (await ports.held(durable)) {
      // Either an earlier run pushed it and died before moving the row, or
      // another row is the same picture. Content addressed, so the bytes there
      // are these bytes, and overwriting would be a change to a file that is
      // already published.
      batch.push({ row, durable });
      continue;
    }

    const bytes = await ports.read(blobTierUrl(row.path));

    // The durable tier's history cannot be rewritten, so a short read or an
    // error page has to be caught before the commit rather than after it. The
    // upload route checks the body against this same number before it stores
    // anything, so a row that disagrees is a row about bytes that are not
    // there any more.
    if (bytes.byteLength !== row.bytes) {
      ports.say(
        `skip ${row.id}: the store returned ${bytes.byteLength} bytes and the row says ${row.bytes}.`,
      );
      skipped++;
      continue;
    }

    await ports.write(durable, bytes);
    batch.push({ row, durable });
  }

  if (batch.length === 0) {
    return { drained, promoted: 0, skipped, deleted: 0 };
  }

  // 4. Last look before the permanent bit, then one commit and one push.
  const surviving = await stillPromotable(
    supabase,
    batch.map((item) => item.row.id),
  );
  const pushing = batch.filter((item) => surviving.has(item.row.id));
  skipped += batch.length - pushing.length;

  if (pushing.length === 0) {
    ports.say("Every row in the batch changed state while its bytes were being read.");
    return { drained, promoted: 0, skipped, deleted: 0 };
  }

  await ports.publish(pushing.map((item) => item.durable));

  // 5. Not "we pushed", but "the durable tier is serving it". Fatal rather
  // than a skip: the rows must not say `static` while the tier 404s, and the
  // objects are all still in Blob, so stopping here loses nothing.
  const live = new Set(await ports.serving(pushing.map((item) => item.durable)));
  const missing = pushing.filter((item) => !live.has(item.durable));
  if (missing.length > 0) {
    throw new Error(
      `Pushed ${pushing.length} object(s) and the durable tier is serving ${
        pushing.length - missing.length
      }. Nothing has been moved. First missing: ${missing[0].durable}`,
    );
  }

  // 6. All of them or none, and the staging paths come back out.
  const moved = await promoteRows(supabase, pushing);
  skipped += pushing.length - moved.length;

  // 7. The only irreversible step, and the last one. It asks the durable tier
  // a second time, which step 5 has already answered, and that repetition is
  // deliberate: every deletion in this file goes through one function that
  // checks for itself, rather than some of them inheriting a check made
  // earlier by a caller.
  const deleted = await deletePromoted(supabase, ports, moved);

  return { drained, promoted: moved.length, skipped, deleted };
}
