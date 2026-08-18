import type { SupabaseClient } from "@supabase/supabase-js";
import { queryChunks } from "@/lib/assets/have";
import { type AssetLicenceRow, licenceForMap, mayRedistribute } from "@/lib/assets/licence";
import type { MapFacts } from "@/lib/api/mapLookup";
import { nameFilter } from "@/lib/maps/have";

/**
 * The database half of the map lookup (#188): given canonical map names, the
 * facts the hub holds and may publish for each.
 *
 * Two questions, because they are two decisions. `public.map_facts` answers
 * what the hub holds, in one call, and the migration says why the assembly is
 * SQL. This file answers whether the hub may say so, against
 * `public.asset_licence`, and that answer is `lib/assets/licence.ts`'s rule
 * rather than a second copy of it.
 *
 * Both reads go together rather than one after the other. The licence read is
 * by the same names as the facts read, so nothing about the second depends on
 * the first, and running them in series would double the latency of the route
 * for a gate that drops almost nothing.
 *
 * The gate itself is {@link publishableMaps}, and it is exported because a map's
 * own page (#190) asks the same question about the same table. One rule for
 * "may the hub publish this map" rather than one per surface: a second copy
 * would keep serving a page for a map the API had stopped answering for, and a
 * takedown that only half takes effect is worse than no takedown at all.
 */

/**
 * How many names go into one licence request.
 *
 * The filter travels in the query string, so the ceiling is URL length rather
 * than anything about the database, which is `lib/assets/have.ts`'s reasoning
 * and the reason this reuses its chunker. The facts read is an RPC and carries
 * its names in the body, so it needs no chunking at all.
 */
const NAMES_PER_QUERY = 100;

/** One map as `public.map_facts` returns it: the name it was found under, and
 * the wire shape beneath it. */
interface MapFactsRow {
  map_name: string;
  facts: MapFacts;
}

export type MapFactsLookup = { ok: true; facts: Map<string, MapFacts> } | { ok: false };

/**
 * The licence rows that could answer for these maps: each map's own row, and
 * the blanket row that covers every map without one.
 *
 * The whole row rather than the four columns the decision reads, because
 * `mayRedistribute` takes a row and not two of its fields, and the table holds
 * a handful of rows. Selecting a subset would mean either a second shape to
 * keep in step with `AssetLicenceRow` or a cast that says a partial row is a
 * whole one.
 *
 * Read as `service_role`, which is the only role that may. `20260814180000`
 * grants select on `public.asset_licence` to nobody else, so the route holds
 * the secret key for this read alone.
 *
 * `nameFilter` is `lib/maps/have.ts`'s, and it fits because the column here is
 * also called `map_name` and also holds the full canonical name. It is reused
 * rather than rewritten because a second copy of a PostgREST escaping rule
 * parts company quietly, and the way it shows is a filter that ends early on a
 * map name containing a comma and a takedown that stops being honoured.
 */
async function readLicences(
  supabase: SupabaseClient,
  mapNames: string[],
): Promise<{ ok: true; rows: AssetLicenceRow[] } | { ok: false }> {
  const chunks = queryChunks(mapNames, NAMES_PER_QUERY);

  const responses = await Promise.all(
    chunks.map((chunk) =>
      supabase
        .from("asset_licence")
        .select("*")
        .or(["all_maps.is.true", ...chunk.map(nameFilter)].join(",")),
    ),
  );

  const rows: AssetLicenceRow[] = [];
  for (const { data, error } of responses) {
    if (error || !data) return { ok: false };
    rows.push(...(data as unknown as AssetLicenceRow[]));
  }

  return { ok: true, rows };
}

/**
 * Whether the hub publishes anything about this map at all.
 *
 * The catalog makes no licence decision of its own. It honours the one
 * `20260814170100_asset_licence_all_maps.sql` already recorded, so a map taken
 * down is taken down whole: no minimap and no page of facts either. A map that
 * has been withdrawn does not keep a page describing it, and a takedown stays
 * one insert rather than two mechanisms a maintainer has to remember.
 *
 * ## Which question is asked of the row
 *
 * `mayRedistribute` answers for one origin, `extracted` or `rendered`, and
 * neither of them is quite this question. A wind range and a description are
 * not an image, so they have no origin, and reading the rule for one class of
 * picture as the rule for metadata would extend a decision further than
 * whoever made it meant.
 *
 * So the test here is narrower: the facts are withheld only when the resolved
 * row permits nothing at all. That is what a takedown is, and it is the shape
 * the takedown described in the blanket row's own notes takes, which sets both
 * permissions to `denied`.
 *
 * The mixed row is what the narrow test is for. A mapper who is happy for the
 * hub to render his models and not to repost his minimap has objected to one
 * class of picture, not to the hub knowing how windy his map is, and his map
 * keeps its facts.
 *
 * A map with no licence row and no blanket row answers no, which is
 * `mayRedistribute`'s own reading of a missing row and is deliberate. If the
 * blanket row were ever removed the catalog goes quiet rather than publishing
 * on the strength of nobody having decided.
 */
function published(
  mapName: string,
  perMap: Map<string, AssetLicenceRow>,
  blanket: AssetLicenceRow | undefined,
): boolean {
  const licence = licenceForMap(perMap.get(mapName), blanket);
  return mayRedistribute(licence, "extracted") || mayRedistribute(licence, "rendered");
}

/**
 * Which of these names the hub may publish anything about, read as
 * `service_role`.
 *
 * The subset rather than a yes or no per name, so a caller asking about one map
 * tests membership and a caller asking about five hundred does not have to zip
 * two lists back together. A name the gate refuses is absent, which is the same
 * shape of answer the facts lookup gives for a map the hub has never heard of.
 *
 * `ok: false` when the licence table could not be read. An empty subset would be
 * a claim - none of these may be published - and a caller acting on it would
 * take down the whole catalog over one failed request. Both callers fail closed
 * on it, which is the safe direction: the route answers 503 and the page answers
 * not found, and neither publishes on the strength of a read that did not
 * happen.
 */
export type PublishableMaps = { ok: true; names: ReadonlySet<string> } | { ok: false };

export async function publishableMaps(
  supabase: SupabaseClient,
  mapNames: string[],
): Promise<PublishableMaps> {
  const licences = await readLicences(supabase, mapNames);
  if (!licences.ok) return { ok: false };

  const perMap = new Map<string, AssetLicenceRow>();
  let blanket: AssetLicenceRow | undefined;
  for (const row of licences.rows) {
    if (row.all_maps) blanket = row;
    else if (row.map_name !== null) perMap.set(row.map_name, row);
  }

  return {
    ok: true,
    names: new Set(mapNames.filter((mapName) => published(mapName, perMap, blanket))),
  };
}

/**
 * What the hub holds and may publish for each of these names, keyed on the name
 * exactly as stored. A name the hub has no row for is simply absent from the
 * map, and so is a name it holds a row for and may not publish, which is what
 * makes a takedown look like a map the hub has never heard of.
 *
 * A read that fails is `ok: false` rather than an empty map. An empty map is a
 * claim - the hub knows nothing about any of these - and a caller acting on a
 * claim the hub cannot make would remember that a map it holds facts for has
 * none. The route turns this into a 503, the same as `/api/v1/maps/have`.
 */
export async function fetchMapFacts(
  supabase: SupabaseClient,
  mapNames: string[],
): Promise<MapFactsLookup> {
  const [held, publishable] = await Promise.all([
    supabase.rpc("map_facts", { p_names: mapNames }),
    publishableMaps(supabase, mapNames),
  ]);

  if (held.error || !held.data || !publishable.ok) return { ok: false };

  const facts = new Map<string, MapFacts>();
  for (const row of held.data as MapFactsRow[]) {
    if (publishable.names.has(row.map_name)) {
      facts.set(row.map_name, row.facts);
    }
  }

  return { ok: true, facts };
}
