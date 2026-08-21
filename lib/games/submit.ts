import type { SupabaseClient } from "@supabase/supabase-js";
import type {
  GameFactsOutcome,
  GameFactsResult,
  SubmittedUnit,
} from "@/lib/api/gameFacts";
import { encodedHash } from "@/lib/assets/hash";
import { canonicalJson } from "@/lib/maps/facts";

/**
 * The database half of a game facts submission (#224): the digest the hub works
 * out for itself, and the one call that decides and writes the whole game.
 *
 * The decision itself is `public.submit_game_facts`, and the migration says at
 * length why it is there rather than here. The short of it is that one request
 * touches five tables and a retirement pass that needs the whole unit list, so
 * splitting deciding from writing across a network is a way to leave a game
 * holding new facts beside an old faction set.
 *
 * What is left on this side is the part the database cannot do for itself: the
 * digest over each unit's normalised facts.
 */

/** One unit with its digest filled in. Snake case because these are jsonb keys
 * rather than TypeScript. */
export interface UnitSubmission {
  unit: SubmittedUnit;
  facts_digest: string;
}

/** Everything the function reads, in the shape the route sends. */
export interface GameSubmission {
  shortname: string;
  release: string;
  complete: boolean;
  start_units: string[] | null;
  factions: { key: string; name: string }[] | null;
  units: UnitSubmission[];
}

/**
 * The digest over one unit's facts.
 *
 * The name is inside the hash as well as on the row, the way a map's is, so
 * "the same facts" never has to ask whether identity was part of the
 * comparison. Build options are sorted and deduplicated here rather than
 * trusted to the caller, because order is not a fact: two clients reading one
 * def can list what it builds in whatever order Lua handed it over, and one
 * digest for both is the difference between idempotence and a new revision on
 * every run.
 */
export async function unitDigest(unit: SubmittedUnit): Promise<string> {
  const canonical = canonicalJson({
    name: unit.name,
    fullName: unit.full_name,
    factionKey: unit.faction_key,
    buildOptions: [...new Set(unit.build_options)].sort(),
    stats: unit.stats,
  });
  return encodedHash(new TextEncoder().encode(canonical).buffer as ArrayBuffer);
}

export async function buildGameSubmission(
  submission: Omit<GameSubmission, "units"> & { units: SubmittedUnit[] },
): Promise<GameSubmission> {
  const units = await Promise.all(
    submission.units.map(async (unit) => ({ unit, facts_digest: await unitDigest(unit) })),
  );
  return { ...submission, units };
}

interface OutcomeRow {
  kind: string;
  name: string;
  outcome: GameFactsOutcome;
  said: string | null;
}

export type GameFactsWrite =
  | { ok: true; results: GameFactsResult[] }
  | { ok: false };

/**
 * The whole game, decided and written in one call.
 *
 * The answers come back keyed by kind and name, and are returned in the order
 * the parser produced them - factions first, then units - so a caller zips
 * against its own request rather than against whatever order the function
 * walked.
 */
export async function submitGameFacts(
  supabase: SupabaseClient,
  submission: GameSubmission,
  submittedBy: string,
): Promise<GameFactsWrite> {
  const { data, error } = await supabase.rpc("submit_game_facts", {
    p_submission: submission,
    p_submitted_by: submittedBy,
  });

  if (error || !data) {
    return { ok: false };
  }

  const written = new Map<string, OutcomeRow>();
  for (const row of data as OutcomeRow[]) {
    written.set(`${row.kind}:${row.name}`, row);
  }

  const results: GameFactsResult[] = [];
  const push = (kind: "faction" | "unit", name: string) => {
    const row = written.get(`${kind}:${name}`);
    if (row) {
      results.push({ kind, name, outcome: row.outcome, ...(row.said ? { said: row.said } : {}) });
    }
  };
  for (const faction of submission.factions ?? []) push("faction", faction.key);
  for (const unit of submission.units) push("unit", unit.unit.name);

  return { ok: true, results };
}
