/**
 * One map's facts as the hub holds them, and the digest that says two
 * submissions are the same facts (#182, #187).
 *
 * `public.map.facts_digest` exists so that "has anything changed" is one string
 * equality rather than a comparison of twenty columns and a few hundred point
 * rows. A field by field comparison grows a bug every time a column is added:
 * the new column is not in the list, so a submission that changes only that
 * column reads as unchanged and the improvement is dropped without a trace.
 *
 * A digest only works if the same facts always produce the same string. The
 * request is JSON, and JSON is not a canonical form: an object's keys have no
 * order, a client may leave an optional field out or send it as null, and the
 * order it happened to walk a Lua table in is not a fact about the map. So the
 * entry is normalised before it is hashed, and every choice below is forced by
 * one of those.
 *
 * The digest is over the entry including its points, which is why they are
 * normalised here rather than left to the writer.
 */

import { encodedHash } from "@/lib/assets/hash";

/**
 * One point on a map, in the coordinate space the archive reports: x across, z
 * along. `y` is null on almost every start position, because the engine
 * resolves a spawn height from the terrain rather than storing one.
 */
export interface MapPoint {
  x: number;
  z: number;
  y: number | null;
  meta: Record<string, unknown> | null;
}

/** The three kinds `map_point.kind` accepts, each in one array. */
export interface MapPoints {
  start: MapPoint[];
  metal: MapPoint[];
  geo: MapPoint[];
}

/**
 * A map entry as the hub holds it: every column `public.map` has that a client
 * can measure, and nothing the hub works out for itself.
 *
 * Snake case, matching the wire and matching the table, because this object is
 * both the thing that gets hashed and the jsonb the submission function reads.
 * One shape rather than two saves a translation nobody would notice going
 * wrong.
 *
 * Every optional field is present and null rather than absent. A client that
 * omits `min_wind` and a client that sends `"min_wind": null` are saying the
 * same thing about the same map, and they have to reach the same digest.
 */
export interface MapEntry {
  map_name: string;
  display_name: string | null;
  description: string | null;
  map_version: string | null;
  author: string | null;
  archive_filename: string | null;
  source_archive: string;
  source_hash: string;
  catalog_version: number;
  width_elmos: number;
  height_elmos: number;
  world_height_min: number;
  world_height_max: number;
  min_wind: number | null;
  max_wind: number | null;
  tidal_strength: number | null;
  void_water: boolean | null;
  void_ground: boolean | null;
  water_coverage: number | null;
  appearance: Record<string, unknown>;
  points: MapPoints;
}

/**
 * The order two points of the same kind are written in, when the order is not
 * itself a fact.
 *
 * Position first, because a metal spot is where it is. The rest of the point
 * breaks the remaining ties, which are two spots at one coordinate carrying
 * different amounts, so the order is decided by the whole point and two points
 * that differ never swap places between runs. Two points that are equal in
 * every field compare equal, and which of them is written first cannot matter,
 * because they are the same point twice.
 */
function comparePoints(left: MapPoint, right: MapPoint): number {
  if (left.x !== right.x) return left.x - right.x;
  if (left.z !== right.z) return left.z - right.z;

  const leftRest = canonicalJson({ y: left.y, meta: left.meta });
  const rightRest = canonicalJson({ y: right.y, meta: right.meta });
  if (leftRest === rightRest) return 0;
  return leftRest < rightRest ? -1 : 1;
}

/**
 * The entry in the one order the hub stores and hashes it in.
 *
 * Metal spots and geo vents are sorted and start positions are not, and that
 * split is the schema's own. `20260818100000_map_catalog.sql` says
 * `map_point.ordinal` is the team index on a start position and carries
 * meaning, and on the other two kinds it is "the order the archive listed them
 * in, which is stable but arbitrary".
 *
 * So an arbitrary order is normalised away, and a meaningful one is kept. Two
 * clients that list a map's metal spots in different orders have measured the
 * same map and must reach one digest. Two clients that list its start positions
 * in different orders are describing different team spawns, and calling that
 * the same facts would store one client's team layout and then refuse the
 * other's forever.
 *
 * The points are stored in the order this produces, so the stored ordinals and
 * the digest always agree about what was submitted.
 */
export function canonicalEntry(entry: MapEntry): MapEntry {
  return {
    ...entry,
    points: {
      start: entry.points.start,
      metal: [...entry.points.metal].sort(comparePoints),
      geo: [...entry.points.geo].sort(comparePoints),
    },
  };
}

/**
 * A value as one settled string, whatever order its keys arrived in.
 *
 * Keys are sorted rather than written in a fixed list, because `appearance` and
 * a point's `meta` are pass through objects whose keys come out of the
 * archive's Lua and are not the hub's to enumerate. Sorted by code unit, which
 * is what `Array.prototype.sort` does with no comparator. `localeCompare` would
 * be the readable choice and it is the wrong one: its order depends on the
 * locale the process is running in, so the hub would compute one digest on a
 * developer's machine and another on a server.
 *
 * Numbers go through `JSON.stringify`, which is the shortest string that reads
 * back as the same double and is specified exactly rather than left to the
 * runtime. That settles the two ways a client can write one measurement: `890`
 * and `890.0` parse to one double and serialise to `890`. It also settles
 * negative zero, which JSON writes as `0`.
 *
 * Null is written as null and nothing is ever absent, so an omitted optional
 * field and an explicit null are one string. `undefined` cannot arrive here:
 * the parser fills every field in.
 *
 * This is not `JSON.stringify` with a replacer, because a replacer cannot
 * reorder keys and `JSON.stringify` preserves insertion order, which is the one
 * property that makes it useless as a canonical form.
 */
export function canonicalJson(value: unknown): string {
  if (value === null) return "null";
  if (typeof value === "number" || typeof value === "boolean" || typeof value === "string") {
    return JSON.stringify(value);
  }
  if (Array.isArray(value)) {
    return `[${value.map(canonicalJson).join(",")}]`;
  }

  const record = value as Record<string, unknown>;
  const fields = Object.keys(record)
    .sort()
    .map((key) => `${JSON.stringify(key)}:${canonicalJson(record[key])}`);
  return `{${fields.join(",")}}`;
}

/**
 * The digest the row stores, and the whole of what "the same facts" means.
 *
 * SHA-256 as 64 lowercase hex characters, the same shape and the same call
 * `lib/assets/hash.ts` uses for the encoded bytes, so the hub has one spelling
 * of a digest rather than two. It sits well inside the 128 character check on
 * `public.map.facts_digest`.
 *
 * Computed by the hub and never sent by a client, for the reason the migration
 * gives: a client that could declare its own digest could declare the one
 * already stored and have its facts read as unchanged forever.
 *
 * The entry is canonicalised here as well as at parse time, so this function
 * answers the same for any caller rather than only for one that remembered.
 *
 * What this does not have to survive is a round trip through the table. The
 * columns are `real`, so a value read back has lost precision the request
 * carried, and nothing here ever reads one back: the digest is computed from
 * the request and compared against the digest stored from an earlier request.
 * Two digests are compared, never a digest and a row.
 */
export async function factsDigest(entry: MapEntry): Promise<string> {
  const canonical = canonicalJson(canonicalEntry(entry));
  return encodedHash(new TextEncoder().encode(canonical).buffer as ArrayBuffer);
}
