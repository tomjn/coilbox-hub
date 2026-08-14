import type { SupabaseClient } from "@supabase/supabase-js";
import type { AssetIdentity } from "./asset";

/**
 * The database half of the batch have check (issue #103): given a list of
 * identity keys, what `source_hash` does the hub hold for each.
 *
 * Answered entirely from `public.asset`. Nothing here goes near Vercel Blob:
 * `head()` would be a metered simple operation asking a question the row
 * already answers, and `list()` spends the advanced allowance that uploads
 * live on. `lib/assets/blob.ts` does not export either one.
 */

/** The five columns an answer needs. Not `hash`, which is over the encoded
 * bytes and differs between Coilbox releases and libwebp builds, so comparing
 * on it would report the whole corpus as changed after any encoder upgrade. */
const HAVE_COLUMNS = "game, unit_name, map_name, variant, source_hash";

/**
 * How many identity keys go into one PostgREST request.
 *
 * The filter travels in the query string, so the ceiling here is URL length
 * rather than anything about the database. Each group is around 60 characters
 * before encoding and punctuation roughly triples under `encodeURIComponent`,
 * which puts 50 groups near 5 kB and leaves room under the 8 kB request line
 * most proxies allow. A batch at the route's limit is therefore ten requests,
 * issued together.
 */
const KEYS_PER_QUERY = 50;

interface AssetHaveRow {
  game: string | null;
  unit_name: string | null;
  map_name: string | null;
  variant: string;
  source_hash: string;
}

/**
 * One string per identity, for looking a row up against the key that was
 * asked for. Separated by a null character, which no column here can contain,
 * so `("bar", "armsolar")` and `("bar\u0000armsolar", "")` cannot collide.
 */
export function identityKey(identity: AssetIdentity): string {
  return identity.keyedOn === "unit"
    ? ["unit", identity.game, identity.unitName, identity.variant].join("\u0000")
    : ["map", identity.mapName, identity.variant].join("\u0000");
}

/**
 * A value as a PostgREST filter operand.
 *
 * Always quoted, never conditionally. A map name is the full canonical name
 * the engine reports and is free text: commas, brackets and full stops all
 * end a filter early if they arrive bare, and `postgrest-js` only quotes when
 * it spots a reserved character and never escapes a quote inside the value.
 * Quoting everything and escaping the two characters that matter inside the
 * quotes is one rule that holds for every value rather than a test that has to
 * keep pace with the grammar.
 */
function operand(value: string): string {
  return `"${value.replace(/\\/g, "\\\\").replace(/"/g, '\\"')}"`;
}

/**
 * The filter for one identity, as a PostgREST `and(...)` group.
 *
 * Written out per key rather than as three `in` lists, because three lists
 * describe the cross product of everything asked for. Fifty keys naming fifty
 * games and fifty units would ask for 125,000 combinations and answer for
 * whichever of them happen to exist, which is both the wrong answer and an
 * unbounded amount of work for a caller to ask for.
 *
 * A unit group names `game` as well, since unit names are not unique across
 * games. A map group does not, since a map is not scoped to one.
 */
export function identityFilter(identity: AssetIdentity): string {
  return identity.keyedOn === "unit"
    ? `and(game.eq.${operand(identity.game)},unit_name.eq.${operand(identity.unitName)},variant.eq.${operand(identity.variant)})`
    : `and(map_name.eq.${operand(identity.mapName)},variant.eq.${operand(identity.variant)})`;
}

/** Splits a batch into the requests it will take. */
export function queryChunks<T>(identities: T[], size = KEYS_PER_QUERY): T[][] {
  const chunks: T[][] = [];
  for (let start = 0; start < identities.length; start += size) {
    chunks.push(identities.slice(start, start + size));
  }
  return chunks;
}

/** Which key a row answers for, read back from the columns. `map_name` is set
 * on a map row and null on a unit row, and `asset_identity_check` in the
 * migration is what makes that a safe thing to test. */
function rowIdentity(row: AssetHaveRow): AssetIdentity {
  return row.map_name === null
    ? {
        keyedOn: "unit",
        game: row.game ?? "",
        unitName: row.unit_name ?? "",
        variant: row.variant,
      }
    : { keyedOn: "map", mapName: row.map_name, variant: row.variant };
}

export type SourceHashLookup =
  | { ok: true; sourceHashes: Map<string, string> }
  | { ok: false };

/**
 * The `source_hash` the hub holds for each of these identities, keyed by
 * {@link identityKey}. An identity the hub has no row for is simply absent
 * from the map, which is what tells a missing asset apart from a changed one.
 *
 * Every moderation state counts, so this wants a client that bypasses row
 * level security. Read `lib/supabase/admin.ts` for why, and the route for what
 * it does and does not pass back to a caller as a result.
 *
 * Each chunk can return at most one row per key, because both identity indexes
 * are unique, so PostgREST's default thousand row ceiling is never in reach and
 * an answer is never quietly truncated into a false "missing".
 */
export async function fetchAssetSourceHashes(
  supabase: SupabaseClient,
  identities: AssetIdentity[],
): Promise<SourceHashLookup> {
  const chunks = queryChunks(identities);

  const responses = await Promise.all(
    chunks.map((chunk) =>
      supabase
        .from("asset")
        .select(HAVE_COLUMNS)
        .or(chunk.map(identityFilter).join(",")),
    ),
  );

  const sourceHashes = new Map<string, string>();
  for (const { data, error } of responses) {
    if (error || !data) return { ok: false };
    for (const row of data as unknown as AssetHaveRow[]) {
      sourceHashes.set(identityKey(rowIdentity(row)), row.source_hash);
    }
  }

  return { ok: true, sourceHashes };
}
