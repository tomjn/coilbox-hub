/**
 * Find the pictures Vercel Blob will not return, and mark them so Coilbox
 * uploads them again (issue #336). The reasoning is in
 * `lib/assets/unreadable.ts`.
 *
 *   bun run find:unreadable-blob --dry-run
 *   bun run find:unreadable-blob --write
 *
 * Either way it reads every non-rejected row on `tier = 'blob'` and makes one GET
 * of each row's Blob URL. A GET is data transfer, not an operation, so a run
 * spends none of the allowance that suspended the store, and needs no Blob
 * token.
 *
 * A dry run stops there and says what it would mark and clear. It writes
 * nothing, so it can be pointed at production.
 *
 * `--write` marks the rows whose bytes are lost and clears the mark on rows whose
 * bytes came back. It is safe to run again: a second run finds nothing new to
 * mark, and after the store answers again it clears what the first run marked.
 *
 * It exits non-zero when any row got no usable answer, because those were
 * neither marked nor cleared and need another run.
 */

export {}; // top level await needs this file to be a module

import { createClient } from "@supabase/supabase-js";
import { blobTierUrl } from "@/lib/assets/blob";
import {
  type BlobRead,
  clearBytesMissing,
  fetchBlobAssetRows,
  findUnreadable,
  markBytesMissing,
} from "@/lib/assets/unreadable";

const args = process.argv.slice(2);

if (args.includes("--write") && args.includes("--dry-run")) {
  console.error("Asked for both --write and --dry-run. Pick one.");
  process.exit(1);
}

const write = args.includes("--write");

const url = process.env.NEXT_PUBLIC_SUPABASE_URL;
const key = process.env.SUPABASE_SERVICE_ROLE_KEY;
if (!url || !key) {
  console.error(
    "Need NEXT_PUBLIC_SUPABASE_URL and SUPABASE_SERVICE_ROLE_KEY set. " +
      "See .env.development.local for the local stack.",
  );
  process.exit(1);
}

// Which database, before anything is read or written, for the reason
// `backfill-game-names.ts` gives.
console.log(`${write ? "Marking against" : "Reading"} ${new URL(url).host}`);

const supabase = createClient(url, key, {
  auth: { autoRefreshToken: false, persistSession: false },
});

async function read(path: string): Promise<BlobRead> {
  try {
    const response = await fetch(blobTierUrl(path), { cache: "no-store" });
    return { status: response.status, byteLength: (await response.arrayBuffer()).byteLength };
  } catch {
    return { status: null, byteLength: 0 };
  }
}

const rows = await fetchBlobAssetRows(supabase);
console.log(`${rows.length} row(s) in Blob that are not rejected. Asking the store for each.`);

const findings = await findUnreadable(rows, read, (message) => console.log(message));

const statuses = [...findings.statuses].map(([status, count]) => `${count} x ${status}`).join(", ");
console.log(`The store answered: ${statuses || "nothing to ask"}.`);

if (write) {
  const marked = await markBytesMissing(
    supabase,
    findings.lost.map((row) => row.id),
  );
  const cleared = await clearBytesMissing(
    supabase,
    findings.back.map((row) => row.id),
  );
  console.log(
    `Marked ${marked} as lost and cleared ${cleared}. ${findings.stillLost} were marked already and ` +
      `still lost, ${findings.readable} are readable, ${findings.unknown.length} got no usable answer.`,
  );
} else {
  console.log(
    `Would mark ${findings.lost.length} as lost and clear ${findings.back.length}. ` +
      `${findings.stillLost} are marked already and still lost, ${findings.readable} are readable, ` +
      `${findings.unknown.length} got no usable answer. Dry run only. Re-run with --write to apply.`,
  );
}

if (findings.unknown.length > 0) process.exitCode = 1;
