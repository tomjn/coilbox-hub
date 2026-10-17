import type { SupabaseClient } from "@supabase/supabase-js";
import { pictureCaption, QUEUE_PAGE_SIZE } from "./queue";

/**
 * Source disagreements on pictures that are already approved (issue #149).
 *
 * `./sourceConflict` records a second account reporting different bytes out of
 * the same archive, and the contact sheet marks a pending tile. An approved
 * picture is not in the queue, so this is the other half: a list for a
 * moderator to look at. It changes no state. Putting an approved picture back
 * to pending on a stranger's report would let any signed in account de-publish
 * pictures, so a moderator decides, and rejects through the usual action.
 *
 * Both calls go through the moderator's own session. The database functions in
 * `20261019110000_approved_source_conflicts.sql` ask `is_moderator()` themselves
 * and the table stays closed to every browser role, so nothing here widens a
 * grant or reads with the secret key.
 */

/** One disagreement, as the list shows it. */
export interface ApprovedSourceConflict {
  conflictId: number;
  assetId: string;
  /** What the picture is of, and which one. */
  name: string;
  detail: string;
  sourceArchive: string;
  /** The bytes the hub holds. */
  heldSourceHash: string;
  /** The bytes the other account declared. */
  reportedSourceHash: string;
  /** Null once the reporting account has closed. */
  reportedBy: string | null;
  /** Who uploaded the bytes the hub holds, null on the same terms. */
  uploadedBy: string | null;
  at: string;
}

interface ConflictRow {
  conflict_id: number;
  asset_id: string;
  game: string | null;
  unit_name: string | null;
  map_name: string | null;
  faction_key: string | null;
  variant: string;
  source_archive: string;
  held_source_hash: string;
  reported_source_hash: string;
  reported_by: string | null;
  uploaded_by: string | null;
  at: string;
  total: number;
}

export interface ApprovedSourceConflicts {
  conflicts: ApprovedSourceConflict[];
  /** Everything open, which is more than `conflicts` once the list passes a
   * page. */
  total: number;
  /** Set when the read failed, so a list that did not load does not read as a
   * list with nothing on it. */
  error: string | null;
}

/**
 * The open disagreements on approved pictures, oldest first.
 *
 * A page is the moderation queue's own {@link QUEUE_PAGE_SIZE}, passed to the
 * database so it does not hold a second number.
 */
export async function fetchApprovedSourceConflicts(
  supabase: SupabaseClient,
  limit = QUEUE_PAGE_SIZE,
): Promise<ApprovedSourceConflicts> {
  const { data, error } = await supabase.rpc("approved_source_conflicts", { p_limit: limit });

  if (error) return { conflicts: [], total: 0, error: error.message };

  const rows = (data ?? []) as unknown as ConflictRow[];

  return {
    conflicts: rows.map((row) => ({
      conflictId: row.conflict_id,
      assetId: row.asset_id,
      ...pictureCaption(row),
      sourceArchive: row.source_archive,
      heldSourceHash: row.held_source_hash,
      reportedSourceHash: row.reported_source_hash,
      reportedBy: row.reported_by,
      uploadedBy: row.uploaded_by,
      at: row.at,
    })),
    total: rows[0]?.total ?? 0,
    error: null,
  };
}

/** Mark a disagreement as looked at. False when nothing was cleared, because
 * the entry is gone or already cleared, or because the call was refused. */
export async function acknowledgeSourceConflict(
  supabase: SupabaseClient,
  id: number,
): Promise<boolean> {
  const { data, error } = await supabase.rpc("acknowledge_source_conflict", {
    p_conflict_id: id,
  });

  return !error && data === true;
}

/** An entry id taken off a form field, or null when it is not a plain positive
 * whole number that fits exactly in a JavaScript number. */
export function conflictId(value: string): number | null {
  if (!/^[0-9]+$/.test(value)) return null;

  const id = Number(value);

  return Number.isSafeInteger(id) && id > 0 ? id : null;
}
