/**
 * One off backfill for `item.map_name` on scenarios (coilbox #2600).
 *
 * `describe()` in lib/gallery/publish.ts now reads a scenario's map from
 * `payload.scenario.setup.mapName` (or a bare document's `setup.mapName`) at
 * publish time. Scenarios published before that change - including one from
 * a much older coilbox, from early testing - still hold `map_name: null`,
 * which is both why their card cannot draw a minimap and why the map filter
 * cannot find them at all. The container column already holds everything
 * needed to redo the derivation, so this replays it rather than asking
 * anyone to republish.
 *
 * Every gallery kind is re-derived through the same `describe()` call, not
 * only scenarios, for the same reason scripts/backfill-game-names.ts covers
 * every kind: it is one read of the same function a fresh publish uses, and
 * a preset or setup-pack row already holding the right value compares equal
 * and is left alone.
 *
 *   bun run backfill:map-names            dry run, prints changes, writes nothing
 *   bun run backfill:map-names --write    applies them
 *
 * A row identify() cannot read as a known gallery kind is left alone rather
 * than written to null, the same rule backfill-game-names.ts follows: a
 * payload that turns out unreadable must cost nothing, not overwrite a good
 * value with a guess. A payload that reads fine but genuinely names no map
 * still gets null, the same as a fresh publish of it would - an empty or
 * whitespace-only mapName is an invalid map name for a scenario, not a valid
 * empty one, so it is never stored as `""`.
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
const COLUMNS = "id,kind,title,map_name,container";

interface Row {
  id: string;
  kind: string;
  title: string;
  map_name: string | null;
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
// every row regardless of author, and map_name sits outside the columns
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
    // whatever map_name already holds rather than guess.
    console.warn(
      `skip ${row.id} (${row.kind} "${row.title}"): container does not identify as a gallery kind, left unchanged`,
    );
    skipped++;
    continue;
  }

  const container = row.container as { payload: unknown };
  const { mapName } = describe(result.kind, container.payload, result.game);

  if (mapName === row.map_name) continue;

  changed++;
  console.log(
    `${write ? "update" : "would update"} ${row.id} (${result.kind} "${row.title}"): ` +
      `map ${JSON.stringify(row.map_name)} -> ${JSON.stringify(mapName)}`,
  );

  if (write) {
    const { error: updateError } = await supabase
      .from("item")
      .update({ map_name: mapName })
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
