import type { SupabaseClient } from "@supabase/supabase-js";
import { operand, queryChunks } from "@/lib/assets/have";

/**
 * The database half of the map have check (#186): given a list of canonical map
 * names, what facts does the hub already hold under each.
 *
 * Answered entirely from `public.map`, and from three of its columns. Nothing
 * here reads the points, the credits or the appearance blob, because none of
 * them changes the answer: the row's `source_hash` says which archive the facts
 * came from and its `catalog_version` says which extraction read it, and those
 * two together are the whole comparison. Selecting the rest would pull a few
 * hundred point rows per map to throw them away.
 *
 * `facts_digest` is not read either, for a reason worth writing down. It is the
 * digest over the stored entry, so it is what the submission route compares to
 * decide whether a submission changes anything. It is no use here, because the
 * caller has not computed one: the hub computes the digest, not the client, so
 * there is nothing on the request to compare it against. `source_hash` is the
 * one identity both sides hold independently.
 */

/** The three columns an answer needs. `map_name` to key the row back to the
 * request, and the pair that decides the status. */
const HAVE_COLUMNS = "map_name, source_hash, catalog_version";

/**
 * How many names go into one PostgREST request.
 *
 * The filter travels in the query string, so the ceiling is URL length rather
 * than anything about the database, which is `lib/assets/have.ts`'s reasoning and
 * the reason this reuses its chunker. A group here is shorter than an asset one -
 * one column rather than three - so a hundred names sits around the same place a
 * hundred asset groups would, and a batch at the route's cap of 500 is five
 * requests issued together.
 */
const NAMES_PER_QUERY = 100;

/** What the hub holds for one map, and the whole of what decides a status. */
export interface MapCatalogState {
  sourceHash: string;
  catalogVersion: number;
}

interface MapHaveRow {
  map_name: string;
  source_hash: string;
  catalog_version: number;
}

/**
 * The filter for one name, as a PostgREST equality on the quoted value.
 *
 * An `in` list would be the obvious shape for a single column and it is not safe
 * here. `postgrest-js` quotes a value only when it spots a reserved character and
 * never escapes a quote inside it, and a canonical map name is free text: commas,
 * brackets and full stops all end a filter early if they arrive bare. So every
 * value is quoted and escaped through the one rule in `lib/assets/have.ts`, and
 * the groups are joined with `or` the way that file does.
 */
export function nameFilter(mapName: string): string {
  return `map_name.eq.${operand(mapName)}`;
}

export type MapCatalogLookup =
  | { ok: true; held: Map<string, MapCatalogState> }
  | { ok: false };

/**
 * What the hub holds for each of these names, keyed on the name exactly as
 * stored. A name the hub has no row for is simply absent from the map, which is
 * what tells a map the hub has never seen apart from one whose facts have moved
 * on.
 *
 * Read through the secret key client, the same as the asset check, but for a
 * plainer reason: `public.map` grants select to `anon`, so there is no row here
 * the public cannot already read. The route reads as `service_role` because it is
 * the client the write path already holds, and it means this answer cannot be
 * changed by a policy written later for the read side.
 *
 * Each chunk returns at most one row per name, because `map_identity_idx` is
 * unique on `map_name`, so PostgREST's default thousand row ceiling is never in
 * reach and an answer is never quietly truncated into a false `missing`.
 */
export async function fetchMapCatalogState(
  supabase: SupabaseClient,
  mapNames: string[],
): Promise<MapCatalogLookup> {
  const chunks = queryChunks(mapNames, NAMES_PER_QUERY);

  const responses = await Promise.all(
    chunks.map((chunk) =>
      supabase
        .from("map")
        .select(HAVE_COLUMNS)
        .or(chunk.map(nameFilter).join(",")),
    ),
  );

  const held = new Map<string, MapCatalogState>();
  for (const { data, error } of responses) {
    if (error || !data) return { ok: false };
    for (const row of data as unknown as MapHaveRow[]) {
      held.set(row.map_name, {
        sourceHash: row.source_hash,
        catalogVersion: row.catalog_version,
      });
    }
  }

  return { ok: true, held };
}
