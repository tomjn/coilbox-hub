import { canonicalJson } from "@/lib/maps/facts";

/**
 * The wire shape of a game facts submission (#224). A client that has the game
 * archive mounted sends what it read out of it, and the hub answers what it did
 * with each faction and unit.
 *
 * The envelope is `coilbox-hub-games`, carried on the request as well as the
 * reply, the way `/api/v1/maps` carries `coilbox-hub-maps`. A shipped desktop
 * build sits on disk for months, so it reads `format` and `version` first and
 * can say the service is newer than it understands rather than guessing at a
 * shape that changed under it.
 *
 * ## One game per request, where a map submission is fifty maps
 *
 * A game's facts hang together: the factions are a replaced set and the
 * retirement pass needs the whole unit list to compare against. Splitting one
 * game across requests would make `complete` mean whichever slice happened to
 * arrive last, so the batch is the game.
 *
 * That is also why a malformed entry is a `refused` result rather than a 400
 * for the request, which is where this parts company with the have checks. A
 * submission does work, and throwing away nine hundred good units because the
 * nine hundred and first has a stat nobody can store is a client that can never
 * make progress until it is fixed.
 *
 * The strictness itself is the same as everywhere else. An unknown field is
 * refused rather than ignored, because a client that spelled `fullName` as
 * `full_name` and had it dropped would write rows that dedupe against nothing
 * and resubmit their whole corpus on every run.
 *
 * ## What a client never sends
 *
 * `facts_digest` is absent from every shape here, and the unknown field rule
 * turns a client that sends one into a refusal that names the field. The hub
 * computes it over the normalised entry. A declared digest reads as unchanged
 * facts forever, which is the one failure versioning cannot repair after the
 * fact.
 */
export const GAME_FACTS_FORMAT = "coilbox-hub-games";
export const GAME_FACTS_VERSION = 1;

/**
 * How much one request may carry.
 *
 * These are hub constants rather than lines in a vendored file, because the
 * vendored files are agreements about extraction that both repos read, and no
 * coilbox code batches on these numbers yet: the backfill sends one game per
 * request and stops when the units run out. If a client ever wants to split on
 * them, they move into `shared/` and get vendored, because a cap a client
 * batches on and a cap a hub enforces have to be the same number or the client
 * is told to make requests the hub refuses.
 */
export const GAME_FACTS_MAX_BYTES = 2_000_000;
export const GAME_FACTS_MAX_UNITS = 2_000;
export const GAME_FACTS_MAX_FACTIONS = 64;

/**
 * What the hub did with one entry.
 *
 * - `accepted`: current facts changed, and this release's revision was written.
 * - `recorded`: the facts were already held, but this release had no revision
 *   yet. The revision is new even though the facts are not, which is the
 *   ordinary case the second time a release is reported.
 * - `unchanged`: nothing was written at all.
 * - `refused`: the entry is malformed, and `said` carries why.
 */
export type GameFactsOutcome = "accepted" | "recorded" | "unchanged" | "refused";

/** The answer for one faction or unit, in request order so a caller zips by
 * index. `kind` says which list the name came from, since a faction key and a
 * unit name share no namespace but do share a response. */
export interface GameFactsResult {
  kind: "faction" | "unit";
  name: string;
  outcome: GameFactsOutcome;
  said?: string;
}

export interface GameFactsResponseBody {
  format: typeof GAME_FACTS_FORMAT;
  version: typeof GAME_FACTS_VERSION;
  results: GameFactsResult[];
}

/** One faction as the route parsed it, ready for the database. */
export interface SubmittedFaction {
  key: string;
  name: string;
}

/** One unit as the route parsed it, ready for the database. */
export interface SubmittedUnit {
  name: string;
  full_name: string | null;
  faction_key: string | null;
  build_options: string[];
  stats: Record<string, unknown>;
  morph_targets: Record<string, unknown>[];
}

/** Everything one request carries, normalised. */
export interface GameFactsSubmission {
  shortname: string;
  release: string;
  complete: boolean;
  start_units: string[] | null;
  factions: SubmittedFaction[] | null;
  units: SubmittedUnit[];
}

export type ParsedGameFactsBody =
  | { ok: true; submission: GameFactsSubmission }
  | { ok: false; error: string; status: number };

const BODY_FIELDS = [
  "format",
  "version",
  "shortname",
  "release",
  "complete",
  "startUnits",
  "factions",
  "units",
] as const;

const FACTION_FIELDS = ["key", "name"] as const;

const UNIT_FIELDS = [
  "name",
  "fullName",
  "factionKey",
  "buildOptions",
  "stats",
  "morphTargets",
] as const;

const MAX_LENGTHS = {
  shortname: 64,
  release: 64,
  factionKey: 128,
  factionName: 256,
  unitName: 128,
  fullName: 256,
  buildOption: 128,
} as const;

/** The largest serialised stats blob one unit may carry. Stats render as a
 * table, not as a dataset, and a bound here turns a runaway extractor into a
 * refusal naming the unit instead of a row nobody can read. */
const MAX_STATS_JSON = 8_192;

type Read<T> = { ok: true; value: T } | { ok: false; error: string };

function unknownField(
  record: Record<string, unknown>,
  allowed: readonly string[],
): string | null {
  return Object.keys(record).find((field) => !allowed.includes(field)) ?? null;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

/** A required string field, held to the same length the table's check
 * constraints are, measured after trimming the way the constraints measure
 * it. Unlike the map submission, the trimmed value is stored: none of these is
 * an identity another route looks up verbatim before this one runs. */
function readText(
  record: Record<string, unknown>,
  field: string,
  max: number,
): Read<string> {
  const value = record[field];
  if (typeof value !== "string" || value.trim().length < 1 || value.trim().length > max) {
    return {
      ok: false,
      error: `\`${field}\` is required and must be a string of 1 to ${max} characters.`,
    };
  }
  return { ok: true, value: value.trim() };
}

function optionalText(
  record: Record<string, unknown>,
  field: string,
  max: number,
): Read<string | null> {
  const value = record[field];
  if (value === undefined || value === null) return { ok: true, value: null };
  if (typeof value !== "string") {
    return { ok: false, error: `\`${field}\` must be a string of at most ${max} characters.` };
  }
  const trimmed = value.trim();
  if (trimmed.length > max) {
    return { ok: false, error: `\`${field}\` must be a string of at most ${max} characters.` };
  }
  return { ok: true, value: trimmed.length > 0 ? trimmed : null };
}

/**
 * The build edges, sorted and deduplicated.
 *
 * Order is not a fact: two clients reading one def can list its build options in
 * whatever order Lua handed it over, and sorting means both arrive at one
 * digest instead of churning a new revision every run. An edge listed twice is
 * one edge.
 */
function readBuildOptions(record: Record<string, unknown>): Read<string[]> {
  const value = record.buildOptions;
  if (value === undefined || value === null) return { ok: true, value: [] };
  if (!Array.isArray(value)) {
    return { ok: false, error: "`buildOptions` must be an array of unit names." };
  }
  if (value.length > GAME_FACTS_MAX_UNITS) {
    return { ok: false, error: "`buildOptions` is implausibly long." };
  }
  const seen = new Set<string>();
  for (const option of value) {
    if (typeof option !== "string" || option.trim().length < 1 || option.trim().length > MAX_LENGTHS.buildOption) {
      return {
        ok: false,
        error: `\`buildOptions\` entries must be strings of 1 to ${MAX_LENGTHS.buildOption} characters.`,
      };
    }
    seen.add(option.trim());
  }
  return { ok: true, value: [...seen].sort() };
}

function readStats(record: Record<string, unknown>): Read<Record<string, unknown>> {
  const value = record.stats;
  if (value === undefined || value === null) return { ok: true, value: {} };
  if (!isRecord(value)) {
    return { ok: false, error: "`stats` must be a JSON object." };
  }
  if (canonicalJson(value).length > MAX_STATS_JSON) {
    return { ok: false, error: `\`stats\` holds more than ${MAX_STATS_JSON} bytes of JSON.` };
  }
  return { ok: true, value };
}

/** The largest serialised morph blob one unit may carry. The same number
 * `MAX_STATS_JSON` uses, and for the same reason. The widest morph a real game
 * has been measured to declare is 1586 bytes, on Metal Factions' commander. */
const MAX_MORPH_JSON = 8_192;

/**
 * What a unit turns into, as the client read it.
 *
 * `into` is the only field named here. Everything beside it is the game's own
 * condition vocabulary, stored and rendered as it arrives, because four games
 * spell it four ways and a hub that named them would refuse the fifth.
 */
function readMorphTargets(
  record: Record<string, unknown>,
): Read<Record<string, unknown>[]> {
  const value = record.morphTargets;
  if (value === undefined || value === null) return { ok: true, value: [] };
  if (!Array.isArray(value)) {
    return { ok: false, error: "`morphTargets` must be an array of morph objects." };
  }
  const seen = new Set<string>();
  const targets: Record<string, unknown>[] = [];
  for (const entry of value) {
    if (!isRecord(entry)) {
      return { ok: false, error: "`morphTargets` entries must be JSON objects." };
    }
    const into = entry.into;
    if (typeof into !== "string" || into.trim().length === 0) {
      return { ok: false, error: "a `morphTargets` entry must name the unit it turns into." };
    }
    if (into.trim().length > MAX_LENGTHS.unitName) {
      return { ok: false, error: "a `morphTargets` entry names a unit too long to store." };
    }
    // Two edges to one target are one edge. The client deduplicates already, so
    // this is about what a second client might send rather than about ours.
    if (seen.has(into.trim())) continue;
    seen.add(into.trim());
    targets.push({ ...entry, into: into.trim() });
  }
  if (canonicalJson(targets).length > MAX_MORPH_JSON) {
    return { ok: false, error: `\`morphTargets\` holds more than ${MAX_MORPH_JSON} bytes of JSON.` };
  }
  return { ok: true, value: targets };
}

function readUnit(value: unknown): Read<SubmittedUnit> {
  if (!isRecord(value)) return { ok: false, error: "must be a JSON object." };

  const extra = unknownField(value, UNIT_FIELDS);
  if (extra) return { ok: false, error: `unknown field: ${extra}` };

  const name = readText(value, "name", MAX_LENGTHS.unitName);
  if (!name.ok) return name;

  const fullName = optionalText(value, "fullName", MAX_LENGTHS.fullName);
  if (!fullName.ok) return fullName;

  const factionKey = optionalText(value, "factionKey", MAX_LENGTHS.factionKey);
  if (!factionKey.ok) return factionKey;

  const buildOptions = readBuildOptions(value);
  if (!buildOptions.ok) return buildOptions;

  const stats = readStats(value);
  if (!stats.ok) return stats;

  const morphTargets = readMorphTargets(value);
  if (!morphTargets.ok) return morphTargets;

  return {
    ok: true,
    value: {
      name: name.value,
      full_name: fullName.value,
      faction_key: factionKey.value,
      build_options: buildOptions.value,
      stats: stats.value,
      morph_targets: morphTargets.value,
    },
  };
}

function readFaction(value: unknown): Read<SubmittedFaction> {
  if (!isRecord(value)) return { ok: false, error: "must be a JSON object." };

  const extra = unknownField(value, FACTION_FIELDS);
  if (extra) return { ok: false, error: `unknown field: ${extra}` };

  const key = readText(value, "key", MAX_LENGTHS.factionKey);
  if (!key.ok) return key;

  const name = readText(value, "name", MAX_LENGTHS.factionName);
  if (!name.ok) return name;

  return { ok: true, value: { key: key.value, name: name.value } };
}

/**
 * The whole request, normalised.
 *
 * `complete` defaults to false, and false removes nothing: a partial backfill
 * that posts stats only must not retire every unit it did not mention. Absent
 * factions leave the held set alone for the same reason.
 */
export function parseGameFactsBody(body: unknown): ParsedGameFactsBody {
  if (!isRecord(body)) {
    return { ok: false, error: "The request body must be a JSON object.", status: 400 };
  }

  const extra = unknownField(body, BODY_FIELDS);
  if (extra) {
    return { ok: false, error: `unknown field: ${extra}`, status: 400 };
  }

  if (body.format !== GAME_FACTS_FORMAT) {
    return {
      ok: false,
      error: `Send facts as format "${GAME_FACTS_FORMAT}".`,
      status: 400,
    };
  }
  if (body.version !== GAME_FACTS_VERSION) {
    return {
      ok: false,
      error: `This endpoint speaks format version ${GAME_FACTS_VERSION}.`,
      status: 400,
    };
  }

  const shortname = readText(body, "shortname", MAX_LENGTHS.shortname);
  if (!shortname.ok) {
    return { ok: false, error: shortname.error, status: 400 };
  }

  const release = readText(body, "release", MAX_LENGTHS.release);
  if (!release.ok) {
    return { ok: false, error: release.error, status: 400 };
  }

  let complete = false;
  if (body.complete !== undefined && body.complete !== null) {
    if (typeof body.complete !== "boolean") {
      return { ok: false, error: "`complete` must be true or false.", status: 400 };
    }
    complete = body.complete;
  }

  let startUnits: string[] | null = null;
  if (body.startUnits !== undefined && body.startUnits !== null) {
    if (!Array.isArray(body.startUnits)) {
      return { ok: false, error: "`startUnits` must be an array of unit names.", status: 400 };
    }
    if (body.startUnits.length > GAME_FACTS_MAX_FACTIONS) {
      return { ok: false, error: "`startUnits` is implausibly long.", status: 400 };
    }
    const roots: string[] = [];
    for (const root of body.startUnits) {
      if (typeof root !== "string" || root.trim().length < 1 || root.trim().length > MAX_LENGTHS.unitName) {
        return {
          ok: false,
          error: `\`startUnits\` entries must be strings of 1 to ${MAX_LENGTHS.unitName} characters.`,
          status: 400,
        };
      }
      roots.push(root.trim());
    }
    startUnits = roots;
  }

  let factions: SubmittedFaction[] | null = null;
  if (body.factions !== undefined && body.factions !== null) {
    if (!Array.isArray(body.factions)) {
      return { ok: false, error: "`factions` must be an array.", status: 400 };
    }
    if (body.factions.length > GAME_FACTS_MAX_FACTIONS) {
      return {
        ok: false,
        error: `A request may carry at most ${GAME_FACTS_MAX_FACTIONS} factions.`,
        status: 413,
      };
    }
    factions = [];
    for (const faction of body.factions) {
      const parsed = readFaction(faction);
      if (!parsed.ok) {
        return { ok: false, error: `A faction ${parsed.error}`, status: 400 };
      }
      factions.push(parsed.value);
    }
  }

  if (!Array.isArray(body.units)) {
    return { ok: false, error: "`units` is required and must be an array.", status: 400 };
  }
  if (body.units.length > GAME_FACTS_MAX_UNITS) {
    return {
      ok: false,
      error: `A request may carry at most ${GAME_FACTS_MAX_UNITS} units.`,
      status: 413,
    };
  }
  const units: SubmittedUnit[] = [];
  for (const unit of body.units) {
    const parsed = readUnit(unit);
    if (!parsed.ok) {
      return { ok: false, error: `A unit ${parsed.error}`, status: 400 };
    }
    units.push(parsed.value);
  }

  return {
    ok: true,
    submission: {
      shortname: shortname.value,
      release: release.value,
      complete,
      start_units: startUnits,
      factions,
      units,
    },
  };
}
