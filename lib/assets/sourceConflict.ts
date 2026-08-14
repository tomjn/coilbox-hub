import type { SupabaseClient } from "@supabase/supabase-js";

/**
 * A second set of source bytes from the same archive (issue #116).
 *
 * The same archive is the same file, and extraction is deterministic, so two
 * installs of `bar_1.2.sdz` holding a buildpic for `armsolar` produce the same
 * raw bytes. A different `source_hash` from a *different* archive is an
 * ordinary version rollover and is accepted silently. A different `source_hash`
 * from the *same* archive is genuinely odd, either a modified client or a
 * corrupted install, and the picture is worth a proper look before it goes out.
 *
 * ## On `source_hash` and never on `hash`
 *
 * `source_hash` is over the raw archive bytes and `hash` is over the encoded
 * output. Encoding is not deterministic across Coilbox releases or libwebp
 * builds, so a rule that compared encoded hashes would fire on every one of
 * those upgrades, for the whole corpus at once, and the signal would be ignored
 * inside a week.
 *
 * ## A signal, not a gate
 *
 * Nothing here refuses an upload, holds a row back, or changes what a moderator
 * may do. A flagged upload is stored and queued exactly like any other. All it
 * does is mark the tile and leave it unticked, so the one picture in the sheet
 * that is not what it claims to be gets looked at rather than swept up by the
 * approve-everything button.
 *
 * ## Why nothing automated sits behind it
 *
 * No IWF hash list, no PhotoDNA, no equivalent, and this is a decision rather
 * than an omission. Those services match uploads against hashes of known
 * material, and they exist for platforms taking more volume than they can
 * review by hand. Here every untrusted upload is human reviewed before it is
 * served, the volume is long tail only, and the content is mechanically derived
 * from game archives, so anything anomalous is conspicuous in a grid.
 *
 * The shorthand for this is wrong, so it is worth saying precisely. It is not
 * that arbitrary image upload is impossible: a modified client can send any
 * bytes it likes, and this file exists because of that. It is that the queue
 * reviews all of it first.
 *
 * Revisit if any of those stops being true. If untrusted uploads are ever
 * approved automatically, if the queue is bypassed for any class of user, or if
 * a high volume upload channel appears, the argument above no longer holds and
 * the answer has to be reconsidered from the start rather than patched.
 */

/** A disagreement worth recording, as {@link recordSourceConflict} writes it. */
export interface SourceConflict {
  assetId: string;
  /** The archive both sides named, and the reason this is a conflict at all. */
  sourceArchive: string;
  /** What the row held when the upload arrived. */
  heldSourceHash: string;
  /** What the upload declared instead. */
  reportedSourceHash: string;
  /** The account that declared it, which on the case the issue is about is not
   * the account that uploaded the row. */
  reportedBy: string;
}

/** What the rule needs off the row the identity already has. */
interface HeldAsset {
  id: string;
  source_hash: string;
  source_archive: string;
  moderation: string;
}

/** What the rule needs off the declaration. */
interface DeclaredSource {
  sourceHash: string;
  sourceArchive: string;
}

/**
 * The disagreement this upload reports, or null when there is none.
 *
 * A rejected row is left alone. It is out of the queue, so a mark on it would
 * be a mark nobody ever sees, and a safety rejection is a row #115 asks nothing
 * in the hub to add to. The two rows that can still be reviewed are the two
 * worth flagging.
 */
export function sourceConflict(
  held: HeldAsset,
  declared: DeclaredSource,
  reportedBy: string,
): SourceConflict | null {
  if (held.moderation === "rejected") return null;
  if (held.source_archive !== declared.sourceArchive) return null;
  if (held.source_hash === declared.sourceHash) return null;

  return {
    assetId: held.id,
    sourceArchive: declared.sourceArchive,
    heldSourceHash: held.source_hash,
    reportedSourceHash: declared.sourceHash,
    reportedBy,
  };
}

/**
 * Record a disagreement, and say nothing to the caller either way.
 *
 * Best effort, like `recordUploadIp`. This is a hint for a reviewer and the
 * upload's fate is already decided by the time it runs, so failing the request
 * over a hint would trade a working upload for a mark on a tile.
 *
 * An upsert rather than an insert, against the unique index on
 * `(asset_id, reported_source_hash)`, so a client looping on the same refused
 * upload leaves one row rather than as many as it can send. The same bytes
 * reported twice are the same fact reported twice.
 */
export async function recordSourceConflict(
  supabase: SupabaseClient,
  conflict: SourceConflict,
): Promise<boolean> {
  const { error } = await supabase.from("asset_source_conflict").upsert(
    {
      asset_id: conflict.assetId,
      source_archive: conflict.sourceArchive,
      held_source_hash: conflict.heldSourceHash,
      reported_source_hash: conflict.reportedSourceHash,
      reported_by: conflict.reportedBy,
    },
    { onConflict: "asset_id,reported_source_hash", ignoreDuplicates: true },
  );

  return !error;
}

/**
 * Which of these assets somebody has reported different source bytes for.
 *
 * A query of its own rather than an embedded select on the queue's read, so the
 * grid does not depend on PostgREST having noticed the new foreign key. A
 * schema cache that has not reloaded yet would turn the whole queue into an
 * error rather than a page with no marks on it.
 *
 * A failed read comes back empty, which is the one direction to fail in: an
 * unmarked tile is the grid the reviewer had before this existed, and an error
 * would take the queue away over a hint.
 */
export async function fetchSourceConflicts(
  supabase: SupabaseClient,
  assetIds: string[],
): Promise<Set<string>> {
  if (assetIds.length === 0) return new Set();

  const { data } = await supabase
    .from("asset_source_conflict")
    .select("asset_id")
    .in("asset_id", assetIds);

  return new Set(((data ?? []) as { asset_id: string }[]).map((row) => row.asset_id));
}
