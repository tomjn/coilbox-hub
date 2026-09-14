import type { SupabaseClient } from "@supabase/supabase-js";
import { fetchAllPages } from "@/lib/gallery/query";
import { queryChunks } from "./have";

/**
 * Finding the pictures Vercel Blob will not return, and letting Coilbox upload
 * them again (issue #336).
 *
 * In September 2026 Vercel suspended the Blob store for 30 days, and it answers
 * 403 for every object. Every row still on `tier = 'blob'` then named bytes
 * nobody could read, while `/api/v1/assets/have` told Coilbox the hub held them.
 *
 * ## Mark, not delete
 *
 * A marked row keeps its identity, its moderation history and its `asset_event`
 * trail. `bytes_missing_at` is what changes, and everything that reads a row
 * for its bytes skips a marked one:
 *
 * - `have` answers missing (`heldForHave` in `./have`)
 * - the upload takes the same `source_hash` back (`checkAssetUpload` in
 *   `./upload`) and moves the row to the bucket, clearing the mark
 * - pages show the fallback (`fetchHeldAssets` in `./resolve`)
 * - promotion does not select it (`fetchPromotable` in `./promote`)
 *
 * The tier and path stay, so a mark can be taken back. If the store answers
 * again, the next run clears the mark and promotion drains the row like any
 * other, which matters more than it sounds: a picture nobody uploads again is
 * otherwise hidden for good.
 *
 * ## What counts as lost
 *
 * One GET per row. It is a read, which Blob meters as data transfer and not as
 * an operation, so it spends none of the allowance that suspended the store.
 *
 * - any 4xx: the store refused this object. A suspended store says 403 and a
 *   deleted object 404, and either way nobody can read it
 * - a 2xx whose body is not the length the row says: promotion would refuse
 *   those bytes anyway, so the row is as stuck as a refused one
 *
 * Anything else, a 5xx or a request that never got an answer, is not evidence
 * either way. It marks nothing and clears nothing, and the run says so.
 *
 * A rejected row is left out. An upload cannot undo a rejection, so marking one
 * would change nothing a client could act on.
 */

/** What reading a row's bytes needs, and whether it is marked already. */
export interface BlobAssetRow {
  id: string;
  path: string;
  bytes: number;
  bytes_missing_at: string | null;
}

/** What one GET said. `status` is null when no answer came back at all. */
export interface BlobRead {
  status: number | null;
  byteLength: number;
}

export type Readability = "readable" | "lost" | "unknown";

/** `supabase/config.toml` sets `max_rows = 1000`. `fetchAllPages` follows the
 *  count, so a lower ceiling in the cloud only costs more requests. */
const PAGE_SIZE = 1000;

export function readability(read: BlobRead, expectedBytes: number): Readability {
  if (read.status === null) return "unknown";
  if (read.status >= 200 && read.status < 300) {
    return read.byteLength === expectedBytes ? "readable" : "lost";
  }
  if (read.status >= 400 && read.status < 500) return "lost";
  return "unknown";
}

/**
 * Every row that says its bytes are in Blob and is not rejected, marked or not.
 *
 * Wants the secret key. Pending rows are hidden from everybody else, and a
 * pending Blob path is a working public URL to bytes nobody has reviewed.
 */
export async function fetchBlobAssetRows(supabase: SupabaseClient): Promise<BlobAssetRow[]> {
  const { data, error } = await fetchAllPages<BlobAssetRow>(
    async (from, to) =>
      await supabase
        .from("asset")
        .select("id, path, bytes, bytes_missing_at", { count: "exact" })
        .eq("tier", "blob")
        .neq("moderation", "rejected")
        .order("id")
        .range(from, to),
    PAGE_SIZE,
  );

  if (error) throw new Error(`Could not read the rows in Blob: ${error}`);
  return data;
}

export interface UnreadableFindings {
  /** Not marked, and the store would not return the bytes. To mark. */
  lost: BlobAssetRow[];
  /** Marked, and the store returned the bytes. To clear. */
  back: BlobAssetRow[];
  /** Marked, and still lost. Nothing to do. */
  stillLost: number;
  /** Not marked, and readable. Nothing to do. */
  readable: number;
  /** No usable answer, so neither marked nor cleared. */
  unknown: BlobAssetRow[];
  /** How many rows got each answer, keyed by status or `no answer`. */
  statuses: Map<string, number>;
}

/**
 * Ask the store for each row's bytes, one row at a time, and sort the rows by
 * what that means for their mark. Writes nothing.
 *
 * `read` is the GET, injected so a test never reaches the store. It resolves
 * with a null status rather than throwing when the request itself failed.
 */
export async function findUnreadable(
  rows: BlobAssetRow[],
  read: (path: string) => Promise<BlobRead>,
  say: (message: string) => void,
): Promise<UnreadableFindings> {
  const findings: UnreadableFindings = {
    lost: [],
    back: [],
    stillLost: 0,
    readable: 0,
    unknown: [],
    statuses: new Map(),
  };

  for (const row of rows) {
    const answer = await read(row.path);
    const label = answer.status === null ? "no answer" : String(answer.status);
    findings.statuses.set(label, (findings.statuses.get(label) ?? 0) + 1);

    const marked = row.bytes_missing_at !== null;
    switch (readability(answer, row.bytes)) {
      case "lost":
        if (marked) {
          findings.stillLost++;
        } else {
          findings.lost.push(row);
          say(`lost ${row.id}: ${label} with ${answer.byteLength} of ${row.bytes} bytes, ${row.path}`);
        }
        break;
      case "readable":
        if (marked) {
          findings.back.push(row);
          say(`back ${row.id}: the store returned its bytes again, ${row.path}`);
        } else {
          findings.readable++;
        }
        break;
      case "unknown":
        findings.unknown.push(row);
        say(`unknown ${row.id}: ${label}, so its mark is left as it is, ${row.path}`);
        break;
    }
  }

  return findings;
}

/**
 * Mark these rows as holding bytes the store lost, and answer with how many
 * were marked.
 *
 * Only a row still in Blob and not marked already. A row an upload moved to the
 * bucket between the read and this write has its bytes, and is left alone.
 */
export async function markBytesMissing(
  supabase: SupabaseClient,
  ids: string[],
  now: Date = new Date(),
): Promise<number> {
  let marked = 0;
  for (const chunk of queryChunks(ids)) {
    const { data, error } = await supabase
      .from("asset")
      .update({ bytes_missing_at: now.toISOString() })
      .in("id", chunk)
      .eq("tier", "blob")
      .is("bytes_missing_at", null)
      .select("id");

    if (error) throw new Error(`Could not mark the rows whose bytes are lost: ${error.message}`);
    marked += data?.length ?? 0;
  }
  return marked;
}

/**
 * Clear the mark on rows whose bytes the store returned again, and answer with
 * how many were cleared. Promotion takes them from here, a day after this write,
 * because the write moves `updated_at`.
 */
export async function clearBytesMissing(supabase: SupabaseClient, ids: string[]): Promise<number> {
  let cleared = 0;
  for (const chunk of queryChunks(ids)) {
    const { data, error } = await supabase
      .from("asset")
      .update({ bytes_missing_at: null })
      .in("id", chunk)
      .eq("tier", "blob")
      .not("bytes_missing_at", "is", null)
      .select("id");

    if (error) throw new Error(`Could not clear the rows whose bytes are back: ${error.message}`);
    cleared += data?.length ?? 0;
  }
  return cleared;
}
