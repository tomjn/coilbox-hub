/**
 * What is known about a game's or map's redistribution terms, and what that
 * knowledge rests on (issue #97).
 *
 * Types only, the same as `./asset`. The migrations are
 * `supabase/migrations/20260814150000_asset_licence.sql` and the
 * `asset_licence` ones after it, and they are the authority.
 * `licence.test.ts` reads the literal list back out of it, because nothing in
 * TypeScript can keep a check constraint in step.
 *
 * This is research, and it is not a gate. It used to refuse uploads, and
 * because a subject with no row reads as `unknown` it refused every game
 * nobody had got round to, including the most permissively licensed one in the
 * corpus. #167 took it off the upload path: moderation and reporting decide
 * whether a picture stays, and they look at the picture. Nothing on a write
 * path calls anything in this file, and a row here is for a moderator to read.
 */

/**
 * Whether one class of picture may be published for one subject.
 *
 * `unknown` and `denied` both block. They are still separate, because one is a
 * gap in the research and the other is a settled no, and only the first is
 * worth looking at again.
 *
 * There is no fourth state and no absent state. A subject with no row at all
 * reads as `unknown`, so anything deciding whether to publish treats a missing
 * lookup and an undecided one identically. See {@link mayRedistribute}. Maps
 * are the exception, and they are an exception in the data rather than in this
 * rule: a map with no row of its own falls back to a real row that covers every
 * map. See {@link licenceForMap}.
 */
export const ASSET_REDISTRIBUTION_STATES = ["unknown", "allowed", "denied"] as const;

export type AssetRedistribution = (typeof ASSET_REDISTRIBUTION_STATES)[number];

/**
 * A row as the table stores it, in the table's own column names.
 *
 * Exactly one of `game`, `map_name` and `all_maps` is set. `game` is the
 * modinfo shortname, the value `asset.game` and `item.game_key` hold, and never
 * a version. `map_name` is the full canonical name including the version
 * string, the value `asset.map_name` holds, and is never split. `all_maps` is
 * the one row that answers for every map without a row of its own.
 *
 * The three evidence fields are nullable because "nobody could find out" is a
 * real finding worth recording. A null `licence` is not a permissive one.
 */
export interface AssetLicenceRow {
  id: string;

  game: string | null;
  map_name: string | null;
  /** True on the single blanket row, null on every other row. */
  all_maps: boolean | null;

  /** An SPDX identifier where the project publishes a clean one, and a plain
   * description where the tree is mixed. Null when nobody could find out. */
  licence: string | null;
  /** Where the claim came from. The column that outlives the reasoning. */
  licence_url: string | null;
  /** Anything the other fields flatten, such as the one directory that differs. */
  notes: string | null;

  /** The other thing a yes may rest on: a decision by somebody with the
   * standing to make it, where no licence grants what the hub wants to do.
   * Null on rows that permit nothing, and on rows whose yes is a real grant. */
  decision: string | null;
  /** Set exactly when `decision` is. Separate from `checked_at` because
   * research goes stale when a project relicences and a decision goes stale
   * when the person who made it changes his mind. */
  decided_at: string | null;

  checked_at: string;
  /** A person, a handle, or the name of whatever automated the search. */
  checked_by: string;

  /** Images the archive already contains. */
  redistribute_extracted: AssetRedistribution;
  /** Images drawn from a model, which are derivative works and are not always
   * covered by the permission that covers extraction. */
  redistribute_rendered: AssetRedistribution;

  created_at: string;
  updated_at: string;
}

/**
 * Whether the research says this origin may be redistributed for this subject,
 * given the licence row found for it, or `undefined` when no row was found.
 *
 * Reads `allowed` and nothing else as a yes: no row, an undecided row and a
 * refused row all answer no. That is a reading of the record and no longer a
 * decision about a request. Nothing calls it on a write path (#167), and
 * nothing should: an upload of a game nobody has researched is an ordinary
 * upload for the queue, not a refusal.
 *
 * For a map, resolve the row through {@link licenceForMap} first. Handing this
 * function the per map lookup alone answers no for every map that has no row of
 * its own, which is almost all of them.
 *
 * `uploaded` is not covered here. Nobody can tell from the bytes what an
 * uploaded image is a picture of or who made it, so a per game or per map
 * decision cannot answer for one.
 */
export function mayRedistribute(
  licence: AssetLicenceRow | null | undefined,
  origin: "extracted" | "rendered",
): boolean {
  if (!licence) return false;
  const state =
    origin === "rendered" ? licence.redistribute_rendered : licence.redistribute_extracted;
  return state === "allowed";
}

/**
 * Which row decides for a map: its own if it has one, otherwise the blanket row
 * that covers every map (issue #121).
 *
 * Maps have no central licence and no field anywhere that could carry one, so
 * per map research does not scale past the handful of mappers who wrote
 * something down. The maintainer's answer was a default, and the default lives
 * in the table as an ordinary row rather than in this file as a constant. That
 * keeps one property worth keeping: the answer is still data, so it is still
 * revocable, still dated, and still says who decided it and why.
 *
 * Pass both rows. A caller looks up `map_name = <the canonical name>` and
 * `all_maps = true`, and hands them over in that order. Feed the result to
 * {@link mayRedistribute}, which reads a missing blanket row as no.
 *
 * A per map row wins outright, including a per map `denied`. Taking one map
 * back out is one insert and does not disturb the default.
 *
 * Games have no equivalent. There are three of them and each has a repository
 * to read, so a blanket game row would record a finding nobody made.
 */
export function licenceForMap(
  perMap: AssetLicenceRow | null | undefined,
  allMaps: AssetLicenceRow | null | undefined,
): AssetLicenceRow | null {
  return perMap ?? allMaps ?? null;
}
