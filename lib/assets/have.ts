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

/** The columns an answer needs. Not `hash`, which is over the encoded bytes and
 * differs between Coilbox releases and libwebp builds, so comparing on it would
 * report the whole corpus as changed after any encoder upgrade.
 *
 * `moderation` and `bytes_missing_at` decide whether a row counts as held at
 * all. See {@link heldForHave}. */
const HAVE_COLUMNS =
  "game, unit_name, map_name, faction_key, variant, source_hash, moderation, bytes_missing_at";

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
  faction_key: string | null;
  variant: string;
  source_hash: string;
  moderation: string;
  bytes_missing_at: string | null;
}

/**
 * Whether a row means the hub holds the picture, for the have check.
 *
 * Not when the store it names has lost the bytes (#336). The row stays for its
 * history, but answering `have` for it would stop the one client that still has
 * the file from ever offering it again, and the upload rules take the bytes back
 * even with the same `source_hash`.
 *
 * Except when it is rejected. An upload cannot undo a rejection, so answering
 * missing would have a client send it again on every run and be refused every
 * time.
 */
export function heldForHave(row: { moderation: string; bytes_missing_at: string | null }): boolean {
  return row.bytes_missing_at === null || row.moderation === "rejected";
}

/**
 * One string per identity, for looking a row up against the key that was
 * asked for. Separated by a null character, which no column here can contain,
 * so `("bar", "armsolar")` and `("bar\u0000armsolar", "")` cannot collide.
 */
export function identityKey(identity: AssetIdentity): string {
  switch (identity.keyedOn) {
    case "unit":
      return ["unit", identity.game, identity.unitName, identity.variant].join("\u0000");
    case "faction":
      return ["faction", identity.game, identity.factionKey, identity.variant].join("\u0000");
    case "map":
      return ["map", identity.mapName, identity.variant].join("\u0000");
  }
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
 *
 * Exported for `lib/maps/have.ts`, which filters `public.map` on the same free
 * text map name and therefore needs the same rule. A second copy of an escaping
 * rule is the kind of thing that parts company without anybody noticing, and the
 * way it shows is a filter that ends early and answers "the hub has nothing
 * under that name".
 */
export function operand(value: string): string {
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
 * games, and so does a faction group for the same reason. A map group does not,
 * since a map is not scoped to one.
 */
export function identityFilter(identity: AssetIdentity): string {
  switch (identity.keyedOn) {
    case "unit":
      return `and(game.eq.${operand(identity.game)},unit_name.eq.${operand(identity.unitName)},variant.eq.${operand(identity.variant)})`;
    case "faction":
      return `and(game.eq.${operand(identity.game)},faction_key.eq.${operand(identity.factionKey)},variant.eq.${operand(identity.variant)})`;
    case "map":
      return `and(map_name.eq.${operand(identity.mapName)},variant.eq.${operand(identity.variant)})`;
  }
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
 * on a map row, `faction_key` on a faction row and `unit_name` on a unit row,
 * and `asset_identity_check` in the migration is what makes that a safe thing
 * to test.
 *
 * Exported for `./resolve`, which reads a different set of columns off the same
 * table and has to key them the same way. */
export function rowIdentity(row: {
  game: string | null;
  unit_name: string | null;
  map_name: string | null;
  /** Absent from the select lists that predate faction logos, which can only
   * ever return unit and map rows. */
  faction_key?: string | null;
  variant: string;
}): AssetIdentity {
  if (row.map_name !== null) {
    return { keyedOn: "map", mapName: row.map_name, variant: row.variant };
  }

  if (row.faction_key !== undefined && row.faction_key !== null) {
    return {
      keyedOn: "faction",
      game: row.game ?? "",
      factionKey: row.faction_key,
      variant: row.variant,
    };
  }

  return {
    keyedOn: "unit",
    game: row.game ?? "",
    unitName: row.unit_name ?? "",
    variant: row.variant,
  };
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
      if (!heldForHave(row)) continue;
      sourceHashes.set(identityKey(rowIdentity(row)), row.source_hash);
    }
  }

  return { ok: true, sourceHashes };
}
