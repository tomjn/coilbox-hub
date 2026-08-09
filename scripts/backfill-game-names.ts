/**
 * One off backfill for `item.game_name` (issue #38).
 *
 * https://github.com/tomjn/coilbox-hub/pull/37 changed how game_name is
 * derived: `describe()` in lib/gallery/publish.ts now reads it from
 * `identify()`'s unified `game` field instead of a per-kind read. Rows
 * published before that change still hold whatever the old per-kind read
 * produced - a preset's exact pinned build instead of its stable shortname,
 * and null for a scenario or a challenge, which never had a per-kind read at
 * all. The container column already holds everything needed to redo the
 * derivation, so this replays it rather than asking anyone to republish.
 *
 * A migration cannot do this: the derivation is TypeScript, and reimplementing
 * it in plpgsql would be exactly the duplication PR #37 removed. So this
 * imports `identify()` and `describe()` and runs them here, against a plain
 * admin client, with no re-upload from anyone.
 *
 *   bun run backfill:game-names            dry run, prints changes, writes nothing
 *   bun run backfill:game-names --write    applies them
 *
 * A row identify() cannot read as a known gallery kind is left alone rather
 * than written to null. Overwriting a good name because one payload turned out
 * to be unreadable would be worse than leaving it as it was. A payload that
 * reads fine but genuinely names no game still gets null, the same as a fresh
 * publish of it would.
 *
 * Safe to run twice: a row already holding the derived value compares equal
 * and is left alone, so a second run (dry or live) reports nothing to change.
 */

export {}; // top level await needs this file to be a module

import { createClient } from "@supabase/supabase-js";
import { GALLERY_KINDS, identify, type GalleryKind } from "@/lib/container";
import { describe } from "@/lib/gallery/publish";
import { fetchAllPages } from "@/lib/gallery/query";

const PAGE_SIZE = 1000;
const COLUMNS = "id,kind,title,game_name,container";

interface Row {
  id: string;
  kind: string;
  title: string;
  game_name: string | null;
  container: unknown;
}

function isGalleryKind(kind: string): kind is GalleryKind {
  return (GALLERY_KINDS as readonly string[]).includes(kind);
}

const write = process.argv.includes("--write");

const url = process.env.NEXT_PUBLIC_SUPABASE_URL;
const key = process.env.SUPABASE_SERVICE_ROLE_KEY;
if (!url || !key) {
  console.error(
    "Need NEXT_PUBLIC_SUPABASE_URL and SUPABASE_SERVICE_ROLE_KEY set. " +
      "See .env.development.local for the local stack.",
  );
  process.exit(1);
}

// Which database, before anything is read or written. Bun loads .env.local as
// readily as .env.development.local, so whether this is pointed at production
// or the local stack comes down to which file happened to win, and the two look
// identical from the output alone.
console.log(`${write ? "Writing to" : "Reading"} ${new URL(url).host}`);

// Service role, not the session-backed client the app uses: this has to touch
// every row regardless of author, and game_name sits outside the columns
// item_update_own grants an authenticated user (see the gallery_items
// migration), by design, so no authenticated client could write it anyway.
const supabase = createClient(url, key, {
  auth: { autoRefreshToken: false, persistSession: false },
});

const { data: rows, error } = await fetchAllPages<Row>(
  async (from, to) =>
    await supabase
      .from("item")
      .select(COLUMNS, { count: "exact" })
      .order("created_at", { ascending: true })
      .range(from, to),
  PAGE_SIZE,
);

if (error) {
  console.error(`Could not read item: ${error}`);
  process.exit(1);
}

let changed = 0;
let skipped = 0;
let failed = 0;

for (const row of rows) {
  const result = identify(row.container);

  if (result.kind === "unknown" || !isGalleryKind(result.kind)) {
    // Not a container identify() recognises as a gallery kind - leave
    // whatever game_name already holds rather than guess.
    console.warn(
      `skip ${row.id} (${row.kind} "${row.title}"): container does not identify as a gallery kind, left unchanged`,
    );
    skipped++;
    continue;
  }

  const container = row.container as { payload: unknown };
  const { gameName } = describe(result.kind, container.payload, result.game);

  if (gameName === row.game_name) continue;

  changed++;
  console.log(
    `${write ? "update" : "would update"} ${row.id} (${result.kind} "${row.title}"): ${JSON.stringify(row.game_name)} -> ${JSON.stringify(gameName)}`,
  );

  if (write) {
    const { error: updateError } = await supabase
      .from("item")
      .update({ game_name: gameName })
      .eq("id", row.id);
    if (updateError) {
      console.error(`  failed: ${updateError.message}`);
      failed++;
    }
  }
}

console.log(
  `${rows.length} rows scanned, ${changed} ${write ? "updated" : "would update"}, ${skipped} skipped (unreadable), ${failed} failed.`,
);
if (!write && changed > 0) {
  console.log("Dry run only. Re-run with --write to apply.");
}
if (failed > 0) process.exit(1);
