import type { SupabaseClient } from "@supabase/supabase-js";
import type { MapSubmitOutcome } from "@/lib/api/mapSubmit";
import { factsDigest, type MapEntry } from "@/lib/maps/facts";
import { mapSlug, slugAlternative } from "@/lib/maps/slug";

/**
 * The database half of a map submission (#187): everything the hub works out for
 * itself, and the one call that decides and writes the batch.
 *
 * The decision itself is `public.submit_map_facts`, and the migration says at
 * length why it is there rather than here. The short of it is that deciding an
 * outcome means reading a row, and writing one means touching three tables, so
 * splitting the two across a network is a way to leave a map holding new facts
 * and the previous map's metal spots.
 *
 * What is left on this side is the part the database cannot do for itself: the
 * slug, the digest, and turning one call into results a route can zip against
 * its request.
 */

/** One map, as the function reads it: the facts, the URL name, and the digest
 * over the facts. Snake case because these are jsonb keys rather than
 * TypeScript. */
export interface MapSubmission {
  entry: MapEntry;
  slug: string;
  slug_alternative: string;
  facts_digest: string;
}

/** What the hub did with one map, as the function reports it. */
export interface MapOutcome {
  outcome: MapSubmitOutcome;
  said: string | null;
}

interface OutcomeRow {
  map_name: string;
  outcome: MapSubmitOutcome;
  said: string | null;
}

/**
 * A submission with everything the hub derives filled in.
 *
 * Both slugs are computed here rather than in the function, because the second
 * one is a digest of the map's name and Postgres has no sha256 without
 * pgcrypto. The alternative is only used when another map already holds the
 * first, which is two different canonical names rendering to one URL.
 */
export async function buildSubmission(entry: MapEntry): Promise<MapSubmission> {
  return {
    entry,
    slug: mapSlug(entry.map_name),
    slug_alternative: await slugAlternative(entry.map_name),
    facts_digest: await factsDigest(entry),
  };
}

export type MapSubmitWrite =
  | { ok: true; outcomes: Map<string, MapOutcome> }
  | { ok: false; rateLimited: boolean };

/**
 * The whole batch, decided and written in one transaction.
 *
 * Sorted by map name before it goes, which is not cosmetic. Every entry locks
 * its map row before it reads it, so two batches holding the same two maps in
 * opposite orders would each hold what the other is waiting for, and Postgres
 * would break the deadlock by killing one of them. Locking in a settled order
 * means two overlapping batches queue up instead. The order the caller asked in
 * is not lost: the answers come back keyed by name, and the route puts them back
 * in request order.
 *
 * `53400` is the rate limit trigger's errcode, the same one `lib/gallery/publish.ts`
 * reads, and it is the one failure the caller answers differently. The batch
 * wrote nothing when it comes back, so the client's fix is to wait and send the
 * same batch again.
 */
export async function submitMapFacts(
  supabase: SupabaseClient,
  submissions: MapSubmission[],
  submittedBy: string,
): Promise<MapSubmitWrite> {
  const ordered = [...submissions].sort((left, right) =>
    left.entry.map_name < right.entry.map_name ? -1 : 1,
  );

  const { data, error } = await supabase.rpc("submit_map_facts", {
    p_maps: ordered,
    p_submitted_by: submittedBy,
  });

  if (error || !data) {
    return { ok: false, rateLimited: error?.code === "53400" };
  }

  const outcomes = new Map<string, MapOutcome>();
  for (const row of data as OutcomeRow[]) {
    outcomes.set(row.map_name, { outcome: row.outcome, said: row.said });
  }

  return { ok: true, outcomes };
}
