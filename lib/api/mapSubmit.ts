import { MAP_CATALOG_CAPS } from "@/lib/maps/catalog";
import { canonicalEntry, type MapEntry, type MapPoint, type MapPoints } from "@/lib/maps/facts";

/**
 * The wire shape of a map submission (#187). A client that has the archives
 * mounted sends what it read out of them, and the hub answers what it did with
 * each one.
 *
 * The envelope is `coilbox-hub-maps`, carried on the request as well as the
 * reply, the way `/api/v1/items` and `/api/v1/auth` carry theirs. A shipped
 * desktop build sits on disk for months, so it reads `format` and `version`
 * first and can say the service is newer than it understands rather than
 * guessing at a shape that changed under it. The hub reads the request's pair
 * for the same reason in reverse: a build that predates a shape change should be
 * told, rather than have fields it meant one way read another.
 *
 * ## A batch, where an asset upload is one at a time
 *
 * Bytes are what force `/api/v1/assets/upload` to take one asset per request: a
 * multipart body, a platform size cap, and a single Blob write that succeeds or
 * fails. There are no bytes here. So fifty maps travel together and the outcome
 * is per map inside a 200, and one map the hub refuses does not fail the other
 * forty nine.
 *
 * That is also why a malformed entry is a `refused` result rather than a 400 for
 * the batch, which is where this parts company with `parseMapHaveBody`. A have
 * check answers a question and a bad key means the answer would be wrong, so it
 * refuses the lot. A submission does work, and throwing away forty nine good
 * entries because the fiftieth has a field the hub does not know is a client
 * that can never make progress until it is fixed.
 *
 * The strictness itself is the same. An unknown field is refused rather than
 * ignored, because a client that spelled `source_hash` as `sourceHash` and had
 * it dropped would write a row that dedupes against nothing and resubmits its
 * whole corpus on every run.
 *
 * ## What a client never sends
 *
 * `slug`, `facts_digest` and the author keys are absent from every shape here,
 * and the unknown field rule turns a client that sends one into a refusal that
 * names the field. The hub computes all three. A declared slug takes the URL of
 * a map somebody else submitted, a declared digest reads as unchanged facts
 * forever, and a declared key files a map under whichever author the client
 * fancied.
 *
 * Tags are absent for a different reason: nothing about what kind of map it is
 * is measured. `public.map_listing` works them out from the measurements, so a
 * client sending conclusions would be sending something the hub would not
 * believe and does not need. A client sends measurements.
 */
export const MAP_SUBMIT_FORMAT = "coilbox-hub-maps";
export const MAP_SUBMIT_VERSION = 1;

/**
 * How many maps one request may carry, and how large its body may be.
 *
 * Both read from the vendored catalog rather than written out here, for the
 * reason `lib/maps/catalog.ts` sets out: the number a client batches on and the
 * number the hub enforces have to be the same number, or the client is told to
 * make requests the hub refuses. An install with three thousand maps therefore
 * pages through sixty requests, and the have check (#186) means it only ever
 * sends that many once.
 *
 * The byte cap is the second half of the same agreement and it is not the same
 * question. Fifty entries are small until one of them carries a description, an
 * appearance blob and six hundred metal spots, so a request can be inside the
 * count and still far past what the hub wants to parse.
 */
export const MAP_SUBMIT_MAX_MAPS = MAP_CATALOG_CAPS.submitMaps;
export const MAP_SUBMIT_MAX_BYTES = MAP_CATALOG_CAPS.submitBytes;

/**
 * What the hub did with one map.
 *
 * - `stored`: the hub held nothing under that name and now holds these facts.
 * - `unchanged`: the hub already holds these facts, or better ones. `seen_at`
 *   moves, because a client reporting a map present is what that column
 *   records, and nothing else about the row does.
 * - `replaced`: the same archive, read by a newer extraction. The row takes the
 *   new facts and its points and credits are replaced wholesale.
 * - `conflict`: the submission disagrees with what the hub holds about an
 *   archive that cannot have two answers. Nothing is written, and where the
 *   disagreement is about the archive's bytes it is recorded for a reviewer.
 * - `refused`: the entry is malformed, and `said` carries why.
 */
export type MapSubmitOutcome = "stored" | "unchanged" | "replaced" | "conflict" | "refused";

/**
 * The answer for one map, in request order so a caller zips by index the way
 * `/api/v1/maps/have` lets it.
 *
 * `said` is present on a refusal and absent otherwise. A refusal is the one
 * outcome a client can act on: something in the entry is wrong and the message
 * names it. The other four are decisions about facts the hub already holds, and
 * a client has nothing to fix.
 */
export interface MapSubmitResult {
  map_name: string;
  outcome: MapSubmitOutcome;
  said?: string;
}

export interface MapSubmitBody {
  format: typeof MAP_SUBMIT_FORMAT;
  version: typeof MAP_SUBMIT_VERSION;
  results: MapSubmitResult[];
}

/**
 * One entry out of the batch: facts to submit, or the refusal that stands in its
 * place.
 *
 * A refusal still carries a name, because the results are positional and a
 * caller reading the third result expects the third map. An entry whose
 * `map_name` is not a usable string carries an empty one, since there is nothing
 * to echo, and the position is still the answer.
 */
export type SubmittedEntry =
  | { ok: true; entry: MapEntry }
  | { ok: false; mapName: string; said: string };

export type ParsedMapSubmitBody =
  | { ok: true; entries: SubmittedEntry[] }
  | { ok: false; error: string; status: number };

const BODY_FIELDS = ["format", "version", "maps"] as const;

const ENTRY_FIELDS = [
  "map_name",
  "display_name",
  "description",
  "map_version",
  "author",
  "archive_filename",
  "source_archive",
  "source_hash",
  "catalog_version",
  "width_elmos",
  "height_elmos",
  "world_height_min",
  "world_height_max",
  "min_wind",
  "max_wind",
  "tidal_strength",
  "void_water",
  "void_ground",
  "water_coverage",
  "appearance",
  "points",
] as const;

const POINT_KINDS = ["start", "metal", "geo"] as const;

const POINT_FIELDS = ["x", "z", "y", "meta"] as const;

/**
 * The lengths `public.map` accepts, so an entry the table could never hold is
 * refused here rather than after a round trip, and with a message that names the
 * field rather than a constraint.
 *
 * `author` is the one that is not a column. It is a whole credit string that
 * `public.author_credits` splits into people, and each person is a
 * `map_author.raw` of at most 256 characters, so the string itself is longer
 * than any one of them. A thousand is four credits at the full length of a
 * column, which is past every real archive and short of anything worth passing
 * to a regular expression.
 */
const MAX_LENGTHS = {
  map_name: 256,
  display_name: 256,
  description: 4000,
  map_version: 64,
  author: 1024,
  archive_filename: 256,
  source_archive: 256,
  source_hash: 128,
} as const;

type TextField = keyof typeof MAX_LENGTHS;

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

/**
 * A required string field, held to the same length the table's check constraints
 * are. Measured after trimming, the way the constraints measure it, and the
 * untrimmed value is what gets stored and looked up: `map_identity_idx` is on
 * the stored text, so trimming here would key a different row than the have
 * check asked about.
 */
function readText(record: Record<string, unknown>, field: TextField): Read<string> {
  const value = record[field];
  const length = typeof value === "string" ? value.trim().length : 0;
  if (typeof value !== "string" || length < 1 || length > MAX_LENGTHS[field]) {
    return {
      ok: false,
      error: `\`${field}\` is required and must be a string of 1 to ${MAX_LENGTHS[field]} characters.`,
    };
  }
  return { ok: true, value };
}

/**
 * A field the archive may or may not fill in.
 *
 * A blank string is read as absent rather than kept. mapinfo routinely carries
 * `description = ""`, the table's checks refuse a blank, and a client that had
 * its whole entry refused over an empty field it did not write would have no way
 * forward. Absent and blank say the same thing about the map, so they reach the
 * same null and therefore the same digest.
 *
 * The trimmed value is what gets stored, unlike the required fields above. None
 * of these is identity, nothing looks a map up by its description, and the
 * table measures them trimmed, so trimming here makes the length checked and the
 * length stored the same number. It settles one more way two clients can send
 * one fact: a display name with a trailing space is the same display name.
 */
function optionalText(record: Record<string, unknown>, field: TextField): Read<string | null> {
  const value = record[field];
  if (value === undefined || value === null) return { ok: true, value: null };
  if (typeof value !== "string" || value.trim().length > MAX_LENGTHS[field]) {
    return {
      ok: false,
      error: `\`${field}\` must be a string of at most ${MAX_LENGTHS[field]} characters.`,
    };
  }
  const trimmed = value.trim();
  return { ok: true, value: trimmed.length > 0 ? trimmed : null };
}

/** A required positive integer, which is what the table's `check (x > 0)`
 * columns hold. A float or a zero would be a constraint violation after the
 * write rather than a refusal before it. */
function readCount(record: Record<string, unknown>, field: string): Read<number> {
  const value = record[field];
  if (typeof value !== "number" || !Number.isSafeInteger(value) || value < 1) {
    return { ok: false, error: `\`${field}\` is required and must be a positive integer.` };
  }
  return { ok: true, value };
}

/** A required finite number, which is what a world height is and what a count is
 * not: terrain below sea level is negative and a flat map's range is zero wide.
 * Infinity and NaN are refused rather than stored, since neither is a
 * measurement. */
function readMeasure(record: Record<string, unknown>, field: string): Read<number> {
  const value = record[field];
  if (typeof value !== "number" || !Number.isFinite(value)) {
    return { ok: false, error: `\`${field}\` is required and must be a number.` };
  }
  return { ok: true, value };
}

/**
 * A measurement mapinfo may leave out.
 *
 * Absent stays absent rather than becoming a zero, which is the migration's own
 * reasoning about wind: the engine falls back to its own defaults when the
 * archive says nothing, and a zero written in place of an absent value would
 * claim a map with no wind at all.
 */
function optionalMeasure(record: Record<string, unknown>, field: string): Read<number | null> {
  const value = record[field];
  if (value === undefined || value === null) return { ok: true, value: null };
  if (typeof value !== "number" || !Number.isFinite(value)) {
    return { ok: false, error: `\`${field}\` must be a number.` };
  }
  return { ok: true, value };
}

function optionalFlag(record: Record<string, unknown>, field: string): Read<boolean | null> {
  const value = record[field];
  if (value === undefined || value === null) return { ok: true, value: null };
  if (typeof value !== "boolean") {
    return { ok: false, error: `\`${field}\` must be true or false.` };
  }
  return { ok: true, value };
}

/**
 * One point: where it is, and whatever its kind carries.
 *
 * `x` and `z` are the engine's own names for the two ground axes, with `y` the
 * vertical, and they are kept that way here for the reason the migration gives:
 * calling the second one `y` would put the hub one silent axis swap away from
 * every map it draws.
 *
 * `meta` is passed through rather than checked against the kind. The vendored
 * catalog says what each kind carries, and the hub does not read it: a metal
 * spot's amount and radius are numbers nothing here filters or sorts on, so a
 * rule about them would be a second copy of the client's contract that could
 * only ever refuse a field a newer client had started sending.
 */
function readPoint(value: unknown): Read<MapPoint> {
  if (!isRecord(value)) return { ok: false, error: "must be a JSON object." };

  const extra = unknownField(value, POINT_FIELDS);
  if (extra) return { ok: false, error: `unknown field: ${extra}` };

  const x = readMeasure(value, "x");
  if (!x.ok) return x;

  const z = readMeasure(value, "z");
  if (!z.ok) return z;

  const y = optionalMeasure(value, "y");
  if (!y.ok) return y;

  const meta = value.meta;
  if (meta !== undefined && meta !== null && !isRecord(meta)) {
    return { ok: false, error: "`meta` must be a JSON object." };
  }

  return {
    ok: true,
    value: { x: x.value, z: z.value, y: y.value, meta: (meta as MapPoint["meta"]) ?? null },
  };
}

/**
 * The three kinds of point a map has, each in its own array.
 *
 * Absent altogether is allowed and gives three empty arrays. A map with no metal
 * spots is an ordinary map, and an extraction that read the archive and found
 * none has said something true.
 *
 * A kind outside the three is refused rather than dropped, because
 * `map_point.kind` refuses it too, and a client sending `geothermal` would
 * otherwise have its geo vents silently disappear.
 */
function readPoints(value: unknown): Read<MapPoints> {
  const points: MapPoints = { start: [], metal: [], geo: [] };
  if (value === undefined || value === null) return { ok: true, value: points };

  if (!isRecord(value)) return { ok: false, error: "`points` must be a JSON object." };

  const extra = unknownField(value, POINT_KINDS);
  if (extra) {
    return { ok: false, error: `\`points\` names a kind the hub does not know: ${extra}` };
  }

  for (const kind of POINT_KINDS) {
    const raw = value[kind];
    if (raw === undefined || raw === null) continue;
    if (!Array.isArray(raw)) {
      return { ok: false, error: `\`points.${kind}\` must be an array.` };
    }

    for (const [index, entry] of raw.entries()) {
      const point = readPoint(entry);
      if (!point.ok) return { ok: false, error: `\`points.${kind}[${index}]\` ${point.error}` };
      points[kind].push(point.value);
    }
  }

  return { ok: true, value: points };
}

/** Everything only the 3D view reads, passed through as the archive declared it.
 * An object, because that is what the column holds, and empty rather than null
 * when the archive said nothing, matching the column's default. */
function readAppearance(record: Record<string, unknown>): Read<Record<string, unknown>> {
  const value = record.appearance;
  if (value === undefined || value === null) return { ok: true, value: {} };
  if (!isRecord(value)) return { ok: false, error: "`appearance` must be a JSON object." };
  return { ok: true, value };
}

/**
 * One map's facts.
 *
 * The cross field rules at the end are the table's own constraints, checked here
 * so a client is told which pair disagrees rather than being handed a constraint
 * name. They are also the rules whose failure is quiet: a reversed height range
 * reads every sample upside down and looks entirely plausible, and a void water
 * map that also reports a water share is two answers to one question with
 * nothing to say which the extractor meant.
 */
function parseEntry(value: unknown): Read<MapEntry> {
  if (!isRecord(value)) return { ok: false, error: "A map entry must be a JSON object." };

  const extra = unknownField(value, ENTRY_FIELDS);
  if (extra) return { ok: false, error: `Unknown field: ${extra}` };

  const mapName = readText(value, "map_name");
  if (!mapName.ok) return mapName;

  const sourceArchive = readText(value, "source_archive");
  if (!sourceArchive.ok) return sourceArchive;

  const sourceHash = readText(value, "source_hash");
  if (!sourceHash.ok) return sourceHash;

  const catalogVersion = readCount(value, "catalog_version");
  if (!catalogVersion.ok) return catalogVersion;

  const width = readCount(value, "width_elmos");
  if (!width.ok) return width;

  const height = readCount(value, "height_elmos");
  if (!height.ok) return height;

  const worldHeightMin = readMeasure(value, "world_height_min");
  if (!worldHeightMin.ok) return worldHeightMin;

  const worldHeightMax = readMeasure(value, "world_height_max");
  if (!worldHeightMax.ok) return worldHeightMax;

  const displayName = optionalText(value, "display_name");
  if (!displayName.ok) return displayName;

  const description = optionalText(value, "description");
  if (!description.ok) return description;

  const mapVersion = optionalText(value, "map_version");
  if (!mapVersion.ok) return mapVersion;

  const author = optionalText(value, "author");
  if (!author.ok) return author;

  const archiveFilename = optionalText(value, "archive_filename");
  if (!archiveFilename.ok) return archiveFilename;

  const minWind = optionalMeasure(value, "min_wind");
  if (!minWind.ok) return minWind;

  const maxWind = optionalMeasure(value, "max_wind");
  if (!maxWind.ok) return maxWind;

  const tidalStrength = optionalMeasure(value, "tidal_strength");
  if (!tidalStrength.ok) return tidalStrength;

  const voidWater = optionalFlag(value, "void_water");
  if (!voidWater.ok) return voidWater;

  const voidGround = optionalFlag(value, "void_ground");
  if (!voidGround.ok) return voidGround;

  const waterCoverage = optionalMeasure(value, "water_coverage");
  if (!waterCoverage.ok) return waterCoverage;

  const appearance = readAppearance(value);
  if (!appearance.ok) return appearance;

  const points = readPoints(value.points);
  if (!points.ok) return points;

  if (worldHeightMax.value < worldHeightMin.value) {
    return { ok: false, error: "`world_height_max` cannot be below `world_height_min`." };
  }

  if (minWind.value !== null && maxWind.value !== null && maxWind.value < minWind.value) {
    return { ok: false, error: "`max_wind` cannot be below `min_wind`." };
  }

  if (waterCoverage.value !== null && (waterCoverage.value < 0 || waterCoverage.value > 1)) {
    return {
      ok: false,
      error: "`water_coverage` is a share of the map between 0 and 1, not a percentage.",
    };
  }

  if (voidWater.value === true && waterCoverage.value !== null) {
    return {
      ok: false,
      error: "A map with `void_water` has no water to report a `water_coverage` for.",
    };
  }

  return {
    ok: true,
    value: canonicalEntry({
      map_name: mapName.value,
      display_name: displayName.value,
      description: description.value,
      map_version: mapVersion.value,
      author: author.value,
      archive_filename: archiveFilename.value,
      source_archive: sourceArchive.value,
      source_hash: sourceHash.value,
      catalog_version: catalogVersion.value,
      width_elmos: width.value,
      height_elmos: height.value,
      world_height_min: worldHeightMin.value,
      world_height_max: worldHeightMax.value,
      min_wind: minWind.value,
      max_wind: maxWind.value,
      tidal_strength: tidalStrength.value,
      void_water: voidWater.value,
      void_ground: voidGround.value,
      water_coverage: waterCoverage.value,
      appearance: appearance.value,
      points: points.value,
    }),
  };
}

/** The name to answer a malformed entry under, which is whatever of it can be
 * echoed back. Nothing, when the entry has no usable name, since the result's
 * position is what identifies it either way. */
function labelOf(value: unknown): string {
  if (!isRecord(value)) return "";
  const mapName = value.map_name;
  if (typeof mapName !== "string") return "";
  const trimmed = mapName.trim();
  return trimmed.length > 0 && trimmed.length <= MAX_LENGTHS.map_name ? mapName : "";
}

/**
 * The batch.
 *
 * Everything that refuses the whole request is here, and it is a short list: a
 * body that is not an object, an envelope the hub does not speak, an unknown
 * field on the body, a batch that is empty or over the cap, and one name twice.
 * Anything wrong with a single entry is that entry's own outcome.
 *
 * A batch over the cap is a 413 rather than a 400. The request is well formed
 * and the caller's fix is to split it, which is a different thing to be told
 * than "that was malformed".
 *
 * One name twice is the exception that refuses the batch, and it matches
 * `parseMapHaveBody`. One canonical name is one archive, permanently, so a batch
 * naming one map twice is a client that has misread its own map list. Answering
 * it would mean deciding what the second entry means against a row the first
 * entry has just written, which is a rule nothing has a reason to have.
 */
export function parseMapSubmitBody(body: unknown): ParsedMapSubmitBody {
  if (!isRecord(body)) {
    return { ok: false, error: "The request body must be a JSON object.", status: 400 };
  }

  const extra = unknownField(body, BODY_FIELDS);
  if (extra) {
    return { ok: false, error: `Unknown field: ${extra}`, status: 400 };
  }

  if (body.format !== MAP_SUBMIT_FORMAT) {
    return {
      ok: false,
      error: `\`format\` must be "${MAP_SUBMIT_FORMAT}".`,
      status: 400,
    };
  }

  if (body.version !== MAP_SUBMIT_VERSION) {
    return {
      ok: false,
      error: `\`version\` must be ${MAP_SUBMIT_VERSION}. This hub speaks no other.`,
      status: 400,
    };
  }

  const raw = body.maps;
  if (!Array.isArray(raw)) {
    return { ok: false, error: "`maps` is required and must be an array.", status: 400 };
  }
  if (raw.length === 0) {
    return { ok: false, error: "`maps` must not be empty.", status: 400 };
  }
  if (raw.length > MAP_SUBMIT_MAX_MAPS) {
    return {
      ok: false,
      error: `A batch may carry at most ${MAP_SUBMIT_MAX_MAPS} maps. That request carried ${raw.length}. Split it.`,
      status: 413,
    };
  }

  const entries: SubmittedEntry[] = [];
  const seen = new Set<string>();

  for (const [index, value] of raw.entries()) {
    const label = labelOf(value);
    if (label !== "") {
      if (seen.has(label)) {
        return {
          ok: false,
          error: `maps[${index}] names a map already in the batch.`,
          status: 400,
        };
      }
      seen.add(label);
    }

    const parsed = parseEntry(value);
    entries.push(
      parsed.ok
        ? { ok: true, entry: parsed.value }
        : { ok: false, mapName: label, said: parsed.error },
    );
  }

  return { ok: true, entries };
}

export function buildMapSubmitBody(results: MapSubmitResult[]): MapSubmitBody {
  return {
    format: MAP_SUBMIT_FORMAT,
    version: MAP_SUBMIT_VERSION,
    results,
  };
}
